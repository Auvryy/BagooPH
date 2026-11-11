# B04: tariffs, liability and original payment allocations

## Decisions and delivery boundary

This is the money contract for brainstorming topics 7, 8, 17 and 25–27 under [the build order](BUILD_ORDER.md). It builds on [B03](B03_SHIPMENT_CUSTODY_PRIVACY.md), the version-two journey, original voucher funding and the separate COD/Wallet journals. The user selected the price and refund decisions below on November 11, 2026.

B04 decides these rules. B05 applies the tariff and freezes supported purchased terms. New refund execution, liability assessments and funding consumers retain B11–B13/B15 acceptance. A policy decision is not evidence that an automatic compensation or shipping-refund writer exists.

## Current tariff amendment

The user replaced the earlier PHP 60 default with **PHP 50 including 1 kg, plus PHP 20 for each started extra kilogram**. Keep divisor 3,500 and charge the greater of actual and volumetric weight. Catalogue dimensions describe each packed unit; quantities aggregate actual grams and volumetric grams across the shop parcel.

| Billable weight | Base | Started extra kg | Shipping |
|---|---:|---:|---:|
| 0.50–1.00 kg | PHP 50 | 0 | PHP 50 |
| 1.01–2.00 kg | PHP 50 | 1 | PHP 70 |
| 2.01–3.00 kg | PHP 50 | 2 | PHP 90 |
| Two 0.50 kg items, each 35 × 20 × 10 cm | PHP 50 | 3 (4 kg volumetric total) | PHP 110 |

This replaces the proposed PHP 50/70/100 geography tiers and divisor 5,000. Do not invent distance bands, fragile/bulky surcharges, insurance percentages or COD handling fees. An approved company owner can publish a different supported tariff for future purchases. Existing published company settings are retained; changing the platform default does not overwrite their configuration or purchased quotes. The demo seeder uses the new baseline for its future demo setup, without running a reset.

Weight-priced confirmation requires actual weight and all dimensions. Provisional checkout before company selection can identify unknown measurements; it cannot confirm a selected weight-priced carrier by guessing them. Self-pickup retains zero buyer doorstep charge. A funded shipping voucher is distinct from a company giving free shipping.

## Shipping and merchandise pools

All authoritative allocations use integer centavos. Keep merchandise commission at 10%, seller gross at 90%, product-voucher funding separate, and shipping separate.

| Original shipping pool | Model | Pickup | Delivery | Company | Sum |
|---|---|---:|---:|---:|---:|
| 5,000 cents | Standard | 0 | 2,500 | 2,500 | 5,000 |
| 5,000 cents | Dedicated fleet | 833 | 2,083 | 2,084 | 5,000 |
| 7,000 cents | Dedicated fleet | 1,166 | 2,916 | 2,918 | 7,000 |

The company receives rounding remainders. New quotes retain `earnings_terms_version`, `earnings_split_cents` and `payable_shipping_cents`. Collection quotes retain a zero payable pool and zero shipping entitlements, even when they also retain the unused doorstep tariff for comparison. Old quotes without explicit cent allocations keep the original named-model interpretation; settings edits never change their purchased fee.

Actual origin receipt earns the configured pickup share. Valid delivery earns company and delivery shares; COD rider release still requires acknowledged remittance. Preserve earned entries and record any approved adjustment separately. This does not grant unconditional rider pay for work that never happened or authorize withholding earned pay merely because somebody reported a loss.

## Selected successor refund and liability policy

The user selected seller-fault item-plus-shipping refunds, funded courier-fault review and exclusion of voluntary post-delivery change-of-mind returns. They additionally selected buyer liability for shipping when the buyer changes their mind after shipment.

| Finding | Required result | Required evidence and funding |
|---|---|---|
| Seller fault: wrong/defective/not-as-described eligible item | Refund the original net item amount plus its allocated **actually paid** outbound shipping. No buyer return-shipping charge. | Existing private review and actual reverse/seller receipt, plus a reasoned fault decision. Seller bears the approved shipping liability; preserve carrier entitlements for actual work. |
| Courier-company fault: proven transit loss/damage | Review buyer refund and any seller protection separately; preserve eligible seller recovery/compensation once. | Custody and exception investigation, original paid amounts, responsible company and actual received compensation funding. A last scan alone is not a fault decision. |
| Buyer changes mind after physical pickup/refuses an otherwise conforming shipment | Outbound shipping remains spent. Buyer bears the evidenced approved return shipping if return transport occurs. | Actual pickup/return evidence and reviewed reason/charge. A cancellation click cannot undo custody. Never invent a refund for uncollected COD. |
| Voluntary change of mind after delivery | Remains excluded as an eligible buyer-return reason. | Do not expand the existing damaged/wrong/missing-parts/not-as-described reason list through a generic free-text reason. |
| Pre-pickup eligible cancellation | Retain its original cancellation/Wallet rules. | B06 verifies the last permitted boundary; no fictitious return trip or shipping debt. |

This policy supersedes the brainstorming proposal to refund immediately at a scan timeout, automatically blame the last scanner, charge a fixed late-pickup fine, use an assumed insurance deposit, debit a negative seller Wallet or hide their catalogue. Monitoring/investigation remains B14/B15; restrictions remain B16. Insurance is not an existing funding source.

### Exact examples and funding conservation

For a full seller-fault return: item PHP 500 + outbound PHP 50 = original paid PHP 550. The selected successor refund is PHP 550. The original PHP 50 carrier pool remains owed for actual transport. Therefore the extra PHP 50 refund must be backed by seller recovery or an explicitly approved, actually received platform advance; a recorded debt/offer is not money received. Before any seller payout, original PHP 550 + actual extra PHP 50 backing = PHP 550 refund + PHP 50 protected carrier pool. After a PHP 450 seller payout, actual recovery/funding must cover that original payout as well. Never make the buyer fund this seller-fault shipping.

For a partial seller-fault return: merchandise PHP 1,000 less original product discounts PHP 100 gives PHP 900 net merchandise. Returning half of those equally priced net units gives PHP 450 item refund plus PHP 25 of the original paid PHP 50 shipping: PHP 475. Remaining net merchandise PHP 450 + remaining paid shipping PHP 25 + refund PHP 475 = original buyer payment PHP 950. The shipping reimbursement has its separate liable-party funding; this equality does not erase earned carrier obligations.

Allocate paid outbound shipping deterministically over original purchased units using their original net merchandise weights, with original gross weights as the fallback when all net merchandise is zero. Use cumulative integer allocation so every remainder belongs to a stable original unit. Multiple/partial cases together cannot refund more than the original buyer-paid shipping. Shipping-voucher funding is not buyer-paid shipping and is not refunded to the buyer or restored to campaign budget automatically.

For buyer refusal after shipment: item PHP 500 + outbound PHP 50 paid = PHP 550. If actual approved return shipping is PHP 40, refund PHP 460. The buyer's total cost is PHP 90, not PHP 140: do not deduct the already spent PHP 50 outbound fee a second time from the PHP 500 item refund. PHP 550 = PHP 460 refund + PHP 50 outbound + PHP 40 return. For unpaid COD, buyer refund is zero and the PHP 90 reviewed shipping liability is separate; never record PHP 500 merchandise as collected or repaid. If approved charges exceed refundable amounts, record a separate reviewed balance, not a negative refund or automatic Wallet debit.

For courier fault, the investigation names the liable company and approved original amounts. A paid buyer can receive at most the evidenced payment; an unpaid COD buyer has no repayment entitlement for money never collected. Seller protection is separate from earnings for delivery and must not duplicate proceeds already paid. Any platform advance requires its own authorization and actual backing; recoveries remain receivables until received. No assumed insurance pool balances an otherwise unfunded example.

For Wallet, apply the same original-unit/shipping limits in virtual centavos through balanced entries and explicit reviewed sources. A virtual credit is not cash, a bank refund or an insurer payment. Existing user balances cannot go negative. Do not charge real cash to settle a purely virtual deficit or silently debit the original shipping pool while leaving its entitlements outstanding.

## Rollout and original terms

The operational post-delivery refund writer currently implements **refund policy 1**: original net merchandise only, separate protected shipping, reviewed actual return evidence and backed COD/Wallet repayment. New B05 quotes explicitly freeze this supported version. An unknown purchased refund-policy version is rejected for review rather than interpreted as policy 1.

The selected successor policy above is **policy 2, approved for the later refund delivery but not activated by B04/B05**. B11–B13 must add original-unit shipping allocations, reviewed fault/liability records, extra seller/company funding and repayment presentation before issuing policy-2 purchases. B12/B15 must supply actual reverse charge/exception evidence where applicable. Checkout must not advertise policy-2 refunds while these consumers are missing. Activation changes future purchase terms; do not rewrite old quotes, refunds, cash or earnings.

This explicit rollout boundary preserves the user's decisions without silently enabling unfunded money movement. Each original topic's other operational proposals retain their own build-order gates. Verification and local delivery status are recorded in the roadmap.
