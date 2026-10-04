# Courier and Rider Flow

Pickup and delivery riders are phases of the same approved `courier` account. A rider may perform either phase only through a valid assignment. Hub and Mother-Hub custody follows `docs/SORTING_CENTER_LOGISTICS_FLOW.md`. Eligibility, scan validation, concurrency, COD, and recovery rules follow `docs/CORE_FLOW_VALIDATION_AND_EDGE_CASES.md`.

## 1. Registration and Availability

Courier submits identity, license, vehicle, and required ownership/registration documents. Platform Admin KYC approval is required before transactional portal access; pending/rejected applicants use their own review/resubmission holding screen. An approved Logistics Company Admin may accept and place that courier at an eligible company/hub and barangay. This operational placement cannot grant platform approval or reverse suspension. The authority and evidence rules are in [ADMIN_FLOW.md](ADMIN_FLOW.md).

A rider must be active, approved, available, and within eligible company/hub scope to claim or receive work. A rider may hold several pickup assignments in one route batch, subject to the configured active-pickup capacity; each parcel still has exactly one pickup custodian.

Going off duty removes the rider from new pickup and final-mile assignment choices at the assigned hub. It does not abandon any parcel already claimed or assigned: the rider must still complete the relevant pickup, hub handoff, delivery, or approved exception process.

Suspension differs from going off duty: it blocks new work and requires controlled recovery of any parcel or cash already held. The expected hub must record custody recovery before reassignment. Recovery uses a narrowly authorized action; approval, resubmission, or a company placement cannot restore unrestricted access to a suspended courier.

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

The pickup rider may claim multiple eligible jobs from the assigned Origin Bayan Hub until the active-pickup capacity is reached. An optional pickup note is sent to the seller's delivery-linked Messages thread only after the pickup is confirmed. The rider cannot move a parcel to generic `in_transit`, assign a delivery rider, or mark it out for delivery. Each pickup assignment ends only when its assigned Origin Bayan Hub scans that parcel inbound. The hub controls all facility custody after intake.

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

Attempts one and two may be retried only after the destination hub receives the parcel and approves a schedule. After the third failed attempt, logistics starts return-to-sender through the Mother-Hub network. The rider cannot mark the parcel `RETURNED`.

## 5. COD Remittance and Earnings

The rider's COD ledger distinguishes cash held, cash remitted, discrepancies, and reconciled transactions. Rider delivery earnings are separate from COD cash and from the seller's product share.

## 6. Required Rider Notifications

- Available pickup job board.
- Successful pickup claim details.
- Final-mile assignment queue.
- Assignment cancellation or reassignment.
- Failed-parcel return instruction.
- COD remittance due and reconciliation result.

## 7. Implementation Status

Current courier gaps and their approved delivery phase are tracked only in `docs/CORE_FLOW_ROADMAP.md`.
