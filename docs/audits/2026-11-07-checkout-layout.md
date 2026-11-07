# Minimal buyer checkout

Branch: `feat/checkout-minimal-layout`, based on the completed local logistics branch `fix/logistics-checkout-and-network` at `23b289e`. The original checkout remains preserved in its separate working directory.

## Requested layout and behavior

- Desktop uses a seven-column delivery/payment area and a five-column order summary. Product images, names, chosen variants, quantities and quoted prices begin at the top of the right column. Products, vouchers and the final price occupy one restrained card with shared spacing, dividers and Bagoo crimson accents. Small screens stack the columns.
- Complete saved addresses start as a single truncated summary row with the full address in its title and accessible text. Edit opens the existing controls; Done collapses them. New or incomplete addresses open immediately. Fields remain mounted so their values survive collapse. Server address errors and native required-field errors reveal the editor; native validation focuses the first invalid field after reveal.
- Remove the introductory instructions, duplicate carrier panel, routine successful-quote message and repeated selected-carrier name. Keep a carrier logo beside the selector. Use concise section labels and compact payment choices. The Wallet balance and virtual-credit nature remain visible.
- Move optional shop pricing/shipping calculations and voucher policies into disclosures. The final amount, discounts, blocking errors, quote/loading failures, KYC requirements and confirmation remain actionable. Map keyboard instructions remain available to assistive technology without a visible paragraph in checkout; map loading/failure feedback remains visible. Existing map ResizeObserver handles an editor being revealed.

## Evidence and limits

Final frontend batch: 85 passed, covering address expand/collapse and retained values, new/error states, required-field reveal and focus, rendered product/price placement, vouchers and stale totals, GPS, Shopping Bag, Wallet and map interactions. The new disclosure regression explicitly checks that a burst of native invalid events focuses the first field.

Final TypeScript/Vite production build passed (12.71 seconds Vite phase), with isolated output under `/tmp/bagoo-checkout-layout-build`. Git whitespace check passed. No backend or database code changed. Browser/phone screenshots and live visual acceptance were not performed under the repository's routine-testing rule; layout evidence is source inspection, rendered markup, interaction tests and build output. Task tracking remained unavailable because the authenticated session was expired.

Scoped checkout clarity assessment: 5/10 to 8/10, based on fewer always-visible explanatory blocks, a compact address entry and product/pricing grouping. This is an engineering assessment, not a measured usability score or a claim of visual acceptance on every viewport.

## Local PR draft

Title: Simplify buyer checkout and group products with the order summary

Base: `fix/logistics-checkout-and-network` (or main after that branch is merged).

Body: Checkout now shows products and prices together at the top right and uses one clean order-summary card. Saved addresses expand only for editing, missing details or validation feedback. Remove repeated instructional copy and carrier panels, shorten payment choices, and reveal optional calculation/policy details on demand. Retain required checkout validation and payment controls.

Validation: 85 focused frontend cases and the TypeScript/Vite build; see final result above. No visible-browser or phone check. The draft is local; no push or GitHub PR was created. Source is in the isolated worktree, so the original running web server still serves its original checkout until the intended branch is used for deployment or local serving.
