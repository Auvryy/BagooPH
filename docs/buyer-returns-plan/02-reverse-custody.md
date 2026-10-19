# R02: Buyer return through the original hub network

Follow the [shared contract](README.md). A new reverse parcel is linked to the
post-delivery case; the original delivery, successful proof, order status and COD
collection remain unchanged. Existing failed-delivery reverse parcels retain
their own authority and behavior.

## Actual route and handovers

1. After approval the buyer presents the labelled return at the original
   destination Bayan Hub. Its currently assigned authorized handler scans the
   return reference, verifies the case and quantities, records condition/evidence
   and accepts physical custody before the drop-off deadline.
2. Freeze the reverse of the evidenced original hub sequence. It includes the
   destination Mother Hub and origin Mother Hub when distinct, and ends at the
   original origin Bayan Hub. Preserve positional hops when the endpoint Bayan
   IDs coincide; do not deduplicate away the Mother Hub or invent a shortcut.
3. The company prepares the next exact leg with an eligible same-company source,
   destination, fleet and driver. Reuse manifest/fleet authority and retain
   membership, sealed list, departure and each receiving scan. A source handler
   cannot manufacture the destination's receipt.
4. An assigned receiving handler confirms the actual expected return parcel on
   the dispatched manifest. No next departure is allowed until that receipt;
   stale/duplicate/wrong-hub/foreign references retain current custody and give
   useful recovery guidance. Close a leg only after its actual required receipts.
5. The origin handler stages the fully received parcel for its original seller.
   The authenticated owning seller collects it and records receipt/condition.
   A discrepancy retains the receipt and enters review rather than silently
   granting or denying repayment. Seller receipt alone is not proof of money.

This follows the existing origin-hub staging/seller-collection pattern. A new
rider account role, employment flow or Flutter change is unnecessary for it.
Drivers remain the existing eligible fleet couriers, with their ordinary busy
and assignment checks.

Each buyer-return parcel keeps one retained manifest per exact route leg. It
uses the shared transport header, fleet and driver authority with a separate
immutable return-route link and return membership; it does not masquerade as a
new outbound `Delivery`. The measured intake weight must fit the actual vehicle.
The ordinary scan workstation can identify its barcode and link to the case,
but that lookup does not substitute for intake or manifest receipt.

## Source and capacity integrity

Keep a separate return barcode and item/unit list. An original forward waybill
must not mutate a delivered parcel when scanned as a buyer return. Existing hub
workstations should direct the handler to the real return action and expected
facility; merely recognizing a barcode cannot invent custody.

Keep original route/source evidence and actual accepted weight/condition with
versioned events. The selected fleet must have capacity and current eligibility;
its driver/vehicle cannot depart conflicting work simultaneously. Freeze leg
source/destination and retained membership before dispatch. Account/company/hub
restrictions and reassignment are rechecked rather than hidden by cached options.

An unavailable facility or unverified custody remains an explicit retained
exception. No admin normal-status override, delete-and-recreate route, invented
old scan or unrecorded handover may clear it. Recovery must refer to actual holder,
original departure and an authorized receiving confirmation. Safe retries use the
original command and cannot duplicate membership, custody or receipt evidence.

## Checks and handoff

Exercise a genuine completed forward flow, approved buyer request, destination
intake, every reverse manifest hop and authenticated seller receipt. Cover same-
and different-Mother-Hub routes, endpoint ID reuse, wrong role/company/facility,
inactive assignment, unsupported/direct legs, weight/capacity, early seller
receipt, altered membership, conflicting/replayed commands, condition disputes
and failed journal/receipt writes. Preserve original forward and cash snapshots.

Expose current custody, retained timestamps and the next real step in buyer,
seller and logistics pages. Use actual source data and private evidence links;
no automatic refund, fake in-transit status or guessed delivery date is implied.
R03 still gates all money and case closure.
