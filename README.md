# Lebanon Tourism

A tour booking platform for Lebanon. Travelers browse and book tours, tour
operators publish them, administrators run the platform.

This is a rewrite, built from scratch, of an earlier student project. It is
meant to be **read** as much as run. The code is commented where a decision
was made, and the [manual in `docs/`](docs/README.md) explains the why behind
the architecture, the security measures and the trade-offs.

| Layer    | Technology                                                   |
|----------|--------------------------------------------------------------|
| API      | Laravel 13 (PHP 8.4), Sanctum session auth                   |
| Database | PostgreSQL 18                                                |
| Frontend | React 19, TypeScript, Vite, Tailwind CSS 4, TanStack Query   |
| Runtime  | Docker Compose (nginx, PHP-FPM, queue worker, Mailpit) on Windows via WSL 2, or on macOS |

## Quick start

Everything runs in Docker: you do **not** need PHP, Composer, Node or
Postgres on your machine. You need Docker, `make` and a terminal.

### 1. Prerequisites

**Windows**: WSL 2 with a Linux distribution (Ubuntu, Fedora, ...) and
Docker inside it (Docker Engine, or Docker Desktop with the WSL backend).
Run every command from a **WSL terminal**, not PowerShell.

**macOS** (Intel or Apple Silicon):

- Docker Desktop, [OrbStack](https://orbstack.dev) or Colima. Start it before
  continuing.
- The Xcode Command Line Tools, which provide `make` and `git`:

```bash
xcode-select --install
```

Details and alternatives: [docs/01-getting-started.md](docs/01-getting-started.md).

### 2. Run it

Open a terminal in the project folder. For example, on Windows:

```bash
cd /mnt/j/Work/Lebanon_tourism-tutorial
```

On macOS, anywhere in your home folder, for example:

```bash
cd ~/code/lebanon-tourism
```

Then, on both systems:

```bash
make setup
```

`make setup` checks that Docker is running, writes `.env` with your user id,
builds the images, installs PHP and npm dependencies, starts everything and
loads demo data. It takes a few minutes the first time.

| What             | Where                               |
|------------------|-------------------------------------|
| The app          | http://localhost:5173               |
| E-mails (Mailpit) | http://localhost:8025              |
| API directly     | http://localhost:8080/api/tours     |
| Postgres         | `localhost:54320`, user/password `lebanon_tourism` / `secret` |

### Demo accounts

Every password is `password`.

| E-mail                              | Role                                 |
|-------------------------------------|--------------------------------------|
| `traveler@lebanon-tourism.test`     | Traveler with $1,500 wallet credit   |
| `traveler2@lebanon-tourism.test`    | Traveler with a few bookings         |
| `unverified@lebanon-tourism.test`   | Traveler who never verified the e-mail |
| `operator@lebanon-tourism.test`     | Approved operator (Cedars Trails)    |
| `operator2@lebanon-tourism.test`    | Approved operator (Phoenician Journeys) |
| `pending@lebanon-tourism.test`      | Operator waiting for approval        |
| `admin@lebanon-tourism.test`        | Administrator                        |

Discount codes: `WELCOME10` (10%), `SUMMER25` ($25 off), `EXPIRED5` (expired, to see the error).

## Everyday commands

```bash
make help        # list every command
make check       # is Docker installed and running?
make up          # start the stack
make down        # stop it (data is kept)
make logs        # follow the logs
make test        # run the backend test suite
make fresh       # wipe the database and reload demo data
make shell       # a shell inside the PHP container
make artisan c="route:list --path=api"
```

## Project layout

```
backend/        Laravel API (app/, routes/api.php, database/, tests/)
frontend/       React app (src/)
docker/         Dockerfiles and nginx / PHP / Postgres configuration
docs/           The manual: decisions, architecture, security, testing, deployment
compose.yaml        Development stack
compose.prod.yaml   Production-like stack (example)
Makefile        Shortcuts for the commands above
```

## Where to start reading

1. [docs/decisions.md](docs/decisions.md): the decisions behind the project
2. [docs/02-architecture.md](docs/02-architecture.md): how a request travels through the code
3. [backend/routes/api.php](backend/routes/api.php): every endpoint and who may call it
4. [backend/app/Services/BookingService.php](backend/app/Services/BookingService.php): the core business logic
5. [docs/06-security.md](docs/06-security.md): what the original project got wrong, and the fix for each issue
