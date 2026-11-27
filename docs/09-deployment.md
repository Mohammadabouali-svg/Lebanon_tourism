# 09. Deployment

`compose.prod.yaml` is a working example of a production setup on a single
server. Real deployments vary (Kubernetes, a PaaS, managed databases), but the
principles below apply everywhere.

## What is different from development

| | Development | Production |
|---|---|---|
| Code | bind-mounted from your disk | baked into the image at build time |
| PHP dependencies | including dev tools (PHPUnit, Pint) | `composer install --no-dev`, optimised autoloader |
| Frontend | Vite dev server, hot reload | `npm run build` → static files served by nginx |
| Errors | shown (`APP_DEBUG=true`) | logged to stderr, generic message to visitors |
| Config | read from `.env` on every request | cached at container start (`config:cache`) |
| opcache | re-checks files on every request | never re-checks (the code cannot change) |
| E-mail | Mailpit | a real SMTP provider |
| Cookies | HTTP | `Secure` (HTTPS only), encrypted session payload |

## The images

- [`docker/php/Dockerfile`](../docker/php/Dockerfile), target `prod`: PHP-FPM
  with the application and its production dependencies. The same image runs
  three containers: `php` (web requests), `queue` (e-mails) and `scheduler`
  (housekeeping from `routes/console.php`).
- [`docker/nginx/Dockerfile`](../docker/nginx/Dockerfile): a multi-stage
  build. Stage 1 uses Node to build the React app. Stage 2 copies only the
  resulting `dist/` folder into nginx. Node never reaches production.
- [`.dockerignore`](../.dockerignore) keeps `.env` files, `vendor/`,
  `node_modules/` and logs out of the images. Without it, your local secrets
  would be baked into an image anyone with registry access can pull.

### Building on an Apple Silicon Mac

Docker builds images for the machine it runs on. On an M-series Mac that is
`linux/arm64`, while most servers are `linux/amd64` (Intel/AMD). An arm64
image started on an amd64 server fails with `exec format error`.

Options, best first:

1. **Build in CI** (GitHub Actions, GitLab CI, ...) on an amd64 runner, then
   push the images to a registry. Production images should come from CI
   anyway, not from a developer's laptop.
2. **Build for the server's platform explicitly** (slower, because the Mac
   emulates amd64 while building):

   ```bash
   DOCKER_DEFAULT_PLATFORM=linux/amd64 docker compose -f compose.prod.yaml build
   ```

3. **Deploy to arm64 servers** (AWS Graviton, Hetzner Ampere, ...), where
   the Mac's images run natively.

On a typical Windows PC (Intel/AMD) the default platform is already
`linux/amd64`.

## Running it

```bash
cp .env.production.example .env.production   # then edit EVERY value
docker compose -f compose.prod.yaml build
docker compose -f compose.prod.yaml run --rm php php artisan key:generate --show
# paste the key into APP_KEY in .env.production
docker compose -f compose.prod.yaml up -d
docker compose -f compose.prod.yaml exec php php artisan db:seed --class=CatalogSeeder --force
docker compose -f compose.prod.yaml exec php php artisan app:create-admin
```

On start, the `php` container caches configuration and routes, then runs
`migrate --force`, because `RUN_MIGRATIONS=true` is set on that service
only. With several web replicas, run migrations as a separate one-off
deployment step instead, so they never run twice at the same time.

## HTTPS

The nginx container listens on plain HTTP port 80. Put a TLS-terminating
proxy in front: a cloud load balancer, Caddy or Traefik (automatic Let's
Encrypt certificates), or nginx with certbot. Then:

- `APP_URL` and `FRONTEND_URL` start with `https://`;
- `SESSION_SECURE_COOKIE=true` (cookies are never sent over plain HTTP);
- `SANCTUM_STATEFUL_DOMAINS` contains your real domain;
- the proxy's address is trusted: `bootstrap/app.php` trusts private network
  ranges. If your proxy has a public IP, list it there instead. Trusting
  every address (`*`) would let any client fake its IP with an
  `X-Forwarded-For` header and slip past rate limits.

## Deploying a new version

1. Build and push new images (ideally in CI, after the tests pass).
2. Pull and restart the containers. The `php` container migrates on start.
3. `php artisan queue:restart` so workers load the new code after their
   current job. (Restarting the `queue` container does the same.)

Write migrations that work with both the old and the new code during the
switch: add a nullable column first, start using it in a later release,
remove old columns last.

## Data you must back up

- **The database.** For example a nightly
  `pg_dump -Fc lebanon_tourism > backup.dump`, copied off the server, plus a
  restore test every now and then. A backup you have never restored is a hope,
  not a backup. Managed Postgres services do this for you.
- **The `uploads` volume** (tour images, review photos). In larger setups use
  an object store (S3-compatible) instead: Laravel's `Storage` facade makes it
  a configuration change.
- **`APP_KEY`.** Losing it logs everyone out and breaks every signed link.
  Changing it on purpose (if it leaked) has the same effect, which is fine.

## Go-live checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] Unique `APP_KEY`, kept in a secret store
- [ ] Strong, unique database password; the database is not reachable from the internet
- [ ] HTTPS everywhere, `SESSION_SECURE_COOKIE=true`
- [ ] `SANCTUM_STATEFUL_DOMAINS` and `FRONTEND_URL` set to the real domain
- [ ] Real SMTP provider configured, with SPF/DKIM on the sending domain
- [ ] Queue worker and scheduler running
- [ ] Card payments: the fake gateway declines everything in production. With
  Stripe (Exercise 9, D26): `PAYMENT_GATEWAY=stripe`, the **live** secret key
  in a secret store, a webhook endpoint `https://<domain>/api/webhooks/stripe`
  registered in the Stripe dashboard for `checkout.session.completed`,
  `checkout.session.expired`, `checkout.session.async_payment_succeeded` and
  `checkout.session.async_payment_failed`, and its signing secret in `STRIPE_WEBHOOK_SECRET`
  (the Stripe CLI is for development only). The Checkout page is a
  navigation, so the CSP needs no change.
- [ ] Backups scheduled and a restore tested
- [ ] First admin created with `php artisan app:create-admin`; no demo accounts
- [ ] Monitoring on `GET /up` and alerts on errors in the logs
- [ ] `composer audit` and `npm audit` clean, and a plan to update dependencies regularly
- [x] A `Content-Security-Policy` header: built into the nginx image
  (`docker/nginx/security-headers.conf`, D22). If you add a third-party
  service (payments, analytics, a CDN, web fonts), extend the policy first
  and check the browser console, or the browser will block it
