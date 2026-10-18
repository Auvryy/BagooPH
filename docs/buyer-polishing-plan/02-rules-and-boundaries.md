# BP02: Retained rules and precise boundaries

Proposed branch: `test/buyer-rules-and-boundaries`.
Active allocation: **15 original cases**, listed in the
[case ledger](CASE_COVERAGE.md): 13 rule conflicts and two missing exact boundary
sequences. Follow the [shared plan](README.md).

## Outcome

Each affected case has a justified Bagoo expectation and an actual result for
that expectation. Keep the original expectation available for comparison. A
renamed status or passing alternative test is not evidence that an unchanged
original case passed.

The user chose to retain Bagoo's rules. This batch documents and checks those
rules; it does not silently add competing commercial behavior. Where the required
outcome remains unresolved, retain Blocked with its owner/prerequisite.

## Required expectation decisions

| Area | Retained behavior and targeted verification | Active references |
|---|---|---|
| Shop taxonomy | One approved master root and its active descendants. Verify supported nested filtering; independent store-created categories/multiple memberships remain separate scope. | `BUY-SRCH-002` |
| Voucher slots | One offer per shop and one Bagoo offer. Verify replacement inside a slot, rejection of excess platform slots and preserved sibling selections. | `BUY-VOU-003`, `BUY-CHK-001` |
| Shipping | Current per-shop doorstep policy and supported land/hub network; hub collection is distinct. Verify the actual fee, its exact free-shipping threshold and unserviceable destinations. No region/weight tariff is implied. | `BUY-VOU-009`, `BUY-CHK-001`, `BUY-CHK-002`, `BUY-CHK-003` |
| Seller funding | Seller-funded savings cannot exceed the original seller share. Verify capped savings and nonnegative seller/commission/shipping accounting; do not promise free merchandise from a conflicting shop offer. | `BUY-VOU-010` |
| Cancellation use | Release only the original order's eligible funding once. Retain redemption usage and sibling allocations; cancellation does not automatically create a new voucher use. | `BUY-VOU-012` |
| Seller note | Current meaningful plain-text maximum is 500 characters. Verify just below, at and above the limit, persistence and the complete seller-visible value. | `BUY-CHK-005` |
| Purchase stages | Verify supported COD stages, order-issue navigation and owned counts. Do not add fake online-payment or full-refund states to match alternate tab labels. | `BUY-TRK-001` |
| Receipt and funds | Only an owned physically delivered order can be completed by buyer receipt. Seller funds still require their independent cash/settlement gates. | `BUY-TRK-003`, `BUY-TRK-005` |
| Review limits | Preserve the current 1,000-character text maximum, integer stars, five validated images and the documented per-image size/type rules. Verify all affected boundaries. | `BUY-RATE-004` |

Read the actual `CheckoutQuoteService`, `VoucherSelectionService`,
`VoucherFundingService`, `CommerceInputService`, `OrderLifecycleService` and
`ReviewService` before constructing the fixtures. Use current enums, services and
funding records. Confirm the precise money basis and cents rounding, rather than
guessing a price from a displayed label.

## Missing exact voucher sequences

`BUY-VOU-004` needs an explicit one-cent-below, exactly-at and one-cent-above
minimum-spend sequence for one eligible selected shop. Verify the specific
shortfall, no applied saving below the minimum, and the exact discount at/above
it. An unselected expensive item or another shop cannot supply the minimum.

`BUY-VOU-008` needs the complete minimum-spend and percentage-cap sequence.
Check the cent below eligibility, a proportional saving below the cap, the exact
cap boundary and amounts above it. Use actual charged sale/option prices, integer
cent arithmetic and a real eligible funded offer. Verify preview, confirmation
and saved amounts agree without changing stock or consuming usage on rejection.

Use project-owned fixtures with derived expected totals. Do not reset live
catalogues or copy private example accounts/products into tracked test data.

## Window and format differences that must remain explicit

The review edit implementation measures its one-edit window from posting.
Creation does not independently enforce an assumed deadline measured from order
completion. Record that distinction while reviewing the policy; do not promote
the already-supported posting-age case into a new active work item unless a real
defect appears or the user selects a different rule.

Likewise, accepted image types and per-field amount/quantity ranges must be read
from their actual project rules. Do not change a shared allowlist or price bound
merely to imitate an unrelated assumption. Changed expectations need an explicit
reason and matching valid, invalid and boundary coverage.

## Method and exit

Keep a per-case record of original requirement, retained rule, aligned expected
result, actual test method/outcome and remaining limits. A rule-aligned automated
pass and the original scenario's strict status are separate records. Full manual
Pass requires every relevant stated outcome and side effect to have been checked.

Extend existing focused buyer voucher, funding, checkout and review tests for
the selected gaps. Add service-level and HTTP checks only where their boundary
differs or a user action can bypass it. Check ownership, invalid input, stale
quotes, rollback, repeat submissions and exact conservation of money. For changed
UI, verify field feedback and run the production build.

Use the real separate finance gates when receipt is tested. Do not release seller
funds or mark a COD order paid simply to obtain a passing label. No new payment
provider, full refund, extra shop, extra voucher slot, automatic completion or
shipping engine begins in this batch.
