# Shortcuts for the commands you will type every day.
# Run them from a terminal in the project root, e.g. `make up`:
#   - Windows: a WSL terminal (Ubuntu, Fedora, ...), not PowerShell
#   - macOS:   Terminal or iTerm
# Every target is a thin wrapper around a docker compose command, so you can
# always read the recipe below to see what actually happens.
# The recipes stick to POSIX shell and the tools both systems ship with
# (BSD tools on macOS, GNU tools on Linux), so they behave the same on both.

DC  = docker compose
PHP = $(DC) exec php
ART = $(PHP) php artisan

# Vite must poll for file changes when the project sits on a Windows drive
# mounted in WSL (/mnt/c, /mnt/j, ...): Linux gets no change events from there.
# Everywhere else (macOS, the WSL file system, Linux) events work and polling
# would only waste CPU.
POLLING := $(if $(filter /mnt/%,$(CURDIR)),true,false)

.DEFAULT_GOAL := help
.PHONY: help check env setup up down restart logs ps shell artisan composer migrate fresh seed \
        test test-filter pint npm frontend-shell build-prod

help: ## List the available commands
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

check: ## Check that Docker is installed and running
	@command -v docker >/dev/null 2>&1 || { echo "docker not found. macOS: install Docker Desktop or OrbStack. Windows: install Docker inside WSL (see docs/01-getting-started.md)."; exit 1; }
	@docker info >/dev/null 2>&1 || { echo "Docker is installed but not running. macOS: open Docker Desktop / OrbStack (or run 'colima start'). WSL: start the Docker service."; exit 1; }
	@docker compose version >/dev/null 2>&1 || { echo "Docker Compose v2 ('docker compose') is missing. Update Docker."; exit 1; }
	@echo "OK: $$(docker --version | cut -d, -f1), compose $$(docker compose version --short), $$(uname -s) $$(uname -m), user $$(id -u):$$(id -g), polling=$(POLLING)"

env: ## Create .env for Docker with your user id and the right polling setting
	@if [ -f .env ]; then echo ".env already exists, leaving it alone."; else \
		sed -e "s/^UID=.*/UID=$$(id -u)/" -e "s/^GID=.*/GID=$$(id -g)/" -e "s/^VITE_USE_POLLING=.*/VITE_USE_POLLING=$(POLLING)/" \
			.env.example > .env && echo "Created .env (UID=$$(id -u), GID=$$(id -g), VITE_USE_POLLING=$(POLLING))."; fi

setup: check env ## First-time setup: env files, images, dependencies, database with demo data
	@[ -f backend/.env ] || cp backend/.env.example backend/.env
	$(DC) build
	$(DC) run --rm --no-deps --entrypoint composer php install --no-interaction
	@# Only on the first run: a new key would log everyone out and break signed links.
	@grep -q '^APP_KEY=base64:' backend/.env || $(DC) run --rm --no-deps --entrypoint php php artisan key:generate
	$(DC) run --rm --no-deps frontend npm ci
	$(DC) up -d
	@echo "Waiting for the php container to finish its migrations..."
	@until $(ART) migrate:status >/dev/null 2>&1; do sleep 2; done
	$(ART) db:seed --force
	@echo ""
	@echo "Done. Open http://localhost:$$(grep '^FRONTEND_PORT=' .env | cut -d= -f2) (app) and http://localhost:$$(grep '^MAILPIT_PORT=' .env | cut -d= -f2) (e-mails)."

up: ## Start the stack in the background
	$(DC) up -d

down: ## Stop the stack (data is kept)
	$(DC) down

restart: ## Restart every container
	$(DC) restart

logs: ## Follow the logs of every container
	$(DC) logs -f --tail=100

ps: ## Show container status
	$(DC) ps

shell: ## Open a shell inside the php container
	$(PHP) bash

artisan: ## Run an artisan command: make artisan c="route:list"
	$(ART) $(c)

composer: ## Run a composer command: make composer c="require foo/bar"
	$(PHP) composer $(c)

migrate: ## Run pending database migrations
	$(ART) migrate

fresh: ## Drop every table, migrate again and reseed the demo data
	$(ART) migrate:fresh --seed

seed: ## Seed the demo data
	$(ART) db:seed

test: ## Run the backend test suite
	$(ART) test

test-filter: ## Run tests whose name matches: make test-filter f=Booking
	$(ART) test --filter=$(f)

pint: ## Format the PHP code with Laravel Pint
	$(PHP) ./vendor/bin/pint

npm: ## Run an npm command in the frontend: make npm c="run build"
	$(DC) exec frontend npm $(c)

frontend-shell: ## Open a shell inside the frontend container
	$(DC) exec frontend bash

build-prod: ## Build the production images
	docker compose -f compose.prod.yaml build
