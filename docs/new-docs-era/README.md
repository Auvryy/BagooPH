# New Docs Era: logistics version 2

The user-selected [Logistics Architecture and Algorithms Specification, version 2.0.0](LOGISTICS_SPECIFICATION_V2.pdf) is the authority for the product journey, seven logistics algorithms, company provisioning and earnings model. It supersedes earlier rules that require a Mother Hub for same-child-hub deliveries, reserve completion to buyer confirmation, use a PHP 50 merchandise-based shipping threshold, leave shipping earnings undefined, or prevent an approved company owner from provisioning staff.

Existing ownership, company isolation, approval, actual scans, private proof, cash counting, atomic stock, validation, retry protection and retained evidence still apply. Historical transactions retain their original amounts and evidence. New checkout quotes freeze the selected company's route, tariff and earnings terms; later settings edits do not rewrite purchased orders.

## Product journey

1. The buyer chooses an eligible logistics company for each shop order. The server matches the seller and buyer postal/address coverage to child hubs, stores the ordered hub path, quotes shipping and issues a unique waybill. The seller packs the purchased items and marks the attached waybill ready for pickup.
2. The origin pickup rider claims the job and scans its actual waybill at the seller. The origin child hub's receiving scan transfers custody from that rider to the hub.
3. For a regional journey, an operator scans parcels into a numbered Mother Bag, records a seal and loads the bag onto a vehicle/driver manifest. Scanning the sealed bag applies its recorded transport departure to all included parcels atomically.
4. At each receiving hub, staff verify/open the seal and scan individual parcels. Mother Hub receiving identifies the destination child hub and sorting cage. Destination receiving identifies the local delivery shelf. A wrong-hub scan creates a misroute hold and shows the required recovery route through the Mother Hub.
5. When origin and destination are the same child hub, local intake proceeds directly to destination sorting and rider dispatch. This is the explicit local bypass; it does not permit a direct transfer between different child hubs.
6. The assigned delivery rider scans the parcel out of the destination shelf. Valid recipient evidence, original payment/COD and private photo proof record Delivered and complete the order automatically. Earnings release uses the same committed delivery evidence.
7. Rejection or a third failed delivery starts the reversed stored hub path. Each return movement retains real transport and receiving evidence, ending with the seller's receipt.
8. A rider may close duty only after all COD collected during that shift has an acknowledged hub receipt. Cash in transit or a discrepancy keeps the shift open.

The PDF's example calls its route Laguna-to-Batangas while naming Biñan and Pagsanjan, both in Laguna; actual configured addresses determine routing. Its page-four release-event column is clipped. The rules below use the complete prose and retain a clear difference between an earned/released ledger amount and an actual external cash transfer.

## Algorithms and money

| Algorithm | Required result |
|---|---|
| Forward hierarchical routing | Resolve exact postal/address coverage within the chosen company; use configured parent Mother Hubs; freeze an ordered route on the parcel. Cross-region routes include both necessary Mother Hubs. |
| RTS reverse engine | Reverse the frozen forward path, including the one-child local bypass, without inventing arrivals or erasing the original delivery. |
| Misroute trap and recovery | Compare receiving hub with the next frozen hop, retain the unexpected physical observation, block ordinary dispatch, and route recovery through the recorded Mother Hub. |
| Intra-hub bypass | An identical origin/destination child hub has a one-hub path, local destination sorting and no Mother-Hub transport requirement. |
| Volumetric weight and rates | Volumetric kg = length × width × height / 3,500; billable weight is the greater of actual and volumetric weight. Use the selected company's published rate terms and freeze the calculation. |
| Mother Bag aggregation | Owned open bag → scanned membership → numbered seal → manifest bag scan → atomic parcel departures → verified seal opening and individual receipt. Prevent simultaneous active container membership. |
| Daily COD reconciliation | Use actual collection and acknowledged remittance events for the rider's shift; unresolved held cash, offers and differences prevent duty closure. |

Product proceeds remain 90% seller and 10% Bagoo before original funded promotions/returns. Shipping is a separate source pool. The default example tariff is PHP 60, with no automatic merchandise-based free shipping. The PDF does not supply an excess-weight surcharge: the configured tariff exposes the included weight and additional-kilogram fee, with a zero default surcharge preserving its flat PHP 60 baseline. The weight calculation is retained even under a flat tariff. A configured surcharge applies to each started kilogram above the included weight. Self-pickup retains its separate no-doorstep-charge option.

Two company-selected shipping models are supported and frozen at checkout:

- Standard: 50% company and 50% delivery rider (PHP 30 / PHP 30 from PHP 60).
- Dedicated fleet: one sixth pickup rider, five twelfths delivery rider, remainder company (PHP 10 / PHP 25 / PHP 25 from PHP 60). Integer-cent allocation conserves the original shipping pool.

Pickup earnings accrue on actual origin receipt. Company and final-mile earnings accrue on valid delivery; COD rider amounts become available after acknowledged cash remittance. Seller and platform proceeds are released automatically from valid delivery evidence. A ledger release is recorded automatically; a real bank/cash payment still needs its actual transfer reference and proof. Wallet orders use their existing prepaid virtual journal and collect no cash.

## Provisioning and interface

The platform approves a registered logistics company. Its company owner creates child/Mother hubs with explicit parent and service coverage, configures its shipping model/rates, and creates named operator/rider accounts assigned to that company's hub. Temporary credentials require an initial password change; account roles and company ownership remain fixed, and credentials are never saved as plaintext.

Logistics uses a restrained shadcn-style workspace with Plus Jakarta Sans, Bagoo red, white raised panels, readable slate borders, 8px controls, compact status badges, consistent tables, clear scanner feedback and dedicated loading/empty/error states. Company setup, parcel scan, bags, manifests, staff and earnings remain reachable from the same shell.

The step-by-step station guide is [OPERATIONS.md](OPERATIONS.md); installation and additive migration instructions are in [DEPLOYMENT.md](DEPLOYMENT.md).

Detailed acceptance is in [ACCEPTANCE.md](ACCEPTANCE.md). Current completion evidence belongs in [the roadmap](../CORE_FLOW_ROADMAP.md), not in this contract.
