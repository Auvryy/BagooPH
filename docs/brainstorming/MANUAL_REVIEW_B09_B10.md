# B09/B10 manual review

Use the existing food-seller/BGR manual-testing PDF and its accounts. This is an additional review, not evidence that the user has accepted B01–B08. Keep its coffee order, measured package, purchased total and waybill as the shared test identity. Never substitute the order number for a physically scanned waybill.

## Preparation

Use the food seller's **Products** page to save actual packed weight and all three outside dimensions for the coffee SKU, then place a quantity-one order as described in the PDF. Record the displayed purchased total. A 250 g contents label does not establish shipping weight. Existing company tariffs remain authoritative; PHP 269 merchandise plus PHP 70 shipping is only a valid PHP 339 example when the actual purchased quote says so.

Use existing local infrastructure. On deployment, run the established deployment procedure with its additive migrations and asset build. B09/B10 introduce no additional migration or environment variable. Do not reset/reseed to display these changes. Real phone/camera tests remain separately scheduled.

## B09: Sending hub, packing and manifest

1. Sign in as the origin BGR hub operator. Open **Scan Station** and select the actual sending hub. After the pickup rider has collected the parcel, inspect the waybill and explicitly confirm its origin receipt using the existing PDF procedure.
2. Inspect the waybill again. Verify **Route Target Facility** names the purchased next Mother Hub and its proposed bin refers to that facility. Inspection must leave the parcel/checkpoint count and custody unchanged.
3. If the package has no usable combined outer measurements, check that packing guidance requests readings. It must not claim an assumed 0.6 kg sack or treat every unpacked package as loose freight.
4. In **Outbound staging**, inspect the corridor to the purchased next hub. Check package count, measured/declared weight, open versus sealed bags, unpacked count and fleet suggestion. Missing readings must display **Needs readings** and an actionable blocker.
5. Click **Open packing**, or expand **Hub transfers** in the sidebar and click **Mother Bags**. Use the actual waybill with **Find bag & add parcel**. Supply combined outer-package readings when required. Confirm the bag remains open; packing alone must not create a numbered seal or departure.
6. Inspect again. **Recorded packing** must show the original bag and retained weight. An unpacked parcel may instead show **Suggested packing**; that display must not create membership. A full bag must not be proposed for a parcel that would exceed its count/kg/volume limit.
7. Seal the bag using its actual new numbered seal. Return to **Scan Station**. Once compatible cargo and an eligible vehicle are available, **Prepare manifest** opens the existing manifest workflow. It must not directly depart or receive a parcel.
8. In the sidebar, expand **Hub transfers** and click **Manifests**. Continue the PDF's explicit prepare, bag load, manifest seal and departure sequence. At each receiving hub, open/verify the bag and receive each actual waybill. Receiving one parcel must not receive unseen members or close an incomplete manifest.
9. At the destination child hub, inspect the parcel. Check **Local delivery staging** and its actual/proposed local bin. Sort and assign the final-mile rider using the existing controls. This shelf must not appear as another linehaul corridor.

For a separate same-child order, verify origin receipt advances to local destination sorting without a Mother Bag or manifest. For a Mother-to-Mother trip, expect a wing truck; a feeder trip requires the source hub's closed van. A remote vehicle, inactive/unavailable driver or insufficient configured kg capacity must not be presented as an eligible match. Fleet changes and trip preparation require a fresh server check; suggestions are not reservations.

## B10: Delivery, earnings and cash

1. As the assigned delivery rider, open **Dashboard**, select the parcel and record its actual out-for-delivery waybill scan. At handover, use the exact purchased COD amount, actual tender/change, recipient details and permitted proof image. Complete **Record delivery** using the existing PDF's detailed buttons.
2. As the buyer, open **My Orders** and the same order. A version-two order should already be **Completed** after valid proof/payment. Do not require a second buyer confirmation to create earnings. Submit a rating only for the actual purchased item.
3. As the rider, click **Trips** in the sidebar. Under **Pickup and delivery earnings**, locate the original order. Check its amount and **Receipt** reference. The delivery share should say **Awaiting hub cash receipt** until counted remittance; current held COD must match the collected original amount.
4. Click **COD cash handovers**. Offer the actual amount to the destination operator. Before that operator accepts the counted receipt, check the rider's held cash and duty-closure blocker remain.
5. As the hub operator, expand **Cash & earnings** and click **COD cash**. Open the offered account and record the actual counted receipt using the PDF procedure. An exact acknowledged receipt clears the rider's held amount and releases the eligible delivery share once. It does not mark a seller bank/cash payout paid.
6. On a separate test order, record a short count. Check both the rider earnings hold and duty-closure blocker remain. Receiving the missing cash still needs the existing independent discrepancy review. Do not approve a difference without its actual supporting receipt.
7. As the company owner, expand **Cash & earnings** and click **Delivery earnings**. Find the order and compare its company share, source receipt and release state with the original model. Check both narrow/mobile cards and the desktop table when manually reviewing responsive layout.
8. Repeat using a separately funded **Bagoo Wallet** order. It must complete from its original prepaid journal and valid recipient handover, create no COD cash account and collect no cash at delivery. A virtual credit is not an external transfer.
9. Repeat the PDF's **Bayan Hub Self-Pickup** variation. Use the actual buyer claim code and ID checks. Confirm zero purchased doorstep shipping, no invented delivery-rider share and the correct original COD or Wallet collection evidence.
10. Reload each completed order/earnings/cash page. Retrying an already successful original action must retain the original reference and amount, without duplicate cash, earnings or virtual proceeds. A failed/rejected action must preserve usable safe input; do not retry an uncertain order until checking its saved history.

## Record results

| Scenario | Original order/waybill | Expected and observed result | Pass/fail and evidence |
|---|---|---|---|
| Regional sorting / actual bag route | | | |
| Local final-mile bypass | | | |
| Unknown readings / full bag | | | |
| Wrong vehicle / unavailable driver | | | |
| Partial receiving / seal exception | | | |
| COD completion / held earnings | | | |
| Exact counted remittance / replay | | | |
| Short count / recovery review | | | |
| Wallet completion / balanced proceeds | | | |
| Counter COD / counter Wallet | | | |

Automated SQLite and rendered-component results are recorded separately in the roadmap. They do not certify physical package fit, device scanning, live PostgreSQL concurrency, production deployment or these manual observations.
