# Logistics checkout, network and account repair

Branch: `fix/logistics-checkout-and-network`, based on `origin/main` at `b6c104d`.

## Outcome

The local database initially contained no logistics companies or hubs. Registration alone also cannot provide checkout coverage: an approved company needs an eligible owner, active origin and destination child hubs, and the required Mother Hub parents. Buyer selection continues to enforce these conditions and retains the purchased company, tariff and route.

`RegionalLogisticsDemoSeeder` adds Bagoo Regional Express (`BGR`) without resetting the database or replacing existing account passwords. It creates three Mother Hubs (Laguna, Metro Manila and Apayao), seven children (Los Banos, Santa Cruz, Calamba, Manila, Quezon City, Makati and Calanasan), ten facility operators and 24 assigned couriers/vehicles. Each child has pickup and delivery motorcycles and a transfer van; each Mother Hub has a transfer van. Child capacity is 1,500 parcels and Mother capacity 12,000. These are illustrative software-demo capacities, not measured throughput. Facilities have explicitly labeled demo addresses and no invented map coordinates. The illustrative published tariff is PHP 60 for the included kilogram and PHP 20 per extra started kilogram, subject to the existing actual/volumetric calculation. Weight-priced purchases still require product measurements.

Run `./bagoo.sh seed-logistics` on this branch to add the network. It is an explicit optional seed, so ordinary database seeding retains existing baseline fixtures. First-time rider intake is recorded through the normal intake service; reruns preserve an owner's later pause. Existing ownership/assignment conflicts stop the transaction. The local network was seeded and verified eligible with 24 operational riders; no reset was performed.

The existing shop and buyer address use Calanasan, Apayao with postal code 4012. PHLPost lists Calanasan as 3814: [ZIP-code locator](https://phlpost.gov.ph/zip-code-locator/). Saved addresses were preserved. The seed serves 3814 and does not falsely register 4012 as Calanasan coverage. The user prioritized consistent future behavior over repairing existing test addresses.

Service-region corrections previously rendered as a scalar select and serialized multipart arrays as strings. The form now offers multiple checkboxes, retains selected regions, and sends indexed array fields. History labels show readable regions. The server compares arrays safely and treats reorder-only submissions as unchanged. Valid changes still require the existing admin review, ownership checks and application evidence; account roles remain fixed.

Company setup now explains approval, origin/destination child coverage and Mother Hub requirements so owners can understand why registration alone does not expose a carrier at checkout. The CLI help lists the optional additive seed.

A demo-voucher start one minute in the past blocked database setup under current validation. The seed now starts at the current minute.

## Verification

- Isolated regional seed: 3 tests / 31 assertions, including an unassigned rider applying to and accepting an offer from the seeded company, repeat runs, operational assignments, valid and invalid buyer coverage, and preservation of paused rider intake.
- Checkout/routing/seed batch: 17 tests / 154 assertions before the added intake assertions; the separate current seed result above supersedes its seed count.
- Docker complete rider acceptance and product journeys plus routing, company setup, local flow, Mother Bags and checkout: 43 tests / 1,679 assertions. Includes web and native accepted-rider paths, physical handoffs and retained order completion.
- Docker account corrections, rider requests/offers and voucher seed: 210 tests / 1,888 assertions.
- Final frontend identity corrections, logistics workstation/reliability and rider/hub interaction batch: 41 passed.
- Final TypeScript/Vite production build passed from the final main-based branch (17.85 seconds Vite phase). Output was isolated under `/tmp/bagoo-logistics-final-build`.
- Logistics registration: 25 tests / 198 assertions passed with an independent temporary storage path. An initial concurrent registration run cleared the full suite's fake upload files. The registration assertion captured an unexpected handover-evidence file, and the two simultaneous handover cases returned 404 for missing private evidence. The full affected handover class then passed with independent storage: 54 tests / 4,943 assertions.
- Full logistics backend directory: 858 tests / 49,453 assertions, with exactly the two temporary-file collision failures above (20 minutes 38 seconds; 147.5 MiB peak PHP memory). All remaining cases passed. The complete affected 54-case class passed in isolation; the initial full run is not represented as a clean green run. No unresolved product failure remains from these checks.
- Task tracking could not be updated because its existing authenticated session expired. Repository verification continued.
- No visible browser or phone interaction. Tests use isolated SQLite memory databases; only the explicit additive demo seed modified the development database.

Scoped assessment: region corrections 2/10 to 8/10, based on actual multiple-selection submission and reviewed application tests. Local demo-network availability 0/10 to 8/10, based on eligible routing, assignments and buyer purchase tests. These are engineering assessments of these changes, not measured usability scores or overall production readiness.

## Requirement audit

| Requested outcome | Current evidence |
| --- | --- |
| Seed one realistic company with Mother and child facilities | Local BGR company is eligible; live counts are 3 Mothers, 7 children and 24 operational riders. Seed tests cover counts, handlers, fleet and repeat runs. |
| Buyer can choose the carrier | The checkout HTTP regression displays BGR for a valid route and persists its company on the purchased delivery. Invalid postal coverage and inactive companies are excluded. |
| Future company setup works logically | Fresh logistics registration passes 25/198; owner setup/provisioning is covered in the 43-test journey batch; setup explains the coverage prerequisites. |
| Region updates stop returning array errors | Actual multiple-selection frontend submission and admin correction approval tests pass; list validation and reorder-only rejection remain enforced. |
| Product handoffs and rider applications work | Web/native accepted-rider journeys, pickup, manifest/receipt, local bypass and completion passed in the 43-test batch. The seeded company also accepts a fresh unassigned rider through request, offer and consent. |
| Broader logistics process remains sound | All 858 cases ran; 856 passed and two concurrent-storage evidence failures were resolved by a clean isolated rerun of the complete affected class (54/4,943). The earlier targeted three-case rerun also passed (336 assertions). |

## PR draft

Title: Fix logistics region corrections and add an operational demo network

Body: Logistics account corrections now preserve region lists through the form and review process. Add an optional regional demo company with Mother/child hubs, operators, assigned riders/vehicles and recorded rider intake so buyers can choose a configured carrier. Preserve existing accounts, owner intake decisions and route/approval checks. Correct the past-dated voucher seed that blocked setup.

Validation: focused region, seed, checkout and cross-role logistics tests and the TypeScript/Vite build passed; see results above. No browser or physical-device testing was performed. The draft remains local; no push or GitHub PR was created.

## Local handoff

The original `/home/andy/Projects/bagoo` checkout retains the previous branch and its unrelated uncommitted changes. Logistics source is isolated in `/home/andy/Projects/bagoo/.worktrees/logistics-repair`. Only the additive development seed was applied to the running database; the original web server still serves the original checkout. Use the logistics branch as the source for review/build/deployment. No source deployment, push, or GitHub PR was performed.

Local feature commits: `6dfd075` voucher seed dates; `e5afdf4` region corrections; `62d55b7` operational network and checkout regression; `f48c89a` seeded rider application regression; `7d405e0` owner coverage guidance and CLI help. This audit and the roadmap are committed separately as the verification handoff.
