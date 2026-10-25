# Local Bagoo Puppeteer audit — October 26, 2026

## Result

The local seeded application completed the full buyer order journey in the visible browser: a buyer placed a COD order, the seller prepared it, the pickup rider collected it, both hub legs were received and sorted, the delivery rider recorded delivery proof, and the buyer confirmed receipt and saved a product review. The order detail page showed the completed timeline and all 16 journey checkpoints. The proof image was explicitly synthetic test evidence; no physical handoff or real cash exchange is claimed.

The buyer order was **BGO-DNK5C8TX** for the Aero Graphite Weatherproof Roll-Top 26L at **₱2,750**. Its tracking reference was **BGO-IYQKOXXRME**. The buyer order detail showed “Order Completed,” “Buyer confirmed receipt,” and “Review saved.” The seller dashboard reflected ₱2,750 in completed sales and an estimated ₱2,475 seller share, and the seller review page showed the verified review.

## Role page coverage

Pages below were opened in headed Puppeteer against the local Docker site. Each route returned HTTP 200 with page content and no visible Laravel/SQL error or “Could not load this page” message. This is page-load coverage; it does not mean every action on every page was exercised.

| Role / account | Pages opened |
|---|---|
| Buyer | Marketplace, product detail, search, Shopping Bag, purchases list and completed order detail, account and login activity, address book, Bagoo Wallet, vouchers, messages, saved products, followed shops, returns, and account status. |
| Seller | Dashboard, settings, products, store categories, orders, handover issues, vouchers, promotions, messages, reviews, disputes and returns, finances, seller proceeds, virtual proceeds, and sales report. |
| Los Banos Bayan Hub | Overview, facility network, fleet, manifests, delivery recovery, handover issues, COD cash, parcels and waybills, scan station, and counter self-pickup. |
| Laguna Mother Hub | The same hub operations pages, plus resource activity, governance history, and delivery exceptions. |
| Santa Cruz Bayan Hub | The ten hub operations pages listed for Los Banos. |
| Delivery rider and pickup rider | Dashboard/map, trips, messages, profile, and handover issues for each seeded rider account. The delivery rider's Trips page reflected the completed trip. |
| Admin | Dashboard, shop reviews, KYC queue, users, review requests, products, voucher campaigns, advertisements, logistics, delivery exceptions, buyer returns, handover issues, COD cash, seller proceeds, wallet credits, virtual proceeds, financial oversight, governance history, and resource activity. |

## Journey and validation notes

- Invalid checkout contact/postal input, unknown or mismatched waybills, underpaid COD, and a review without a chosen rating were rejected during the local order exercise. No authentication edge-case testing was included.
- The buyer's “Buy again” action returned the Aero Graphite product to the existing Shopping Bag at the current ₱2,750 price. The bag showed one selected item and 18 units in stock. It was left unchanged.
- Buyer, seller, pickup rider, hub and delivery rider pages reflected the same order as it advanced. The buyer confirmed receipt; this confirmation was the final commerce step.
- The hub COD page displayed a recorded ₱2,750 collection entry, but a physical cash handover, platform reconciliation, and seller payout were not exercised. The UI does not prove cash physically changed hands.
- The local database was not reset or reseeded for this browser pass. Signing into seeded accounts and opening pages did not submit new operational forms.

## Deployment build note

The deploy helper caches Composer and npm package downloads under the ignored `storage/framework/cache` directory. The cache is persisted by the repository bind mount across disposable dependency-install containers, so repeat deployments can avoid some package downloads. The first run remains cold; Azure build time was not measured. Keep `tsc`: its roughly 24-second local runtime does not explain the reported Azure build duration, and removing it would drop TypeScript checks.

The cache wiring is covered by three passing deployment-helper tests. `bash -n bagoo.sh` and `git diff --check` also passed. These checks do not measure an Azure build.
