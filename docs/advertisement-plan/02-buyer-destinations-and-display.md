# AD02: Buyer Destinations and Scheduled Display

Proposed batch, depending on AD01. Complete the real path from a published
advertisement to its matching content. Retain the [chosen grid and click rules](README.md).

## Reader and layout

Resolve at most three main slides and two side images from approved revisions
and current placement schedules. Do not sort by an invented paid score. Preserve
the bounded buyer content width, image-overlay arrows, natural image proportions
and borderless image treatment. Keep supporting images beneath the carousel on
smaller screens. No visible caption panels, promotional chip strip or automatic
rotation is required.

Evaluate time, pause/end state, revision approval, asset availability and current
target eligibility on every fresh request. Read-time expiry must work without a
cron run. Where data is cached, bound its lifetime by the next schedule boundary
and invalidate it on publication, pause and relevant eligibility changes.
Personalized voucher state must not leak through a shared public cache.

Omit an unavailable placement. One main image needs no arrows/indicators; zero
valid images leaves normal browsing without a blank promotional panel. Missing
side images must not produce the rejected oversized viewport-wide hero. Provide
a compact, bounded fallback arrangement and test its widths explicitly.

## Destinations

The server resolves a destination type and selected public record to an existing
canonical product/shop/category route or a proposed public collection/offer page.
Use Inertia for same-origin page visits and keep a usable real href for ordinary
link actions. Allowlist catalogue filter keys and values through the existing
discovery service. Do not redirect to an arbitrary supplied URL.

Propose two new buyer-styled presentation responsibilities; their route names
and schema are to be chosen during implementation, not treated as existing APIs:

1. **Event/collection page:** actual campaign title, period, terms, participating
   eligible products, linked published offers and clear Browse/Add to Bag actions.
2. **Offer terms destination:** issuer, real discount, minimum spend, cap, period,
   delivery constraints and related products. Explain that final eligibility is
   checked at checkout; continue to the existing owned voucher collection for
   saving/selection. Never expose an admin draft or private funding record.

A single-product image opens its product details, a shop image opens that shop,
and a category image opens scoped results. A multi-product event opens its
matching collection. Do not point every advertisement at a generic feed.

Guests can read public campaign/product content. When a guest chooses an owned
save or purchase action, use the existing sign-in flow and retain a safe return
destination. Pending buyers retain browsing but do not gain checkout access.
Never bypass current account, category, shop or product restrictions.

## Match the claim to the destination

Before publication and display, confirm that a dated event is in its actual
window, products are eligible and related voucher revisions are published and
usable for the advertised conditions. A generic banner cannot promise a
buyer-specific benefit to everyone. Defer new first-order/segmentation targeting
unless the existing voucher rules can establish it truthfully.

Do not calculate new prices from a marketing image or imply that an ad code
automatically applies. Use the canonical voucher quote and checkout services.
Published ad revisions retain the offer references reviewed with them; changing
an offer cannot quietly change an approved promise. Re-review or suppress the ad
when its linked terms no longer support the message.

On collection pages, remove newly ineligible products and show a useful empty
state. If the main promise can no longer be fulfilled, stop advertising it. An
old link can show an ended/unavailable message with existing browsing options;
do not expose restricted details or return a raw exception. Final stock, price,
route, voucher and checkout checks remain authoritative even after a valid click.

## Gate and rollout

Verify exact start/end boundaries in Asia/Manila, pause/resume/expiry, a stopped
scheduler, stale/shared cache, changed offer terms, unavailable image, withdrawn
product, suspended shop, guest browsing and pending-buyer restrictions. Inspect
empty/single/partial/full grid rendering and supported destination links. Keep
keyboard controls, accessible descriptions and image proportions.

Use additive migrations and retain existing data. Import supplied artwork as
unpublished draft candidates; validate its claims before activation. Public
display changes only after AD01/AD02 pass together. With no approved campaigns,
show normal products rather than restoring unsupported hardcoded sale promises.
A disable/rollback path stops new display without removing historical decisions,
orders or voucher records. No migration/reset command is prescribed by this plan.
