# 03. Authentication and authorization

Two different questions:

- **Authentication**: *who are you?* (login, sessions, e-mail verification,
  password reset)
- **Authorization**: *what may you do?* (roles, ownership, policies)

## Sessions, not tokens

When you log in, Laravel stores your user id in a **session** (a row in the
`sessions` table) and sends the browser a cookie with the session id. The
browser attaches the cookie to every later request automatically.

The cookie is:

- **HttpOnly**: JavaScript cannot read it. An XSS bug cannot steal it.
- **SameSite=Lax**: the browser does not send it on cross-site `POST`
  requests from other websites.
- **Encrypted** by Laravel with `APP_KEY`.
- **Secure** in production: only sent over HTTPS (`SESSION_SECURE_COOKIE`).

A popular alternative is a token (often a JWT) stored in `localStorage` and
sent in an `Authorization` header. It is simpler to reason about across
domains, but any script running on the page can read `localStorage`. For a
first-party SPA on the same site as its API, cookies are the safer default.
Laravel Sanctum's documentation recommends exactly this setup.

## CSRF: the weakness of cookies, and the fix

Because the browser sends cookies automatically, a malicious site could try
to make your browser send a request to our API ("Cross-Site Request
Forgery"). The original project's admin actions were plain GET links
(`delete_user.php?id=5`), so an `<img src="...">` on any page would trigger
them for a logged-in admin.

Defences here:

1. **No state change on GET.** Every action is `POST`, `PUT`, `PATCH` or
   `DELETE`.
2. **The XSRF token.** Before the first state-changing request the SPA calls
   `GET /sanctum/csrf-cookie`. Laravel sets an `XSRF-TOKEN` cookie that
   JavaScript *on our origin* can read. Axios copies it into an
   `X-XSRF-TOKEN` header on each request. Another website cannot read our
   cookies, so it cannot produce the header → **419 CSRF token mismatch**.
3. **SameSite=Lax** cookies as a second layer.

## Login

[`LoginRequest`](../backend/app/Http/Requests/Auth/LoginRequest.php) and
[`SessionController`](../backend/app/Http/Controllers/Api/Auth/SessionController.php):

- E-mails are compared lower-case.
- Wrong e-mail and wrong password give **the same message**, so the login
  form cannot be used to discover who has an account.
- After 5 failures for one e-mail + IP, login is locked for a minute (**429**).
- Suspended accounts are refused.
- On success the session id is **regenerated**. That defeats *session
  fixation*, where an attacker gets a victim to use a session id the attacker
  already knows.

## E-mail verification

1. On registration, Laravel fires the `Registered` event, which sends
   [`VerifyEmailNotification`](../backend/app/Notifications/VerifyEmailNotification.php)
   through the queue.
2. The e-mail links to the React page `/verify-email?id=…&hash=…&expires=…&signature=…`.
3. That page calls `POST /api/email/verify/{id}/{hash}?expires=…&signature=…`.
4. The `signed:relative` middleware recomputes an HMAC of the path and query
   with `APP_KEY`. Changing any character (another id, a later expiry)
   breaks the signature → 403. The controller also checks `hash` against the
   user's current e-mail.

Unverified users can log in, but every route group marked `verified`
(booking, reviewing, operator and admin areas) answers 403. The app shows a
banner with a "resend" button (rate limited to 6 per minute).

## Password reset

Built on Laravel's password broker, see
[`PasswordResetController`](../backend/app/Http/Controllers/Api/Auth/PasswordResetController.php).
The class comment compares it line by line with the original implementation.
In short: a 64-character random token, stored hashed, valid 60 minutes, single
use, throttled. Asking for a link gives the same answer whether or not the
account exists. A successful reset logs the account out of every device.

## Roles

`users.role` is one of `traveler`, `operator`, `admin` (PHP enum
[`UserRole`](../backend/app/Enums/UserRole.php), plus a `CHECK` constraint).
`role` is not mass-assignable: registration always creates travelers or
operators. Admins are created by another admin (invited by e-mail) or by the
console command `php artisan app:create-admin`.

Operators have an extra profile with a status: `pending` → `approved`
(by an admin) → maybe `suspended`. Only approved operators reach the
operator area, and only their tours are visible to the public
(`Tour::visibleToPublic()`).

## Authorization in three layers

```
routes/api.php        role:admin, role:operator, operator.approved, verified
      ↓
Policies              TourPolicy::update → is this the operator's own tour?
      ↓               BookingPolicy::cancel → is this the traveler's own booking?
Scoped queries        $request->user()->tours() → only their records exist
```

1. **Route groups** decide who may enter an area. Adding a new admin
   endpoint inside the `admin` group protects it automatically.
2. **Policies** ([`app/Policies`](../backend/app/Policies)) answer questions
   about a specific record. Controllers call `Gate::authorize('update', $tour)`,
   which throws a 403 if the policy says no.
3. **Scoped queries**: lists start from the user's relation, so other
   people's records never even come out of the database.

**Not every "you can't do that" is a policy.** A policy answers "may this
user act on this record at all?" and its answer is a 403. A rule that depends
on *what* the request asks for and on the record's current state is a
business rule. Example: the admin moderation lock (Exercise 6). The
operator still owns the tour and may edit it (the policy says yes), but
while an admin's archive locks it they may not change its *status*.
`TourService` checks that under a row lock and answers 422 with the code
`tour_moderated`. See [decisions.md D19](decisions.md) for why it is not a
policy or a Form Request rule.

The frontend also hides buttons the user may not use, but that is only for
convenience. **Every rule is enforced by the API**, because anyone can send
requests without our frontend (curl, Postman, the browser console).
