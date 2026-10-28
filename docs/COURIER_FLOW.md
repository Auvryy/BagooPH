# Courier and Rider Flow

Pickup and delivery riders are phases of the same approved `courier` account. A rider may perform either phase only through a valid assignment. Hub and Mother-Hub custody follows `docs/SORTING_CENTER_LOGISTICS_FLOW.md`. Eligibility, scan validation, concurrency, COD, and recovery rules follow `docs/CORE_FLOW_VALIDATION_AND_EDGE_CASES.md`.

## 1. Registration and Availability

A self-registering courier submits identity, license, vehicle, and required ownership/registration documents. Platform Admin KYC approval is required before transactional portal access; pending/rejected applicants use their own review/resubmission holding screen. For a first company association, an active, approved, email-verified adult rider requests a company with open intake, its owning administrator issues an exact assignment offer, and the rider accepts its displayed version. Acceptance and the company/hub/vehicle placement commit together. This operational placement cannot grant platform approval or reverse suspension. The authority and evidence rules are in [ADMIN_FLOW.md](ADMIN_FLOW.md).

Company joining lives in Profile → Assignment. The offer records the eligible facility, optional covered barangay, personal or available fleet vehicle, working arrangement and seven-calendar-day expiry. Offers reserve neither a rider nor a vehicle. Current approval, intake, reviewed identity, placement and resource versions are checked again on acceptance; a disable/reactivate cycle requires a new offer. Ordinary contact/photo edits do not invalidate it. Reading an expired offer changes no history; an explicit expiry command or the next application reconciles its terminal decision. The rider remains off duty until deliberately activating duty.

One rider may have one open company request. Withdrawal, rejection, decline, cancellation and expiry retain the original request and offers; another application creates a new request. Existing company assignments retain their original provenance without invented past consent. Same-company facility/vehicle moves use the existing placement and custody guards. Company transfer and automatic departure are outside this workflow.

A rider must be active, approved, available, and within eligible company/hub scope to claim or receive work. A rider may hold several pickup assignments in one route batch, subject to the configured active-pickup capacity; each parcel still has exactly one pickup custodian.

Going off duty removes the rider from new pickup and final-mile assignment choices at the assigned hub. It does not abandon any parcel already claimed or assigned: the rider must still complete the relevant pickup, hub handoff, delivery, or approved exception process.

Suspension differs from going off duty: it blocks new work and requires controlled recovery of any parcel or cash already held. The expected hub must record custody recovery before reassignment. Recovery uses a narrowly authorized action; approval, resubmission, or a company placement cannot restore unrestricted access to a suspended courier.

An approved logistics company owner may also provision a named rider with a fixed courier role and physical child-hub assignment under [the version-two contract](new-docs-era/README.md). That rider must replace the temporary password, acknowledge the assignment and verify the sign-in email before working. This is distinct from changing an existing account’s role or bypassing suspension.

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
-> DELIVERED and automatic new-order completion, or DELIVERY_FAILED
```

Only the assigned final-mile rider can scan out or submit the result. Successful delivery records COD as held by that rider until remittance; it does not mean the money has reached the platform.

A [Bagoo Wallet](BAGOO_WALLET.md) parcel instead requires its verified original
prepayment. Retain the recipient, waybill, private proof and actual custody
checks, but collect no cash and record no COD receipt. An edited paid label is
insufficient. The native backend shares this rule; Flutter UI adoption is a
separate client change.

## 4. Failed Attempt

Rider selects a valid failure reason, adds notes, and returns the parcel to the destination Bayan Hub. Hub inbound scan ends rider custody. The hub decides retry scheduling and reassignment. A rider cannot repeatedly reschedule a parcel independently.

Attempts one and two may be retried only after the destination hub receives the parcel and approves a schedule. Refusal or the third failed attempt starts the reversed purchased route. Regional returns retain their Mother-Hub legs; a one-child local return proceeds to actual seller receipt at that child hub. The rider cannot mark the parcel `RETURNED`.

## 5. COD Remittance and Earnings

The rider’s shift ledger distinguishes exact collected, remitted and held COD, pending offers and unresolved differences. Duty cannot close until the actual hub acknowledges the counted cash and the rider’s differences are resolved. COD rider earnings become available after that receipt. Prepaid delivery earnings release at valid delivery; dedicated pickup earnings release at actual origin intake. Standard PHP 60 shipping splits PHP 30 company/PHP 30 delivery; dedicated fleet splits PHP 25 company/PHP 25 delivery/PHP 10 pickup. Integer-cent allocations conserve the original shipping pool. These are earned ledger entitlements, separate from actual payouts. Web earnings and native trip/cash JSON read that ledger; historical cash is never treated as invented earnings.

## 6. Required Rider Notifications

- Available pickup job board.
- Successful pickup claim details.
- Final-mile assignment queue.
- Assignment cancellation or reassignment.
- Failed-parcel return instruction.
- COD remittance due and reconciliation result.

## 7. Implementation Status

Current courier gaps and their approved delivery phase are tracked only in `docs/CORE_FLOW_ROADMAP.md`.
