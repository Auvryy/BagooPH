# H03 writer and receipt contract

The reviewed H01 hold remains the authority boundary. Observations and
authorization do not prove a physical transfer. H02 remains a separate local
delivery; H03 can be reviewed on its own branch based on the verified H02 code.
User publication and deployment remain separate actions.

## Held writers

| Writer | Required boundary |
|---|---|
| Seller fulfillment, cancellation and batch work | Retain H02 guards and atomic batch refusal. |
| Rider claims, departure, delivery and failure | Retain shared holds and exact committed replay behavior. |
| Hub intake, sorting, assignment and counter work | Refuse ordinary mutation of the held parcel. |
| Manifest load, remove, seal, reopen, dispatch and close | Check every affected parcel; batch work cannot move or omit a held obligation. |
| Manifest receive | Check the actual candidate; another unheld parcel may be received without clearing this hold. |
| Manifest observation | Remain available without recording custody or resolving unsupported facts. |
| Failed-delivery hub return and reverse transport | Require the actual attempt/route and a supervised receipt; preserve cash and buyer authority. |
| Existing wrong-hub recovery | Permit only the exact linked observation/case under current independent authority; retain its actual intake and required transport legs. |
| Corrective receipt and reviewed manifest closure | Require original source evidence, current authorization, actual handler receipt and truthful complete obligations. |

Locks follow the existing order/parcel/network/manifest pattern. Each command
rechecks current authority, source and optimistic versions within its
transaction. No client flag bypasses a hold. An exact committed retry returns
its own result; a changed retry or competing action cannot add another receipt.

## Supported physical sources

- Missed origin intake: retain the actual pickup source and assigned origin
  Bayan Hub. Receive the physical parcel at that hub without inventing a past
  inbound scan.
- Correct-facility final-mile handover: retain the destination-hub source and
  original rider assignment. Actual supervised hub receipt precedes any new
  assignment or departure; it cannot prove buyer delivery.
- Missed manifest receipt: retain the original member, recorded transport,
  facilities and vehicle/driver. Record the current corrective hub receipt.
- Missed dispatch: retain the prepared manifest, actual original load, source
  hub and known vehicle/driver. A current corrective receipt may prove arrival;
  it cannot manufacture the original dispatch or departure time.
- Missed loading: require the known same-company parcel, actual source-hub
  custody, the existing correct next-leg manifest and its original vehicle and
  driver. Keep an unlisted corrective receipt separate from the original list.
  Never add a retrospective member or guess a transport vehicle.
- Failed-delivery return: retain the actual failed attempt and responsible
  rider. Actual destination-hub return may continue the documented retry or
  reverse route; it does not collect COD or complete an order.
- Existing wrong-hub recovery: bind the original observation and case facts.
  Reuse its intake, retained reverse/forward route and real recovery manifests.
  A new hold is released only at the original correct receiving facility.

Unknown possession, missing original source or an incompatible route remains
open. A Bayan-to-Bayan shortcut, skipped Mother Hub, foreign facility or new
vehicle guessed from an observation cannot become a receipt.

## Authority and actual receipt

An eligible independent company administrator authorizes the known holder and
source. Platform admins retain the existing independent oversight authority,
including when the company administrator reported the concern. Authorization
never grants routine hub scanning permission. The original driver or an eligible
assigned handler may acknowledge
current physical possession. Another eligible handler records the actual
matching-waybill receipt at the correct facility. Company authorization does
not grant scanning permission. Inactive/suspended riders retain only their own
explicit recovery access; an unapproved or closed holder cannot use it.

An acknowledged holder remains responsible while no later actual custody
checkpoint records receipt. New rider work and account closure cannot bypass
that responsibility. Expiry or withdrawal does not invent its physical release.

Authorizations bind commercial/route/source evidence and the current accounts,
facilities, personnel and recorded transport. They expire after 24 hours.
Expiry, withdrawal, lost authority and changed sources retain the hold. A fresh
authorization must use the then-current actual source. Existing linked recovery
may progress only through its original case guards and real receipt chain.

## Original and corrective manifest evidence

Corrective receipts reference the original manifest and member when one exists.
They retain the before-recovery list, expected/received counts and source
evidence. Original load, dispatch, receipt timestamps and unrelated members
stay intact. Unlisted receipts are shown separately, without increasing the
original expected count. Partial recovery cannot imply complete arrival.

A documented corrective receipt can support the corresponding discrepancy;
other unresolved discrepancies remain open. Reviewed administrative closure
requires every original included obligation to have its real normal or
corrective receipt and every discrepancy to have its supporting resolution.
It records closure now, without inventing earlier lifecycle timestamps.

Only actual custody at the correct facility and a compatible next route step
can release the parcel hold. Preserve stock, commercial totals, cash custody,
original checkpoints, return evidence and buyer-only completion. Portal
guidance uses each role's existing shell; public tracking exposes only a
general actual milestone.
