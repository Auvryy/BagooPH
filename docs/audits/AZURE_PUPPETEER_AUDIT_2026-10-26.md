# Azure Bagoo Puppeteer audit — October 26, 2026

## Result

The Azure deployment was checked in a visible, headed Chrome session with Puppeteer. I opened 109 authenticated role pages: Buyer 14, Seller 26, Los Banos Bayan Hub 11, Laguna Mother Hub 14, Santa Cruz Bayan Hub 11, Rider 6, and Admin 27. All returned HTTP 200 with page content. No visible Laravel/SQL/404 error or captured browser JavaScript exception appeared in those page sweeps. This is page-load coverage, not proof that every action on every page works.

The purchase and logistics checks used existing seeded demo orders. One parcel advanced through the origin hub, Mother Hub, destination hub, destination sorting, rider assignment, and rider departure. Buyer, seller, and rider views agree that it is currently **Out for delivery**. No delivery proof, cash attestation, or buyer receipt was submitted; a real handoff is still required to finish this parcel.

## Functional checks

- Marketplace pagination: clicked the visible Next link and reached page 2 of 2, showing products 19–20 of 20.
- Search: searched for “aero”; the search query remained in the URL and results showed two matches.
- Product detail: the Vanguard page returned 200 and all five displayed product/account images loaded.
- Shopping Bag: added a product, then used Buy again twice from a completed order. The same product stayed in one line and increased to quantity 2 with a ₱5,700 subtotal. Removed it afterward; the Bag is empty.
- Checkout: proceeded to the checkout review and confirmation step with the saved address and COD visible. Stopped before “Confirm & Place Order”; no additional order was created.
- Seller catalog: product listing search returned the matching Vanguard listing; pagination reached page 2. Opened and canceled the new-product form; the catalog remained at 23 listings.
- Seller offers: opened and canceled a new voucher draft; campaign count remained zero. The new promotion form loaded with its required artwork fields; returned to the listing without saving, which remained at zero promotions.
- Logistics: created and dispatched the existing demo parcel through the Los Banos-to-Mother-Hub and Mother-Hub-to-Santa-Cruz manifests, recorded the required hub sort, assigned the Santa Cruz delivery rider, and started final-mile delivery. Vehicle and driver options were present at both manifest legs.
- Hub scan validation: submitted an intentionally unknown QA waybill. The scan request returned 404, but the page stayed usable and showed “Parcel #BGO-QA-INVALID-0001 not found in logistics registry.” The hub queue remained at zero for this parcel, and buyer, seller, and rider pages continued to show the actual demo parcel as Out for delivery. This is a handled invalid-waybill response, not a broken page.
- Hub workstation tabs: Counter Release Desk showed an empty counter queue; Station Audit Log displayed the retained sort and rider-assignment scans for the active parcel; Intake & Sort Station returned to the scanner. All three tabs remained usable and showed no server error.
- Review: opened the review form for an existing completed seeded order, submitted a clearly labelled Azure QA review, and verified the saved 5.0 rating and text on the product page and the seller’s Reviews page.
- Rider proof gate: opened the delivery form for the active parcel. An empty submit was stopped by browser-required fields before any request was sent; the parcel stayed Out for delivery.

The login requests returned HTTP 409 before navigating to the authenticated dashboard, consistent with the app’s Inertia redirect behavior. They did not produce an error page.

## Findings and limits

1. **Completed-order tracking text conflicts with its own timeline.** On seeded completed order `BGO-VMIR2NNK`, the order detail says the parcel holder and handover are not recorded, while the same timeline contains “Doorstep handover recorded” and “Buyer confirmed receipt.” The order is marked completed. This may be stale seeded data, but it is confusing and needs investigation.
2. **Final delivery remains unverified.** The active demo parcel `BGO-CJLQBQOHQP` / order `BGO-F3ZV2SLH` is with the rider and marked Out for delivery. The form requires truthful recipient, relationship, cash received/change, and proof details. No physical delivery happened during this browser check, so I did not invent those facts or submit a synthetic handoff. Buyer receipt and the rating step for this active parcel therefore remain untested; the rating workflow itself was tested on the separate completed demo order.
3. **The QA review remains visible.** The review text explicitly identifies itself as an Azure QA test. The buyer UI offers “Edit review” but no delete action, so I left it labelled rather than replacing it with invented customer feedback. It contributes one five-star verified rating to the seeded product and seller.
4. The delivery form’s proof-photo input and cash-count checkbox were not submitted. The browser’s native validation blocked the empty form on other required fields before server-side proof validation could be assessed.

No extra order was placed, the Shopping Bag was restored to empty, and no code or Azure deployment settings were changed. The browser test used visible-page navigation and button interactions; no curl/API calls were used as substitutes for UI checks. Some text inputs were set through browser input events because keyboard emulation did not populate them reliably in this session.

## October 27 Azure recheck

The latest deployed site was rechecked in headed Puppeteer for buyer and rider actions. Separate headless Puppeteer sessions were used for the seller, logistics, and admin read-only sweeps. These checks do not represent physical fulfillment.

- Buyer order `BGO-F3ZV2SLH` / waybill `BGO-CJLQBQOHQP` remains **Out for delivery** and is still assigned to the delivery rider. The buyer order page shows the ₱2,750 COD amount and the saved buyer destination.
- Opened the rider delivery form. It asks for the actual recipient and relationship, counted COD and change, an attestation, and a JPG/PNG/WEBP proof photo (5 MB maximum). An empty submit was blocked by required-field validation; the order remained Out for delivery. No handoff, cash receipt, or proof was fabricated.
- The separate completed order `BGO-VMIR2NNK` still has its review saved, and its “Edit review” form opens and can be dismissed without changing the review. This confirms the rating UI on an already completed order; it does not complete rating for the active Out-for-delivery order.
- The completed order’s tracking contradiction is still present: its page warns that the parcel handover is not recorded and shows no recorded holder, while the event timeline says “Doorstep handover recorded” and “Buyer confirmed receipt.”
- Rider pages: all 6 inspected pages returned HTTP 200, with no visible Laravel/SQL/404 error or captured page exception. Opening and dismissing the delivery form did not alter parcel state.
- Seller pages: all 26 inspected seller, order, finance, returns, and filter pages returned HTTP 200, with no visible server error or captured page exception. Product search for “Vanguard” found the matching listing. Product pagination advanced from page 1 of 3 to page 2 of 3, and storefront preview advanced from page 1 of 2 to page 2 of 2, with different rows shown.
- Logistics pages: all 15 inspected pages/filters returned HTTP 200, with no visible server error or captured page exception. Santa Cruz’s out-for-delivery hub queue showed zero parcels, consistent with custody already moving to the rider.
- Admin pages: all 39 inspected pages/record links returned HTTP 200, with no visible server error or captured page exception.

**Current outcome:** the purchase, seller, hub, and rider checks show their pages loading; the rating UI works on an existing completed order. The requested end-to-end parcel is not complete: it still needs a real recipient handoff and buyer receipt confirmation before its own rating can be tested. No code or Azure configuration was changed. The report remains a local untracked file.

## Additional buyer checks — October 27

- Opened the buyer address book, Bagoo Wallet, vouchers, order issues, and account settings from the visible sidebar. The pages rendered without a “could not load” or Laravel/SQL error. The wallet balance and request form loaded; no credit request was submitted.
- Order issue filters displayed the five cancelled orders after the async list finished loading. Delivery issue, returned, and return/refund filters displayed their expected empty states. The first quick read happened during “Loading orders”; waiting for completion confirmed the cancelled records appear.
- The account-settings page shows Personal info, Email addresses, Password, Identity correction, and Login activity sections. Clicking the Login activity or Identity correction sidebar link updates the active highlight but leaves the target section far below the viewport. Scrolling manually brings the section into view and updates the highlight. Opening the corresponding URL fragment directly also aligns the section. This is a real click-to-scroll failure in the buyer settings navigation; no profile data was edited.
- The Login activity and signed-in sessions content loaded. No session was signed out and no sign-in/security settings were changed.
- On the active order detail, clicked Refresh tracking. The browser received HTTP 200, the “Last checked” time advanced, and the order remained Out for delivery with no error message. Expanded and collapsed the journey’s “See more” section; the nine earlier checkpoints appeared and were hidden again without changing parcel state.
- Purchase-stage filters also worked: All (16) paginated as 12 orders on page 1 and 4 on page 2; To Ship showed 6, In Transit 4, Completed 1, and To Pay/Delivered showed their zero-order empty states. Clicking Next reached page 2 of 2. No filter or pagination error appeared.
- Rechecked the active product detail: all five rendered images finished loading after a short wait, and no page error appeared. The buyer Returns and refunds page also loaded with its expected “No return requests” state; no request was created.
- Public parcel tracking: submitted the current waybill through the visible Track parcel form. It showed Out for delivery, a protected delivery area, and no delivered timestamp, consistent with the buyer and rider views. Submitting an unknown QA waybill showed a clear “Parcel not found” message with instructions to recheck the number; no server error or data change occurred.
