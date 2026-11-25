# 06. Security

This chapter walks through each weakness found in the original project, how
an attacker would use it, and what prevents it here. Every row links to the
code and, where possible, to a test proving the fix.

## The big ones

| # | Original weakness | Attack | Prevention here | Proof |
|---|---|---|---|---|
| 1 | Admin pages (`add_admin.php`, `edit_admin.php`, `wallet_add.php`, `delete_*.php`) had **no login check** | One anonymous POST creates an admin account | Every admin route sits in the `auth:sanctum` + `role:admin` group in [`routes/api.php`](../backend/routes/api.php). Admins are created only by admins (by invitation) or on the command line | `AccessControlTest` |
| 2 | Logged-in checks never looked at the **role** | Any traveler opens the admin dashboard | [`EnsureUserHasRole`](../backend/app/Http/Middleware/EnsureUserHasRole.php) on route groups | `AccessControlTest::test_travelers_and_operators_cannot_use_admin_endpoints` |
| 3 | **Password reset bypass**: the reset page only checked a session flag set *before* the code was verified | Take over any account, including admins, knowing only the e-mail | Laravel's password broker: every reset verifies the token itself; tokens are random, hashed, expiring, single-use | `PasswordResetTest::test_a_password_cannot_be_reset_without_the_emailed_token` |
| 4 | **Unrestricted upload**: original file extension kept, files stored in a PHP-executable folder | Upload `shell.php`, run commands on the server | Validation by content (`image`, `mimes`, no SVG) → re-encoding with GD ([`ImageUploader`](../backend/app/Support/ImageUploader.php)) → random name with our extension → nginx never executes anything but `index.php` | `ReviewTest::test_a_php_script_disguised_as_a_photo_is_rejected`, `TourManagementTest::test_svg_images_are_refused` |
| 5 | **Price taken from the browser**, negative spots accepted | Book for $0, or *gain* wallet money | Server-side pricing ([`PricingService`](../backend/app/Services/PricingService.php)), `spots` validated 1–20, DB constraints on totals | `BookingTest::test_the_price_sent_by_the_client_is_ignored`, `…zero_or_negative_spots_are_rejected` |
| 6 | **Stored XSS** in the admin booking view (`innerHTML` with the traveler's name) | A traveler named `<img onerror=…>` runs script as the admin | React escapes every value it renders; `dangerouslySetInnerHTML` is never used; data is returned raw as JSON | `ReviewTest::test_reviews_show_a_short_author_name_and_the_raw_comment_text` |
| 7 | **Reflected XSS**: `$_GET['id']` printed into JavaScript | A crafted link runs script for whoever clicks it | No server-rendered HTML at all; route parameters are validated as numbers (`whereNumber`) | n/a by design |
| 8 | **Race conditions** on wallet and spots (read, compute, write back) | Two parallel requests spend the same money / take the same last spot | Row locks inside transactions + `CHECK` constraints | `BookingTest::test_the_database_itself_refuses_overbooking` |

## The rest

| Original weakness | Prevention here |
|---|---|
| State changes through **GET links** (CSRF with an `<img>` tag) | Only POST/PUT/PATCH/DELETE change state, Sanctum checks the XSRF token (419 otherwise), cookies are SameSite=Lax |
| **No CSRF tokens** anywhere | Same as above, built into Sanctum's stateful middleware |
| Booking report downloadable by anyone by changing the id (**IDOR**) | Exports are scoped queries: operators only get bookings of their own tours, admins need the admin role. `AccessControlTest::test_operators_only_see_bookings_of_their_own_tours` |
| **Session fixation** (no new session id after login) | `session()->regenerate()` on login and registration |
| **User enumeration** ("No user found" vs "Invalid password", "Email not found") | Identical messages for unknown e-mail and wrong password, and for password reset requests. `LoginTest`, `PasswordResetTest` |
| **No rate limiting** | Named limiters (`auth`, `bookings`, `uploads`, `quotes`) and the login lockout. `LoginTest::test_login_is_throttled_after_five_failed_attempts` |
| Reset code from `time() * rand()`, stored plain, never expires, unlimited guesses | Framework token broker (CSPRNG, hashed, 60 minutes, throttled) |
| Verification code type mismatch (32 hex chars into an `int`) made codes guessable | No stored codes: HMAC-signed, expiring URLs. `EmailVerificationTest::test_a_tampered_link_is_rejected` |
| Report "Excel" file built from unescaped HTML | Real CSV with **formula-injection** protection ([`CsvExport`](../backend/app/Support/CsvExport.php)). `TourManagementTest::test_bookings_export_is_csv_and_neutralises_formulas` |
| Database and mailer errors printed to users | JSON errors only; in production a generic "Server Error", details in the log |
| Database user `root` without password | Dedicated database user; in production the password comes from a secret |
| Personal e-mail address and SMTP settings in the code | Everything in environment variables; `.env` files are git-ignored |
| Pending operators could log in and act immediately | `operator.approved` middleware; their tours are hidden until approval |
| Deleting a user deleted their bookings first, then failed on the wallet foreign key | Users are suspended, never deleted; foreign keys `RESTRICT` deletes that would lose history |
| Any visitor could post a review under any name | Reviews need a logged-in, verified traveler with a confirmed booking, once per tour |

## Mass assignment

`User::create($request->all())` would let a request set *any* column,
including `role` or `suspended_at`. Two defences:

1. Controllers pass only `$request->validated()` (or `safe()->only([...])`).
2. Models list what may be filled (`#[Fillable([...])]`). `role`,
   `booked_spots`, `balance_cents`, `status` on profiles are not in those
   lists and are set explicitly by the code that owns them.

`RegistrationTest::test_the_client_cannot_choose_its_own_role` tries it.

## Files and uploads, in depth

Each layer catches what the previous one might miss:

1. **Size limits**: `upload_max_filesize` (8 MB per file) / `post_max_size`
   (20 MB per request) in docker/php/conf.d, and nginx `client_max_body_size
   20m`: a review may carry three 5 MB photos (D21). Larger requests get a 413.
2. **Validation**: `image|mimes:jpg,jpeg,png,webp|max:5120`, per file
   (`photos.*`), and at most three review photos (`photos` `array|max:3`,
   backed by a database CHECK). Laravel checks
   the MIME type from the file *content* (libmagic), not the name. SVG is not
   allowed: it is XML that can contain JavaScript.
3. **Re-encoding**: GD decodes the pixels and writes a brand-new WebP. Hidden
   payloads and EXIF metadata (GPS location of the photo!) disappear.
4. **Naming**: a random UUID plus an extension we choose.
5. **Serving**: nginx serves `/storage/*` as static files with
   `X-Content-Type-Options: nosniff`. Only `index.php` is ever executed.
6. **Cleanup**: files are not in database transactions. `TourService` and
   `ReviewService` delete a file they just wrote when the request fails
   later (e.g. review photo 2 of 3 is not a real image), and delete old
   files only after the database committed.

## Secrets and configuration

- `APP_KEY` signs and encrypts cookies, sessions and signed URLs. Anyone who
  has it can forge them. Generate it once per environment and keep it secret.
- `.env` and `.env.production` are git-ignored. The `*.example` files show
  which variables exist, with harmless placeholder values.
- `APP_DEBUG=false` in production, always: debug pages reveal code,
  configuration and queries.

## Things the browser cannot be trusted with

A short list to remember:

- prices, totals, discounts
- who the current user is (comes from the session, never from a field)
- the owner of a record (`operator_id` is ignored; it is always the logged-in operator)
- roles and permissions (the frontend hides buttons only for convenience)
- file types (check the content, not the name)
- anything in the query string, form fields, headers or cookies the app did not sign

## Production hardening checklist

See [09-deployment.md](09-deployment.md#go-live-checklist).
