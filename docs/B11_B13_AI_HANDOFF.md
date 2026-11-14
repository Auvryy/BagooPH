# B11-B13 handoff for the next AI

> **Completion update (November 14, 2026):** All remaining items listed in this handoff (Steps 1–6) have been completed, verified locally, and committed on `feat/brainstorm-b11-b13-returns-refunds` (branch remains unpublished; no push performed; `B14–B17` kept strictly out of scope). See [CORE_FLOW_ROADMAP.md](CORE_FLOW_ROADMAP.md#complete-remaining-b11b13-scope-hardening-policy-v2-activation-and-integrated-verification--november-14) and `/home/andy/Downloads/BAGOO_B11_B13_MANUAL_TESTING_GUIDE.md` for final verification counts, deploy/migration steps, and manual QA instructions.

Snapshot: **November 14, 2026, 10:31 Asia/Manila**. Originally prepared at the user's request to stop application coding, explain the work, and pause the goal. This historical snapshot plus its completion checkboxes is retained for traceability; [CORE_FLOW_ROADMAP.md](CORE_FLOW_ROADMAP.md) remains the living implementation/evidence record.

## What we are building

The selected batch is **B11 + B12 + B13**, on the existing BagooPH web/backend project. It connects a purchased product's return decision, actual reverse transport and correctly funded financial outcome. It extends the existing application; it is not a rewrite of the three apps.

The normal buyer purchase, seller fulfillment, pickup, Mother Hub transport, delivery, rating and separate COD handover remain the ordinary process. The old B01-B08 manual guide still describes that normal journey; it does not describe all the new return/loss branches.

| Package | Purpose | Required boundary |
|---|---|---|
| B11 | Buyer return requests, original item quantities, seller decisions, appeal/escalation and independent fault findings | Approval is neither a parcel receipt nor a refund. Preserve original purchased units and private evidence. |
| B12 | Actual reverse custody, measured packages, sacks, compatible shared vehicle trips and wrong-hub recovery | Each parcel keeps its own direction, barcode, route and individual receipts. Grouping or opening a sack does not receive its contents. |
| B13 | Original entitlement, partial/voucher allocations, actual COD backing, Wallet reserves, refusal/loss outcomes and adjustments | A decision does not create funds. Refund once from actual backing, preserve earned shipping and original proceeds, and keep an advance separate from company debt. |

Read the authoritative [build order](brainstorming/BUILD_ORDER.md), [B11 contract](brainstorming/B11_RETURN_REQUESTS_AND_REVIEW.md), [B12 contract](brainstorming/B12_REVERSE_TRANSPORT_AND_SHARED_TRIPS.md), [B13 contract](brainstorming/B13_REFUND_EXECUTION_AND_FUNDING.md) and [B04 money decisions](brainstorming/B04_MONEY_AND_LIABILITY.md). Do not derive new policy from this handoff or historical PDFs.

The full goal is still unfinished. The earlier **25-30% remaining** statement was an informal engineering estimate, not measured test coverage or a completion guarantee.

## Repository and saved state

- Repository: `/home/andy/Projects/bagoo`.
- Branch: `feat/brainstorm-b11-b13-returns-refunds`.
- Last committed application HEAD: **`940679c`**.
- Branch base used for this batch: **`8570c9c`**, the merged B09/B10 main snapshot. This handoff did not fetch or establish today's remote-main freshness.
- **57 local commits** from that base through `940679c`. They divide implementation, interfaces, validation and documentation into concerns; they are not 57 independent new features.
- No push was performed for this batch. Do not push to main. Follow `AGENTS.md`, including the requirement for fresh explicit push authorization.
- The application worktree is deliberately dirty: the platform-source restoration draft below is not committed or fully verified. Preserve it. Do not reset, clean, switch branches, stash-pop or rewrite history as part of reading this report.
- Thirteen additive branch migrations exist. This agent applied none to the live local database. The live applied/pending migration status was not rechecked for this handoff. Isolated builds did not replace the running application's assets.
- No visible browser, connected-phone/ADB operation, mobile repository edit, live database reset/seed or external payment execution was performed by this work.

For the complete commit history, inspect `git log --reverse --oneline 8570c9c..940679c` locally.

## Implemented and saved, with scoped automated evidence

### B11 review and original claims

- Original purchased unit claims, bounded return reasons/evidence, seller review and buyer escalation/appeal retain their own history.
- Independent seller/courier fault findings are separate from authorization, physical custody and money.
- Once a repayment basis or an active original loss authority is approved, ordinary fault review cannot silently replace that basis.
- Original payment/policy versions and successful command retries remain retained.

### B12 actual custody and transport

- Compatible sealed forward, RTS and buyer-return manifests can share a retained vehicle trip without merging their identities.
- Actual return-package weight/dimensions, capacity checks, typed return sack membership, numbered seals and individual receipts are connected to operator controls.
- Wrong-hub and corrective recovery retain original and corrective histories. Recovery uses its reviewed dedicated transport rules.
- Detached forward recovery preserves the original bag and vehicle responsibilities. A recovered parcel cannot fabricate arrival of its earlier outer sack.
- Independently confirmed losses can count as accounted losses, separately from actual receipts. Missing members retain `received_at = null`.
- Actual original vehicle arrival has a separate assigned-handler command. It receives no parcel or sack, moves no money and cannot stand in for a lost vehicle that never arrived.
- Private loss-accounting financial reasons stay out of floor manifest history.

### B13 money and public controls

- Original-cent allocations support partial item and buyer-paid shipping refunds while retaining voucher and earned-shipping treatment.
- Successor post-delivery COD repayments use actual original collection/reconciliation, acknowledged backing, the original buyer and a once-only repayment. Payment receipt/nonreceipt review remains separate.
- Wallet refunds use balanced, case-owned reserve entries. Original escrow, liable-party credits and explicit virtual advances are separate sources.
- Reviewed funding credits are issued to the original liable account only after independent review. Issuance is not a reserve contribution or debt payment.
- Conforming refusal/RTS outcomes preserve spent outbound shipping and evidenced reverse charges; unpaid COD does not report a buyer cash refund.
- Original transit-loss reviews, private evidence, operational dispositions, funded buyer refunds and separate seller protection are connected to public role-scoped pages and actions.
- Original orders, Wallet and seller proceeds link to their financial reviews. Multiple partial-return claims keep separate references and a bounded preview with complete scoped history access.
- Seller protection approved and seller protection actually credited are displayed separately from the buyer's refund amount.

### Later-found parcels: the part we were finishing

A parcel can be found after being independently reviewed as lost, possibly after compensation was paid. Ordinary receiving must not silently resume that parcel and duplicate its money outcome.

Saved work now:

1. An assigned handler records the actual original waybill and private finding evidence into quarantine. Original missing receipts, route position, payments and transport obligations remain retained.
2. New ordinary funding, grants, compensation and onward movement are held. Exact saved retries and acknowledgement of actual previously recorded transfers retain their own rules.
3. The original company owner can voluntarily return an original reviewed grant's actual unused credits to their original demo issuance. This does not pay debt, retire an advance or release the parcel.
4. Before any loss compensation is paid, the owner can restore an actual unused original company reserve contribution to its own Wallet. The exact consumed grant positions are restored; unrestricted credits remain unrestricted. Another case's grants and reserves stay separate.
5. Received company funding is netted after restoration, so returning money does not forgive the original liability. Original escrow and actual advance retirement are preserved.

Recent saved commits:

| Commit | Subject |
|---|---|
| `0c4de16` | feat(finance): return unused reviewed loss credits to original issuance |
| `d8a3743` | feat(finance): show explicit consent for unused grant returns |
| `8b82860` | feat(finance): restore unused company loss reserve contributions |
| `940679c` | feat(finance): distinguish restored contributions from grant returns |

## Exact unfinished draft at the pause boundary

The current uncommitted draft adds **`return-platform-source`**, a Wallet-only admin action after an evidenced finding and before loss compensation has been paid. Its intended behavior is to restore unused original escrow to escrow and only the unused portion of an actual demo advance to demo issuance. It must not credit a buyer, erase an earlier advance retirement, forgive debt or release property.

| Draft file | What changed |
|---|---|
| [WalletLossPlatformReturn.php](../app/Services/Wallet/WalletLossPlatformReturn.php) | New, untracked service: original source/event/journal checks, chronological advance consumption, source selection, current admin/version/consent, exact retry and balanced return. |
| [WalletRefundReserveEntry.php](../app/Models/WalletRefundReserveEntry.php) | Additional guards for negative `original_escrow_return` and `found_advance_return` rows. |
| [ShipmentLossActions.php](../app/Services/Finance/ShipmentLossActions.php) | Defines and dispatches the new Wallet action. |
| [ShipmentLossControls.php](../app/Services/Finance/ShipmentLossControls.php) | Offers current eligible original platform sources to an admin; keeps ordinary finding holds. |
| [WalletLossReserve.php](../app/Services/Wallet/WalletLossReserve.php) | Nets escrow restoration, distinguishes returned advances from prior retirement and calculates remaining backing. |
| [WalletReturnReserve.php](../app/Services/Wallet/WalletReturnReserve.php) | Global original-escrow reservation sum includes actual escrow returns. |
| [WalletSettlementService.php](../app/Services/Wallet/WalletSettlementService.php) | Original-escrow debit sum includes actual escrow returns. |
| [ShipmentLossGrantReturnTest.php](../tests/Feature/Finance/ShipmentLossGrantReturnTest.php) | One new connected platform-restoration test and input helper; earlier saved grant/company-source cases remain. |

One actual connected positive case passes: restore company funding, return its unused reviewed issuance, restore the remaining PHP 38.33 advance after an actual PHP 150 retirement, and restore PHP 250 original escrow. It checks that the buyer balance, physical quarantine and prior retirement are unchanged, and that the reserve is empty afterward. The prior attempt failed with a real 404 before the action existed.

**This is a prototype checkpoint, not a finished source-return feature.** After this latest draft, there is no new broad regression, complete rejection/rollback matrix, Pint, frontend/type/build acceptance or tailored platform-return consent/history/Wallet labels. The generic shared form may render the action, but that is not interface acceptance. Add multiple-advance FIFO, foreign-source, role/version/nonce/consent, missing-evidence, paid-outcome and rollback cases before accepting it. Recheck global escrow consumers after an independently authorized successor outcome exists; the current positive case still has an active loss authority.

## Verification that actually exists

All PHP evidence below uses Docker PHP 8.4 and isolated SQLite `:memory:`. These are overlapping targeted gates, not additive counts or a passing full repository suite. Logs/XML are in ignored local `storage/framework/testing/`; another checkout may not have them.

| Scope | Observed result | Local evidence prefix |
|---|---|---|
| Normal purchase through delivery/completion and owned rating, with review integrity/adversarial cases | 48 tests / 1,075 assertions passed | `b13-demo-delivery-rating-pass` |
| Operational loss accounting, recovery, bags/trips, tracking and normal commerce | 248 tests / 28,039 assertions passed | `b13-accounting-operations` |
| Original financial consumers, review/Wallet/return/exception integration | 171 tests / 14,196 assertions passed | `b13-consumers-integrated-final` |
| Later final consumer/cash coverage | 34 tests / 2,852 assertions passed | `b13-consumers-cash-final` |
| Grant/company-source restoration with existing loss HTTP, ordinary return repayment and Wallet money | 104 tests / 10,773 assertions passed | `b13-company-source-integrated` |
| Final saved grant/company-source cases after provenance hardening and expanded cross-case rejection | 16 tests / 1,088 assertions passed | `b13-company-source-reviewed` |
| Saved grant/company-source controls, outcomes and Wallet interface | 36 frontend tests passed; TypeScript passed; isolated build passed in 15.65s | `b13-company-source-frontend`, `b13-company-source-types`, `b13-company-source-build` |
| Latest uncommitted platform-source prototype only | 1 test / 62 assertions passed; 4.24s | `b13-platform-source-first` |

Final Pint passed for the saved company-source work (`b13-company-source-pint-final`). That result predates the platform-source draft. Existing failures and repairs remain documented in the roadmap; do not call a stopped or initially failing command green.

At handoff, `docker top bagoo_app` showed PHP-FPM only: **no PHP test runner remained active**. Do not restart an imagined previous run. No browser/phone, live PostgreSQL, physical parcel or real-bank acceptance is established by these results.

## Remaining work toward the full goal (completed in local follow-up commits)

All required B11–B13 items below are now implemented and verified locally (`B14–B17` remain strictly out of scope):

- [x] Finish and verify the uncommitted unused escrow/advance restoration draft, including its public interface and complete source accounting (`08850e3`).
- [x] Complete found-parcel reconciliation for already paid outcomes and actual COD sources (`887d89e`). Retain paid buyer refunds, seller protection, earned pickup and actual transfer history. No unilateral beneficiary Wallet debit, duplicate buyer repayment or invented cash receipt. Any voluntary return needs the original participant's authority and real retained source evidence.
- [x] Implement independent, source-aware reconciliation approval and actual authorized release from quarantine (`887d89e`). Preserve original fault findings, claimed units and money snapshots without using a generic unfunded void or ordinary recovery receipt to erase original loss or invent historical arrival.
- [x] Ensure historical loss accounting remains valid through a legitimate reviewed successor movement (`887d89e`). Record actual new-leg custody separately without fabricating missing-member receipt; invalidate stale release authority when source or payment state changes.
- [x] Finish independent whole-transport-loss accountability when the original vehicle/sack never actually arrives (`327df3c`: `WholeTransportLossService`, `WholeTransportLossConfirmation`, physical bag-tag/unarrived-bag loss locking).
- [x] Finish native/API and browser privacy (`9f3aa76`: `EncryptsSensitiveHistory`, `HandleInertiaRequests` history reset safeguard, `auth.user?.id`-bound financial form resets & tokens, non-empty legacy `blockers` strings on active loss return DTOs without modifying mobile repos).
- [x] Complete integrated operational/money/privacy acceptance and activate refund policy two for future checkouts (`CheckoutQuoteService` updated to `'refund_policy_version' => 2`, `BuyerReturnAmounts::shipping()` / `shippingForCase()` supporting both v1 and v2 while isolating `original()`/`forCase()` to historical v1 orders).
- [x] Finish the combined manual testing guide in Downloads (`/home/andy/Downloads/BAGOO_B11_B13_MANUAL_TESTING_GUIDE.md`), check off B11–B13 in [BAGOO_PH_BRAINSTORMING_CHECKLIST.md](brainstorming/BAGOO_PH_BRAINSTORMING_CHECKLIST.md), and prepare the final PR title/body/deployment guidance in the final response.

## Practical continuation order

1. Read `AGENTS.md`, the local ignored task guide, this snapshot, the B11/B12/B13 contracts and the current roadmap. Inspect actual Git status and preserve the draft.
2. Finish unused Wallet platform-source restoration before treating any found reserve as reconciled. Keep original escrow, company contribution, reviewed grant and advance return separate.
3. Complete paid-compensation and actual cash reconciliation, with explicit entitlement and source checks.
4. Add independent reviewed reconciliation and the correct actual physical release/next leg. Preserve original loss accounting, historical source evidence and all outstanding bag/vehicle responsibilities.
5. Finish whole-transport-loss accountability and final browser/native privacy/compatibility checks.
6. Run the required integrated gates, review original-cent conservation and partial claims, activate only eligible future purchased terms, and prepare the combined manual/PR handoff.

Work only within the authorized web/backend scope. Keep tests isolated; do not reset or migrate the live database while preparing code. Do not inspect private configuration/secrets, consume paid APIs, control the screen or use the connected phone. Local small logical commits are allowed; publication follows the user's explicit authorization and repository rules.

External task tracking last returned an expired-session error. No task update, completion or measured time record was claimed as saved. That did not block independent local work.

## Why the batch took longer than expected

The normal delivery flow was not the slow part. The remaining work connected three dependent packages and exposed cases where physical loss, actual money already paid, later findings, original grants, reserves and transport responsibilities must remain consistent. The work was kept in one large continuous batch for too long instead of providing clearer bounded checkpoints. The next AI should use the explicit remaining list and saved evidence, avoid rebuilding completed foundations, and report concrete checkpoints without treating a percentage estimate as proof of readiness.

Application implementation, verification, and documentation updates for B11–B13 are now saved locally on `feat/brainstorm-b11-b13-returns-refunds`; **no `git push` was run** (awaiting explicit user authorization to publish or open the PR), and separate live PostgreSQL migration, build/asset publish, mobile device checks, and user manual testing remain with the user.
