# Local pull request draft

This title and body have not been saved on GitHub. Only the user publishes this branch. The branch combines the original audit with its subsequently authorized fixes.

## Title

fix(logistics): resolve browser audit findings and demo readiness gaps

## Body

Counter handover previously put its confirmation button below short screens, first-leg wrong-hub scans linked to an empty recovery list, and hub creation retained stale postal text. Repair those interactions and align buyer/counter guidance with automatic completion while preserving historical receipt instructions. The counter now scrolls its fields inside a labelled, focus-managed dialog with persistent mouse-accessible actions; scanner links target only an observation the current handler can actually read.

Checkout and quote/voucher selection now exclude counters without recorded collection hours and reject stale or unavailable selections before stock reservation. Successful checkout replay retains its original result. Fresh demo seeds include collection hours, a Mother backup van and a destination return van. The additive migration repairs only the exact eligible reserved demo network, preserving passwords, restrictions, assignments, hours and parcel history. Empty installations and unrelated companies are left alone; conflicts fail transactionally. No general fleet-management workflow was added.

Validation:

- Final affected checkout, routing, local delivery, secure counter, replay and demo migration batch: 112 passing tests and 2,900 assertions, using isolated SQLite memory. Recovery coverage separately passed first-leg unchanged custody and authorized observation navigation.
- All 8 frontend scanner checks, the Docker TypeScript/Vite production build, PHP formatting and Git whitespace checks passed.
- The preserved visible Puppeteer session confirmed postal reset, both migrated vans in their actual facility views, wrong-hub guidance without an empty recovery link, dialog/Escape behavior at 1360×650 and 390×650, wrong-code rejection without cash collection and an entire fresh counter order completed through mouse controls at phone width. No keyboard-submit workaround was used.
- The original audit's six completed orders, three seller-received returns, eleven closed manifests and nine received bags are retained with their original conditional-fixture disclosures. Those original multi-leg journeys were not all repeated from an untouched fresh installation after the fixes.

Deployment: publish and merge normally, then use the existing deployment procedure so migrations and compiled assets are applied. The new migration adds reserved demo transport without a full reseed. Existing owners must record their actual child-counter hours through Overview facility selection and Counter Self-Pickup; the upgrade does not invent existing hours. Real companies still need appropriate transport. No Azure deployment, PostgreSQL migration, Flutter change, external seller transfer or physical hardware verification is included in this repair.

The audit report and roadmap contain the remaining verification boundaries. Browser sessions remain open for continuation. Task tracking was unavailable due to expired authentication; no saved board update/time is claimed.
