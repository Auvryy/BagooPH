# B03: shared shipment, custody and privacy contract

## Purpose and authority

This contract defines the boundaries later brainstorming packages must reuse. It covers topics 5, 11–14, 17, 20, 25 and 27 under [the build order](BUILD_ORDER.md). The selected [version-two contract](../new-docs-era/README.md), [validation rules](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md), [logistics flow](../SORTING_CENTER_LOGISTICS_FLOW.md) and [Wallet contract](../BAGOO_WALLET.md) retain authority. B03 does not approve every proposal in those source topics.

Build on Q1's purchased route and B02's named, activated workers. Keep existing database names and API responses. This is a contract and compatibility checkpoint; later transport, finance and cancellation packages implement their own missing consumers. Verification and delivery status belong in the roadmap.

B00's wider policy inventory and B01's remaining topology questions are still open. B03 uses the already accepted Q1 company hierarchy and does not decide a new central hub, delivery promise, tariff, liability, automatic penalty or timeout. Later work affected by those open choices must resolve them before changing behavior.

## 1. Shipment identity

| Record | Existing identity | Meaning and boundary |
|---|---|---|
| Forward parcel | `Delivery.id`, unique `tracking_number`, original `order_id` | One shop's purchased parcel. An order number is not a universal parcel identifier. |
| Failed-delivery RTS | Original delivery plus its retained return route/events | Reverse physical journey of an undelivered parcel. Preserve its forward history; do not create a completed-order buyer claim. |
| Approved buyer return | `BuyerReturnCase.id/reference`, `BuyerReturnParcel.id/barcode` (`BRT-…`), approval event | Separate physical parcel tied to original approved units and original delivery. Its numeric ID may equal an ID in another table without identifying the same object. |
| Mother Bag | `MotherBag` reference, retained membership and seal | A container and its trip evidence. A bag code is not a child waybill or proof of every member's arrival. |
| Transport manifest | `LogisticsManifest.id/reference`, direction, version, member records | One retained company/source/destination/vehicle/driver leg. Drafting, loading and departure are different facts. |
| Physical checkpoint | `DeliveryCheckpoint.record_reference` (`CP-…`) | Immutable forward/RTS evidence with actor, provenance, source and target. |
| Manifest / return event | `LogisticsManifestEvent.reference` (`MFE-…`), `BuyerReturnEvent.reference` (`BRE-…`) | Immutable evidence in its owning aggregate; references must not be substituted across event families. |

Any future combined queue or load must carry a **shipment kind plus its owning ID/reference**, never a bare numeric `parcel_id` without context. This is an internal contract, not a new HTTP payload field. Existing forward and return member tables remain distinct. Prefixes help choose a lookup; successful lookup still requires server authorization. Do not rename existing fields or create a second shipment table merely to unify presentation.

An approved return request authorizes a later physical return; it does not prove drop-off. Printing any label does not prove pickup. A return QR must not replace the original forward waybill, and vice versa.

## 2. Purchased route and versions

The forward plan is the original `Delivery.route_hub_ids` and snapshotted hub/company fields under `Order.logistics_flow_version`. Identical child hubs use Q1's single-hub bypass. Different children keep their required recorded Mother Hub(s). Neither an inspection, new address, newly configured parent nor return decision recalculates the purchased path.

Buyer returns retain `BuyerReturnCase.route_snapshot` and `BuyerReturnParcel.route_snapshot/hub_ids`, tied to the approval event. RTS uses `DeliveryReturnService`'s original reverse route. The next physical receipt must match that journey's stored next hop; identical province names are insufficient. A wrong-hub observation enters recovery, never an ordinary shortcut.

Distinguish these versions:

- `logistics_flow_version`: purchased business contract, including historical version-one completion.
- Manifest `version` and return case/parcel `version`: current mutable command state, not a new route revision.
- Checkpoint source/target state and return route snapshots: retained evidence of the original path and transition.

There is no universal `route_version` request field today. A future reroute needs a separately authorized immutable revision referencing the original route, reason, approver and effective leg. It must preserve previous observations and must not recalculate historic earnings. Add that contract and compatibility checks before enabling rerouting.

## 3. Event and command fields

| Concern | Existing carrier | Required interpretation |
|---|---|---|
| Authenticated actor | `scanned_by_id` / `actor_id`, retained `actor_role` | Resolve from the current session/token. Never accept a client-selected operator or substitute the owner. |
| Company and station | Owning shipment company, event `hub_id`, current eligible assignment | Revalidate at the write, including restrictions and revoked/inactive assignments. Network visibility alone is not floor authority. |
| Source and recipient | `custody_before/after` or return/manifest `source_state/target_state`, named rider/hub/driver/buyer/seller as applicable | Preserve who held the parcel before and who actually received it. The acting receiver and prior holder are distinct concepts. |
| Evidence | Actual submitted barcode, provenance, event reference, private proof where required | No generated scan, inferred receipt from a label, fabricated actor or client-supplied proof URL. |
| Expected state | Forward `expected_status`; manifest `version`; return `expected_version` | Compare with locked authoritative state. These mechanisms are intentionally different. Do not require a made-up universal version from old clients. |
| Retry identity | Manifest `request_token` + fingerprint/actor; return hashed token + fingerprint/actor; forward service's retained original scan | An identical authorized retry returns original evidence. A changed payload or actor cannot appropriate that event. |
| Occurrence and corrections | Server event time, original/source-event reference and corrective receipt | Never overwrite an original event or backdate a new observation to hide a missed handoff. |

Commands use existing services and transactions. Acquire the relevant order/delivery, eligible network resources and aggregate locks in the service's established order. Revalidate authorization before accepting a replay. Preserve uniqueness constraints and retry the same request identity after a lost response; rescan after a genuine state conflict. A failure writing evidence must roll back state and related money effects, and discard unused private files.

This does not establish a shared offline queue, batch command or globally ordered event stream. B17 must preserve these per-item checks and original request identities when adding replay. SQLite checks cannot prove simultaneous PostgreSQL behavior.

## 4. Physical custody and human handovers

Inspection, routing guidance, assignment, manifest preparation and staging labels are not physical movement. Record real collection, transport departure, receiving scan and recipient handover through their existing writers. Commercial order status, physical custody and cash custody are separate state machines.

`DeliveryCheckpoint::lastCustody()` derives the latest original custody evidence. A supplemental/corrective record does not silently replace it; reviewed recovery must produce its supported receipt. Missing evidence remains unknown, rather than being inferred from a status or GPS dot.

Use separate authenticated participants wherever the existing service requires independent review/acknowledgement. A company manager, platform admin or second account cannot impersonate the holder. Different accounts do not prove different physical people. B03 retains the existing supervised recovery process; emergency delegation remains deferred from B02. B07 must establish any new small-hub handover procedure before implementing it.

Staging means an actually held parcel is waiting at its recorded hub/shelf. It does not create a truck leg, seller receipt or cash refund. Loose/bulky freight must retain individual shipment identity and actual manifest membership; never invent a bag to satisfy a scan screen. Capacity, bag/seal lifecycle and loose-load execution remain B08/B09.

## 5. Transport sharing without shared identity or money

Forward, RTS and approved buyer returns may eventually use compatible physical transport. A shared vehicle does not merge their routes, custody events, item quantities, buyer claims, COD or refund entitlements.

Current buyer-return manifests use `direction=buyer_return`, retained per-case leg routes and separate `BuyerReturnManifestParcel` membership. **B03 does not enable mixed forward/return manifests.** Until B12 supplies that behavior, continue using the existing dedicated manifests; never work around direction guards by putting a return barcode in a forward member row.

Before B12 enables shared loads, require all of the following:

1. Same eligible company and identical next source/destination leg, with compatible capacity, handling and seal requirements. Sharing a final destination is insufficient when next hops differ.
2. Typed members referring to original shipment records; one active transport/container membership per physical shipment. No cross-table numeric-ID assumptions.
3. Departure and receipt outcomes per member, with original actor, expected state and retry evidence. Partial arrival cannot receive missing cargo.
4. Independent forward, RTS and buyer-return histories and original routes. Unloading branches according to each member's stored next hop.
5. No new collection, repayment, seller debit, insurance payment or earnings merely because a parcel loaded, arrived or shared a vehicle.

Worked contract example: configured children A, B and C share Mother Hub M. A forward parcel follows A → M → B. A buyer return reverses its original C → M → A journey into A → M → C. They could share the A → M vehicle leg once B12 implements compatible loading. At M, their next children differ, so their membership and receiving outcomes must branch separately. A COD forward parcel remains uncollected during this linehaul; the return of a Wallet purchase remains a separate refund case. Neither receipt changes the other's payment or order. This is a future compatibility example, not evidence that mixed dispatch is enabled today.

Physical seller receipt can satisfy an existing refund prerequisite; it cannot itself assert an external refund was paid. B04/B10/B13 own original allocations, actual cash/Wallet events and funding. A last scan alone does not establish fault or liability.

## 6. Authorized visibility and waybill lookup

| Viewer/surface | Permitted information and guard |
|---|---|
| Public tracking web/API, including signed-in users | Masked name/phone, coarse city/province and allowlisted milestones only. Never street, landmark, exact pin, private notes/proof, internal actor, claim code, item prices or cash custody. No operational action. |
| Original buyer | Own order and recipient snapshot; own return and its private authorized evidence. Another buyer's numeric ID or QR grants no access. |
| Original seller | Owned shop/order fulfilment and authorized return details. Existing owned-order access and label availability remain; changing disclosure specifically before acceptance belongs to B06. B03 does not claim that proposal already shipped. |
| Assigned rider | Existing authorized job/phase details needed for pickup or delivery, using original snapshots. An open-pool preview does not grant full assigned-job access. Current restrictions and assignment guards apply. |
| Logistics operator | Eligible assigned company/hub station and its operational data. Return identification additionally requires a hub on its recorded route. Inspection grants no transfer authority beyond the next permitted action. |
| Company owner/admin | Existing scoped management/review details and private evidence endpoints. Oversight is not ordinary scan authority or permission to impersonate a worker. |

QR/barcode contents identify a protected record or its existing public tracking URL. Do not embed reusable credentials, claim secrets, plaintext personal details or encrypted address payloads with a shared app decoding key. Authorization stays server-side even when a code was photographed, forwarded or copied from a label.

Use `WaybillScanInputService` for existing ASCII normalization and bounded lookup. Forward alias matching is legacy compatibility and must reject ambiguous orders; public lookup accepts the unique tracking code, not the order number. `BRT-…` resolves a separate return; bag lookup resolves the bag. Reject foreign URL origins and malformed/control-containing codes. A valid native B02 token must still pass current hub assignment and action checks.

Existing `PublicTrackingService` uses an explicit sanitized response. Preserve that allowlist rather than serializing full models into a new tracking endpoint. Private evidence remains behind existing role/ownership checks; a storage path is not a public link. Operational UI permission checks do not replace backend guards.

## 7. Compatibility and future branch ownership

| Package | Reuse this boundary | Decisions still owned there |
|---|---|---|
| B04/B05 | Original identity, route and payment sources | Tariffs, liability, refund allocations and quote/purchased-term acceptance |
| B06 | Owned order and distinct commercial/custody state | Cancellation boundaries and any accepted pre-acceptance disclosure change |
| B07 | Named B02 actor, expected state, retained scan/retry evidence | Full individual handover/recovery acceptance and small-hub operation |
| B08/B09 | Membership identity, staged versus transported custody | Bags, numbered seals, loose freight, capacity, sorting and dispatch |
| B10/B13 | Separate cash/Wallet and physical events | Completion, earnings, reconciliation, repayment and actual funding |
| B11/B12 | Separate approved buyer return versus RTS | Return decisions, reverse custody and compatible shared transport |
| B14–B17 | Original server timestamps, event identity and scoped authority | Clocks, investigations, evidence-based restrictions and batch/offline performance |

Keep existing schema/API names and the B02 scanner inspect/confirm envelope. Additive fields must remain optional to old clients until both server and consumer support them. Any later migration preserves original IDs, route snapshots, references and history; do not backfill imaginary receipts or change old flow versions. Deploy compatible backend support before requiring a mobile update.

Serialize edits to shared routing, custody, manifest, return and ledger services across branches. Each branch identifies its owned files and acceptance evidence. This reduces future conflicts; it cannot guarantee that later independent edits will never conflict.

## 8. Acceptance evidence map

| Contract requirement | Existing executable evidence to run |
|---|---|
| Q1 purchased route/local bypass and B02 activated hub scope retained | `NewEraRoutingRateTest`, `NewEraCheckoutTest`, `NewEraLocalFlowTest`, `LogisticsNativeAccountTest`, `LogisticsNativeCustodyTest` |
| Bag identity, seals and individual member receiving remain separate | `NewEraMotherBagTest` |
| Submitted identity, immutable custody, failed-write rollback and actor-bound retry | `CustodyEvidenceTest`, `ManifestCustodyTest` |
| RTS reverses original route with actual receipt | `ReturnCustodyTest` |
| Buyer-return identity, original order preservation, private actual intake and manifests | `BuyerReturnCustodyTest` |
| Public QR has no privileged access or private data for any role | `PublicTrackingTest` |
| Same-origin URL parsing, malformed code and alias input boundaries | `WaybillScanInputTest`, `CustodyEvidenceTest` |
| B02 native return identification cannot become a forward confirmation or public return disclosure | `ShipmentContractTest` |

These checks support the contract checkpoint, not complete B07–B17 or prove live operational throughput, on-device behavior, physical independence of workers or PostgreSQL races. Record observed results and any failures in the roadmap before marking B03 verified.
