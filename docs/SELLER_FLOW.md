# Seller Module Flow

This document defines seller actions. Parcel custody and settlement follow `docs/SORTING_CENTER_LOGISTICS_FLOW.md`. Input, ownership, lifecycle, cancellation, concurrency, and recovery rules follow `docs/CORE_FLOW_VALIDATION_AND_EDGE_CASES.md`.

## 1. Registration and Store Management

Seller submits identity and business requirements. Platform Admin approval is required before transactional portal access. Pending/rejected applicants use their own review/resubmission holding screen. The account and selected shop must both be eligible; each shop keeps its approved root category and manages products, stock, variants, prices, images, and vouchers within that scope. An approved account or switching shops does not approve another shop automatically.

Approval, rejection, suspension, and reactivation follow [ADMIN_FLOW.md](ADMIN_FLOW.md). Suspending the seller or selected shop blocks new listings/orders in that scope and sends unfulfilled orders to an admin exception queue. It cannot silently cancel orders, restore stock, erase history, or bypass custody. Reactivation re-checks account and shop eligibility; document approval alone cannot clear an independent suspension.

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

## 4. Returns and Settlement

For return-to-sender, the seller sees reverse-hub checkpoints and receives the parcel through a final authenticated scan. Only that scan makes the order `RETURNED`.

Product settlement is not released merely because a rider marks the parcel delivered. The order must be `COMPLETED`, and COD must be reconciled at platform level. The product split is 90% seller and 10% platform; shipping and handling remain separate.

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
