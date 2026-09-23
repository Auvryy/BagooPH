# System Architecture

This document describes how the platform's backend, frontend, and database connect together.

---

## 1. Tech Stack Overview

- **Backend:** Laravel handles database queries (Eloquent ORM), authentication, and business logic.
- **Frontend:** React with TypeScript renders interactive user interfaces styled with Tailwind CSS.
- **Bridge (Inertia.js):** Connects Laravel controllers directly to React pages without having to build a separate REST API.
- **Database:** PostgreSQL 16 stores all user accounts, products, orders, and delivery records.
- **Docker:** Runs PHP, Nginx, and PostgreSQL in isolated background containers.

---

## 2. User Role Access (RBAC)

When a user logs in, `RoleMiddleware` checks their role and routes them to their portal:

- **Buyer:** Allowed on Marketplace (`/`), Cart (`/cart`), Checkout (`/checkout`), and Orders (`/my-orders`).
- **Seller:** Allowed on Seller Center (`/seller/*`).
- **Courier:** Allowed on Courier Delivery Board (`/courier/*`).
- **Admin:** Has full oversight across all portals and Admin Control Center (`/admin/*`).

*Note: If an account status is `pending_approval`, access to portals is locked until Admin approval.*

---

## 3. Order & Delivery Flow

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
