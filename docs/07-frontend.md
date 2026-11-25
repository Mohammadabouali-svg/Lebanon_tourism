# 7. The frontend (React + TypeScript + Vite + Tailwind)

This chapter explains how the user interface in `frontend/` is organised and
why it is built the way it is. Read it with the code open: every file it
mentions has comments of its own.

## 7.1 The stack in one paragraph

**React 19** renders the pages. **TypeScript** checks at build time that we
use the API's data correctly (a typo like `tour.prize_cents` fails the build).
**Vite** serves the app in development (with instant reload) and bundles it for
production. **Tailwind CSS 4** provides the styling as utility classes
(`rounded-lg px-4 py-2`). **React Router 8** maps URLs to pages.
**TanStack React Query 5** fetches and caches data from the API. **axios**
makes the HTTP requests.

That is the whole list. There is no UI kit, no form library and no chart
library. A small booking site does not need them, and every dependency is
code you must keep updated and trust. The UI components in
`src/components/ui` are a few dozen lines each.

## 7.2 Folder map

```
frontend/src
├── main.tsx              entry point: providers + router
├── router.tsx            every URL of the app, with guards and layouts
├── index.css             Tailwind import + design tokens (@theme)
├── lib/                  framework-free helpers
│   ├── api.ts            axios instance, CSRF handling, error parsing
│   ├── types.ts          TypeScript mirrors of the Laravel API Resources
│   ├── money.ts          cents ↔ "$129.99"
│   ├── dates.ts          date formatting (time zones!)
│   └── queryClient.ts    React Query defaults
├── hooks/                small reusable hooks (forms, URL filters, debounce, file preview)
├── components/
│   ├── ui/               Button, Field, Alert, Badge, Card, Dialog, Table, Pagination, ...
│   ├── layout/           RootLayout, PublicLayout (navbar + footer), DashboardLayout (sidebar)
│   ├── StarRating.tsx
│   └── MonthlyBarChart.tsx
├── features/             one folder per business area
│   ├── auth/             hooks.ts, guards.tsx, login/register/reset/verify pages
│   ├── tours/            queries.ts, list, detail, reviews
│   ├── bookings/         queries.ts, checkout page, my bookings, bookings table
│   ├── account/          profile, password, wallet
│   ├── operator/         dashboard, tours, tour form, bookings
│   └── admin/            dashboard, users, operators, tours, bookings, discounts, catalog
└── pages/                pages that belong to no feature (home, about, 404, error)
```

**Group by feature, not by file type.** Everything about bookings sits in
`features/bookings`: its API hooks *and* its pages. When you work on bookings,
you open one folder. A layout like `components/`, `hooks/`, `services/` with
every feature mixed inside each folder gets harder to navigate as it grows.

## 7.3 How a request travels

Take the "My bookings" page:

```
MyBookingsPage.tsx         const bookings = useMyBookings(page)
      │
features/bookings/queries.ts   useQuery({ queryKey: ['bookings','mine',page],
      │                                  queryFn: () => api.get('/bookings', ...) })
      │
lib/api.ts                 axios: baseURL '/api', cookies, X-XSRF-TOKEN header
      │
Vite dev server (:5173)    proxy: /api/* → http://nginx  (vite.config.ts)
      │
nginx → PHP-FPM → Laravel  routes/api.php → middleware → BookingController@index
```

The browser only ever talks to `http://localhost:5173`. In production nginx
plays the same role (see `docker/nginx/prod.conf`): it serves the built files
*and* forwards `/api` to Laravel. One origin in both environments means no
CORS configuration and no third-party cookie problems.

Components never call `api` directly. They call a hook from the feature's
`queries.ts`. That keeps URLs, query keys and cache invalidation in one place
per feature.

## 7.4 Authentication: session cookies, not tokens

Many tutorials store a JWT in `localStorage` and send it in an
`Authorization` header. We deliberately do not:

| | Token in localStorage | Session cookie (what we use) |
|---|---|---|
| Readable by JavaScript | Yes. Any XSS bug can steal it | No (`HttpOnly`) |
| Revocable by the server | Not before it expires | Yes: delete the session row |
| Logout everywhere | Hard | `DELETE FROM sessions WHERE user_id = ?` |
| CSRF risk | None | Yes, so it needs a CSRF token |

The CSRF part is handled in `lib/api.ts`:

```ts
export const api = axios.create({
  baseURL: '/api',
  withCredentials: true,  // send the session cookie
  withXSRFToken: true,    // copy the XSRF-TOKEN cookie into the X-XSRF-TOKEN header
})
```

Before the first POST/PUT/PATCH/DELETE, `ensureCsrfCookie()` calls
`GET /sanctum/csrf-cookie`, and Laravel sets the `XSRF-TOKEN` cookie. Another
website can make your browser *send* our cookies, but it cannot *read* them,
so it cannot produce the matching header, and Laravel answers **419**. If our
own session expired while the tab was open, the response interceptor fetches
a fresh token and retries the request once.

"Who is logged in?" is just another query: `useCurrentUser()` calls
`GET /api/user`. A **401** answer means "nobody" and becomes `null` (it is
expected, so you will see that 401 in the browser console as a guest). Login
and logout write the result straight into the cache:

```ts
onSuccess: (user) => queryClient.setQueryData(['user'], user)
```

Every component reading the user (navbar, guards, booking box) re-renders.
No separate "auth store" is needed.

## 7.5 Route guards are UX, not security

`features/auth/guards.tsx` decides what to *show*:

```tsx
{ element: <RequireAuth roles={['traveler']} requireVerified />,
  children: [{ path: 'bookings', element: <MyBookingsPage /> }] }
```

A guest is redirected to `/login?redirect=/bookings`, an operator sees "not
available for your account", an unverified traveler sees "please verify your
e-mail".

**This protects nothing.** The JavaScript runs on the user's computer and
anyone can change it. The real protection is on the server: the same route
in `backend/routes/api.php` sits behind `auth:sanctum`, `verified` and
`role:traveler`, and policies check ownership. The guards just spare honest
users from clicking into pages that would only answer 403.

The original project hid admin links but left the admin PHP files open, which
is exactly this mistake.

Two small safety details in the auth code:

- `safeRedirect()` only follows `?redirect=` values that start with a single
  `/`. Otherwise a phishing link could send users from our real login page to
  `https://evil.example` (an "open redirect").
- The login error never says whether the e-mail or the password was wrong.
  The server sends one generic message on purpose.

## 7.6 Server state vs UI state

There are two kinds of state, and they are handled differently:

- **Server state** is data that lives in the database: tours, bookings, the
  current user. Use **React Query**. It caches, deduplicates simultaneous
  requests, shows stale data while refetching, and refreshes after changes.
- **UI state** is state that only this screen cares about: is the dialog
  open, what is typed in the search box, which star is hovered. Use
  **`useState`**.

A common beginner mistake is copying server data into `useState`
(`const [tours, setTours] = useState([])` + `useEffect(fetch...)`). You then
have to handle loading, errors, refetching, race conditions and stale copies
yourself, on every page.

After a change, **invalidate** the affected keys instead of patching the
cache by hand:

```ts
// features/bookings/queries.ts
onSuccess: () => {
  queryClient.invalidateQueries({ queryKey: ['bookings'] }) // my bookings
  queryClient.invalidateQueries({ queryKey: ['tours'] })    // free spots changed
  queryClient.invalidateQueries({ queryKey: ['user'] })     // wallet balance changed
}
```

Query keys are arrays from general to specific (`['tours', 'detail', 5]`), so
invalidating `['tours']` refreshes every tour list and detail at once.

**Filters live in the URL.** On the tours page and the admin tables, filters
are read from and written to the query string (`?region_id=4&sort=price_asc`)
through `useSearchParams` / `hooks/useQueryFilters.ts`. The back button,
bookmarks, shared links and page refreshes all keep the filters, for free.

## 7.7 Forms and validation errors

The browser does not decide what is valid; Laravel does. A form:

1. keeps what the user typed (`hooks/useFormFields.ts`);
2. sends it with a React Query **mutation**;
3. shows Laravel's 422 messages next to the right fields.

```tsx
const login = useLogin()
const error = login.error ? parseApiError(login.error) : null
...
<TextField label="E-mail" {...field('email')} error={error?.fieldErrors.email} />
```

`parseApiError()` (in `lib/api.ts`) turns any failure into
`{ status, message, code, fieldErrors }`:

- **422 validation**: `fieldErrors` maps field names to messages. Nested
  fields keep Laravel's dotted names, so the tour form shows
  `errors['itinerary.2.title']` under the third itinerary row.
- **422 business rule**: `code` is the machine-readable reason from
  `BusinessRuleException` (`insufficient_funds`, `not_enough_spots`,
  `invalid_discount`, `tour_has_bookings`, ...). The checkout page uses
  `code === 'invalid_discount'` to show the message under the code field.
- **403**: the policy's explanation ("You can only review tours you have booked.").
- **5xx**: a friendly generic message. Technical details never reach the screen.

Attributes such as `required` or `min` on inputs are only a convenience.
Users can bypass them, so they never replace server validation.

`TextField`, `SelectField` and `TextareaField` (in `components/ui/Field.tsx`)
connect the label, the hint and the error to the control with
`htmlFor`/`id` and `aria-describedby`, so screen readers read the error
together with the field.

## 7.8 Money: integer cents, and the server sets the price

The API speaks **integer cents** in both directions (`price_cents: 6500`).
`lib/money.ts` is the only place that converts:

```ts
formatMoney(6500)            // "$65.00"
parseDollarsToCents('65.5')  // 6550
parseDollarsToCents('1.005') // null (too many decimals)
```

`parseDollarsToCents` splits the text into dollar and cent digits. It never
does `parseFloat(x) * 100`, because floating point cannot represent most
decimals exactly (`1.005 * 100 === 100.49999999999999`).

More important: **the browser never computes a price.** The checkout page
asks the server for a quote whenever the number of travelers or the discount
code changes:

```ts
// POST /api/tours/{id}/quote  { spots, discount_code }
// → { subtotal_cents, discount_cents, total_cents, ... }
```

The quote runs the same `PricingService` that the booking itself uses, so
what is shown is what is charged. When booking, only *what* is wanted is
sent (`spots`, `payment_method`, `discount_code`), never a price. In the
original project the price came from a hidden form field, so anyone could
book a tour for $0.

The spots stepper is debounced (`hooks/useDebouncedValue.ts`): clicking "+"
five times quickly sends one quote request, not five.

Card payments: in development a fake gateway approves them, and the page
says so. **No card-number input exists anywhere in this app.** With a real
provider (e.g. Stripe), the card is typed into the provider's own embedded
form, so card data never touches our code or servers.

## 7.9 Other things worth noticing

- **Dates.** A tour date like `"2026-11-18"` has no time zone. `new Date()`
  would read it as midnight UTC and show "17 Nov" to someone in New York.
  `lib/dates.ts` formats calendar dates in UTC and timestamps in the
  visitor's zone.
- **Uploads.** Review photos and tour images are sent as `FormData`
  (multipart). PHP only parses multipart bodies on POST, so updates send
  `POST` with `_method=PUT` (Laravel's "method spoofing").
  `hooks/useFilePreview.ts` shows a preview and frees its memory afterwards.
- **No `dangerouslySetInnerHTML`.** All text from users (tour descriptions,
  reviews, names) is rendered as text, which React escapes. Line breaks are
  kept with the CSS class `whitespace-pre-line`, not by injecting `<br>`.
- **Display server-decided flags, don't re-derive them.** Tours carry
  `available_spots` and `is_bookable` from the API. The card's "Sold out"
  badge checks `available_spots === 0` (that is literally what "sold out"
  means), while the detail and booking pages hide the book button on
  `!is_bookable` (which also covers started or draft tours). Neither page
  works out bookability from dates itself, and the API refuses a booking
  anyway (`not_enough_spots`).
- **Code splitting.** Operator and admin pages are `lazy` routes in
  `router.tsx`. Their code is a separate download that travelers never fetch.
- **Page titles.** React 19 moves a `<title>` rendered anywhere into
  `<head>`. `PageHeader` and `DocumentTitle` use that.
- **Dialogs** use the native `<dialog>` element (`components/ui/Dialog.tsx`),
  which handles focus, the backdrop and the Esc key without a library.
- **Accessibility basics:** visible focus rings (`index.css`), a "skip to
  content" link, real `<button>`s and `<a>`s, radio buttons behind the star
  picker, and a hidden data table behind the bar chart.

## 7.10 Adding a page, step by step

Example: a public "Operators" page listing approved companies.

1. **Backend first.** Add the route, controller and Resource in Laravel and
   test them (see the backend chapters). The frontend only displays what the
   API allows.
2. **Type.** Mirror the new Resource in `src/lib/types.ts`:
   ```ts
   export interface PublicOperator { id: number; company_name: string; tours_count: number }
   ```
3. **Query hook.** In `src/features/operators/queries.ts`:
   ```ts
   export function usePublicOperators() {
     return useQuery({
       queryKey: ['operators', 'public'],
       queryFn: async () => (await api.get<{ data: PublicOperator[] }>('/operators')).data.data,
     })
   }
   ```
4. **Page component.** In `src/features/operators/OperatorsPage.tsx`, handle
   the three states explicitly: `isPending` → `<PageSpinner />`,
   `isError` → `<Alert tone="error">{parseApiError(error).message}</Alert>`,
   otherwise render the data (or an `<EmptyState>`). Start with
   `<PageHeader title="Operators" />` so the tab title is set.
5. **Route.** Add `{ path: 'operators', element: <OperatorsPage /> }` to the
   `PublicLayout` children in `router.tsx`. If it needs a login, put it under
   a `<RequireAuth>` element instead.
6. **Link.** Add it to `linksFor()` in `components/layout/Navbar.tsx`.
7. **Check.** `make npm c="run build"` (type check + build) and
   `make npm c="run lint"` must pass. Then click through the page as a guest
   and as each role.

## 7.11 Commands

```bash
make npm c="run build"   # tsc -b (type check) + vite build into frontend/dist
make npm c="run lint"    # oxlint: React hooks rules, common mistakes
make frontend-shell      # a shell inside the Node container
```

The dev server already runs inside the `frontend` container (`make up`) at
http://localhost:5173 and reloads on every save. On macOS and inside the WSL
file system, Vite is notified of file changes directly. Only for a project on
a Windows drive mounted in WSL (`/mnt/<drive>`), where Windows file changes
emit no Linux events, does Vite poll for changes (`VITE_USE_POLLING=true` in
`.env`, set automatically by `make env`).
