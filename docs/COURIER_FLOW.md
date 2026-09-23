# Courier and Rider Flow

Pickup and delivery riders are phases of the same approved `courier` account. A rider may perform either phase only through a valid assignment. Hub and Mother-Hub custody follows `docs/SORTING_CENTER_LOGISTICS_FLOW.md`.

## 1. Registration and Availability

Courier submits identity, license, vehicle, and required ownership/registration documents. Approval is required before portal access. A rider must be active, approved, and available to claim or receive work.

## 2. Pickup-Rider Phase

```text
Seller marks READY_FOR_PICKUP
-> Eligible job appears on available board
-> Rider claims atomically
-> Travel to seller
-> Verify parcel and scan waybill
-> PICKED_UP and custody recorded
-> Deliver only to assigned Origin Bayan Hub
-> Hub scans inbound
-> Pickup assignment complete
```

The pickup rider cannot move the parcel to generic `in_transit`, assign a delivery rider, or mark it out for delivery. The hub controls all facility custody after intake.

## 3. Delivery-Rider Phase

```text
Destination Bayan Hub sorts parcel
-> Hub assigns eligible barangay rider
-> Assignment appears in rider queue
-> Assigned rider scans parcel out
-> OUT_FOR_DELIVERY
-> Deliver and collect exact COD amount
-> Record proof and result
-> DELIVERED or DELIVERY_FAILED
```

Only the assigned final-mile rider can scan out or submit the result. Successful delivery records COD as held by that rider until remittance; it does not mean the money has reached the platform.

## 4. Failed Attempt

Rider selects a valid failure reason, adds notes, and returns the parcel to the destination Bayan Hub. Hub inbound scan ends rider custody. The hub decides retry scheduling and reassignment. A rider cannot repeatedly reschedule a parcel independently.

After the third failed attempt, logistics starts return-to-sender through the Mother-Hub network.

## 5. COD Remittance and Earnings

The rider's COD ledger distinguishes cash held, cash remitted, discrepancies, and reconciled transactions. Rider delivery earnings are separate from COD cash and from the seller's product share.

## 6. Required Rider Notifications

- Available pickup job board.
- Successful pickup claim details.
- Final-mile assignment queue.
- Assignment cancellation or reassignment.
- Failed-parcel return instruction.
- COD remittance due and reconciliation result.

## 7. Remaining Courier Work

- Require proof fields appropriate to successful and failed delivery.
- Build failed-parcel return-to-hub scan and retry assignment flow.
- Add COD remittance records instead of a calculated on-hand total only.
- Separate pickup tasks, final-mile tasks, and completed earnings clearly.

