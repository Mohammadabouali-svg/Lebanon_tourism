# Exercise 7: Content-Security-Policy

## What was asked

> Add a strict `Content-Security-Policy` header in `docker/nginx/prod.conf`
> (`default-src 'self'; img-src 'self' data:; ...`). Check the browser console
> for anything it blocks. CSP is a second line of defence against XSS.
> ([10-exercises.md](../10-exercises.md))

## What we found first

| Fact | Where |
|---|---|
| No CSP. Three security headers were set at server level | `docker/nginx/prod.conf` |
| nginx's `add_header` inheritance trap: `/`, `/assets/` and `/storage/` each had their own `add_header`, so `index.html` and the bundles got **none** of the three headers (proved with `curl -I`, below) | `prod.conf` |
| The build has one external module script, no inline script or style, no `data:` or `url()` in the CSS, no `eval` | `dist/` in the nginx image |
| Upload previews use `blob:` URLs | `hooks/useFilePreview.ts`, `hooks/useFilePreviews.ts` |

## Decisions

Full version: **D22** in [decisions.md](../decisions.md).

| Question | Choice | Rejected |
|---|---|---|
| Where | Snippet `security-headers.conf`, included at server level and in every location with an `add_header` | Repeating lines per location (drifts); `add_header_inherit` |
| Page policy | `default-src 'self'`, `img-src 'self' blob:`, `object-src 'none'`, `base-uri 'none'`, `form-action 'self'`, `frame-ancestors 'none'` (+ `X-Frame-Options` kept) | `data:` (unused), any `'unsafe-*'`, `upgrade-insecure-requests` |
| Other responses | `default-src 'none'; frame-ancestors 'none'` | Same page policy everywhere |
| Rollout | Enforced, no report endpoint | Report-Only + new public endpoint |
| Dev | No CSP (Vite needs inline scripts + websocket) | A loose dev policy |

## What was done

| Layer | File | Change |
|---|---|---|
| nginx | `docker/nginx/security-headers.conf` (new) | 3 existing headers + `Content-Security-Policy $content_security_policy` |
| nginx | `docker/nginx/prod.conf` | the two policies as variables; snippet included at server level, `/storage/`, `/assets/`, `/` |
| Image | `docker/nginx/Dockerfile` | copies the snippet to `/etc/nginx/snippets/` |
| Docs | `06-security.md`, `09-deployment.md`, `10-exercises.md`, `decisions.md` (D22), `AGENTS.md` §10/§11, this write-up + README row | |

## Tests

No PHP test can see an nginx header. **Red run** (prod stack, before the change):
`/`, `/tours/1` and `/assets/*.js` had no security headers at all,
`/storage/*` only `nosniff`, `/api` and `/sanctum` the three server-level ones.
**After:** `nginx -t` OK; every location (`/`, `/tours/1`, `/favicon.svg`,
JS, CSS, 404 asset, `/storage`, `/api`, `/sanctum`, `/index.php` 404, `/.env`
403) has the four headers and exactly one CSP.

## Verification

- Positive controls in the browser: an injected inline script did not run,
  an external image was blocked, framing was blocked; all reported in the console.
- Console clean (no CSP violations) as guest, traveler (booking, review with
  2 `blob:` previews + gallery from `/storage`), operator (dashboard chart and
  progress bar: React style props applied; tour form preview + saved image),
  admin (all 7 lazy pages).
- Dev stack on :5173 unchanged (no CSP there). Dev DB untouched.
- Backend suite: 160 passed (613 assertions), unchanged by this config-only change.
- Prod stack (images, `lebanon-tourism-prod` volumes, local `.env.production`, never committed) kept on purpose for later checks.

**Environment notes.** Prod PHP image built from the OneDrive folder had
read-only directories (`config:cache` failed); worked around locally with a
derived image, not committed (follow-up). The prod image has no Faker, so
demo data was seeded from the dev image into the prod network. Cookies
ignore ports: logging in on `http://localhost` replaced the dev session
cookie of `localhost:5173`.
