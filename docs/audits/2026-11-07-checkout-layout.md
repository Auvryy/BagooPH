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

Validation: 85 focused frontend cases and the TypeScript/Vite build; see final result above. No visible-browser or phone check. The draft is local; no push or GitHub PR was created. Source is in the isolated worktree. The local serving follow-up below supersedes the original separate-preview setup.

## Local serving and carrier follow-up

The user's screenshots showed the old checkout served on port 8000 while the new branch was only available on port 8001. An ignored local Compose override now points the existing web service to the feature worktree through its local Nginx configuration. Both ports serve the current compiled checkout; HTTP requests confirmed status 200 and matching latest asset bytes. Original tracked checkout files and unrelated changes were preserved. The local override and runtime configuration are excluded from Git and are not deployment files.

The company existed and was eligible, but the current local shop and buyer address both recorded Calanasan, Apayao with postal code 4012. Following the user's earlier permission to correct those two local test records, only their postal fields were changed to 3814. The recorded physical pins, account/shop approval, products and purchased-order history were preserved. A guarded transaction checked the route and quote before committing; no database reset, new order or fabricated payment was used. Checkout now returns one carrier, Bagoo Regional Express, with an available quote. A real buyer-scoped HTTP-kernel GET with an in-memory session returned Checkout/Index, status 200, one item and that carrier. Login credentials and existing browser sessions were not accessed.

Remove the routine voucher-policy paragraphs entirely, rather than keeping a terms disclosure on checkout. The compact map uses only screen-reader keyboard guidance and no visible tutorial. Unsupported delivery has one concise status; repeated carrier instructions are removed. Blocking errors, accessible map controls and payment checks remain available.

Follow-up verification: 86 frontend cases passed; final TypeScript/Vite production build passed (14.50 seconds Vite phase) and was copied into the worktree's ignored public/build output. Git whitespace check passed. No visible browser or phone was controlled. Source/response assessment for local checkout availability: 2/10 to 8/10, based on correcting the served branch, verifying actual carrier choices and checking the exact assets on both ports. This does not measure subjective visual acceptance.
