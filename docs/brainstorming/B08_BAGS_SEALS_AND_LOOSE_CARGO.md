# B08 — Bags, numbered seals and loose cargo

This package covers topic 16 and the cargo portion of topic 27. It uses B01's purchased next hop, B02's named operators, B03's retained custody and B07's explicit handovers. It does not enable automatic sealing, dispatch or receipt, introduce a paid provider, or change the purchased shipping fee.

## Packing identity and first scan

The Mother Bags page has an explicit **Find bag & add parcel** action. The operator selects their actual sending hub and scans the actual waybill. The backend derives the next hub from the purchased route and retained custody. It reuses the open automatic packing lane for that company/source/destination, or creates its first trip and member in one transaction. Inspection alone creates nothing.

Orders and parcels are locked before the company/network and container. The observed lane is rechecked under those locks; a competing scan that changed its membership receives a retry instruction instead of creating a competing bag. A unique automatic-lane key is an additional database safeguard. Successful retries retain their original actor-bound request and container. Changing the parcel/readings under an already recorded request is rejected. SQLite acceptance checks exercise retained uniqueness and replay; they do not establish PostgreSQL race throughput.

When another parcel would exceed a bag's capacity, an untagged automatic lane starts another open trip and retains a capacity-turnover event. The previous bag stays open for the actual operator's numbered seal. A tagged physical sack cannot silently become a second sack: the operator must finish its current packing and select another actual sack. Operators can also prepare an explicit separate bag for the same next hop.

One parcel can have one active bag and one corresponding active manifest member representing the same transport, never a different loose manifest alongside that bag. Packing refuses an already active manifest. Individual manifest loading refuses a packed parcel; sealed bag members load and unload together through their master tag. These assignments do not change hub custody.

## Permanent sack, trip label and disposable seal

These are separate identities:

| Identifier | Meaning |
| --- | --- |
| Optional permanent sack tag | The physical reusable sack, scoped to its logistics company; entered from the actual tag with the operator's acknowledgement |
| `BAG-…` trip reference and QR | This particular route, packed membership and transport history; a new trip gets a new reference |
| Numbered seal | The physical disposable seal used for this sealing; its number cannot be reused within the company |

Historical and untagged trip containers retain an unknown permanent sack identity; none is invented. A tagged sack has one active trip. Opening its seal does not release the sack: every included parcel must actually be received before the physical tag can be used again. The next use must start at the last receiving hub. It cannot teleport back to the original hub. Original references, seals and packing events remain retained when the sack starts its next trip. Separate accounts and an acknowledgement do not prove distinct physical people or hardware identity.

## Handling readings and limits

The existing handling limits become enforceable for newly created packing-policy-1 bags:

| Limit | Boundary |
| --- | --- |
| Individual baggable parcel | At most 15 kg and 60 cm on each outer side |
| Bag total | At most 50 parcels, 25 kg and 300,000 cm³ (0.30 m³) |
| Handling input | Positive weight/dimensions, at most 10,000 per field, at most three decimal places; complete readings and an actual-package acknowledgement |
| Vehicle | Retained included parcel weights cannot exceed the configured vehicle capacity in kg |

For a single purchased packed unit with complete dimensions, the original quote supplies a **purchased declaration**. Later catalogue edits cannot replace it. Multiple item/unit dimensions cannot prove the combined outer box: the operator must enter its weight and all three outside dimensions and acknowledge checking the actual parcel. The saved snapshot identifies the operator and server time. The page distinguishes **Operator reading** from **Purchased declaration**; neither is described as a hardware scale integration. Missing historical readings remain unknown, not zero.

Bag capacity is checked on packing and again on sealing. The seal event retains the member list and handling snapshots. SQL guards reject insertion, identity/snapshot changes or deletion against sealed membership; a documented pre-departure reopen permits corrections while retaining the earlier seal event. Receipt fields advance individually after actual seal opening. Manifest load/seal/departure rechecks cargo weight and actual configured vehicle capacity; bag members count once each, without adding a second copy of their weight for the container.

Volume is the sum of the declared/measured bounding boxes. It does not prove real nesting or physical fit. The operator still checks fit before sealing/loading. No new vehicle dimension or volume rating is invented where the fleet record stores only kg capacity. Existing untyped transport history retains its original contract; mixing unknown historical cargo with typed cargo requires a new, checked draft rather than treating its missing weight as zero.

## Explicit loose cargo

A package over 15 kg or a 60 cm side uses **Loose cargo** in the manifest's individual loading panel. Its actual waybill, handling snapshot, next hop and current holder are checked. A baggable or unmeasured parcel cannot use this choice as a shortcut around sealing. No fictitious bag or seal is generated for loose cargo.

Loose cargo can be removed individually from an undeparted draft with the existing reason/evidence workflow. After departure, each actual waybill must be received separately at the correct hub. Receiving one parcel does not receive the others or complete the manifest. Existing damaged/missing/wrong-hub reporting and supervised recovery remain in force. Separate buyer-return and recovery protocols retain their existing authority; mixed forward/reverse co-loading remains B12.

## Operator pages and rollout

- **Mother Bags:** automatic lane packing, optional physical sack acknowledgement, handling readings, trip/sack/seal distinctions, capacity meters and retained per-parcel provenance.
- **Manifests:** explicit loose-cargo choice, optional outer-package readings, retained vehicle weight summary, cargo treatment per row and individual receipt/removal controls. A sealed bag member cannot be removed through the individual control.
- Failed actions preserve typed values. Synchronous submit guards prevent rapid duplicate posts; confirmed requests rotate their tokens. Changed versions require reviewing the current bag, rather than silently overwriting it.

Apply the additive `2026_11_11_220000_retain_bag_capacity_and_loose_cargo` migration with the normal deployment workflow before using these controls. The page explains an unapplied schema and keeps existing history accessible; the automatic command returns an actionable conflict. No reset or demonstration reseed is required. Retained physical evidence is intentionally not dropped by a destructive rollback.

Implementation/test results and scope ratings belong in [the roadmap](../CORE_FLOW_ROADMAP.md). Actual user acceptance remains unchecked in the [later-review guide](MANUAL_REVIEW_B01_B06.md). PostgreSQL simultaneous requests, real package fit, sack handling and device operation remain distinct from automated SQLite/component checks. B09 connects sorting/dispatch guidance to these primitives without restoring the unsafe B07 shortcuts.
