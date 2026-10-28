# Running a version-two delivery

Use the account assigned to each physical station. Company ownership manages the network; it does not itself record a parcel arriving at an unassigned station. Platform Admin is needed for company approval and later platform cash/payment review, not for every ordinary hub scan.

For the existing synthetic demo network, inspect `database/seeders/DatabaseSeeder.php` and `LogisticsNetworkSeeder.php` for the verified accounts. The stations are Los Banos child hub, Laguna Mother Hub and Santa Cruz child hub. Keep synthetic checks separate from real parcels and cash.

## Company owner: prepare the network

1. Open **Company setup → Hub network**. Create a Mother Hub, then its child hubs. Give each child its actual parent, distinct four-digit postal coverage and optional barangay restrictions. **Edit coverage** changes future routing; purchased parcels retain their saved path.
2. Open **People & access → Create account**. Choose operator or rider, an assigned physical hub, adult birth date, name, sign-in email, phone and temporary password. Share the temporary login privately. The worker replaces it, acknowledges the assignment and verifies the registered email before work access. **Replace temporary login** requires the company owner's current password and invalidates the old temporary login.
3. Open **Shipping & earnings**. The default fee is PHP 60. Actual and volumetric weight are retained even with a flat tariff. Configure additional started-kilogram pricing only when required measurements are complete. Choose standard or dedicated-fleet earnings; existing orders keep their purchased tariff.
4. In **Fleet Management**, ensure each sending hub has an active available vehicle and its eligible assigned driver. Feeder transfers use a closed van; Mother-to-Mother transfers use a wing truck. Transport vehicle/driver assignment is separate from assigning the final-mile rider.

## Buyer and seller

1. Buyer: open a product, choose **Add to Bag**, open **Shopping Bag**, select it and choose **Proceed to checkout**.
2. Check the full address and ZIP. Choose a serving **Logistics company**. Review the weight/rate calculation, payment and total, then **Review & Place Order**, acknowledge the review and **Confirm & Place Order**. Counter collection uses its own eligible destination hub and claim verification.
3. Seller: open **Orders**, find the order, choose **Review & Confirm → Confirm & Pack Order**, then the order row's **Ready for pickup** button. A similarly named filter only filters the list.
4. Seller: print and attach the parcel's waybill. Use that parcel tracking barcode throughout its journey; the order number and bag master tag are different identifiers.

## Pickup rider and origin operator

1. Pickup rider: go on duty, open Dashboard, **Claim pickup**, then **Scan waybill · confirm pickup**. Scan/type the actual parcel code and **Confirm pickup** only at physical handover.
2. Origin operator: open **Scan Station**, scan/type that same code and choose **SCAN**. A verified barcode alone does not change custody. Read the next action and choose **Confirm custody action** when the parcel physically arrives.
3. If the saved route has only this one child hub, continue at **Local delivery** below. A different-child route continues through the required Mother Hub(s).

## Each hub-to-hub transfer

Repeat this section at each sending/receiving pair on the saved route.

1. Sending operator: open **Manifests → Prepare a draft**. Select sending hub, receiving hub and the available source-hub vehicle/driver, then **Prepare manifest**. This prepares transport; it does not move the parcel.
2. Open **Mother Bags**. Choose the sending station and next receiving hub, then **Create bag**. Scan each **Parcel waybill → Add parcel**. Only parcels already received and, at a Mother Hub, sorted for that next hop can be packed.
3. Enter the unused physical **Numbered seal → Seal bag**. Print the master tag if needed. A used seal remains retained even after a correction.
4. Under **Load by master tag**, scan the **Master bag barcode**, enter the seal, choose the matching **Draft transport manifest** and **Load sealed bag**. One tag loads every frozen member. Do not load a new-flow bag member separately in the historical individual-loading section.
5. Open the transport manifest, choose **Seal parcel list**, then **Record departure** at the actual departure. Every packed parcel moves atomically; preparing or sealing alone does not transfer custody.
6. Receiving operator: open the arriving bag in **Mother Bags**, scan its master tag, enter the observed seal and confirm that the physical seal is intact. Choose **Verify and open bag**. A broken/different seal needs a recorded discrepancy, not a false intact confirmation.
7. Open the arriving manifest, scan every **Actual arriving waybill → Record parcel receipt**, then **Close received manifest** when every expected parcel has arrived and discrepancies are resolved.
8. At a Mother Hub, open **Scan Station**, scan the parcel and **Confirm custody action** to record its sorting. The next receiving hub/cage appears beside the code. Then pack the next outbound bag. A further region uses the destination Mother Hub before the final child hub.

## Local delivery and final-mile dispatch

1. At the destination child hub, open **Scan Station**, scan the parcel, and choose **Open parcel sorting**. Choose **Sort to Bin** to record the actual destination shelf/bin.
2. Choose the eligible local rider in that parcel row and **Assign**. Assignment does not yet transfer custody.
3. Rider: open Dashboard, choose **Scan waybill · start delivery**, scan/type the original parcel code and **Start delivery** at physical hub collection.
4. At the buyer, choose **Scan waybill · record delivery**. Enter the actual waybill, recipient and relationship. For COD, enter cash received, change given and confirm the count. For prepaid wallet, collect no cash. Attach the actual delivery proof photo and choose **Record delivery**.
5. Valid version-two evidence completes the commercial order automatically. Buyer tracking, seller order history and released/pending earnings reflect it. The seven-day return window remains based on delivery. Historical version-one orders retain their original buyer receipt action.

A one-child local route uses this same sorting/rider/proof sequence after origin intake. It creates no Mother-Hub manifest.

## End-of-shift cash

1. Rider: open **COD cash**, find the order and **Review cash → Hand over cash**. Choose the recorded holder and assigned hub recipient, enter the actual amount and a reference, then **Record handover offer**.
2. Receiving hub operator: open the same cash record and **Confirm counted cash**. Enter the actual counted amount, acknowledge physical receipt, provide a receipt reference and **Confirm counted receipt**.
3. COD final-mile earnings become available only after complete acknowledged rider-to-hub remittance with no unresolved difference. An offer alone does not release them or permit duty closure.
4. Rider: use the duty switch and confirm **Go off duty**. Held cash, a pending offer or an unresolved shortage/overage blocks closure. The closing snapshot retains exact collected and acknowledged amounts; a new shift starts its own event boundary.
5. Further hub-to-platform handover, platform reconciliation and a real seller cash/bank payout keep their existing separate receipt/proof controls. A released earning is not proof of a bank transfer. Virtual proceeds use their balanced wallet journal; the scheduler retries legitimate deferred credits.

## Failed attempts and wrong hubs

- Record the real failed attempt and proof. Return the parcel to the destination operator for an actual intake scan. First/second retry requires the recorded reason/date and another rider departure scan.
- Refusal or the third unsuccessful attempt starts return to sender. Reverse the saved hub path with actual bag/manifest receipts, stage it at the original child hub and obtain the owning seller's actual return receipt. The one-child route needs no fictitious Mother hop.
- A wrong-hub scan sounds the scanner error, retains the observation and holds ordinary movement. Set the parcel aside and open the linked recovery case. The company authorizes the supported recovery path through the Mother Hub; assigned station operators record each actual recovery intake, transfer and receipt. An explanatory note alone does not clear missing custody.
