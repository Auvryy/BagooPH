# B07 — Individual scans and actual custody handovers

This package implements source topics 14 and 20 and the handover part of topic 25. It builds on the accepted Q1/B01 purchased route, B02 named workers, B03 immutable custody, B04–B05 original money and B06 acceptance/cancellation. It does not activate B08–B09 bulk dispatch or B17 offline/throughput proposals.

## Selected independent-participant rule

The user selected one named hub operator who may receive, sort, pack, seal and dispatch at a small hub. Moving between work stages inside a hub does not require a second sorter. The pickup rider or vehicle driver is a separate named courier account; an operator cannot acknowledge their own vehicle handover. Existing assignments, active eligibility, receiving facility and company scope remain mandatory. A company owner or platform administrator does not gain ordinary floor authority through oversight alone.

The authenticated receiving actor records the actual handover from the retained source holder. Driver assignment and a receiver's event are not a new driver-signature workflow. Distinct accounts do not establish distinct physical people; there is no impersonation, invented second identity or claim of biometric verification. Existing supervised recovery retains its independent reviewer, holder acknowledgement, expiry and actual receiving requirements.

## One online action at a time

The web and native scanners use the same inspect/confirm envelope. Inspection identifies the waybill and displays the next permitted action; it never receives, sorts, assigns or dispatches that parcel automatically. Confirmation supplies the server's action and expected status. Barcode, notes, current user, facility scope, source custody and order state are revalidated inside the existing transaction.

| Boundary | Required source and result |
|---|---|
| Pickup claim and pickup | Original seller custody for new purchases; current eligible assigned rider. An unassigned mutable field cannot override retained hub/courier custody. Historical first scans may retain an unknown prior holder instead of inventing a seller receipt. |
| Origin intake | Recorded pickup-rider custody, picked-up commercial state, expected origin hub and separate receiving operator. Actual receipt ends the pickup obligation and preserves its original earning terms. |
| Local destination readiness | The original identical-child route records a derived local destination checkpoint from that same origin intake. It is not a second physical receipt or a new Mother-Hub leg. |
| Hub sorting and rider assignment | Actual custody at the selected hub; a status or assignment alone cannot establish receipt. |
| Manifest departure/arrival | Retained loaded membership, correct frozen next leg, appropriate vehicle, a separate named courier driver, seal requirements and the existing per-parcel receipt. |
| Delivery outcome | The assigned rider must hold the parcel; original payment, recipient and proof requirements remain separate and mandatory. |
| Cancellation request | A live B06 request holds preparation and pickup. A physical scan cannot erase that review or change purchased cancellation terms. |
| Missing/wrong/stale custody | Explain the conflict and use the existing authorized recovery workflow; never rewrite old events or backdate a replacement scan. |

Duplicate authorized confirmation returns its original evidence without another receipt or earning. A competing receiver cannot appropriate another actor's receipt. Changed assignment, expected status, hub or retained source cannot manufacture movement. Failed evidence writes roll back order, parcel, checkpoint and related money effects. The existing order-then-parcel lock order is preserved, including current courier participants in eligibility locks.

## Conflicting shortcuts repaired

The earlier web continuous flag could turn `mode=inspect` into manifest receipt, claimed intact seal opening, sorting and rider assignment. A bag-only confirmation could mark unseen members received. The old fast-dispatch route generated packing, seal and load evidence from selected database IDs and could use an arbitrary configured driver. Those shortcuts contradicted the authoritative actual-scan contract, the B02 native scanner and B07's explicit single-handover gate.

The web scanner now follows the native explicit-confirmation boundary. Automatic progression flags and client-selected actor/custody fields are rejected. The legacy progression service cannot be enabled. Bag lookup stays read-only and links to the existing bag-receiving page: verify the actual numbered seal there, then scan individual waybills through the arriving manifest. The old fast-dispatch route returns a conflict and manifest destination without writes; scanner buttons and hardware dispatch commands open the existing manifest preparation workflow. Their labels describe preparation rather than a completed departure or generated gate pass.

This reuses existing bag/manifest services instead of building a second transport writer. B08/B09 may add a verified efficient workflow, and B17 may add measured batch/offline behavior, provided they retain actual membership, seal observations, participant authority and replay evidence. Historical movement records remain untouched. The scanner labels its synthetic handling profile as an estimate and its local sizing tool as a calculator; neither is represented as a hardware measurement or a change to the B05 purchased shipping quote.

## Route-dependent scan evidence

There is no universal four/six/eight-scan minimum or maximum. Required actions depend on the stored route, actual bag membership, collection method, exceptions and retries.

| Stored forward route | Required physical actions that determine the work |
|---|---|
| One identical child | Pickup waybill; origin intake with derived local destination readiness; local sorting/staging; assigned rider departure and recipient/POD, or secure counter collection. No Mother transport. |
| Origin child → shared Mother → destination child | Pickup/intake plus two actual transport legs. Each leg retains scanned membership/master-tag load, numbered seal/departure and verified opening with individual receipt. Mother sorting remains distinct. |
| Origin child → origin Mother → destination Mother → destination child | Same requirements across three actual legs, with both Mother Hubs retained. |
| Failed/returned/recovered parcel | Its recorded reverse/recovery route adds supported actions; it cannot reuse an outward receipt as new physical evidence. |

The scanner's expandable **Scan evidence** displays the stored route-hub count, latest original checkpoint reference, named actor, event and server time. Counts refer to canonical checkpoint records: submitted waybill evidence, departure records inheriting their original load, and derived local records are separate. Supplemental aliases and prepared labels are excluded. These counters are not physical touch counts and do not replace bag/manifest history. Repeated inspect/confirm does not increase them merely because another HTTP request occurred.

## B01–B07 continuity gate

`BrainstormContinuityTest` exercises six real checkout-to-destination-sort journeys: local/shared-Mother/cross-Mother, each with COD and paid Wallet. Every journey verifies policy/version/price snapshots, pre-acceptance seller privacy, a cancellation request holding preparation, a saved decline allowing it to resume, actual rider pickup, native operator login/inspection/intake/retry and all required downstream transport legs. Later tariff edits cannot reprice that purchase; routing cannot skip a parent hub; a declined cancellation cannot produce a refund or collect COD.

B02 invitation/activation/revocation, B03 retention/recovery, B04 tariff/allocation, B05 checkout replay and B06 cancellation/privacy also retain their focused regression gates. Actual results and remaining limitations are recorded in [the roadmap](../CORE_FLOW_ROADMAP.md). Remaining B00/B01 policy decisions and B04 successor refund execution are not silently declared complete by these connected paths.

No new provider, paid API, mobile source edit or B07 schema migration is introduced. B06's additive cancellation tables are still required for policy-2 manual review. Deploy the compatible backend/web build together; older native clients may ignore the new optional scan-evidence/bag-navigation fields. Device scanning, physical independence, PostgreSQL concurrency and throughput remain separate acceptance checks. User review is deferred until the requested later milestone, with progress recorded in [the review checklist](MANUAL_REVIEW_B01_B06.md).
