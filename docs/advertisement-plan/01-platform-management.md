# AD01: Platform Advertisement Management

Proposed batch. Build the authoring boundary before exposing managed content to
buyers. Combine with AD02 for the first useful release, while verifying this
batch independently. Follow [the ownership and scope decision](README.md).

## Admin experience

Add Advertisements to the existing Platform Admin console. Provide a list with
draft/review/display state, placement, owner, destination and schedule; a creation
form; a desktop/mobile preview; and a retained decision/history view. Use clear
actions such as Save draft, Schedule, Publish now, Pause and End.

The form selects purpose, title, image, accessible description, destination,
optional related offer, placement position and start/end times. Show the actual
destination preview and the current referenced product/shop/category/offer state.
Saving a draft must not make it public. A preview is authorized and excluded from
search indexing and performance counts.

## Proposed information model

These are proposed record responsibilities, not installed models or tables.
Choose final names and reuse existing audit patterns during implementation.

| Record responsibility | Required information |
|---|---|
| Advertisement identity | Creator, platform or owned-shop scope, purpose, current revision and archive state. |
| Retained content revision | Image reference/hash, plain title and alt text, typed destination, related published offer revision and reviewed claims. |
| Asset | Validated format, dimensions, source/optimized references, checksum and uploader. Draft sources are not public advertisements. |
| Placement schedule | Exact revision, one main/side position, start/end instants, order, pause/end state and version. |
| Action history | Actor, server time, action, reason where needed, changed revision/placement and before/after references. |

An editorial collection is a grouping of approved products and published offers;
it is not a second voucher funding system. Keep image publication separate from
discount publication and from catalogue eligibility.

## Upload and form contract

- New promotion artwork accepts decoded JPEG/JPG, PNG or WebP, at most **5 MiB
  (5,242,880 bytes)** per source image. Show these limits beside the picker.
- Recommended output is **1880 x 880**; minimum input is **940 x 440**, at the
  existing **47:22** ratio within 1% rounding tolerance. Limit each dimension to
  4096 pixels and total decoded size to 16 megapixels. These are Bagoo choices.
- Reject videos, PDFs, SVG, animated GIF, renamed text/HTML, corrupt files and
  unsupported formats. Validate real content with a decoder as well as MIME and
  size. Do not rely on the filename or browser accept attribute.
- Explain an incorrect ratio instead of silently cropping. Preserve the supplied
  artwork and create optimized, metadata-stripped public variants only after
  validation. Keep an internal target of 350 KiB or less per delivered image
  where readable; review an exception rather than damaging text readability.
- Trim title/alt text; title is 1-80 characters and alt text 1-180. An optional
  plain-language terms summary is at most 600 characters. Reject whitespace-only
  required fields, control characters, scripts and unsupported target types.
- Require a valid destination and an end strictly after the start. Publish now
  uses server time. A source can be replaced in a draft without changing a live
  revision. A failed upload preserves entered data and the previous working image.

These new promotion rules do not silently change legacy shop-branding validation
or re-review a seller's ordinary logo, contact details or description.

## Decisions, concurrency and recovery

Platform editorial drafts may be approved/published by the same authorized admin,
with a recorded action. AD03 adds submitted seller revisions and review feedback.
Keep review state separate from display state: approval alone does not occupy a
slot or bypass a future start time, pause, expired target or suspension.

Every writer reloads current marketplace authority. Reuse server-side account,
shop/category and offer eligibility; a generic admin-looking client role is not
authorization. Reject private target IDs, foreign seller scope and arbitrary
links. A direct request cannot publish by assigning a client-supplied status.

Version forms so stale edits return a conflict and preserve recoverable input.
Reserve/swap positions transactionally: two overlapping publications cannot own
the same main/side position. Use half-open time intervals, so one campaign may
end exactly as the next begins. Lock in a consistent order, enforce duplicate
placement rules and retain original revisions. Repeated submissions do not
create duplicate publication decisions.

Prepare a validated immutable image before committing a live reference. If file
storage or the database action fails, retain the previous working publication.
Clean up only proven unreferenced temporary files; do not delete historical assets
still referenced by a live revision or retained review. Pause/end/replace keeps
history instead of deleting evidence. Invalidate public presentation only after
a successful commit, with a retryable invalidation path.

## Gate

Verify owner/role restrictions, disguised and boundary files, field limits,
schedule conflicts, duplicate/stale writes, failed publication rollback, private
draft previews and retained history using isolated SQLite and focused rendering
checks. Build the admin frontend. SQLite checks do not prove concurrent
PostgreSQL publication; record that limit and obtain separate authorization for
any production-engine verification. No development or production database reset.

Keep the static reader replacement behind the AD02 release gate; a successful
admin form alone is not a completed advertisement feature.
