# BagooPH Project Context

BagooPH is one Laravel backend with React/Inertia web portals and a versioned
native rider JSON API. This is a supporting overview; use
[the documentation map](docs/README.md) to select authoritative contracts.

## Architecture and technology

The current web stack is Laravel 13, PHP, React 18, TypeScript 5, Inertia.js 2,
Tailwind CSS 3, Vite 6 and PostgreSQL 16. Docker Compose defines Nginx, PHP-FPM
and database services. Azure is the deployment target. Exact locked versions,
optional integrations and source evidence belong in [TECH_STACK.md](docs/TECH_STACK.md).
See [ARCHITECTURE.md](docs/ARCHITECTURE.md) for request and deployment diagrams
and [the presentation guide](docs/TECH_STACK_PRESENTATION.md) for explanations.

## Accounts and responsibilities

`UserRole` stores five roles: `buyer`, `seller`, `courier`, `logistics`, `admin`.
Pickup/final-mile are courier phases. Logistics Company Admin and Hub Handler
are company/facility responsibilities within `logistics`.

Authentication, approval, activity, ownership, company placement and duty are
separate checks. Roles remain fixed at account creation. Buyers have permitted
personal browsing/history before approval, while checkout requires current
active approval. The approval authority and restricted-account exceptions are
specified in [ADMIN_FLOW.md](docs/ADMIN_FLOW.md).

## Core flow

```text
Buyer checkout -> Seller confirms/prepares/marks ready -> Pickup Rider
-> Origin Bayan Hub -> at least one Mother Hub -> Destination Bayan Hub
-> Delivery Rider or verified Self-Pickup Counter -> Buyer confirms receipt
```

Buyer confirmation and platform COD reconciliation are independent prerequisites
for evidenced seller settlement. The product share is 90% seller and 10%
platform, with shipping and promotional expenses accounted for separately.
Recorded delivery does not create an automatic bank transfer or fixed rider fee.

The detailed lifecycle and custody sources are
[SYSTEM_FLOW_AND_SPECIFICATIONS.md](docs/SYSTEM_FLOW_AND_SPECIFICATIONS.md) and
[SORTING_CENTER_LOGISTICS_FLOW.md](docs/SORTING_CENTER_LOGISTICS_FLOW.md).
Simulated advance/reset HTTP routes are unavailable; demonstrations use real
role-owned actions and recorded history.

## Implementation map

| Area | Existing source |
|---|---|
| Routes and access | `bootstrap/app.php`, `routes/web.php`, `routes/auth.php`, `routes/api.php`, `app/Http/Middleware` |
| Checkout and commercial lifecycle | `app/Services/Orders` |
| Custody, routing, manifests, counter and recovery | `app/Services/Logistics`, `app/Services/Courier` |
| Cash and seller payment | `app/Services/Finance/CodCashService.php`, `app/Services/Finance/SellerSettlementService.php` |
| Account/shop review and corrections | `app/Services/KycDecisionService.php`, `app/Services/IdentityCorrectionService.php`, `app/Services/ShopReviewService.php` |
| Vouchers and advertisements | `app/Services/Vouchers`, `app/Services/Advertisements` |
| Durable notices | `app/Services/Notifications`, `routes/console.php` |
| UI and model | `resources/js`, `app/Models`, `database/migrations` |

## Evidence and scope

[CORE_FLOW_ROADMAP.md](docs/CORE_FLOW_ROADMAP.md) records actual implementation,
checks, ratings and remaining deployment/device limits. Branch plans describe
intended deliveries and do not prove a feature is released. Post-delivery
refunds, wallet payment, live GPS and automated banking remain separate scopes.

[AGENTS.md](AGENTS.md) supplies repository workflow and safety instructions.
[GETTING_STARTED.md](GETTING_STARTED.md) supplies verified local fixture accounts
and setup. The separate Flutter repository is not modified by web documentation.
