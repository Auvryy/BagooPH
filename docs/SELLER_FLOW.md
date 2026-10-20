# Seller Module Flow

This document defines seller actions. Parcel custody and settlement follow `docs/SORTING_CENTER_LOGISTICS_FLOW.md`. Input, ownership, lifecycle, cancellation, concurrency, and recovery rules follow `docs/CORE_FLOW_VALIDATION_AND_EDGE_CASES.md`.

## 1. Registration and Store Management

One seller account owns one shop. Registration creates that shop together with the account and requires a choice from the 14 master root categories. The seller submits birth date, identity and business requirements; Platform Admin reviews the account and its registered shop together before transactional portal access. Pending/rejected applicants use their own review/resubmission holding screen.

The seller opens their sole shop automatically after approval. There is no extra-shop creation or shop switcher. Products, stock, variants, prices, images and vouchers stay within the approved root category and its active descendants. A reviewed category change requires the existing identity/scope correction process. Legacy shops without recorded approval use the existing shop review path; approval is never inferred from an active flag.

Sellers may organize their own products into optional store categories, separate
from the platform taxonomy. Names contain 1–50 characters and are unique within
the store after case/spacing normalization. Categories support two levels,
manual ordering and visibility; each product may belong to at most three.
Hiding a group hides its public category destination and children, while the
products retain their independent eligibility. Deleting a group previews its
children and distinct affected products, requires the current preview, and
removes memberships without deleting products. Products without any remaining
store category are Uncategorized. These controls never change the shop's
approved master root or a product's platform category.

An approved seller may save a private draft with only a valid title. Supplied
values still need valid formats and limits. Publishing requires complete title,
description, gallery, explicit approved department, price, stock and declared
weight. Optional dimensions must be complete and positive when supplied.
Duplicate opens a separate private draft for editing with a distinct identity;
it does not change the original or reserve stock. Delete rejects a product
with unfinished orders and leaves its listing state unchanged. An explicit
archive/deactivate action can pause new purchases; retained history is never
hard-deleted. Bulk actions validate the owned selection before writing and
report each changed, archived or skipped item.

Sellers edit personal/shop contact numbers, profile photos, shop description, logo and banner directly in settings. These changes preserve the registered shop's approval and catalogue eligibility. Legal identity, birth date, shop name, category, pickup address and private business/identity evidence require the correction request and history beside identity information in merchant account settings. A restricted shop's contact/branding edit cannot reactivate it or open new work. The original sign-in email stays fixed; additional verified contact/recovery addresses use current-password confirmation and emailed OTP verification.

Approval, rejection, suspension, and reactivation follow [ADMIN_FLOW.md](ADMIN_FLOW.md). Suspending the seller or their shop blocks new listings/orders in that scope and sends unfulfilled orders to an admin exception queue. It cannot silently cancel orders, restore stock, erase history, or bypass custody. Reactivation re-checks account and shop eligibility; document approval alone cannot clear an independent suspension.

## 2. Order Fulfillment

```text
New-order notification
-> Review items, buyer delivery details, and stock
-> Accept: PLACED -> CONFIRMED
-> Prepare and pack: CONFIRMED -> PREPARING
-> Print and attach one parcel waybill
-> Mark READY_FOR_PICKUP
-> Wait for pickup rider claim
-> Verify rider and parcel
-> Pickup rider scans the waybill
-> PICKED_UP
-> Track parcel until DELIVERED, COMPLETED, or RETURNED
```

The seller cannot mark an order `PICKED_UP`. The pickup rider's authenticated scan is the custody handover.

Each seller parcel has its own order and waybill. Items from another shop cannot share that parcel.

The seller controls only `PLACED -> CONFIRMED -> PREPARING -> READY_FOR_PICKUP`. Repeated, skipped, or backward transitions are rejected. Seller cancellation is allowed only before pickup and before a rider has claimed custody; after that point, the parcel must follow delivery or return-to-sender.

## 3. Waybill Requirements

- Order and tracking numbers.
- Barcode or QR code.
- Seller pickup name, address, and phone.
- Recipient name, phone, destination, and barangay.
- Delivery type and facility route codes.
- Exact COD amount and handling notes.
- Original declared product weight, summed from retained order-item quantities and unit weights when known. Unknown legacy values stay unrecorded; this is separate from actual hub weighing.

## 4. Returns and Settlement

For return-to-sender, the seller sees reverse-hub checkpoints and receives the parcel through a final authenticated scan. Only that scan makes the order `RETURNED`.

Post-delivery buyer returns use the separate [return contract](buyer-returns-plan/README.md).
The current approved seller reviews the original purchased units and private
evidence within three elapsed days. A rejection retains its exact reason and the
buyer's permitted admin appeal. Approval does not establish physical receipt or
repayment and never rewrites the original completed purchase as a failed delivery.
Unresolved claims hold proceeds; original paid evidence and any later recovery
liability remain separately traceable.

Product settlement is not released merely because a rider marks the parcel delivered. The order must be `COMPLETED`, and COD must be reconciled at platform level. The product split is 90% seller and 10% platform; shipping and handling remain separate.

For [Bagoo Wallet](BAGOO_WALLET.md), the seller cannot confirm or prepare an unpaid
order. Verified paid orders follow the same custody route without cash collection.
After buyer confirmation, a platform admin can record the original 90% seller
share in **Virtual proceeds**, with original discounts, accepted returns and
shipping kept separate. Sellers can read their own credits and history; these
virtual proceeds cannot be spent, withdrawn or represented as a cash payout.

## 5. Required Seller Notifications

- New order requiring review.
- Pickup rider claim and rider details.
- Pickup rider note, when supplied at confirmed pickup, in the delivery-linked Messages thread.
- Successful pickup and tracking link.
- Delivery failure when seller action may become necessary.
- Return-to-sender started and ready for seller receipt.
- Buyer completion and payout eligibility.

## 6. Implementation Status

Current seller gaps and their approved delivery phase are tracked only in `docs/CORE_FLOW_ROADMAP.md`.
