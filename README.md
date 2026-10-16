# BagooPH

BagooPH is a Philippine ecommerce and road-logistics application connecting
buyers, sellers, couriers, logistics staff and Platform Admin. One Laravel
backend owns the commercial, custody and cash rules; React web portals use
Inertia, and the separate rider client connects through a versioned JSON API.

## Start here

- [Verified technology stack and source evidence](docs/TECH_STACK.md)
- [Technology presentation and questions to prepare for](docs/TECH_STACK_PRESENTATION.md)
- [Architecture, request paths and deployment boundaries](docs/ARCHITECTURE.md)
- [Local setup and actual demo accounts](GETTING_STARTED.md)
- [Documentation map and authority](docs/README.md)
- [Current implementation evidence and remaining limits](docs/CORE_FLOW_ROADMAP.md)

## Technology overview

| Responsibility | Technology |
|---|---|
| Backend | Laravel 13, PHP; Docker PHP-FPM 8.4 |
| Web interface | React 18, TypeScript 5, Inertia.js 2 |
| Styling and controls | Tailwind CSS 3, Headless UI, Lucide React, Plus Jakarta Sans |
| Assets | Vite 6, Node.js/npm build tooling |
| Persistent database | PostgreSQL 16 |
| HTTP/container environment | Nginx, Docker Compose |
| Native rider interface | Versioned Laravel JSON API, Sanctum personal-access tokens and current-scope middleware |
| Hosting target | Azure VM; deployment-specific operation requires its own verification |
| Automated checks | PHPUnit with SQLite `:memory:`, Node test runner, TypeScript/Vite and Pint |

Exact resolved versions and supporting libraries are recorded once in
[TECH_STACK.md](docs/TECH_STACK.md), checked against the dependency locks on
October 16, 2026. The repository does not prove which commit/runtime is currently
running on Azure or which Flutter packages are installed in another repository.

## Roles and transaction

The stored account roles are `buyer`, `seller`, `courier`, `logistics` and
`admin`. Pickup and delivery are courier phases. Company administration and hub
handling are responsibilities scoped within logistics. Account roles are fixed.

- Buyers browse approved products, manage their Shopping Bag and permitted
  personal pages, check out when eligible, and confirm receipt of owned orders.
- A seller owns one reviewed shop in its approved master category, manages
  products and inventory, prepares orders, and views recorded seller proceeds.
- Couriers perform authorized pickup or final-mile tasks within their current
  company/hub placement and record required handover evidence.
- Logistics staff record facility intake, sorting, manifests, counter collection,
  recovery and operational cash handovers within their own scope.
- Platform Admin governs account/shop review, restrictions, recorded exceptions,
  COD reconciliation and evidenced seller payment.

```text
Buyer checkout -> Seller preparation -> Pickup Rider -> Origin Bayan Hub
-> at least one Mother Hub -> Destination Bayan Hub
-> Delivery Rider or authorized Self-Pickup Counter -> Buyer
```

Only the buyer confirms commercial completion. Seller settlement waits for both
that confirmation and platform COD reconciliation. The product split is 90%
seller and 10% platform; shipping and promotion funding have separate accounting.
Recorded tracking milestones are not continuous GPS. Post-delivery refunds,
wallet payments and automatic banking transfers are separate scope.

## Setup, deployment and verification

Follow [Getting started](GETTING_STARTED.md) for a fresh local environment. The
seed account password is `Password1234`; use the account table there rather than
old credentials or guessed demo shortcuts. Setup commands that seed/reset demo
data are for an explicitly prepared local environment.

After the user publishes and pulls reviewed changes, the existing deployment
workflow is:

```bash
./bagoo.sh deploy
./bagoo.sh verify
```

See [the deployment guide](docs/WEB_AUTH_DEPLOYMENT.md) for environment, HTTPS,
mail, background schedules and catalogue checks. Deploy builds frontend assets,
applies migrations and warms Laravel caches; it does not reset or seed the
application database. Verification checks the asset manifest and pending
migrations, not every deployed user journey.

## Development and documentation rules

[AGENTS.md](AGENTS.md) is the repository instruction authority. Local staging
and descriptive commits are allowed; only the user pushes. Preserve each
portal's own style, enforce authorization and business rules on the server,
and keep ordinary tests isolated from development/production PostgreSQL.

The planned project delivery window remains October 4 to November 20, 2026.
Plans describe intended work; the dated roadmap records implementation evidence
and its limits. Historical milestones and original source documents are not a
current feature inventory.
