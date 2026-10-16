# BagooPH Technology Stack

Verified against this web repository on October 16, 2026, starting from `6d2ff6a`.
This is the reference for technology names, versions and responsibilities. Use
[the presentation guide](TECH_STACK_PRESENTATION.md) for a short explanation and
[the architecture](ARCHITECTURE.md) for request, deployment and data boundaries.

## How versions were verified

`composer.json` and `package.json` declare allowed dependency ranges.
`composer.lock` and `package-lock.json` record the resolved package versions.
Container image tags describe the configured runtime, not the exact software
currently running on Azure. A read-only local runtime probe also confirmed PHP 8.4.24, Laravel 13.32.0,
Inertia Laravel 2.0.25 and Sanctum 4.3.3; twelve inspected frontend package
installations matched their lock versions. This review does not inspect the
Azure environment or the separate Flutter repository.

For example, Laravel's declared `^13.17` range resolves to **13.32.0** in the
current Composer lock. Calling this project Laravel 11/12 or React 18/19 is
incorrect. The existing application is Laravel 13 with React 18. Updating this
document does not upgrade either dependency.

## Main web stack

| Technology | Verified version or configuration | Responsibility in BagooPH | Why it fits this project |
|---|---|---|---|
| PHP | Composer requires `^8.3`; Docker uses `php:8.4-fpm` | Runs the server application through PHP-FPM | Matches the Laravel dependency requirements and the existing container environment. |
| Laravel | `13.32.0`, allowed range `^13.17` | Routes, middleware, validation, authentication, business services, Eloquent, migrations, mail and scheduling | Keeps account, stock, parcel and financial rules in one backend shared by all portals and the rider API. |
| React / React DOM | `18.3.1` | Interactive pages and reusable components in `resources/js` | Shared forms, dialogs, layouts and stateful controls support several distinct role interfaces. |
| TypeScript | `5.9.3`, strict mode | Checks frontend types before building JavaScript | Helps catch incompatible page props, action inputs and component changes during development. It does not replace server validation. |
| Inertia.js | React adapter `2.3.27`; Laravel adapter `2.0.25` | Connects Laravel routes/controllers to React page components and props | Provides application-style navigation and form handling while retaining Laravel routing, sessions and authorization. |
| Tailwind CSS | `3.4.19` | Utility classes, responsive layout and the shared theme | Lets portals retain their own consistent appearance using the existing brand and component rules. |
| Vite | `6.4.3` | Development asset server and production asset bundling | Produces the JavaScript/CSS and asset manifest consumed by Laravel. `npm run build` first runs TypeScript checking. |
| PostgreSQL | `postgres:16-alpine` in Compose | Persistent relational application database | Accounts, shops, orders, custody and cash have related records, constraints and transaction requirements. |
| Nginx | `nginx:alpine`; exact release is not pinned | Serves public assets and forwards PHP requests to `app:9000` | Separates HTTP/static-file handling from PHP execution. |
| Docker Compose | `app`, `web` and `db` services | Defines the PHP-FPM, Nginx and PostgreSQL environment and persistent database volume | Makes the local and deployment setup reproducible from the same service definitions. Runtime settings still need environment-specific verification. |
| Node.js / npm | Docker installs the Node 20 package line; CLI patch versions are not pinned | Installs frontend packages and runs TypeScript/Vite | Supplies build tooling. Laravel/PHP-FPM serves the deployed application. |
| Composer | Copied from `composer:latest`; exact CLI version is not pinned | Installs PHP packages from the lock file | Makes the backend dependency set repeatable without manually copying framework code. |
| Azure VM | Deployment target; private machine details are intentionally omitted | Hosts the containerized application | Supports the existing SSH and Docker deployment workflow. The repository does not prove live TLS, firewall, backups or scheduler operation. |

The rationale above explains how the existing choices suit BagooPH. It is not a
benchmark, a claim that these tools are universally best, or a record of the
original developer's historical decision process.

## Supporting packages and application services

| Component | Verified dependency or source | Actual use and boundary |
|---|---|---|
| Laravel Sanctum | `4.3.3`; `RiderAccountService` and `EnsureRiderAccountToken` | Personal-access tokens for the versioned native rider JSON API. Custom middleware also checks current account eligibility, token abilities and credential state. Browser portal authentication uses Laravel sessions. |
| Laravel Socialite | `5.31.0`; `config/services.php` | Optional configured Google sign-in. Provider credentials and successful live sign-in are separate environment checks. |
| Ziggy | `2.6.4`; `@routes` and `resources/js/utils/routeOrigin.ts` | Makes Laravel route names available to React while retaining the current portal origin. |
| Headless UI | `2.2.10` | Existing dialogs, popovers and other interactive controls. Actual keyboard/device usability still requires acceptance checks. |
| Lucide React | `0.475.0` | Interface icons. |
| Leaflet | `1.9.4`; `CourierJobMap.tsx` | Displays selected recorded stops and saved coordinates with external map tiles. It does not supply live rider positions or automated dispatch. |
| QR and barcode generation | `qrcode.react` `4.2.0`; `jsbarcode` `3.12.3` | Renders waybill/tracking codes. A displayed code is not permission to change parcel custody. |
| Three.js | `0.185.1`; `ThreeShoppingBag.tsx` | Existing visual shopping-bag presentation. It does not participate in checkout, routing or financial decisions. |
| Laravel Breeze | `2.4.2`, development dependency | Authentication scaffolding retained in project history/source; current custom auth and approval behavior must be read from the application. |
| Eloquent ORM | Part of Laravel | Maps models and relationships to SQL records. Domain services and database constraints enforce business rules. |
| Laravel HTTP client | `AiTextService` | Optional configured text assistance for buyer support and seller descriptions. Requests require a server-side key; the configured provider/model is not evidence of a successful live integration. |

## Storage, notifications and external boundaries

- **Application data:** PostgreSQL in Compose. Framework database-driver templates
  for other engines do not establish additional deployed databases.
- **Sessions, cache and queue configuration:** `.env.example` selects database
  drivers. Compose has no Redis service. The actual private server environment
  can override configuration and was not inspected here.
- **Files:** `config/filesystems.php` defines private local storage and public
  assets. Identity documents and delivery/payment proof use authorized private
  access. Public product artwork and avatars have a separate public path. An S3
  configuration template does not establish an active object-storage deployment.
- **Notifications:** source transactions retain `notification_deliveries`; delivery
  to owned `notifications` runs after commit and supports scheduled retry.
  These records are not SMS, push notifications, WebSockets or live GPS.
- **Background work:** `routes/console.php` schedules pickup holding, pending
  notifications, expiring rider offers and advertisement-event cleanup. Compose
  does not start a scheduler or queue-worker service. Registered schedules and a
  database queue setting do not prove an operating background process.
- **Email:** the example configuration uses SMTP. Account email/OTP flows need a
  real working transport; a configured hostname or sent-request handler does not
  prove mailbox delivery. Secret-mail protections reject unsafe log transports.
- **Existing network integrations:** address selectors may query a public PSGC
  service with local fallback; rider maps request external map tiles; the font
  stylesheet is externally hosted. Optional sign-in and text-assistant integrations
  need their own configuration. These are existing boundaries, not new services
  introduced by this documentation review.

## Verification tools

| Tool | Verified version/source | What it establishes |
|---|---|---|
| PHPUnit | `12.5.33` | Laravel HTTP, service, validation, ownership, retry and transaction checks. Ordinary repository tests force isolated SQLite `:memory:`. |
| Node test runner | `tests/Frontend` and `tests/Deployment` | Frontend helper/component-harness and deployment-command checks; these are not a real browser session. |
| TypeScript + Vite | `package.json` build command | Type checking and asset compilation. A successful build does not prove a deployed workflow. |
| Laravel Pint | `1.30.5` | PHP formatting checks. |

SQLite is test isolation, not the production database. It cannot prove PostgreSQL
row-lock contention or deployment-specific triggers. The separate disposable
PostgreSQL verifier requires the explicit permission described in
[its guide](../scripts/verify-rider-races/README.md); it never authorizes tests
against the development or Azure database. Current run results and deployment
limits belong in [the roadmap](CORE_FLOW_ROADMAP.md), not a changing version table.

## Source evidence

| Claim | Repository evidence |
|---|---|
| Allowed and locked backend versions | [composer.json](../composer.json), [composer.lock](../composer.lock) |
| Allowed and locked frontend versions | [package.json](../package.json), [package-lock.json](../package-lock.json) |
| Runtime/container definitions | [Dockerfile](../docker/php/Dockerfile), [Compose](../docker-compose.yml), [Nginx](../docker/nginx/default.conf) |
| React, Inertia, TypeScript and styles | [app.tsx](../resources/js/app.tsx), [app.blade.php](../resources/views/app.blade.php), [tsconfig.json](../tsconfig.json), [Tailwind config](../tailwind.config.js), [Vite config](../vite.config.js) |
| HTTP and API entry points | [bootstrap/app.php](../bootstrap/app.php), [web routes](../routes/web.php), [auth routes](../routes/auth.php), [API routes](../routes/api.php) |
| Database and background defaults | [database](../config/database.php), [session](../config/session.php), [cache](../config/cache.php), [queue](../config/queue.php), [example environment](../.env.example), [schedules](../routes/console.php) |
| Data model | [schema guide](SCHEMA.md), [migrations](../database/migrations), [models](../app/Models) |

## Official technical references

These sources support the tool descriptions; the repository evidence above
determines which versions and features BagooPH actually uses.

- [Laravel frontend architecture](https://laravel.com/framework/docs/13.x/frontend)
  explains integrating React with Laravel through Inertia.
- [Inertia v2 introduction](https://inertiajs.com/docs/v2/getting-started)
  describes server-driven single-page applications.
- [React components and state](https://react.dev/learn) and
  [TypeScript handbook](https://www.typescriptlang.org/docs/handbook/intro.html)
  explain the frontend programming model and type checking.
- [Tailwind v3 utilities](https://v3.tailwindcss.com/docs/utility-first) and
  [Vite guide](https://vite.dev/guide/) explain styling and asset tooling.
- [PostgreSQL 16 concurrency](https://www.postgresql.org/docs/16/mvcc-intro.html)
  explains transaction visibility and concurrent access.
- [Docker Compose application model](https://docs.docker.com/compose/intro/compose-application-model/)
  explains services, networks and volumes.
