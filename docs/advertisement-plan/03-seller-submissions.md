# AD03: Seller Proposals and Platform Review

Proposed follow-up, depending on accepted AD01 and AD02. Marketplace placement
is a proposal and review flow; ordinary own-shop branding remains an owner edit.

## Seller experience

Add a small Promotions area in the existing seller portal. An eligible seller
can create a draft with artwork, a proposed period, its purpose and one owned
shop/product/offer destination. Show submission state, current display state,
feedback and retained history separately. Use normal messages such as
"Your promotion is waiting for review" and "Please replace the expired offer."

The seller cannot reserve a homepage position, demand publication or publish by
posting an admin field. The admin controls the platform calendar and final slot.
Allow a seller to withdraw its own request or stop its own promotion. Neither
action deletes past review decisions or another advertiser's placement.

## What review checks

The reviewing active Platform Admin checks the exact artwork, destination,
ownership, approved shop category, published offer terms, period and available
placement. Approve that revision, request changes or reject with a useful reason.
Approval does not override a suspension, grant catalogue eligibility or promise
traffic. Existing platform product moderation remains authoritative.

An approved proposal can be scheduled by the admin. A materially changed image,
destination, linked offer or requested period creates a new draft/review; it
cannot silently replace approved content. The old revision remains only within
its original valid conditions unless withdrawn or paused. Admin placement edits
are versioned and audited as separate decisions.

Reuse the existing governed notification pattern where helpful: submission and
decision messages include an owned portal link, not private evidence or secrets.
Deliver notifications after commit with existing retry/idempotency behavior.
A delivery failure does not undo an otherwise successful review.

## Ownership and ordinary branding

One seller owns one shop and its approved master category. Product/category
targets must remain within that scope and eligible descendants. A seller can
reference only its own published shop-funded offers. A platform-wide event or
another seller's products requires an admin-created editorial promotion.

Preserve direct owner editing of the shop logo, banner, description and contact
number through existing settings/storefront actions. Do not turn a logo change
into an identity-review request or invalidate catalogue approval. A shop's
ordinary branding image does not automatically become homepage advertising.

No new advertiser role, seller role conversion, extra shop or company-admin
publishing permission is part of this delivery. Recheck current owner and account
eligibility for reads/writes; hidden controls alone cannot protect foreign drafts.

## Gate

Verify foreign-shop/product/offer IDs, inactive and suspended actors, forged
publication fields, direct endpoints, review of an outdated revision, concurrent
seller edit/admin decision, duplicate submit/withdraw, removal of a target and
notification failure/retry. Verify that an ordinary branding edit preserves
approval and does not create a promotion request.

Use the upload and form boundaries from AD01. Seller and admin screens retain
their own layouts, saved-state feedback and responsive behavior. Do not claim
acceptance until the seller-to-review-to-buyer path is verified in addition to
the admin-created path.
