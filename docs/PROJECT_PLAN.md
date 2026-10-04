# BagooPH Project Plan

BagooPH ("Bag & Go") is a practical ecommerce project for a small, supported Philippine road network. It demonstrates how buyers, sellers, couriers, logistics operators, and Platform Admin work together in a believable transaction. It does not require thousands of users, a nationwide commercial fleet, or enterprise infrastructure.

> **Authority:** Supporting project overview. Use [README.md](README.md) for documentation authority, the normative role/flow contracts for required behavior, and [CORE_FLOW_ROADMAP.md](CORE_FLOW_ROADMAP.md) for delivery phases and current gaps.

## 1. Bounded Project Baseline

The core project covers account review, product discovery, Shopping Bag, validated multi-shop checkout, seller preparation, authenticated road/hub custody, delivery or self-pickup, failure recovery, basic in-app notifications, buyer confirmation, COD reconciliation, and seller settlement. A small set of demo accounts, products, facilities, and route examples is enough to prove these flows.

Use the existing Laravel, React/TypeScript, Inertia, PostgreSQL, and Docker setup. Keep authorization, validation, stock, custody, and money decisions on the server even for a small demonstration. Avoid adding infrastructure or services merely to imitate a large commercial platform.

Delivery is land-only on supported contiguous roads. Boats, ports, RORO, sea crossings, and air freight are excluded. Unsupported destinations must fail route/serviceability validation; they cannot skip the Mother Hub or acquire an invented transport leg.

## 2. Independent Onboarding and Approval

| Role | Review and access |
|---|---|
| Buyer | Identity/account review by Platform Admin before transactional access; public catalogue browsing grants no checkout permission |
| Seller | Platform Admin reviews identity, business requirements, and shop/category scope |
| Courier | Platform Admin reviews identity, license, vehicle, and OR/CR; an approved company separately places the eligible rider at its hub/barangay |
| Logistics | Platform Admin reviews the company application; company and facility responsibilities stay scoped to that network |
| Admin | Controlled existing admin access; no public registration and no inactive/suspended privilege bypass |

Pending/rejected applicants may sign in to their own holding/resubmission screen. Email verification, document upload, company placement, and going on duty cannot substitute for platform approval. Active status and approval are separate gates. Suspension preserves affected orders, custody, and cash through the narrow recovery rules in [ADMIN_FLOW.md](ADMIN_FLOW.md).

Pickup and delivery are phases of `courier`. Logistics Company Admin and Hub Handler are responsibilities within `logistics`; do not introduce extra public roles or conflate a company application with a rider application.

## 3. Core Transaction and Physical Route

```text
Approved buyer checks out
-> One order, parcel, waybill, shipping fee, and route per shop
-> Seller confirms, prepares, and marks ready for pickup
-> Assigned pickup rider scans at seller
-> Origin Bayan Hub
-> At least one Mother Hub
-> Destination Bayan Hub
-> Assigned delivery rider or authorized self-pickup counter
-> Delivered with required evidence and COD collection
-> Buyer confirms receipt: COMPLETED
-> Platform COD reconciliation
-> Seller settlement
```

Different regions may use origin and destination Mother Hubs; the same-region route still passes through one Mother Hub. Custody changes require authenticated scans and the expected company, facility, actor, and source state. Admin oversight cannot perform routine scans or impersonate those actors.

Supported textual addresses and configured facility coverage determine serviceability. Valid saved coordinates may assist address/map display; they do not replace address validation, select an unauthorized hub, or require live GPS, OCR, or route optimization.

The canonical statuses, failure branch, and actor ownership remain in [SYSTEM_FLOW_AND_SPECIFICATIONS.md](SYSTEM_FLOW_AND_SPECIFICATIONS.md). Detailed manifests, retry/return, counter release, notifications, and COD custody follow [SORTING_CENTER_LOGISTICS_FLOW.md](SORTING_CENTER_LOGISTICS_FLOW.md).

## 4. Money and Stock Rules

- Adding to the Shopping Bag reserves no stock. Checkout atomically validates and decrements current stock, applies eligible vouchers, and creates every selected shop order or rolls back the complete submission.
- Authorized seller cancellation before pickup claim/custody restores stock once. No automatic expiry, cancellation, or restocking policy is introduced by this overview.
- Platform commission is 10% of product subtotal; seller share is 90%. Shipping, handling, logistics revenue, and rider earnings stay separate. This plan sets no shipping revenue percentage.
- COD collection, remittance, and platform reconciliation are distinct recorded events. Physical delivery alone cannot mark COD paid or seller proceeds settled.
- Seller settlement requires both buyer `COMPLETED` and platform-level COD reconciliation. Corrections append traceable adjustments and retain the original evidence.

## 5. Recovery and Deferred Work

Failed deliveries return to the expected destination hub before retry assignment. The baseline permits three total attempts and requires reverse-hub custody plus seller receipt for `RETURNED`. Self-pickup requires the documented identity, one-time claim, expiry, facility, state, and COD checks, followed by buyer confirmation.

Suspension is an access restriction with recovery responsibility, not an order cancellation or custody scan. Role changes/deletion cannot discard active orders, cash, settlement, or historical evidence. Approval and suspension acceptance checks are in the admin and validation contracts.

Complete post-delivery refunds, exchanges, and dispute processing remain separate future work. Live GPS, AI dispatch/density prediction, advanced rates/analytics, automated warehouses, and external order-notification services are outside the core baseline. Optional ideas need their own approved scope and cannot delay the required flow or appear as working placeholder actions.

## 6. Delivery and Verification

Follow the existing roadmap phase order: shared safety and approval gates, normal commerce, manifests, delivery exceptions/self-pickup, notifications, COD/admin reconciliation, then cross-role cleanup. This overview adds no new implementation milestone.

Acceptance means the same order and parcel remain consistent across all five roles, including invalid actors, malformed input, stale decisions, retries, rollback, and financial gates. Automated tests use isolated SQLite `:memory:`; they never wipe PostgreSQL. Record implementation evidence and scoped ratings in the roadmap rather than treating this plan as a completion report.
