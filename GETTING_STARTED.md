# BagooPH Getting Started

Use this guide for a new **local** clone. Existing Azure data and private
configuration require the [deployment workflow](docs/WEB_AUTH_DEPLOYMENT.md).
The [verified stack](docs/TECH_STACK.md) and [presentation guide](docs/TECH_STACK_PRESENTATION.md)
explain the technologies and their purpose.

## Requirements

Install Git and Docker Engine/Desktop with Docker Compose. The project image
supplies PHP 8.4, Composer and Node.js; Compose supplies Nginx and PostgreSQL 16.
The initial build and package installation need network access. The setup time
depends on the machine and existing image/package caches.

## New local clone

From the repository directory, create the local environment file if it does not
already exist. Do not overwrite an existing private `.env`.

```bash
cp .env.example .env
./bagoo.sh start
./bagoo.sh composer install
./bagoo.sh npm ci
./bagoo.sh artisan key:generate
./bagoo.sh artisan storage:link
```

For a new, empty local database, apply migrations and load the synthetic demo
accounts/network:

```bash
./bagoo.sh migrate
./bagoo.sh seed
./bagoo.sh npm run build
```

Open `http://localhost:8000`, unless your local `APP_PORT` selects another port.
The application container receives `DB_HOST=db` from Compose; the host-side
loopback PostgreSQL port is a separate access path. Credentials and secrets
belong in the private environment file.

`./bagoo.sh fresh` deletes all application tables and recreates demo data. Use
it only when deliberately resetting a disposable local database. Ordinary
updates use migrations, not a reset. No reset was performed to verify this guide.

## Verified seed accounts

These are synthetic fixtures from [DatabaseSeeder](database/seeders/DatabaseSeeder.php),
[SellerDemoSeeder](database/seeders/SellerDemoSeeder.php) and
[LogisticsNetworkSeeder](database/seeders/LogisticsNetworkSeeder.php).
Their seeded password is **`Password1234`**. Previously changed account passwords
and database state are not inferred from these source defaults.

| Account or responsibility | Email | Stored role |
|---|---|---|
| Buyer | `buyer@bagoo.test` | `buyer` |
| Seller | `seller@bagoo.test` | `seller` |
| Pickup rider at Los Banos | `pickup.rider@bagoo.test` | `courier` |
| Final-mile rider at Santa Cruz | `rider@bagoo.test` | `courier` |
| Company administrator | `logistics.admin@bagoo.test` | `logistics` |
| Origin Bayan Hub handler | `losbanos.hub@bagoo.test` | `logistics` |
| Mother Hub handler | `motherhub@bagoo.test` | `logistics` |
| Destination Bayan Hub handler | `logistics@bagoo.test` | `logistics` |
| Platform Admin | `admin@bagoo.test` | `admin` |

Use `/login` on the local application; current role and eligibility determine
the destination. Root-path partner portals and their configured subdomain
variants share server authorization. Do not assume every seeded account has the
same company, assigned facility or operational permission. The demo route uses
Los Banos, a Laguna Mother Hub and Santa Cruz; configured coverage still applies.

## Everyday commands

| Command | Purpose |
|---|---|
| `./bagoo.sh start` | Start the defined services |
| `./bagoo.sh stop` | Stop/remove containers while retaining named volumes |
| `./bagoo.sh logs` | Follow service logs |
| `./bagoo.sh artisan <command>` | Run a Laravel console command in the app container |
| `./bagoo.sh composer <arguments>` | Run Composer in the app container |
| `./bagoo.sh npm run build` | Type-check and compile production assets |
| `./bagoo.sh test` | Run ordinary PHP tests with SQLite memory overrides; see the cache-isolated command in the testing guide |
| `./bagoo.sh deploy` | Build, apply migrations and warm deployment caches |
| `./bagoo.sh verify` | Check the compiled manifest and pending migration state |

`npm run dev` starts a Vite development asset server; exposing it to a host needs
appropriate local networking. It is not the production web server. A completed
`npm run build` supplies assets for the Nginx/PHP-FPM application.

## Background processing

A real deployment needs an actual scheduler invocation every minute or a managed
`schedule:work` process for the jobs registered in `routes/console.php`.
Compose does not start that process automatically. A configured database queue
also does not prove a worker is running. See [deployment guidance](docs/WEB_AUTH_DEPLOYMENT.md)
for these environment checks.

## Where the code lives

| Path | Responsibility |
|---|---|
| `routes/web.php`, `routes/auth.php` | Web, portal and authentication entry points |
| `routes/api.php` | Public tracking and versioned rider JSON entry points |
| `app/Http/Controllers`, `app/Http/Middleware` | HTTP coordination and current access checks |
| `app/Services`, `app/Models` | Business rules, persistence and relationships |
| `database/migrations`, `database/seeders` | Schema changes and synthetic demo fixtures |
| `resources/js/Pages`, `resources/js/Components`, `resources/js/Layouts` | React pages, controls and portal shells |
| `tests` | Isolated backend, frontend-harness and deployment-command verification |

Read [the testing guide](TEST_INFRA.md) before running tests. Current successful
checks and unverified boundaries are recorded in [the roadmap](docs/CORE_FLOW_ROADMAP.md).
