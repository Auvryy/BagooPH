# Sorting Center and Logistics Flow

> **Status:** Authoritative operational specification.
> This document defines parcel custody from checkout through completion, including normal doorstep delivery, Mother-Hub transfers, failed delivery, hub self-pickup, basic in-app notifications, and COD custody. It intentionally excludes maritime transport, air freight, live GPS fleet optimization, and automated warehouse machinery.

## 1. Scope and Non-Negotiable Rules

BagooPH uses a road-only hub network. Every parcel follows an authenticated chain of custody and passes through at least one Regional Mother Hub.

```text
Seller
  -> Pickup Rider
  -> Origin Bayan Hub
  -> Origin Mother Hub
  -> Destination Mother Hub, only when a different region is required
  -> Destination Bayan Hub
  -> Delivery Rider or Self-Pickup Counter
  -> Buyer
```

If origin and destination use the same Mother Hub, that one Mother Hub receives and sorts the parcel before forwarding it to the destination Bayan Hub. A parcel must never move directly from the seller to the buyer or directly from an origin Bayan Hub to a destination Bayan Hub.

Other rules:

- An order status is the buyer-facing commercial state.
- A delivery status and checkpoint record physical parcel custody in greater detail.
- Every custody change requires an authenticated waybill scan.
- Manual tracking-number entry is a recovery method only and creates the same audit checkpoint as a scan.
- Sorting and rider assignment are separate actions.
- Pickup riders and delivery riders use the same `courier` account role; their current assignment determines the phase.
- One parcel has one seller pickup origin and one waybill. A multi-shop Shopping Bag must be split into one order and parcel per shop.
- Only the buyer may change `DELIVERED` to `COMPLETED`.

## 2. Status Model

### Customer-Facing Order Statuses

```text
PLACED
-> CONFIRMED
-> PREPARING
-> READY_FOR_PICKUP
-> PICKED_UP
-> AT_SORTING_CENTER
-> SORTED
-> ASSIGNED_TO_RIDER
-> OUT_FOR_DELIVERY
-> DELIVERED
-> COMPLETED
```

Failure branch:

```text
OUT_FOR_DELIVERY
-> DELIVERY_FAILED
-> SORTED -> ASSIGNED_TO_RIDER -> OUT_FOR_DELIVERY     (approved retry)
or
-> RETURNED                                            (return completed at seller)
```

### Internal Delivery Checkpoints

These do not create extra customer-facing order statuses:

```text
assigned_pickup
picked_up
arrived_at_origin_hub
in_transit_to_mother_hub
arrived_at_mother_hub
sorted_to_line_haul
in_transit_to_destination_hub
arrived_at_destination_hub
sorted_to_barangay_bin
assigned_to_rider
out_for_delivery
delivered
```

Self-pickup adds `ready_for_hub_pickup` and `customer_collected`. Reverse logistics uses `return_to_sender` internally; the order becomes `RETURNED` only after the seller receives the returned parcel.

Legacy values such as `pending`, `processing`, `shipped`, generic `in_transit`, and `failed` are compatibility aliases only. New code and documentation must use the canonical values.

## 3. Normal Doorstep Delivery

### A. Checkout and Order Creation

1. The buyer selects COD, confirms the delivery address, barangay, contact number, and doorstep delivery.
2. The backend validates service coverage and atomically checks and decrements stock.
3. A multi-shop Shopping Bag is split by shop. Each resulting order receives one delivery record, tracking number, route, and seller pickup origin.
4. The routing engine assigns the origin Bayan Hub, origin Mother Hub, destination Mother Hub when different, destination Bayan Hub, and barangay bin.
5. Order status becomes `PLACED`.
6. The seller receives a persistent in-app new-order notification.

### B. Seller Preparation

1. Seller reviews and accepts the order: `PLACED -> CONFIRMED`.
2. Seller prepares the items: `CONFIRMED -> PREPARING`.
3. Seller prints and attaches the waybill.
4. The waybill contains tracking barcode or QR, order number, seller pickup details, buyer destination, COD amount, delivery type, route facility codes, and handling notes.
5. Seller marks the parcel `READY_FOR_PICKUP`.
6. The parcel appears on the eligible pickup-rider job board.

The seller cannot mark the parcel `PICKED_UP`. Only the assigned pickup rider's scan may do that.

### C. Pickup Rider Handover

1. An approved, active, and available rider claims the pickup atomically.
2. The system removes the job from every other rider's available board.
3. At the seller, the rider scans the waybill and verifies the order number and parcel count.
4. The scan records the pickup rider as custodian and changes the order to `PICKED_UP`.
5. The pickup rider brings the parcel only to the assigned origin Bayan Hub.

### D. Origin Bayan Hub Intake

1. An operator assigned to the origin Bayan Hub scans the parcel inbound.
2. The system verifies the expected facility and current custody.
3. The scan records operator, facility, time, tracking number, and hub custody.
4. The order becomes `AT_SORTING_CENTER`.
5. The pickup assignment ends.
6. The parcel is scanned onto a feeder manifest for its assigned Mother Hub.

### E. Mother-Hub Processing

Every parcel must be received and sorted by a Mother Hub.

1. Origin Mother Hub scans the feeder manifest and each parcel inbound.
2. The parcel is sorted into the correct destination line-haul group.
3. If another regional Mother Hub is required, the parcel is scanned onto a line-haul manifest, received there, and sorted again.
4. The responsible Mother Hub scans the parcel onto a feeder manifest for the destination Bayan Hub.
5. Each outbound scan transfers custody to a manifest; each inbound scan transfers custody to the receiving facility.

Each manifest records its number, sending hub, receiving hub, vehicle when used, dispatcher, receiver, departure time, arrival time, and included waybills. A manifest cannot be closed with unscanned or duplicate parcels.

### F. Destination Bayan Hub and Rider Assignment

1. Destination Bayan Hub scans the parcel inbound.
2. Operator scans or selects the destination barangay bin: order becomes `SORTED`.
3. The system lists only approved, active, available riders assigned to that hub and compatible barangay.
4. Operator assigns exactly one delivery rider: order becomes `ASSIGNED_TO_RIDER`.
5. The assignment appears in that rider's queue.
6. The assigned rider scans the parcel out of the hub: order becomes `OUT_FOR_DELIVERY`.

### G. Buyer Handover and Completion

1. For COD, the rider verifies and collects the exact amount due.
2. Rider records proof of delivery and delivery notes.
3. Successful handover changes the order to `DELIVERED`.
4. Buyer receives an in-app prompt to inspect the order and confirm receipt.
5. Only the buyer's confirmation changes `DELIVERED -> COMPLETED`.
6. Seller settlement becomes eligible only after completion and COD reconciliation.

## 4. Failed Delivery, Retry, and Return-to-Sender

### Failure Recording

Only the assigned delivery rider may record a failed attempt. The rider must select a reason and enter useful notes.

Allowed baseline reasons:

- Customer unreachable.
- Customer unavailable or requested reschedule.
- Incorrect or incomplete address.
- Customer refused the parcel.
- Unsafe access or severe weather.
- COD amount unavailable.

The event increments the attempt count, records time and location, changes the order to `DELIVERY_FAILED`, and instructs the rider to return the parcel to the destination Bayan Hub. A failed parcel must not remain in the rider's active custody after the hub return scan.

### Retry

1. Destination Bayan Hub scans the failed parcel back in.
2. Handler reviews the reason and contacts the buyer when clarification is needed.
3. A retry date is recorded; the parcel returns to the barangay sorting queue.
4. The hub may assign the same or another eligible rider.
5. Retry follows `SORTED -> ASSIGNED_TO_RIDER -> OUT_FOR_DELIVERY`.

The baseline permits three total delivery attempts. Attempts one and two may be retried. The third failure starts return-to-sender.

### Return-to-Sender

1. The system creates a reverse route and internal `return_to_sender` state.
2. Destination Bayan Hub sends the parcel through its Mother Hub route.
3. Origin Mother Hub forwards it to the origin Bayan Hub.
4. Origin Bayan Hub records seller-return staging.
5. Seller or authorized representative receives the parcel through a final scan.
6. Only that final handover changes the customer-facing order to `RETURNED`.

RTS must preserve the original waybill and append reverse checkpoints. It must not erase the outbound history.

## 5. Hub Self-Pickup

Self-pickup follows the same seller, pickup-rider, origin Bayan Hub, Mother Hub, and destination Bayan Hub route. It diverges only after destination-hub intake.

1. Parcel becomes internally `ready_for_hub_pickup` and is placed on a controlled shelf.
2. Buyer receives a persistent notification containing hub details, operating hours, expiry date, and a one-time claim code.
3. Counter handler scans the waybill and enters the claim code.
4. The system verifies delivery type, destination hub, ready status, unexpired code, and claimant identity.
5. For COD, the counter handler records the exact cash received before release.
6. Release consumes the claim code and records `customer_collected`.
7. The order maps to `DELIVERED`; the buyer then confirms receipt to reach `COMPLETED`.

Default holding period is seven calendar days. The buyer receives reminders on days three and six. An uncollected parcel enters the exception queue after expiry and follows return-to-sender unless a hub administrator approves an extension.

## 6. Basic In-App Notifications

Notifications are persistent records with unread/read state and a link to the relevant order, parcel, or task. Email and push delivery are optional enhancements; they do not replace the in-app record.

| Event | Recipient | Required message/action |
|---|---|---|
| Order placed | Seller | Review and accept order |
| Seller confirmed/preparing/ready | Buyer | Updated order milestone |
| Ready for pickup | Eligible pickup riders | Job available on pickup board |
| Pickup claimed | Seller and claiming rider | Rider and pickup details |
| Parcel picked up | Buyer and seller | Tracking link |
| Assigned to delivery rider | Assigned rider | Delivery task in queue |
| Out for delivery | Buyer | Rider and COD amount reminder |
| Delivery failed | Buyer and destination hub | Reason and next action |
| Ready for hub pickup | Buyer | Claim code, hub, and expiry |
| Delivered/collected | Buyer | Confirm receipt |
| Completed | Seller | Settlement eligibility |
| RTS started/returned | Buyer and seller | Return progress and final result |

Job-board and assignment-queue entries count as operational rider notifications. Buyer and seller notifications must also appear in their notification center.

## 7. COD Cash Custody and Settlement

COD cash and order status are related but separate. `DELIVERED` proves parcel handover; it does not prove that cash has been remitted to the platform.

### Doorstep COD

```text
Amount Due
-> Collected by Delivery Rider
-> Remitted to Destination Bayan Hub
-> Reconciled and Remitted to Platform
-> Seller Settlement Eligible
-> Seller Paid
```

### Self-Pickup COD

```text
Amount Due
-> Collected at Destination Bayan Hub Counter
-> Reconciled and Remitted to Platform
-> Seller Settlement Eligible
-> Seller Paid
```

Every cash movement records amount, order, delivery, collector, remitter, receiver, timestamp, reference number, and reconciliation status. Cash records are append-only; corrections use adjustment entries.

Financial rules:

- The order total is the exact COD amount presented to the buyer.
- The platform commission is 10% of the order product subtotal.
- The seller share is 90% of the order product subtotal.
- Shipping and handling are tracked separately and are not included in the 10%/90% product split.
- Seller payout requires order `COMPLETED` and COD reconciled at platform level.
- Rider earnings and logistics shipping revenue are separate from seller proceeds.
- Admin may audit and approve reconciliation but cannot silently rewrite cash history.

## 8. Authorization and Scan Validation

- Seller actions are limited to orders containing that seller's items.
- Pickup jobs are claimable only at `READY_FOR_PICKUP` and while unclaimed.
- Hub operators scan only at facilities assigned to them; logistics company administrators may manage facilities in their own company.
- Every scan must match the delivery's expected next facility and state.
- A final-mile rider must be approved, active, available, assigned to the destination hub, and compatible with the barangay.
- Only the assigned final-mile rider may scan out or submit a delivery result.
- Duplicate scans are idempotent and do not create a second custody change.
- Cancelled, completed, or returned parcels cannot re-enter active dispatch.
- Claim codes are one-time, stored securely, expire, and are never displayed to unauthorized users.

## 9. Implementation Alignment as of September 23, 2026

| Area | Current state | Required follow-up |
|---|---|---|
| Normal doorstep custody | Core path implemented | Add full end-to-end coverage through buyer confirmation |
| Mother-Hub scans | Origin/Mother scan path exists | Complete destination-Mother and manifest close/receive controls |
| Pickup rider claim | Implemented as atomic job claim | Add persistent seller/buyer notifications |
| Destination sort and rider assignment | Core safeguards implemented | Add personnel/capacity management UI |
| Failed delivery | Failure count and terminal trigger partly exist | Build hub return scan, retry scheduling, reverse route, and seller receipt |
| Hub self-pickup | Counter release screen exists | Enforce ready state, hub, one-time claim code, expiry, identity, and COD checks |
| In-app notifications | Rider boards/queues provide operational notice | Add persistent buyer/seller notification storage and UI |
| COD | Delivery currently marks payment paid and creates a simple ledger | Add custody/remittance records and delay seller settlement until completion and reconciliation |
| Multi-shop checkout | One order can currently include multiple shops | Split checkout into one order, parcel, and waybill per shop |

The designed flow is complete enough for the intended road-based e-commerce scope. The implementation is not yet complete until the required follow-ups above are delivered and verified.

## 10. Recommended Implementation Order

1. **Complete normal doorstep delivery:** split multi-shop checkout, enforce handler facility scope, finish destination-Mother transfers and manifests, and add one end-to-end test from checkout through buyer confirmation.
2. **Complete failed delivery and RTS:** destination-hub return scan, retry schedule, reassignment, reverse route, and seller receipt.
3. **Secure hub self-pickup:** ready-state and destination-hub checks, one-time claim code, identity verification, hold expiry, and COD collection.
4. **Add basic persistent notifications:** use the event matrix in Section 6; keep rider boards and queues as their operational task notifications.
5. **Build COD custody and settlement:** rider/counter collection, hub remittance, platform reconciliation, completion gate, and separate seller, platform, logistics, and rider ledgers.

After these five items pass cross-role tests, the core buyer-seller-rider-logistics-admin transaction flow is functionally complete. Rates, advanced fleet analytics, density assistance, and live vehicle tracking remain optional enhancements.
