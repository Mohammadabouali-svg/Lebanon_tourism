# 01. Getting started

The whole stack runs in Docker, so the setup is the same on Windows and
macOS once Docker is installed: `make setup`. PHP, Composer, Node and
Postgres never need to be installed on your machine.

## What you need

### Windows: WSL 2

- **WSL 2** with a Linux distribution (Ubuntu is the most common; this project
  was built on Fedora).
- **Docker** inside WSL, either:
  - Docker Engine installed in the distribution, with your user in the
    `docker` group (`docker ps` works without `sudo`), or
  - Docker Desktop for Windows with the WSL 2 backend and "WSL integration"
    enabled for your distribution.
- `make` inside WSL (`sudo apt install make` or `sudo dnf install make`).

Always work from a **WSL terminal**, not PowerShell or cmd.

### macOS (Intel or Apple Silicon)

- **A Docker runtime.** Pick one:

  | Runtime | Notes |
  |---|---|
  | [Docker Desktop](https://www.docker.com/products/docker-desktop/) | The official app. Free for personal use and small companies; larger companies need a paid licence. |
  | [OrbStack](https://orbstack.dev) | A lighter, faster drop-in replacement. Noticeably quicker file access for projects like this one. Free for personal use. |
  | [Colima](https://github.com/abiosoft/colima) | Open source, command line only (`brew install colima docker docker-compose`, then `colima start`). |

  All three provide the `docker` and `docker compose` commands this project
  uses. Start the app (or `colima start`) before running any `make` command.
- **The Xcode Command Line Tools**, which provide `make` and `git`:

  ```bash
  xcode-select --install
  ```

Apple Silicon (M1 and later) needs nothing special: every image in
`compose.yaml` is published for both `amd64` and `arm64`, so everything runs
natively, without emulation.

Give Docker at least 4 GB of memory (Docker Desktop: Settings → Resources).

### Check your setup

```bash
make check
```

It reports Docker's version, your system and user id, or tells you what is
missing (most often: Docker is installed but not started).

## Where to keep the project

| System | Recommended | Works, but slower |
|---|---|---|
| Windows | Inside the WSL file system, e.g. `~/code/lebanon-tourism` | On a Windows drive, e.g. `/mnt/c/...` or `/mnt/j/...` (see [performance](#performance-notes)) |
| macOS | Anywhere in your home folder, e.g. `~/code/lebanon-tourism` | Outside `/Users` (an external disk) needs to be shared with Docker first |

On macOS, avoid folders synchronised by iCloud Drive (by default `Desktop`
and `Documents` when "Desktop & Documents Folders" is enabled) or Dropbox.
`vendor/` and `node_modules/` contain tens of thousands of files; syncing
them is slow and can corrupt them. A plain `~/code` folder is ideal.

## First run

```bash
cd ~/code/lebanon-tourism        # or /mnt/j/Work/Lebanon_tourism-tutorial on Windows
make setup
```

What `make setup` does, step by step (read the `Makefile`, it is short):

1. `make check`: Docker is installed and running.
2. `make env`: creates `.env` (Docker settings) from `.env.example`, filling
   in your user and group id and whether Vite must poll for file changes
   (see below). Also copies `backend/.env.example` → `backend/.env`
   (Laravel settings). Existing `.env` files are never overwritten.
3. Builds the PHP image (`docker/php/Dockerfile`, target `dev`).
4. Runs `composer install` and `php artisan key:generate`.
5. Runs `npm ci` for the frontend.
6. Starts the stack. The `php` container runs the migrations on start-up.
7. Seeds the demo data (`backend/database/seeders`).

`make setup` is safe to run again, for example after a first attempt failed
because Docker was not started: existing `.env` files, the application key
and the demo data are kept.

Then open http://localhost:5173 and log in with a demo account from the
[README](../README.md#demo-accounts).

> **Why your user id matters.** Containers run as your own user id (usually
> `1000` on WSL, `501` on macOS) so the files they create in the project
> (logs, `vendor/`, `node_modules/`, uploads) belong to you, not to `root`.
> `make env` reads it with `id -u` / `id -g`. If you change user or machine,
> delete `.env` and run `make env` again.

## The containers

```
docker compose ps
```

| Service    | What it does |
|------------|--------------|
| `php`      | PHP-FPM running Laravel. Runs migrations on start. |
| `queue`    | `php artisan queue:work`: sends e-mails in the background |
| `nginx`    | Web server in front of PHP (port 8080) |
| `frontend` | Vite dev server for React, with hot reload (port 5173) |
| `db`       | Postgres 18. Data lives in the `pgdata` Docker volume |
| `mailpit`  | Catches every e-mail sent. UI on port 8025 |

All ports are bound to `127.0.0.1`: nothing is reachable from your network.

## Daily workflow

```bash
make up            # start
make logs          # watch logs (Ctrl+C to stop watching)
make test          # backend tests
make fresh         # reset the database to the demo data
make down          # stop
```

Editing code: change files in your editor as usual. PHP picks changes up on
the next request; Vite reloads the browser automatically.

Running artisan or composer commands:

```bash
make artisan c="make:migration add_something_to_tours"
make composer c="require some/package"
make npm c="install some-package"
```

Reading e-mails: register a new account in the app, then open Mailpit at
http://localhost:8025 and click the verification link.

### Card payments with Stripe (test mode)

By default card payments use the fake gateway, which approves at once. To
pay through real Stripe Checkout pages (Exercise 9) you need a free Stripe
account; **test mode** never moves real money.

1. In the Stripe dashboard (test mode), copy the **secret key** `sk_test_...`.
   The publishable key `pk_test_...` is not needed: Checkout is hosted by
   Stripe, our pages load no Stripe script.
2. In `backend/.env` (never committed) set:
   ```
   PAYMENT_GATEWAY=stripe
   STRIPE_SECRET=sk_test_...
   ```
3. Start the Stripe CLI container. Stripe cannot reach `localhost`, so it
   receives the webhook events and forwards them to our API:
   ```bash
   docker compose --profile stripe up -d stripe
   docker compose logs stripe      # "Your webhook signing secret is whsec_..."
   ```
4. Put that value in `STRIPE_WEBHOOK_SECRET=whsec_...` in `backend/.env`,
   then `docker compose restart php queue` so both read the new settings.
5. Book a tour as a traveler, choose *Card*, and pay with a
   [test card](https://docs.stripe.com/testing): `4242 4242 4242 4242`
   (approved), `4000 0000 0000 0002` (declined), any future date, any CVC.
   Back on *My bookings* the booking turns *Confirmed* when the webhook
   arrives (`docker compose logs -f stripe` shows each event and our answer).

Abandoned payment: `docker compose exec stripe stripe checkout sessions expire cs_test_...`
sends `checkout.session.expired`, which releases the spots. To go back to
the fake gateway, set `PAYMENT_GATEWAY=fake`, restart `php queue`, and stop
the CLI with `docker compose stop stripe`. How it works:
[05-bookings-and-payments.md](05-bookings-and-payments.md#stripe-checkout-and-its-webhook-exercise-9).

## Editors

- **VS Code**: on Windows, install the "WSL" extension and open the folder
  from the WSL terminal with `code .`. On macOS, open the folder normally.
- **PhpStorm**: open the project folder. On Windows, use a WSL path
  (`\\wsl$\<distro>\home\<you>\code\...`) if the project lives in WSL.

`backend/vendor` and `frontend/node_modules` are on your disk (the containers
write them through the shared folder), so "Go to definition" into Laravel or
React source works without installing PHP or Node locally.

## Performance notes

### Windows: a project on `/mnt/<drive>`

A project on a Windows drive (`/mnt/c`, `/mnt/j`, ...) is reached from Linux
through a network-like bridge. It works, but:

- the first request after a change can take a second or more, because PHP
  checks hundreds of files through that bridge;
- Linux receives no "file changed" events from Windows, so Vite has to poll
  for changes. `make env` detects a `/mnt/...` path and sets
  `VITE_USE_POLLING=true` for you.

For a noticeably faster setup, move the code into the WSL file system:

```bash
mkdir -p ~/code && cp -r /mnt/j/Work/Lebanon_tourism-tutorial ~/code/lebanon-tourism
cd ~/code/lebanon-tourism && rm .env && make setup
```

(Removing the copied `.env` lets `make env` switch polling off.)

### macOS

Docker on macOS runs Linux in a small virtual machine, and your project
folder is shared into it. Sharing is fast enough for this project, but:

- **Docker Desktop**: check that Settings → General → "file sharing
  implementation" is **VirtioFS** (the default on recent versions).
- **OrbStack** generally has the fastest file sharing of the three runtimes.
- The very first request after starting the stack is slower while PHP's
  opcache warms up. That is normal.

File-change events work on macOS, so polling stays off.

## Troubleshooting

| Where | Symptom | Cause and fix |
|---|---|---|
| both | `make check` says Docker is not running | Start Docker Desktop / OrbStack, run `colima start`, or start the Docker service in WSL (`sudo systemctl start docker`). |
| both | `port is already allocated` | Another program uses 5173/8080/8025/54320. Change the port in `.env`, then `make up`. |
| both | 502 Bad Gateway from nginx | The php container is still installing dependencies or running migrations. `docker compose logs php`. |
| both | `419` errors in the browser | The CSRF cookie expired (e.g. after `make fresh`, which clears sessions). Reload the page. |
| both | Changes to `backend/.env` have no effect | A config cache exists. `make artisan c=optimize:clear`. |
| both | Tests fail with "database lebanon_tourism_test does not exist" | The Postgres volume was created before the init script existed. `docker compose down -v` (deletes the data) then `make setup`. |
| both | Seeder says demo data already present | Use `make fresh` to rebuild from scratch. |
| macOS | `make: command not found` or `xcrun: error: invalid active developer path` | Install the Command Line Tools: `xcode-select --install`. |
| macOS | `error getting credentials ... docker-credential-desktop: executable file not found` | Left over from a previous Docker Desktop install. Remove the `"credsStore": "desktop"` line from `~/.docker/config.json`. |
| macOS | `mounts denied: The path ... is not shared from the host` | The project is outside `/Users`. Move it into your home folder, or add its location in Docker Desktop → Settings → Resources → File sharing. |
| macOS | Vite does not reload after you save a file (seen with some Colima setups) | Set `VITE_USE_POLLING=true` in `.env`, then `docker compose up -d frontend`. |
| Windows | `/bin/sh^M: bad interpreter` | A shell script was saved with Windows line endings. `.gitattributes` prevents this for files committed to git; convert the file to LF. |
| Windows | Files created by containers belong to another user | `.env` has the wrong `UID`/`GID`. Delete `.env`, run `make env`, then `docker compose build && make up`. |
