# System Architecture

This document describes the application structure for a practical BagooPH ecommerce project. A small demonstrable marketplace and road network are sufficient; there is no requirement to design for thousands of users or enterprise infrastructure.

> **Authority:** Supporting architecture only. Use `docs/README.md` for documentation authority, `docs/SYSTEM_FLOW_AND_SPECIFICATIONS.md` for lifecycle rules, and `docs/CORE_FLOW_VALIDATION_AND_EDGE_CASES.md` for safety rules.

---

## 1. Tech Stack Overview

- **Backend:** Laravel handles database queries (Eloquent ORM), authentication, and business logic.
- **Frontend:** React with TypeScript renders interactive user interfaces styled with Tailwind CSS.
- **Bridge (Inertia.js):** Connects Laravel controllers directly to React pages without having to build a separate REST API.
- **Database:** PostgreSQL stores application records. Automated tests use isolated SQLite `:memory:` and never reset the development database.
- **Docker:** Runs PHP, Nginx, and PostgreSQL in isolated background containers.

---

## 2. User Role Access (RBAC)

The five account roles are `buyer`, `seller`, `courier`, `logistics`, and `admin`. Pickup/delivery are courier assignment phases. Logistics Company Admin and Hub Handler are company/facility responsibilities within `logistics`, not additional public roles.

Authentication identifies the user; middleware and backend policies/services must verify account activity, required approval, role, and action scope on every protected root and subdomain request. Login redirects and frontend controls are not authorization. Inspect `routes/web.php` and `routes/auth.php` for actual routes rather than treating this overview as a route inventory.

- Buyer actions are scoped to owned addresses, Shopping Bag, orders, tracking, and receipt confirmation.
- Seller actions require the account's sole eligible shop and its approved root category. Registration creates one shop per seller, enforced by unique shop ownership; there is no seller shop switcher.
- Courier actions require eligible company/hub placement and the authorized pickup or final-mile assignment.
- Logistics actions require the actor's own company and assigned active facility where applicable.
- Platform Admin has review and read oversight; that does not grant routine seller, rider, or handler custody actions. Admin KYC exemption never bypasses active status.

[ADMIN_FLOW.md](ADMIN_FLOW.md) defines approval authority and suspension recovery. Pending/rejected applicants use their own holding/resubmission screen. Suspended actors have only the expressly authorized existing-order/recovery exceptions; they cannot start new work. Current enforcement gaps belong in the roadmap.

## 3. Backend and Persistence Boundaries

Keep the application as the existing Laravel/Inertia monolith. Controllers coordinate validated requests and responses; backend services/models own business decisions. React renders those decisions without supplying authoritative prices, stock, approval, custody, or financial state.

Use transactions and consistent record locks for checkout, review, job claims, scans, and money changes. Commit required evidence with the business event before retryable side effects. Append corrections to approval, custody, and cash history rather than replacing it. Migrations/models describe current tables; this overview does not declare future audit, manifest, notification, or ledger tables implemented.

Ordinary lists, pagination, and basic in-app notifications are sufficient for the core flow. Distributed infrastructure, live GPS, AI dispatch, and external order-notification services are outside the baseline.

---

## 4. Order and Delivery Flow

The detailed authoritative lifecycle is maintained in `docs/SYSTEM_FLOW_AND_SPECIFICATIONS.md` and `docs/SORTING_CENTER_LOGISTICS_FLOW.md`.

```
[Buyer Places Order]
         │
         ▼
[Seller Packs Item & Prints Waybill]
         │
         ▼
[Order Marked "Ready for Pickup"]
         │
         ▼
[Courier Accepts Job (First-Come, First-Served)]
         │
         ▼
[Pickup Rider Scans at Seller]
         │
         ▼
[Origin Bayan Hub -> Mother Hub -> Destination Bayan Hub]
         │
         ▼
[Hub Sorts and Assigns Delivery Rider]
         │
         ▼
[Delivery Rider Delivers -> Buyer Confirms Receipt]
         │
         ▼
[COD Reconciled -> 90% Seller / 10% Platform Product Split]
```

Self-pickup uses the same Origin Bayan Hub -> at least one Mother Hub -> Destination Bayan Hub route, then an authorized counter release and buyer confirmation. All transport is by supported contiguous roads; no sea/air leg or direct Bayan-to-Bayan shortcut is allowed. Shipping/handling accounting remains separate from the product split.
