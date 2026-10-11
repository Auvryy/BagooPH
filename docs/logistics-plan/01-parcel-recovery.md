# Unexpected and wrong-hub parcel recovery

Selected branch: `feat/logistics-parcel-recovery`.

Read the [documentation map](../README.md), [physical custody contract](../SORTING_CENTER_LOGISTICS_FLOW.md), and [validation contract](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md). Current implementation evidence belongs in [the roadmap](../CORE_FLOW_ROADMAP.md).

## Outcome and scope

A receiving handler reports an extra or wrong-hub waybill on an arriving manifest. The owning company administrator reviews the original parcel and authorizes a recovery case. An assigned handler scans the actual parcel into the observed facility. Recovery manifests carry it to the original expected hub, where an actual receiving scan restores its original journey. The hub worklist shows responsibility, blocking conditions, permitted next steps and retained history.

Only a recognized same-company parcel with an original dispatched manifest, frozen membership and retained transport custody can be linked. Unknown waybills and unrelated or unsupported custody remain unresolved. A transcription correction retains its existing separate meaning. This is a physical recovery workflow, not a free-form route or status editor.

## Custody and authority

- Authorizing a case or reporting an observation does not transfer custody. Only currently assigned, eligible handlers record physical scans; company administration alone is insufficient.
- Freeze the original observation, manifest member, route, source checkpoint, observed hub and expected receiving hub. Permit one active recovery per parcel and retain every original record.
- Compute recovery legs on the server. Never create a Bayan-to-Bayan leg; use the original leg's Mother Hub when both endpoints are Bayan Hubs. Do not change the checkout route or count an unexpected facility as a required normal-route receipt.
- Reuse the draft, load, seal, dispatch, receive and close manifest workflow, including scoped vehicles and responsible drivers. Keep one active manifest membership for each parcel.
- Preserve the commercial order state, stock and money during recovery. The final original-hub receipt restores only the original manifest's supported inbound state. Buyer completion and COD reconciliation remain separate.
- A completed recovery provides linked receipt evidence for company discrepancy resolution and admin oversight. A manager note cannot replace scans.
- Check current ownership, approval, activity, facility scope, route and retained source state inside transactions. Identical retries retain their original result; changed or stale requests reject without partial writes.

## Interface

Use the existing logistics layout, navigation, Plus Jakarta Sans, Bagoo accent and form treatments. Provide an owned, paginated worklist, a case detail page, clear blockers and actual custody/history. Link recovery from manifest discrepancies and operational attention counts. Admin oversight may read its authorized evidence; it cannot perform routine scans. Public tracking presents only safe recorded recovery milestones, without private names, internal reasons or foreign records.

## Acceptance and stopping point

Verify actual extra-at-correct-hub and wrong-hub recovery; Mother-Hub and Bayan-Hub recovery legs; original receipt, closure and continued normal delivery; and reverse-manifest recovery. Reject unknown/foreign waybills, terminal or incompatible source states, wrong roles/facilities, unapproved or restricted resources, stale versions, changed retries and duplicate active recovery. Prove rollback on evidence failure and retention against model and database history mutation. Run focused SQLite `:memory:` tests, affected custody/oversight/tracking regressions, frontend checks and the production build.

Record migrations, deployment needs, scoped before/after assessment and verification limits in the roadmap. Stop for user review and publication after this batch. Broader restricted-resource recovery, rates, refunds, Flutter changes and general logistics redesign remain separate.
