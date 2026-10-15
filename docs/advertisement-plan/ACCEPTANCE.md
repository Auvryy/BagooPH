# Advertisement Acceptance Matrix

Proposed checks, not tests already run. Each batch owns its relevant rows before
handoff. Follow [form validation review](../FORM_VALIDATION_REVIEW.md) and
[core failure rules](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md).

| Area | Accept | Reject or recover | Owner |
|---|---|---|---|
| Authority | Active Platform Admin drafts/schedules a valid platform ad. | Buyer, courier, company admin or inactive actor sends the same privileged request. | AD01 |
| Upload | Decoded JPEG/PNG/WebP at the exact 5 MiB limit and permitted dimensions. | One byte too large, bad ratio, dimension/pixel overflow, renamed video/HTML, PDF, SVG, GIF or corrupt content; retain old artwork and entered values. | AD01 |
| Text and values | Trimmed title/alt text at their documented lower/upper limits. | Empty/whitespace, over-limit, script/control text, unknown enums or supplied privileged status. | AD01 |
| Destination | Selected public eligible product, shop, category, collection or published offer. | Private/foreign/ineligible record, arbitrary external URL or unapproved voucher draft. | AD01/02 |
| Review revision | Publish exactly the content and offer terms reviewed. | Content changed after review, stale admin form or outdated seller decision; retain recoverable input. | AD01/03 |
| Slots | Up to three main and two side positions; adjacent schedules share an endpoint. | Overlapping position reservations, duplicate placement or two writers winning the same slot. | AD01/02 |
| Schedule | Show exactly at start and stop exactly at end in the declared timezone. | Invalid dates, end before/equal start, clock boundary ambiguity or scheduler outage extending display. | AD01/02 |
| Pause and cache | Committed pause/current restriction disappears on the next fresh public read. | A stale/shared cache reveals a paused ad, draft or another buyer's private voucher state. | AD02 |
| Claims | Event dates and discount/shipping wording match real linked terms. | Unsupported 90%/free/first-order promise, expired or exhausted offer, revised terms invalidating approved copy. | AD01/02 |
| Buyer click | Product/shop/category opens its own existing destination; event opens its matching proposed collection. | Generic unrelated feed, unsafe redirect, automatic Bag mutation, voucher claim or checkout. | AD02 |
| Browsing gates | Guest/pending buyer reads public content and sees appropriate next actions. | Advertisement bypasses checkout approval or exposes restricted product/shop details. | AD02 |
| Layout | Compact main/side grid, image-overlay arrows, complete proportions and useful empty/single/partial states. | Viewport-wide oversized fallback, blank boxes, visible caption bars, hidden focus or duplicate carousel links. | AD02 |
| Live changes | Drop an unavailable target; old campaign link explains ended/unavailable content. | Broken image, raw exception, restored unsupported static claim or stale private target data. | AD02 |
| Atomic recovery | File/publication failure preserves the previous working revision and history. | Orphan live pointer, deletion of referenced assets, duplicate retry decisions or partial slot replacement. | AD01/02 |
| Seller scope | Seller submits only its one shop's eligible product/offer and reads its own feedback. | Other shop IDs, root-category escape, forged placement/publication or another seller's history. | AD03 |
| Branding | Ordinary owner logo/banner/description/contact edit retains existing approval. | Branding edit creates a mandatory identity review or grants marketplace placement. | AD03 |
| Notifications | One owned decision notification after a committed action, with retry on delivery failure. | Duplicate message, foreign history link or transaction rollback solely because mail failed. | AD03 |
| Metrics | Loaded/visible selected images and deliberate clicks produce bounded real counts. | Hidden slides, previews, repeated rerenders, malformed/replayed events or arrow clicks inflate counts. | AD04 |
| Reporting scope | Admin sees platform records; seller sees only owned promotion results. | Fake traffic/revenue, division by zero, foreign statistics or identity data in events. | AD04 |
| Release | Additive install, claims-reviewed draft import and disable path retain existing commerce records. | Production reset/reseed, silently activated sample discounts or erased publication history. | Selected release |

Use isolated SQLite `:memory:` for automated database tests. SQLite contention
checks and static markup do not establish PostgreSQL race safety, rendered
appearance or actual device interaction. Record those gaps explicitly and follow
the repository's separate authorization rules for wider verification.
