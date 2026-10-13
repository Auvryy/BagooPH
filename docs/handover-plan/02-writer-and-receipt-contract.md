# H02 writer and receipt contract

An independently confirmed report places its parcel on hold. Reporting alone
does not. Expiry or withdrawal of a recovery authorization never releases that
hold: physical custody must be established before ordinary work resumes.

## Supported recovery

For seller/pickup discrepancies, the owning approved seller first confirms any
omitted commercial fulfillment in its canonical sequence. Record these
confirmations at the actual server time, with recovery provenance; do not create
an old packing scan or claim that the parcel is physically staged at the shop.
Keep original checkpoints, allocated stock, purchased-item snapshots and cash.

A different eligible company administrator or platform admin then authorizes a
known pickup holder and the original Origin Bayan Hub. An existing pickup
assignment fixes the holder; an unassigned parcel needs an explicit current
recovery authorization for the evidenced holder, rather than a fabricated old
claim. Unknown holders stay unresolved. The named holder acknowledges the exact
waybill and current physical possession. Any corrective pickup/current-custodian
record uses that actual acknowledgement time. The hold stays active until an
eligible handler at the original hub scans the real parcel received.

For a missed destination-hub departure, preserve the original eligible final-mile
assignment and destination-hub evidence. The same named rider acknowledges
current possession; the parcel must return to the correct destination hub for
its handler's actual receipt. This process does not create a past departure,
doorstep proof, failed attempt, COD collection or buyer receipt. Further movement
uses the normal sorting/assignment rules after recovery.

An inactive or suspended approved holder may acknowledge only their own explicit
authorization through the existing restricted recovery sign-in. This does not
restore portal access, duty, native tokens or ordinary scans. Account, identity,
company, facility, placement, evidence and source changes invalidate a grant;
review fresh facts before reauthorization. Closed or unapproved holders cannot
use this exception.

## Writers that must respect the hold

Check the hold after acquiring the existing order/parcel locks and before any
new business write. Whole batch commands roll back if an affected member is
held. Existing committed request results remain evidence, rather than new work.

| Writer | Held actions |
|---|---|
| `OrderLifecycleService` | Seller fulfillment, bulk readiness, cancellation/stock restoration, buyer completion |
| `CourierOperationsService` | Pickup claims shared by web and native |
| `OrderStateMachineService` | Ordinary pickup, intake, sorting, assignment, departure and delivery outcomes |
| `DeliveryRecoveryService` | Failure, hub return, retry approval and retry departure |
| `DeliveryReturnService` | Reverse route/staging and seller receipt |
| `PickupClaimService` | Staging, claim issuance, counter release and reverse departure; expiry/reminders retain their original calendar |
| `LogisticsManifestService` | Loading/removal, sealing/reopening, dispatch, receiving, closure and receipt-based correction/resolution |
| `ParcelRecoveryService` | Other physical recovery authorizations, scans, transfer and completion |
| `RestrictedCustodyRecoveryService` | Competing legacy authorizations and receipts |

Read-only tracking, evidence review, discrepancy observations, notifications and
existing cash liability remain available under their own authority. The hold
must appear in current seller/rider/hub action guidance and native action flags;
disabled buttons never substitute for service checks.

## Authorization and evidence gate

Keep immutable grants and append-only issue events. Bind grants to the current
parcel/route/custody and holder/network authority, exact waybill, original issue,
independent authorizer and fixed expiry. Accept identical request retries once;
reject changed retries, stale pages and competing grants or receipts without
partial updates. Missing/altered private evidence blocks recovery.

Release the hold and close the issue in the same transaction as the final actual
hub receipt and corrective checkpoint. Preserve original transport records and
the Mother Hub route. There is no manifest repair, bulk historical rewrite,
native recovery endpoint or Flutter implementation in H02; those remain H03/H04.
