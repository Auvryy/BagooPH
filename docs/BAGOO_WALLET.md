# Bagoo Wallet

The approved addition is a virtual balance used only inside Bagoo alongside
cash on delivery. One demo peso of wallet credit buys one peso of the displayed
order total. These credits have no cash withdrawal, external transfer or deposit
feature. Admins approve clearly labelled demo-credit requests. No request or
approval represents cash received.

## Delivery and payment contract

Wallet payment is separate from the canonical commercial and parcel statuses.
Checkout reserves the actual items and discounts and creates an unpaid wallet
payment for each shop order. Its one-hour deadline uses server time. Until its
original exact amount is paid, the seller cannot confirm, pack or release it and
the buyer sees To pay. COD remains payable on delivery and has no wallet expiry.

A buyer explicitly confirms payment from their own available balance. Balance
checks, debit, original payment reference and paid state commit together. A retry
returns the saved outcome. Failed attempts preserve the unpaid order; a new
attempt after an approved top-up can succeed. Cancelling the payment confirmation
leaves the order unpaid. Closing the browser does not undo a committed payment.
At or after the deadline, payment is rejected and unpaid cancellation restores
reserved stock and eligible voucher availability once. An expired voucher is
never made active again, and sibling orders retain their original allocation.

Successful wallet orders follow the complete existing hub route and buyer-only
receipt confirmation. Riders and counters collect no cash for an evidenced paid
wallet order. Original payment evidence, rather than a client flag or edited paid
label, authorizes that path.

Pre-dispatch cancellation refunds the original paid wallet amount once. Accepted
post-delivery returns use the existing seven-day window, actual reverse custody,
seller receipt and admin review. Credit goes to the original buyer for the
original paid merchandise units, preserving the documented shipping treatment.
Refunds cannot exceed the original payment or cover the same units twice.

## Credits and accounting

Record immutable balanced journal entries for demo credit issuance, purchase,
refund and virtual settlement, using integer centavos. Keep each user's available
balance non-negative and serialize changes to that balance. Every command has an
owned retry key and an original business reference. Corrections append evidence;
they do not edit or delete transactions.

Virtual seller proceeds preserve the 90% seller / 10% commission merchandise
split, original promotion funding and separate shipping. Seller credits are
visible recorded proceeds, with no cash withdrawal or spending feature. Returns
reverse the evidenced virtual shares, including after virtual settlement. COD
collection, reconciliation, bank-payment proof and cash proceeds retain their
existing separate ledgers and permissions.

## Payment acceptance mapping

The user selected an internal wallet. These are wallet equivalents of the payment
workbook's external-provider scenarios; they do not claim card or gateway support.
Keep original workbook cases and expectations, and record actual executed results
separately from these equivalents.

| Case | Required observable wallet behavior |
|---|---|
| BUY-PAY-001 | COD stays available with its original collection amount and lifecycle. |
| BUY-PAY-002 | Pay the exact saved amount once, retain a reference and unlock seller preparation. |
| BUY-PAY-003 | Insufficient credits show failed payment and Pay again; retain unpaid state and block shipment. |
| BUY-PAY-004 | Cancel the wallet confirmation; preserve one unpaid order and unchanged credits. |
| BUY-PAY-005 | Lose the browser response and reopen; recover the committed result without another charge. |
| BUY-PAY-006 | Expire an unpaid order after one hour; restore stock and eligible voucher availability exactly once. |
| BUY-PAY-007 | Pay before expiry; reject at/after it, with one authoritative result for competing actions. |

Also verify authorization, suspended accounts, changed payloads, stale pages,
currency precision, refunds, multi-shop allocations, failure rollback and cash
separation. Runtime evidence and the scoped assessment belong in
[the roadmap](CORE_FLOW_ROADMAP.md).
