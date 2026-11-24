---
name: lt-frontend-page
description: Use when adding or changing a React page, component or API hook in the Lebanon Tourism frontend. Covers types, React Query hooks and key factories, guards, loading/error/empty states, forms with Laravel 422 errors, money and dates, accessibility, build and lint.
---

# Frontend page or hook

Background: `docs/07-frontend.md`. The backend endpoint must exist and be
tested **first**. The frontend only displays what the API allows.

## Steps
1. **Type.** Mirror the Resource exactly in `src/lib/types.ts`. Money fields
   are `number` cents named `*_cents`. Dates are strings.
2. **Hook.** In `src/features/<area>/queries.ts`:
   - extend the feature's key factory (`tourKeys`, `bookingKeys`, ...), general → specific
   - `useQuery` with `Envelope<T>` / `Paginated<T>`, `placeholderData: keepPreviousData` for paged lists
   - `useMutation` that **invalidates** every affected key (think: spots,
     wallet, `userKey`, lists). Don't patch the cache by hand.
   - Components never import `api` directly.
3. **Page.** In `src/features/<area>/`:
   - starts with `<PageHeader title="..." />`
   - pending → `<PageSpinner />`, error → `<Alert tone="error">{parseApiError(error).message}</Alert>`,
     empty → `<EmptyState>`, then the data
   - filters through `useQueryFilters` (kept in the URL)
   - forms: `useFormFields`, show `parseApiError(e).fieldErrors[field]` under
     fields (dotted names for arrays), react to business `code`s where useful
   - money only through `lib/money.ts` (`formatMoney`, `parseDollarsToCents`);
     dates only through `lib/dates.ts`
   - **never compute a price**: ask the quote endpoint
   - text from users is rendered as text (`whitespace-pre-line`), never HTML
4. **Route.** Add it to `router.tsx` under the right layout. Logged-in areas
   go under `<RequireAuth roles={[...]} requireVerified />`. Operator/admin
   pages are `lazy`. Remember guards are UX only.
5. **Navigation.** `linksFor()` in `components/layout/Navbar.tsx` or the dashboard sidebar.
6. **UI components.** Reuse `components/ui/*`. Labelled fields via `Field.tsx`,
   real `<button>`/`<a>`, native `<dialog>`. No new UI, form or state libraries.

## Verify
- `make npm c="run build"` (strict type check) and `make npm c="run lint"` must pass.
- Click through at http://localhost:5173 as a guest and as each affected role
  (demo accounts in CLAUDE.md §2), including error paths (sold out, invalid
  code, 403). Check the browser console stays clean, apart from the expected guest 401 on `/api/user`.
- If you cannot open a browser, say the manual check is still to do.
