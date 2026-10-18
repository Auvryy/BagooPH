# Buyer delivery results — October 19, 2026

The three core deliveries are prepared as local commits. The final branch
contains its predecessors; no GitHub publication, Azure deployment or Flutter
project change was performed. Current implementation evidence and scoped ratings
are recorded in [the roadmap](../CORE_FLOW_ROADMAP.md).

This is an assessment of 92 allocated scenarios, supported by isolated backend
checks, frontend interaction/rendering checks, builds and static inspection.
It is not a record of 92 manually executed browser passes. The original external
expectations must be aligned with the retained project rules below. New feature
gaps remain explicit; a stable test result does not implement an absent feature.

## Deliveries

| Batch | Branch | Result |
|---|---|---|
| 1: B3–B6, 34 scenarios | `fix/buyer-discovery-and-bag-acceptance` | Full-catalogue shop filtering/paging, bounded search corrections, persistent owned follows, real counts, explicit options and consistent configured variant prices. |
| 2: B7–B9, 30 scenarios | `fix/buyer-checkout-and-voucher-acceptance` | Displayed-price consent, exact Buy Now quantities, retained Bag contents, explicit COD rejection of unsupported methods, tested voucher/funding/rollback and original-result replay. |
| 3: B10–B14, 28 scenarios | `fix/buyer-orders-and-communication-acceptance` | Recorded circular parcel milestones, safe early cancellation, one review edit, masked public names, actual purchased options, private chat photos and safe send/read behavior. |

A Buy Now request for an existing matching Bag line is fully addressed in batch
2: it purchases the requested quantity and leaves the previous quantity in the
Bag. Batch 1's earlier handoff limitation no longer applies to the combined work.

## Retained expectations

- Commission remains 10% of merchandise sales, with shipping and funded discounts
  accounted for separately. Buyer receipt does not replace COD reconciliation or
  seller settlement gates.
- Checkout supports COD. Unsupported online methods and missing payment choices
  fail clearly. Gateway callbacks, payment expiry and online refunds are deferred.
- Only the buyer's receipt confirmation completes a physically delivered order;
  elapsed time does not complete it automatically.
- Shipping retains the existing per-shop policy: PHP 50 for doorstep delivery,
  waived only above PHP 1,500 in merchandise for that shop; hub self-pickup has no
  doorstep fee. Actual route serviceability is checked separately. No assumed
  region/weight tariff or maritime coverage was introduced.
- Voucher choices retain one offer per shop and one Bagoo offer. Shipping savings
  use the actual fee within their cap. Cancellation releases only the original
  applicable funding and does not reset retained redemption usage.
- Bag quantities remain whole numbers from 1 to 99 per line, further bounded by
  the actual combined product and size stock. There is no configurable cumulative
  per-buyer purchase cap across orders.
- Storefront categories follow the approved master category and active descendants;
  the buyer work does not create an independent store category-management system.
- Checkout notes retain 500 characters; review text retains 1,000. Review photos
  retain a maximum of five, each up to 5 MB. Chat allows one readable JPG, PNG or
  WEBP image up to 5 MB and text up to 1,000 characters.
- Full post-delivery returns, seller decisions, escalation and repayment remain
  deferred. Failed-delivery return-to-sender remains a separate custody flow.
  Legacy paid orders cannot use the new unpaid-COD cancellation action.

## Remaining feature work

These checks are not reported as passing implemented features:

1. Seller shop vacation settings and their purchasing behavior.
2. Configurable purchase caps counted across a buyer's orders.
3. Stock for every independent color/size combination. Current colors have
   availability flags; sizes share stock and configured surcharges.
4. Timed sale prices, countdowns and reserved promotional quantities.
5. Sale reminder subscriptions and their scheduled notifications.

Their settings, eligibility, funding and operational rules need a separate
feature delivery. Keep this follow-up visible when discussing review coverage.

## Verification and handoff

The integrated run passed **855 backend tests / 20,558 assertions**, covering
buyer, finance, notification, review and parcel tracking behavior. Related rider
API contracts and seller order behavior passed **47 tests / 1,472 assertions**.
Focused follow-ups cover the final cancellation/payment guard, real image
validation, message retries and public review options. All **436 frontend tests**
passed, with focused follow-ups after the final refinements. Production builds,
formatting and patch whitespace passed.

Backend tests used SQLite in memory. There was no PostgreSQL test reset,
disposable PostgreSQL concurrency run, browser automation, screenshot review,
live email proof or deployed acceptance check. The three additive migrations
were applied to the local Docker database; no records were reset or reseeded.
A read-only local probe confirmed the new schema and 17 visible products.
Normal deployment must apply those migrations before using the new features.

Use isolated browser sessions for each role, such as a normal buyer window and
a private seller window, so changing roles does not replace the buyer sign-in.
For a quick manual review, use the actual seeded accounts in
[DatabaseSeeder](../../database/seeders/DatabaseSeeder.php) and
[SellerDemoSeeder](../../database/seeders/SellerDemoSeeder.php):

1. Browse `/buyer/search`, combine filters, page a shop catalogue and try a typo
   with no exact result. Check a restricted or draft product cannot be purchased.
2. Follow a shop, reload and visit `/buyer/followed-shops`; unfollow from the list.
3. Choose a configured product option, try invalid quantities, and check Bag and
   quoted unit prices agree. Use Buy Now with an earlier matching Bag quantity.
4. At `/checkout`, change a price through the seller portal after opening checkout;
   confirmation must require a fresh review. Check unavailable routes and expired
   offers block continuation with clear feedback.
5. Cancel an owned early unpaid COD order with a reason, then repeat the action.
   Inventory/funding must restore once. Ready, claimed and paid orders are blocked.
6. Open `/buyer/orders`, then the owned order detail. Follow the actual rider/hub
   workflow; check missing hub scans stay unrecorded, counter and return branches
   stay distinct, and only a delivered order offers receipt confirmation.
7. Review a completed purchase, check its masked public name and purchased option,
   then use its one permitted edit. Attempt a second edit and an expired edit.
8. Send text and a valid chat photo from a product and from the inbox. Retry a
   failed send, try invalid/oversized files, and check a different account cannot
   open the private photo. Open owned notifications and verify read counts.

PR titles and bodies are local drafts. Publishing and merging remain user actions.
The simplest combined publication is the final branch against main; it includes
all three deliveries. Separate stacked PRs require keeping their bases and merge
history aligned, especially if earlier deliveries are squash-merged.
