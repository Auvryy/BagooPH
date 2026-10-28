# Local logistics browser audit — October 28, 2026

The original delivery audit reached six completed orders and three seller-received returns, recording four UI issues and three default-setup blockers. Its original evidence is preserved below. The subsequently authorized repairs and their separate verification are recorded under [Follow-up repairs](#follow-up-repairs); the branch now includes those fixes as well as the audit.

## Environment and method

Baseline: merged New Docs Era changes on `main`, commit `593b726`. Visible Chrome/Puppeteer opened the existing development portal on localhost port 8000: login, dashboard and eleven logistics navigation destinations rendered without a missing-page or Laravel server error.

Purchases and operational mutations used a separate localhost port 18828 runtime with the same application code and compiled assets, PHP 8.4, and guarded persistent SQLite `:memory:`. Synthetic demo accounts/products, private uploads and test email capture were isolated. PostgreSQL was not reset or reseeded, and no audit purchases or parcel transitions were written there. Ordinary development-site login still produces normal session/sign-in records.

Business transitions used real browser controls: checkout, seller actions, rider scans, hub confirmations, bag packing, manifest departure/receipt, proof uploads and cash acknowledgements. Read-only state inspection supplemented rendered evidence; it did not advance parcels. Extra transport and counter-hour prerequisites are disclosed below. No curl or direct business API calls substituted for these UI journeys.

Chrome, its authenticated role tabs, the interactive audit worker and the memory server remain running for continuation. Ignored checkpoints and resumption instructions are under `.codex/logistics-visible-audit/`. Later controls omit desktop focus/bring-to-front calls so testing can continue while the user works elsewhere. A rate-limit interruption is not an instruction to close or restart these processes. The memory database would be lost if its server or computer stops.

## Completed journeys

Hub IDs in this isolated run: 1 Los Banos child, 2 Laguna Regional Mother, 3 Santa Cruz child, 4 newly created Audit Mother, 5 newly created Audit Pagsanjan child.

| Order | Scenario | Result |
| --- | --- | --- |
| BGO-ARAHAZD2 | Regional COD, route `[1,2,3]` | Completed automatically after recipient, proof and original COD; hub acknowledged full remittance. |
| BGO-2FHNYWP0 | Second parcel sharing both regional bags/manifests | Completed and remitted; individual receiving never moved the other parcel prematurely. |
| BGO-U6WTV3RP | Standard shipping, same-child route `[1]` | Completed and remitted without creating a Mother Bag or manifest. |
| BGO-MRJHLBQK | Customer refusal, reverse route `[3,2,1]` | Actual reverse transfers, origin staging and seller return receipt reached Returned. |
| BGO-TLDEHQOI | Three genuine failed attempts with reviewed retries | Future scheduling and physical restart passed; third failure created one reverse route, ending with seller receipt. |
| BGO-QTZBKDKR | Wrong-hub scan during an outstanding transport leg | Authorized recovery travelled `[1,2,3]`; original bag/manifest receipts reconciled, followed by normal delivery and remittance. |
| BGO-MCVJUHSO | Same-child refusal | Local return and seller receipt reached Returned without transport records. |
| BGO-6CNX6RB1 | Local counter pickup, PHP 2,850 original COD | Wrong private code rejected; valid identity/code/cash completed automatically. Required hours fixture and keyboard workaround are disclosed below. |
| BGO-PPI0RMNR | Wallet-paid route `[1,2,4,5]` | Origin feeder, Mother-to-Mother wing-truck leg and destination feeder all received/closed; newly provisioned rider completed with original prepaid evidence and no COD account. |

## Functions and rejection checks

| Area | Observed browser evidence |
| --- | --- |
| Company setup | Mother/child creation, required-field checks, duplicate ZIP rejection, parent assignment and saved/reopened barangay coverage. |
| Named work accounts | Operator/rider creation, underage rejection, generated temporary credential, owner-password reset guard, forced password replacement, email verification and assignment acceptance. An ordinary handler could not access owner setup. |
| Email and role separation | Same-role case-equivalent duplicate rejected; separate logistics/courier accounts with the same normalized synthetic email activated and signed in to their correct portals. Rider assignment excluded Mother Hubs. |
| Rates and quotes | Repeated future-tariff saves and negative-value rejection; older quotes remained unchanged. Actual 1.1 kg versus 2.5 kg volumetric weight yielded PHP 80 standard shipping from PHP 70 plus two started additional kilograms at PHP 5. |
| Pickup and custody | Rider claim/waybill confirmation, mismatched barcode rejection, actual origin intake and destination sorting. A busy delivery rider could not take another simultaneous assignment. |
| Mother Bags | Unknown/duplicate parcel rejection, required seal, reasoned removal/re-add, reopen requiring a new seal, retired/wrong seal rejection, matching master tag, whole-bag load/unload/reload and atomic departure. |
| Transport receipt | Seal verification before individual receiving; wrong seal and duplicate receipt rejected; partial receipt moved only that member; complete receipt allowed closure. Both required Mother Hubs were retained on the four-hub journey. |
| Delivery evidence | Missing proof and incorrect net COD rejected; valid recipient, synthetic proof and original payment completed version-two orders automatically. |
| COD and duty | Held cash prevented duty closure. A rider offer alone did not release delivery earnings; a named-hub counted receipt did. Shift closure/new shift and prepaid closure were exercised. |
| Recovery and returns | In-transit wrong-hub observation, company-owner authorization, both real recovery legs and linked original receipts; refusal and three-attempt reverse journeys; ordinary handler could inspect attempts but not approve retries. Past dates and premature restart were blocked. |
| Counter | Required operating hours, wrong-code rejection without cash creation, identity and original COD checks, consumed collection and buyer reflection. The owner subsequently changed child-counter hours through the existing UI and verified persistence; blank hours were rejected. |
| Wallet prerequisite | Buyer credit request did not increase balance. Admin acknowledgement was required before approval. Explicit payment deducted PHP 2,910 once from PHP 5,000; reopening the paid page kept PHP 2,090. No external money was involved. |
| Other navigation | Resource activity, Governance history, Delivery exceptions and Fleet Management links; fleet tier/status filters; selected-facility dashboard; current-custody registry search/type filters with a truthful empty result after terminal orders. |
| Hub hostname | Actual sign-in and sidebar links opened Company setup, Mother Bags, Manifests, Scan Station and Delivery earnings on `hub.localhost`. |
| Responsive controls | Company setup, selected bag, selected manifest, scanner, earnings and counter fit document widths at 1360×650 and 390×650. Mobile drawer opened and closed with Escape; staff and rate tabs remained usable. These page-width passes do not erase the handover-modal issue. |

## Fix list

| ID | Severity | Observed problem | Recommended bounded change |
| --- | --- | --- | --- |
| UI04 | Medium | At 1360×650, valid Confirm Handover was below the viewport at y710–738. Its fixed centered counter modal has no bounded height/internal scrolling; mouse confirmation timed out. | Give the labelled dialog a viewport-based maximum height, internal scrolling and an accessible close/submit path. Source: `resources/js/Pages/Hub/CounterPickup.tsx`, release modal around line 445. |
| UI02 | Medium | A wrong-hub scan after pickup but before the first manifest offers Open Parcel recovery, yet Needs attention and All observations are empty. | Record an actionable first-leg observation or show accurate pickup-specific recovery guidance. The original pickup courier can still bring the parcel to the correct origin. |
| UI01 | Low | After creating a child hub, Add hub displays the previous postal text although the submitted form data was reset. | Reset the separate `postalText` state on successful creation in `resources/js/Pages/Hub/CompanySetup.tsx`. |
| UI03 | Low | Counter guidance says the buyer confirms receipt afterward, while the audited version-two collection completed automatically. Buyer detail also retains receipt-confirmation wording. | Make guidance reflect the saved version-two flow while retaining historical version-one rules. |

The counter code/payment test used actual focus and Enter after recording UI04. That is a disclosed keyboard workaround, not a passing mouse-accessibility result. Private claim codes and verification links are confined to ignored local artifacts and are not included here.

## Default-setup blockers and conditional passes

| ID | Observed prerequisite gap | Continuation and interpretation |
| --- | --- | --- |
| DATA02 | The seeded Mother Hub has no spare available carrier while its original outbound manifest is still outstanding during recovery. | A labelled backup van/driver was added only to memory. Successful recovery is conditional on suitable available transport. |
| DATA03 | The seeded Santa Cruz destination has no closed van/driver for reverse transport. | A labelled return van/driver was added only to memory before the real reverse UI journey. |
| DATA04 | The seeded Los Banos hub offers self-pickup at checkout but has no recorded collection hours, so later staging is blocked. | Initial continuation used an isolated hours fixture. A follow-up browser check confirmed the existing remedy: owner Overview → select child facility → Counter Self-Pickup → Save pickup hours. Hours are editable; the gap is default readiness and offering pickup before configuration. |

The six explicitly labelled additional transport vehicles/drivers also supplied wing-truck and feeder prerequisites for newly created hubs 4 and 5. Their setup did not change orders, custody or manifests. Fleet Management's exercised screen lists/filters resources; this run did not establish an owner UI for provisioning these transport vehicles. Those conditional results must not be described as an untouched fresh-seed network passing every exception or line-haul journey.

The existing development database has a historical synthetic company owner separate from the fresh-seed owner. Its Mother Hub handler correctly lacks owner-only Company setup. Do not reset or reseed that database to make its ownership match a new installation.

## Original audit evidence and limits

The final browser run recorded 224 checkpoints: 217 passed, two failed, three were blocked in the default setup, and two desktop-focus observations remain harness reviews because the source of active-window changes was not established. These are audit checkpoints, not 224 independent automated test cases. Low-severity findings were recorded separately; the four UI issues remain unfixed.

Final read-only checks confirmed six paid/completed orders, three seller-received returns, eleven closed manifests and nine received bags. All thirty earning entries were released. Completed-order allocations exactly conserved their original PHP 17,420 total; three returns retained only their previously earned PHP 10 pickup shares, with no product/delivery reward or COD account. All five COD accounts had counted original cash at their receiving hubs, zero rider-held balance and no pending offer/discrepancy. Platform reconciliation and actual seller transfers were not performed. All three virtual journal transactions balanced; the buyer retained PHP 2,090.

Every buyer order detail rendered its final outcome. Seller orders retained prepaid completion and return records, the populated seller dashboard and company earnings page rendered, and both remaining operational couriers closed duty after acknowledged remittance. No JavaScript exception or unexpected server error was captured on the monitored role pages. Earlier harness sizing, selector and read-only state-serialization interruptions were corrected and resumed; they are not reported as application defects.

Browser checkpoints retain the checks, findings, screenshots, final state and role-tab identities locally. No application fix, Azure deployment, Flutter edit or publication is included.

This run does not prove simultaneous PostgreSQL behavior, load limits, real email delivery, physical cash/seals/parcel handovers, camera/GPS hardware, actual seller bank transfers, or every unrelated governance/payment workflow. Master-tag printing was not invoked because its native print window would interrupt the user's workspace. A released earnings ledger is not proof of an external payout; virtual credits remain website-only records.

Task tracking was unavailable because its authenticated session had expired. No saved board update or measured board time is claimed. Application builds/PHP regressions from the implementation branch are historical evidence; they were not rerun for this documentation-only audit.

## Follow-up repairs

After the audit, the user authorized fixing its findings on the same local branch. The four UI issues are repaired and were rechecked in the preserved browser session without desktop focus commands:

| Finding | Result after repair |
| --- | --- |
| UI01 | Creating another child hub and reopening Add hub showed an empty postal field. Failed forms still retain useful input. |
| UI02 | A fresh pickup scanned at the wrong child hub named its actual origin and kept pickup custody, with no empty recovery link. Recorded in-transit observations instead supply their own authorized recovery URL; backend coverage also opened that specific page. |
| UI03 | Counter guidance now states the actual identity/code/payment checks. The owning buyer saw automatic-completion wording after collection; historical-order wording remains conditional on its saved flow version. |
| UI04 | A labelled Headless UI dialog now bounds its height, scrolls its fields and keeps Cancel/Confirm Handover visible. Both 1360×650 and 390×650 checks passed; Escape/reopen worked. A wrong code still failed without cash collection, and the correct code completed a new order through actual mouse clicks at phone width. No keyboard-submit workaround was used. |

The new counter order, BGO-EKH1MDXO, went through buyer checkout, seller confirm/pack/ready, pickup claim/scan, the deliberate wrong-hub check, correct origin intake/staging, one-time buyer code and original COD collection. It completed automatically. Previous orders, bags and manifests were preserved when applying the additive migration to the memory runtime. The operational rider closed duty afterward.

Default readiness also changed. Fresh demo seeds provide collection hours plus a separate Mother backup van/driver and Santa Cruz return van/driver. An additive migration, `2026_10_28_000000_complete_reserved_demo_transport`, upgrades only the exact eligible reserved demo network. It preserves existing passwords, restrictions, assignments, hours and custody history, rejects conflicting resources transactionally, and leaves unrelated owners/empty installations alone. The actual scoped fleet screens displayed both migrated vans. Real companies still require their own correctly configured transport.

Unconfigured counters are excluded from checkout and quote/voucher selection, with server-side rejection before stock or an order is reserved. New route assignment rechecks readiness under network locks. Collection-hour changes invalidate an unsubmitted checkout review; replay of an already successful checkout keeps its original result. Existing counter hours are never invented by the upgrade. An existing owner can record them through Overview → select facility → Counter Self-Pickup → Save pickup hours.

Verification: the final affected checkout/routing/local-delivery/secure-counter/replay/demo-upgrade batch passed **112 tests / 2,900 assertions** in isolated SQLite memory. A final seed/upgrade check passed **13 tests / 93 assertions**, including preservation of customized counter hours on repeat seeding. The separate recovery suite passed, including unchanged pickup custody and authorized observation navigation. All **8 frontend scanner checks** passed. The Docker TypeScript/Vite production build passed; host output permissions required using Docker. PHP formatting and Git whitespace checks passed. Earlier test fixtures were adjusted to configure hours before checkout and to test hours removal after a legitimate purchase; no rejection was weakened to obtain a pass.

Scoped counter-handover usability is assessed at **4/10 before → 8/10 after**, supported by reachable mouse controls, independent scrolling and intact code/payment checks. This does not raise whole-project readiness. The original multi-leg return/recovery run remains historically conditional on its disclosed fixtures; it was not repeated from an untouched fresh database after these repairs. No Azure deployment, external payment, Flutter edit or physical-device claim is included. Browser sessions remain open. The combined PR description is a local draft.
