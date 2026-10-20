# BagooPH

BagooPH is a Philippine ecommerce and parcel-logistics platform connecting buyers,
sellers, riders, logistics companies and Platform Admin. It brings shopping,
order preparation, recorded parcel handovers and cash-on-delivery accounting
into one application, with separate web portals for each responsibility.

This repository contains the Laravel backend, React web application and shared
rider API. The Flutter rider application is maintained in a separate repository.

## What the system does

| Portal | Main responsibilities |
|---|---|
| Buyer | Browse and search approved products, manage a Shopping Bag, use eligible vouchers, place orders, follow recorded delivery milestones and confirm receipt. |
| Seller | Manage one reviewed shop, products and inventory; prepare orders for pickup; manage promotions; and view seller proceeds and settlement records. |
| Rider | Join a permitted logistics company, manage duty, claim eligible pickup work, perform assigned deliveries and record required handover evidence. |
| Logistics | Manage authorized company/hub operations, parcel intake, sorting, manifests, counter collection, recovery and cash handovers. |
| Platform Admin | Review accounts and shops, manage restrictions and campaigns, inspect recorded exceptions, reconcile COD and record evidenced seller payments. |

The marketplace includes saved products, Buy again, product reviews, vouchers
and managed advertising. Product browsing supports bounded hover/focus
prefetching, responsive search and pagination. Current verification and remaining
work are recorded in [the implementation roadmap](docs/CORE_FLOW_ROADMAP.md).

## Technology stack

Versions below come from the committed dependency locks and container definitions.
They describe this repository, rather than a live server's installed runtime.

| Layer | Technology | Purpose |
|---|---|---|
| Backend runtime | PHP 8.4-FPM in Docker | Executes the server application. Composer requires PHP `^8.3`. |
| Backend framework | Laravel 13.32.0 | Routing, validation, authorization, business services, Eloquent ORM, migrations, mail and scheduling. |
| Web interface | React 18.3.1 and TypeScript 5.9.3 | Interactive pages, reusable components and checked frontend types. |
| Web integration | Inertia.js 2: React adapter 2.3.27, Laravel adapter 2.0.25 | Connects Laravel controllers and sessions to React pages and application navigation. |
| Styling | Tailwind CSS 3.4.19 and Plus Jakarta Sans | Responsive layouts and the shared BagooPH visual identity. |
| Interface components | Headless UI 2.2.10 and Lucide React 0.475.0 | Dialogs, menus and interface icons. |
| Frontend build | Vite 6.4.3, Node.js 20 and npm | Type checking, development assets and production JavaScript/CSS bundles. |
| Database | PostgreSQL 16 | Persistent accounts, products, orders, custody, financial and operational records. |
| Authentication | Laravel sessions and Sanctum 4.3.3 | Session-based web access and personal-access tokens for the versioned rider API. |
| Supporting integrations | Socialite 5.31.0 and Ziggy 2.6.4 | Configured Google sign-in and Laravel route helpers for React. |
| Maps and waybills | Leaflet 1.9.4, qrcode.react 4.2.0 and JsBarcode 3.12.3 | Recorded stop maps and QR/barcode rendering. |
| Web server and containers | Nginx and Docker Compose | HTTP/static assets, PHP-FPM and the database as `web`, `app` and `db` services. |
| Dependency management | Composer and npm lock files | Repeatable PHP and JavaScript package installation. |
| Verification | PHPUnit 12.5.33, SQLite `:memory:`, Node test runner and Laravel Pint | Isolated backend checks, frontend/deployment checks and PHP formatting. |
| Hosting target | Azure VM | Runs the containerized application; deployment settings require separate verification. |

See [the verified stack reference](docs/TECH_STACK.md) for dependency evidence,
configuration boundaries and supporting packages. PostgreSQL is the application
database; SQLite is used for isolated tests. Sessions, cache and queues default
to database drivers in `.env.example`.

## Architecture and delivery flow

BagooPH uses a **modular monolith**: one Laravel backend and relational database,
with business services grouped by domain. Controllers coordinate requests;
services, models and database constraints enforce the shared rules.

```text
React browser -> Nginx -> Laravel web routes + Inertia -> Shared services -> PostgreSQL
Flutter rider -> Nginx -> Laravel /api/v1 + Sanctum  -> Shared services -> PostgreSQL
```

Inertia provides navigation and page data inside the web application. It is not
a separately deployed API gateway. The rider client uses REST-style HTTP JSON
endpoints; it does not connect directly to the database. Both clients use the
same server-side ownership, approval, stock, custody and financial rules.

The physical parcel journey is:

```text
Seller -> Pickup Rider -> Origin Bayan Hub -> at least one Mother Hub
-> Destination Bayan Hub -> Delivery Rider or Self-Pickup Counter -> Buyer
```

- Account roles are fixed. Pickup and delivery are phases of the `courier` role;
  company administration and hub handling are scoped logistics responsibilities.
- One seller account owns one reviewed shop within one of the 14 master categories.
- Only the buyer's receipt confirmation advances an order from `DELIVERED` to
  `COMPLETED`.
- Cash seller settlement requires buyer completion and platform COD reconciliation.
  Product sales split into 90% seller share and 10% platform commission;
  shipping and promotion funding are accounted for separately.
- [Bagoo Wallet](docs/BAGOO_WALLET.md) adds admin-approved virtual demo credits
  alongside COD, exact confirmed payments, unpaid expiry and refunds. Virtual
  seller proceeds remain separate from cash settlement. No external deposit,
  withdrawal or payment gateway is provided.
- Tracking shows recorded milestones. Live GPS, automatic bank transfers and
  exchanges are separate scope. Selected buyer returns preserve actual reverse
  custody and original repayment evidence.

Read [the architecture](docs/ARCHITECTURE.md) and
[canonical system flow](docs/SYSTEM_FLOW_AND_SPECIFICATIONS.md) for the contracts.

## Local setup

Install Git and Docker Engine/Desktop with Docker Compose. The application image
supplies PHP, Composer and Node.js; host installations of those tools are optional
when using the Docker helpers.

For a new local clone with no existing `.env` and an empty application database,
run from the repository root:

```bash
cp .env.example .env
./bagoo.sh start
./bagoo.sh composer install
./bagoo.sh npm ci
./bagoo.sh artisan key:generate
./bagoo.sh artisan storage:link
./bagoo.sh migrate
./bagoo.sh seed
./bagoo.sh npm run build
```

Open `http://localhost:8000`, unless `APP_PORT` selects another local port.
Keep environment values private and preserve an existing `.env` when updating.
Email/OTP delivery requires a working mail transport.

[Getting started](GETTING_STARTED.md) includes the full synthetic demo account
table for every role and the seeded password, verified against the seeders.
Seeding is a deliberate demo-data operation, not part of an ordinary deployment.

## Everyday commands

[`bagoo.sh`](bagoo.sh) wraps the existing Docker Compose commands.

| Command | Purpose |
|---|---|
| `./bagoo.sh start` | Start the containers in the background. |
| `./bagoo.sh stop` | Stop/remove containers while retaining the named database volume. |
| `./bagoo.sh logs` | Follow container logs. |
| `./bagoo.sh build` | Rebuild Docker images. |
| `./bagoo.sh npm run build` | Run TypeScript checks and compile frontend assets. |
| `./bagoo.sh artisan <command>` | Run a Laravel console command inside the app container. |
| `./bagoo.sh migrate` | Apply pending database migrations. |
| `./bagoo.sh seed` | Load the configured demo seeders. |
| `./bagoo.sh fresh` | Delete all application tables, recreate the schema and seed demo data. Use only for a deliberate disposable-database reset. |
| `./bagoo.sh deploy` | Install dependencies, build assets, start services, migrate and warm Laravel caches. |
| `./bagoo.sh verify` | Check the compiled asset manifest and pending migrations. |

## Deployment and verification

After reviewed changes have been published and pulled into the server repository:

```bash
./bagoo.sh deploy
./bagoo.sh verify
```

Deployment preserves application data; it does not reset or seed the database.
The verification helper checks the asset manifest and pending migrations.
See [the deployment guide](docs/WEB_AUTH_DEPLOYMENT.md) for HTTPS/session settings,
mail, the Laravel scheduler, background processing and catalogue visibility.
Compose does not start a scheduler or queue worker automatically.

Use [the verification guide](TEST_INFRA.md) for backend tests with isolated SQLite
`:memory:` and a separate configuration-cache path. Frontend and deployment
checks use the existing Node test runner:

```bash
node --test tests/Frontend/*.test.mjs tests/Deployment/*.test.mjs
```

Run checks in a prepared development/test environment. Passing tests and builds
establish their documented scope; live deployment and device behavior have their
own acceptance checks.

## Repository layout

| Path | Contents |
|---|---|
| `app/Http` | Controllers, request validation and middleware. |
| `app/Services`, `app/Models` | Domain rules, persistence and relationships. |
| `routes` | Web, authentication, API and scheduled-command entry points. |
| `resources/js` | React pages, components, layouts, hooks and utilities. |
| `database` | Migrations, factories and demo seeders. |
| `docker`, `docker-compose.yml` | PHP-FPM, Nginx and PostgreSQL environment. |
| `tests` | Backend, frontend and deployment verification. |
| `docs` | System contracts, role guides, delivery plans and implementation evidence. |

## Further reading

- [Documentation map and authority](docs/README.md)
- [Verified technology stack](docs/TECH_STACK.md)
- [Technology presentation and explanations](docs/TECH_STACK_PRESENTATION.md)
- [Architecture and request paths](docs/ARCHITECTURE.md)
- [Local setup and demo accounts](GETTING_STARTED.md)
- [Deployment guide](docs/WEB_AUTH_DEPLOYMENT.md)
- [Testing guide](TEST_INFRA.md)
- [Current implementation evidence and remaining work](docs/CORE_FLOW_ROADMAP.md)
- [Repository development instructions](AGENTS.md)
