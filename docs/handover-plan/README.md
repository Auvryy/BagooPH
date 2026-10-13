# Handover issue and recovery plan

Physical possession can differ from the recorded parcel history when a seller forgets Ready for Pickup or a rider or hub misses a scan. Ordinary actions must keep their existing readiness, assignment, barcode, route and receipt checks. A report describes an observation; it cannot prove or create a handover.

## Existing safeguards and remaining need

Pickup already requires seller readiness, an eligible assigned rider and a matching waybill. Hub intake, manifest loading/receiving and final-mile departure have their own custody checks. Restricted-rider and wrong-hub recovery cover specific recorded sources. They do not provide a general way for involved staff to report an unrecorded handover.

Use a shared **Handover issues** workspace, with the existing seller, rider, logistics and admin shells. Keep each portal's navigation, colours and responsive behaviour. Reported possession and recorded custody must be clearly separate.

## Delivery sequence

| Batch | Suggested branch | Outcome | Prerequisite |
|---|---|---|---|
| H01 | `feat/handover-issue-reporting` | Involved staff report a known parcel, attach private evidence and receive an independent review with retained history. | Existing parcel and authority models |
| H02 | `feat/pickup-handover-recovery` | Reviewed seller/rider discrepancies gain a safe operational hold and an authorized, actual receipt process. | H01 published and its acceptance gate passed |
| H03 | `feat/hub-handover-recovery` | Extend holds and recovery to missed hub/manifest scans while preserving original transport records and Mother Hub routing. | H02 and existing manifest recovery |
| H04 | `feat/handover-api-contract` | Expose the verified reporting/recovery contract to the native rider client and complete cross-role regression checks. | H01–H03 contracts verified |

Read the separate batch documents before implementing. A batch finishing does not authorize starting the next one. User review and publication remain separate from local commits. The Flutter project belongs to its own delivery session.

## Non-negotiable rules

- Retain the original order, parcel, assignment, scan, manifest, actor and server timestamp. Append evidence and decisions; never manufacture a past scan.
- Only currently eligible, involved staff can report. Another seller, unassigned rider or unrelated hub cannot discover a parcel through this feature.
- Company administrators review their own company's reports; platform admins provide oversight. A reporter cannot review their own report.
- A company review does not grant hub scanning permission. An assignment does not prove physical receipt.
- No direct seller-to-buyer or Bayan-to-Bayan shortcut. Preserve at least one Mother Hub and the actual transport route.
- Delivery confirmation, buyer completion, stock, COD collection and cash custody remain separate authorities. An issue report or review changes none of them.
- Evidence stays private, with bounded uploads and authorized downloads. A public tracking page never exposes a report or its documents.
- Retried submissions must return the original result. Changed retries and stale decisions must fail without partial writes.

## Acceptance and stopping points

H01's gate is reporting and independent triage, not complete recovery. A confirmed concern remains open for the later recovery workflow. H01 does not add an operational hold or a button to repair custody. Existing normal actions keep their current guards; staff must not repeat a physical transfer merely to recreate a missing scan.

H02 and H03 require holds to cover every affected web and native mutation, not only disabled buttons. Resolve reports through verified corrective receipts with their actual recording time, before claiming that unrecorded handovers can be safely recovered. H04 requires an explicit integration handoff rather than assuming the mobile application already supports the feature.

Use isolated SQLite tests for the allowed repository checks. Record any unverified PostgreSQL concurrency or device behaviour explicitly. See [H01](01-reporting-and-review.md), [H02](02-pickup-recovery.md), [H03](03-hub-recovery.md) and [H04](04-native-contract.md).
