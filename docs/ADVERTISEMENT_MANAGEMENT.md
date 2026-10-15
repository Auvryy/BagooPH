# Managing Marketplace Advertisements

Platform Admin manages advertisements from **Advertisements** in its existing
console. An advertisement combines reviewed artwork, a selected destination and
a display period. A banner never activates a discount, changes a product price
or places an order.

## Create and publish

1. Choose **New advertisement**, or import supplied artwork as a private draft.
2. Enter the title, accessible image description and optional collection or
   terms summary. Upload JPEG, PNG or WebP artwork, at most **5 MiB
   (5,242,880 bytes)**. Use **1880 × 880** where possible; the minimum is
   **940 × 440**, with the same **47:22** shape within 1%. Each edge must be
   at most 4096 pixels and the image at most 16 megapixels. Invalid files and
   incorrect shapes are rejected rather than silently cropped.
3. Select a product, eligible shop, active category, collection of 1–24 eligible
   products, or offer destination. A saving or offer purpose requires a real
   published voucher. Its products and dates must support the advertisement.
4. Choose one of three main-slide positions or two side-image positions. Enter
   start and end times in **Asia/Manila**. Save the draft; saving does not make
   it public.
5. Preview the saved revision at desktop and mobile widths. Review its actual
   destination, dates and offer terms, including any spending minimum, cap and
   shipping charge that may remain. The optimized image targets 350 KiB or less;
   the editor flags larger renditions for review. Text readability matters.
6. Confirm that every claim in the saved artwork is accurate. Choose
   **Schedule** for a future start, or **Publish now**, which uses server time
   and the saved end time. The image processor cannot verify marketing text.

Title and image description limits are 1–80 and 1–180 characters respectively;
the optional summary is at most 600 characters. Only decoded JPEG, PNG and WebP
content is accepted. Original uploads remain private, and optimized WebP images
use generated paths. PDF, SVG, GIF, video and unreadable files are rejected.

Supplied sale artwork is a candidate, not an active sale. Importing it neither
publishes it nor creates vouchers. Complete and verify its claims before use, or
replace it with accurate artwork. Ordinary seller shop branding keeps its
existing owner controls and approval rules.

## Buyer journey

```mermaid
flowchart LR
    A[Platform Admin saves private draft] --> P[Preview and review claims]
    P --> S[Publish now or schedule]
    S --> V[Check time, artwork and current eligibility]
    V --> H[Compact homepage image grid]
    H --> C[Buyer clicks an image]
    C --> D[Matching product, shop or category]
    C --> O[Collection or linked offer terms]
    O --> B[Browse products or existing voucher collection]
```

Linked offers open their terms page even when a specific product or shop is
selected. The page shows the actual issuer, saving, minimum spend, cap, period
and use limit. Opening an advertisement does not save or apply a voucher, add
anything to the Shopping Bag, reserve stock or bypass approval. Guests and
pending buyers may browse; owned actions and checkout retain their existing
sign-in and approval rules.

The homepage retains the larger left carousel and two smaller supporting images,
with arrows inside the main image. Empty positions disappear. A single main
image has no carousel controls, and partial content remains compact. With no
eligible live advertisements, buyers see normal product browsing.

## Change, pause or end

- Editing creates a retained draft revision. The live revision continues until
  the replacement is published. Publishing an overlapping replacement ends the
  earlier overlapping placement at the replacement start; it does not restart
  that older placement afterward.
- Two advertisements cannot reserve the same position during overlapping
  periods. Adjacent periods may share an endpoint. One advertisement cannot
  occupy two positions during the same period.
- **Pause** hides the placement but keeps its reservation. **Resume** rechecks
  eligibility. **End placement** releases its remaining reservation. An
  unavailable destination also keeps its calendar reservation until ended.
- **Archive advertisement** stops its placements and prevents further editing.
  Its revisions, assets and decision history remain recorded.
- A stale editor keeps entered values, blocks publication and asks for a reload
  and fresh review. Retrying an identical recorded request cannot duplicate a
  decision. Failed storage or database writes preserve the working publication.

Every fresh read checks `starts_at <= now < ends_at`, pause/end state, current
catalogue eligibility, artwork and linked published voucher terms. Expiry does
not depend on a scheduled job. Withdrawn or sold-out targets, suspended shops,
changed offer revisions and exhausted offers can remove a placement earlier.
These responses use private, non-storing cache headers. Already-open pages need
a fresh request to reflect a later change; following an image always rechecks
its saved publication. Old ended links show an unavailable message and browsing
choices instead of redirecting to a new promotion.

Private previews require current Platform Admin access and are excluded from
indexing. Previously public optimized renditions can remain accessible through
their historical image URLs. Private originals and never-started, canceled
placement artwork are not served through the public artwork route.

## Seller requests and platform review

An eligible seller uses **Promotions** in the existing seller portal to save
artwork, a requested period and its own product, shop or published shop-funded
voucher. The same upload and text limits apply. The seller does not choose a
homepage position or publish directly. Ordinary shop logos, banners,
descriptions and contact details remain owner edits in Settings; changing them
does not submit a promotion or remove shop approval.

```mermaid
flowchart LR
    D[Seller saves private draft] --> S[Submit saved version]
    S --> R[Platform Admin reviews exact version]
    R --> F[Request changes or reject with feedback]
    F --> D
    R --> A[Approve version]
    A --> P[Admin selects position and schedule]
    P --> V[Current time and eligibility checks]
    V --> B[Buyer sees image and matching destination]
```

The review records the submitted artwork, destination, dates and the shop's
reviewed name and category. Platform Admin can inspect the current participating
products and actual offer terms, then approve, request changes or reject with a
useful reason. Approval does not create a placement. The seller sees review
state, feedback and actual display state separately. Submission and decision
notifications link to the recipient's authorized portal page; failed delivery
can retry without duplicating or undoing the saved decision.

Changing submitted content creates a new revision. An older pending request is
marked replaced; an earlier approved live revision continues only under its
original conditions. A changed reviewed shop name or category needs a new
submission even when the shop has been approved again. Updated artwork, offer
terms or requested dates cannot silently inherit an old approval.

Admin can place an approved exact revision within its requested period, or
replace an existing placement with a recorded position/schedule decision. The
original placement and its original dates remain in history. Conflicts reject
without losing the previous reservation. Pause, resume, end and archive retain
their existing calendar rules; archiving also resolves pending seller requests.

Sellers may withdraw an owned pending or approved request, or stop their entire
promotion. These actions end its remaining placements and retain past feedback
and approvals. A new draft and review are needed to restart withdrawn content.
A currently approved seller whose shop becomes restricted can still read owned
history and stop its promotion, while new drafts and submissions remain blocked.
Foreign drafts, images, products, offers and publication controls are rejected
server-side. Stale review forms retain the user's note and choices but require
a reload before a decision.

## Recorded performance

Open **Performance** from Advertisements or seller Promotions. Platform Admin
can compare all advertisements; a seller can read only its own shop's results.
Choose an advertisement, published revision or placement and an inclusive
**Asia/Manila** date window of at most 90 days within the last year. Results are
paged by placement and retain the original title, revision, position and period,
including earlier replacements. Open the matching management page to review,
pause or replace content; reporting does not automatically change ranking or
spend money.

**Recorded views** require the selected image to load and stay at least half
visible for one continuous second while the tab is visible. Hidden carousel
slides, failed images, private previews and unrendered response records do not
earn views. **Image clicks** count deliberate activation of the image link,
including keyboard activation, once for the page and revision. Arrows and dots
only navigate the carousel. Rerenders do not create another event for an already
counted revision on that page.

Click-through rate is recorded clicks divided by recorded views. Without views,
the rate is unavailable. A click may occur before the qualifying viewing second,
so the ratio is not a percentage of unique visitors and need not stay below
100%. Zero recorded events and unavailable reporting are distinct states. No
activity is reconstructed for earlier dates, disabled collection, unsupported
visibility observation or delivery failures.

These are modest first-party activity counts, not certified measurements,
attributed sales or billing. Navigation continues when event delivery fails.
Short-lived encrypted event tokens expire after 30 minutes; the server checks
the exact publication, current eligibility and duplicate key again when saving.
The endpoint accepts bounded JSON, retains CSRF protection and limits requests
to 60 per minute per hashed network source. It does not accept client-supplied
totals, identities or arbitrary event fields.

Raw event records contain only the publication, event type, a page/revision
deduplication hash and server time. They do not contain account IDs, email,
legal identity, raw network addresses, browser fingerprints or the event token.
Daily totals are updated atomically with each accepted event. The daily
`advertisements:prune-events` job removes raw records older than seven days;
the anonymous daily totals and separate review/publication history remain.
The existing Laravel scheduler must actually be running, as described in
[the deployment guide](WEB_AUTH_DEPLOYMENT.md); registered jobs alone do not
establish that maintenance is running. A delayed job retains raw records longer
until the next successful run. Operators may run maintenance explicitly:

```sh
./bagoo.sh artisan advertisements:prune-events
```

## Installation and disabling display

After the user publishes and merges the branch, deploy using the existing
repository workflow:

```sh
./bagoo.sh deploy
./bagoo.sh verify
```

Deployment rebuilds PHP with WebP support, builds the frontend, runs the additive
migration and refreshes application caches. Keep database and private-file
backups together. The feature does not require a database reset or reseeding,
and it does not automatically publish supplied artwork. A missing advertisement
migration leaves normal marketplace browsing available and blocks management
with an installation message.

Set `ADVERTISEMENTS_ENABLED=false` in the deployment environment and refresh the
configuration cache to stop public display while retaining records:

```sh
./bagoo.sh artisan config:cache
```

Re-enable the setting and refresh the cache when ready. Current schedules and
eligibility still apply; old ended placements do not restart. Preserve the
tables and private files when disabling. The migration refuses to remove
retained advertisement identities on rollback. The measurement migration also
refuses to drop tables containing recorded totals.

Set `ADVERTISEMENTS_MEASUREMENT_ENABLED=false` and refresh the configuration
cache to stop recording while keeping public display and retained reports.
Disabling public advertisement display also stops new measurement. Missing
measurement tables leave ordinary display and shopping available, while the
performance page shows an unavailable state. Re-enabling collection records
new eligible activity; it does not backfill the disabled period.

Before a release demonstration, prepare accurate artwork for a platform
collection, a shop/product feature and a real published voucher. Check their
matching destinations, a future start, exact expiry, pause and unavailable target.
Also demonstrate seller feedback, resubmission, exact review, publication and
withdrawal. Use separate test data for verification; never manufacture production
traffic or publish unsupported sale claims. Actual browser/device appearance,
PostgreSQL concurrency and the deployed installation need their own release
acceptance; automated SQLite and component checks establish only their recorded
scope.

Paid placements, advertising balances, auctions, sales attribution and automated
marketing-text verification are outside this feature.
Current implementation evidence and verification limits belong in
[the roadmap](CORE_FLOW_ROADMAP.md); the original scope is in
[the advertisement plan](advertisement-plan/README.md).
