---
name: lt-endpoint
description: Use when adding or changing a Laravel API endpoint in Lebanon Tourism. Covers choosing the route group, Form Request, thin controller, service, policy, Resource, rate limiting, error codes and access-control tests.
---

# Adding or changing an API endpoint

Read `backend/routes/api.php` top to bottom first: it is the security map.

## Steps
1. **Audience → route group.** Put the route inside the group that already
   carries the right middleware (`auth:sanctum` + `active`, then `verified`,
   `role:traveler|operator|admin`, `operator.approved`). Never add role checks
   inside a controller. Numeric params get `->whereNumber(...)`.
2. **Abuse → throttle.** Writes that cost money, send mail or upload files get
   a named limiter (`auth`, `quotes`, `bookings`, `uploads` in
   `AppServiceProvider::configureRateLimits`) or a new one defined there.
3. **Verb.** Never change state on GET. Multipart updates: `PUT` route, client sends `POST` + `_method=PUT`.
4. **Input.** A Form Request in `app/Http/Requests/` (inline
   `$request->validate()` only for 2–3 fields). Use `Rule::enum(...)`, `max:`
   on every string, bounds on every number. Cross-field rules go in `after()`.
5. **Controller.** Thin: read `validated()` data, call a service, return a Resource.
   Lists start from the user's relation when the data is owned, eager-load
   (`with`), paginate, `->withQueryString()` for filters.
6. **Ownership.** `Gate::authorize('<ability>', $model)` with a policy method.
7. **Rules.** In a service method. Failures throw
   `BusinessRuleException::<newNamedConstructor>()` with a stable `snake_case` code.
   Multi-step writes: `DB::transaction` + `lockForUpdate()` before checking.
8. **Output.** A Resource (existing or new). New fields are a contract:
   mirror them in `frontend/src/lib/types.ts`.
9. **Side effects.** Notifications are queued and sent after commit.

## Tests (write first — see `lt-tdd`)
- Happy path plus side effects
- 401 guest, 403 for each wrong role, 403 unverified, 403 suspended,
  403/404 on another user's record (add to `AccessControlTest` when it's about access)
- 422 validation per field, 422 business codes
- Extra hostile fields are ignored
- Throttle (when it matters): the request after the limit returns 429

## Verify
`make test`, `make pint`, and
`make artisan c="route:list --path=api -v"` to check the final middleware on the route.
