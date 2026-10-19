# Retained buyer expectations and boundary evidence

This records the selected BP02 expectation decisions and local automated results
for its 15 references. Read the [planning baseline](CASE_COVERAGE.md),
[BP02 scope](02-rules-and-boundaries.md) and
[current implementation evidence](../CORE_FLOW_ROADMAP.md). The user chose to
retain Bagoo's commercial and completion rules. The descriptions below are
project interpretations; no private example accounts or transactions are copied.

The original expectation remains available for comparison. An automated pass for
an aligned rule does not pass an unchanged conflicting expectation or establish
an unperformed browser procedure. The 13 policy-conflict cases retain that
qualification; the two previously missing money sequences now have direct local
HTTP, persistence and retry evidence. The original manual procedure is still
Not Run where its complete visible sequence has not been observed.

## Per-case decisions

| Reference | Original assumption that differs or needed evidence | Retained expectation and actual local evidence |
|---|---|---|
| `BUY-SRCH-002` | A shop can define independent category memberships. | One approved master root and active descendants. `BuyerStorefrontAcceptanceTest::test_storefront_filters_all_pages_inside_its_approved_category_scope` passes for scoped category filtering and pagination. Independent store taxonomy remains outside this rule. |
| `BUY-VOU-003` | More than one platform offer can occupy separate simultaneous slots. | One shop offer per selected shop and one Bagoo offer. `BuyerVoucherSelectionTest::test_code_replaces_its_own_slot_and_bad_code_retains_manual_choices` and `VoucherFundingFoundationTest::test_wrong_scope_and_excess_stacking_are_rejected_without_writes` pass; replacement retains other slots and excess selection rejects. |
| `BUY-VOU-004` | The exact minimum-spend cent sequence lacked execution. | `BuyerPolishBoundaryTest::test_shop_minimum_cent_sequence_ignores_unselected_and_other_shop_merchandise` passes below, exactly at and above the shop minimum. The shortfall is one cent; unselected/other-shop merchandise cannot supply it. Accepted amounts match saved orders; rejection and identical retry leave stock/use counts correct. |
| `BUY-VOU-008` | The full percentage eligibility/cap sequence lacked execution. | `BuyerPolishBoundaryTest::test_funded_percentage_minimum_rounding_and_cap_match_preview_and_saved_cents` passes nine funded-offer cases: eligibility, proportional saving, the last cent below the rounded cap, rounding into it, the mathematical cap and higher bases. Preview, saved payment, funding allocation and retry agree in cents. |
| `BUY-VOU-009` | A different shipping fee is assumed. | Shipping savings reduce the actual selected-shop fee only. `BuyerVoucherQuoteTest::test_shipping_discount_uses_actual_fees_and_leaves_the_unpaid_cap_visible` and `BuyerVoucherSelectionTest::test_one_cent_shipping_remainder_stays_on_paid_parcels_and_zero_fee_shop_keeps_its_merchandise` pass without reducing merchandise or manufacturing a fee on a free-shipping parcel. |
| `BUY-VOU-010` | A seller-funded offer can erase the seller's entire merchandise price. | Shop-funded saving is capped at the original 90% seller share, with gross commission and shipping retained independently. `VoucherFundingFoundationTest::test_shop_savings_cannot_exceed_the_original_seller_share` passes. A funded platform offer is a separate budgeted source. |
| `BUY-VOU-012` | Cancellation restores a consumed voucher use. | Release the cancelled order's original commitment once while retaining redemption usage and sibling allocations. `VoucherFundingFoundationTest::test_partial_cancellation_releases_only_the_cancelled_order_and_never_restores_uses` and `VoucherFundingFoundationTest::test_buyer_cancellation_releases_only_its_order_funding_once_with_the_buyer_as_actor` pass. |
| `BUY-CHK-001` | Combined totals assume extra offer slots and different fees. | Derive totals from the supported slots, selected shops, current merchandise prices and actual per-shop fees. `VoucherFundingFoundationTest::test_two_shop_offers_and_one_platform_offer_share_exact_immutable_allocations` passes preview, saved amounts, original allocations and replay. This does not accept the differing original arithmetic. |
| `BUY-CHK-002` | A regional tariff guarantees a different destination price. | Keep the documented supported land/hub network and current per-shop fee; reject unsupported destinations and stale/ineligible collection hubs. `BuyerVoucherQuoteTest::test_preview_rechecks_address_and_pickup_hub` passes. A nationwide maritime/air tariff is not implemented. |
| `BUY-CHK-003` | Product weight selects a shipping-price bracket. | Current doorstep shipping uses merchandise subtotal per shop. `BuyerPolishBoundaryTest::test_actual_product_weight_does_not_manufacture_an_unsupported_shipping_tariff` passes at three real stored weights with the same fee. `BuyerPolishBoundaryTest::test_doorstep_shipping_threshold_is_strictly_above_fifteen_hundred` passes one cent below, at and above the actual free-shipping threshold, including saved orders. |
| `BUY-CHK-005` | The seller-note maximum differs. | Meaningful plain text up to 500 characters. `BuyerPolishBoundaryTest::test_seller_note_exact_boundaries_persist_the_entire_accepted_value` passes 499/500/501 characters: accepted values reach seller order props intact; rejection saves no order or stock change. Existing `CommerceInputValidationTest` covers invalid text forms. |
| `BUY-TRK-001` | Purchase tabs include unsupported online-payment/refund stages. | Use actual COD stages and issue navigation with owned counts. `BuyerOrderWorkspaceTest::test_every_stage_and_legacy_alias_has_separate_owned_counts_and_filters` passes. Online-payment and the separately selected buyer-return feature must be judged by their own gates. |
| `BUY-TRK-003` | Buyer receipt immediately releases seller funds. | Buyer completion, reconciled COD and evidenced seller payment are distinct. `SellerSettlementTest::test_authorization_is_not_payment_and_payment_retains_exact_sources_and_shipping` and `SellerSettlementTest::test_status_only_completion_and_admin_confirmation_do_not_manufacture_buyer_evidence` pass. |
| `BUY-TRK-005` | Elapsed days complete an order automatically. | Only an explicit owned buyer receipt completes delivery. `BuyerReceiptClockTest::test_elapsed_time_and_history_reads_never_replace_explicit_buyer_receipt_or_cash_reconciliation` passes a real delivered custody flow after eight days: history reads retain Delivered, buyer receipt completes it, and unreconciled cash still prevents settlement. This checks elapsed-time/read behavior, not a deployed scheduler. |
| `BUY-RATE-004` | The review text maximum differs. | Keep 1,000 characters, integer stars and at most five JPG/PNG/WEBP photos of 5 MB each. `BuyerPolishBoundaryTest::test_review_text_exact_boundaries`, `BuyerPolishBoundaryTest::test_review_photo_count_boundary_does_not_leave_files_after_rejection` and `BuyerPolishBoundaryTest::test_actual_review_photo_bytes_enforce_the_five_megabyte_boundary` pass. `ReviewIntegrityTest::test_invalid_image_types_sizes_and_counts_reject_without_saving_files` covers renamed content, unsupported formats and invalid collections. |

## Precise interpretation

For the checked 10% offer capped at PHP 20, PHP 199.94 produces PHP 19.99,
while PHP 199.95 rounds to PHP 20. The mathematical cap basis is PHP 200 and
higher values remain capped. These are derived test fixtures for cent rounding,
not a copied acceptance example. Rejection below the minimum consumes neither
stock nor a redemption; accepted checkout and same-token retry preserve one use.

Doorstep shipping is PHP 50 per shop at PHP 1,500 and becomes free only above
that subtotal. Supported hub self-pickup has no doorstep fee. Weight-bracket and
regional tariffs are separate features, not silently inferred from a delivery
address or a product weight field.

The 30-day, one-edit review window runs from posting; creation has no separate
assumed deadline from order completion. BP02 preserves that distinction. It does
not introduce automatic completion to make a return/review deadline convenient.

## Verification scope

The selected existing suites and added boundary cases passed **350 tests with
4,549 assertions**; the independent receipt-clock flow passed **one test with
104 assertions**. All used isolated SQLite `:memory:`. This batch changes tests
and documentation only, so it does not claim a new UI build or browser result.
The last application build belongs to BP01 and is recorded in the roadmap.

The full buyer-return prerequisites were explicitly selected after the initial
polishing plan. They remain separate implementation work; online card/e-wallet
payments remain deferred. BP02 does not fabricate refund, cash-repayment or
post-payout adjustment evidence.
