# B01–B08 manual review for later

All boxes below mean **user manual acceptance**, currently pending. Prior automated checks and merged code do not check these boxes. This guide is prepared for a later review session; phone, email inbox and real physical handover checks require their own session.

| Package | Major pages / participants | Review purpose |
|---|---|---|
| B01 | Buyer checkout; company hub setup; authorized hub workstation | Selected company coverage and required route |
| B02 | Company worker invitations; invited worker sign-in; assigned hub workstation | Named identity, activation and limited assignment |
| B03 | Hub scanner, custody history and public tracking | Actual custody and private/public separation |
| B04–B05 | Company tariff editor; buyer checkout; original order / earnings | Weight-priced quotes and frozen original payment terms |
| B06 | Buyer order details; seller Orders; pickup jobs | Acceptance privacy, reviewed cancellation and last permitted pickup boundary |

## B01 — Company and route

- [ ] Checkout shows an eligible company serving both the seller and destination.
- [ ] Same-child collection/intake uses the documented local route.
- [ ] Different child hubs under one Mother Hub retain that required Mother Hub.
- [ ] Different Mother Hubs retain both required parent hubs.
- [ ] Unsupported coverage blocks checkout clearly; collection uses an eligible destination hub.

## B02 — Individual workers

- [ ] Company owner invites a named worker without sharing the owner's password.
- [ ] Worker completes their own activation/OTP and can access only assigned work.
- [ ] Wrong-company, wrong-hub and inactive workers cannot confirm custody.
- [ ] Actual email delivery and device scanner behavior are checked separately when available.

## B03 — Shipment and privacy

- [ ] Inspection alone does not move the parcel; confirmation uses the correct actor and hub.
- [ ] Duplicate scans retain one outcome; wrong-hub or stale confirmation explains the conflict.
- [ ] Public tracking reveals no full recipient address, phone or private return evidence.
- [ ] Approved reverse custody remains separate from the original outward route.

## B04–B05 — Quote and original money

- [ ] New-company default is PHP 50 including 1 kg, then PHP 20 for each started extra kg.
- [ ] A deliberately configured existing company's published tariff remains unchanged until explicitly saved.
- [ ] Actual versus volumetric weight uses the greater value; incomplete measurements receive useful feedback.
- [ ] Checkout, purchased order and earnings retain their original company, route, measurements and money after later source edits.
- [ ] Repeated successful checkout does not create another order or reserve more stock.
- [ ] Keep the distinction clear: successor seller-fault shipping refunds and funded courier liability were accepted as B04 decisions; their later B11–B13 execution is not activated here.

## B06 — Acceptance and cancellation

- [ ] Before acceptance, seller sees items and city/province, but no street, phone, pin, notes, printable waybill or tracking barcode.
- [ ] Acceptance reveals the original recipient; packing and printing remain distinct from marking ready.
- [ ] Buyer cancels a new unaccepted order directly; inventory restores once and other orders stay unchanged.
- [ ] Accepted/packed order offers a request, not direct cancellation; pending review holds preparation and pickup.
- [ ] Seller decline retains the active order and shows the buyer the saved reason.
- [ ] Seller approval cancels once; actual Wallet payment receives one recorded credit refund. Unpaid COD never shows a cash repayment.
- [ ] At the 24-hour deadline, the request expires with order/payment unchanged and preparation can resume. Automated clock tests cover this boundary; a manual review needs the real deadline or an isolated test fixture.
- [ ] Buyer cancellation is unavailable from ready-for-pickup; seller cancellation fails once rider claim or retained handover exists.
- [ ] Refresh, double click and an uncertain response do not create another cancellation, refund or stock restoration.
- [ ] Historical purchases retain their original cancellation policy; unknown purchased versions require review.

## B07 — Actual individual handovers

- [ ] Inspect a waybill without changing custody; confirm only the displayed next action.
- [ ] One named small-hub operator can receive, pack and dispatch, with a separate rider/driver.
- [ ] Duplicate confirmation retains the same event reference/time; a competing receiver cannot appropriate it.
- [ ] A stale ready/unassigned status cannot replace a different recorded holder; wrong-hub and stale-source messages show the correct recovery path.
- [ ] Bag lookup opens seal verification; it cannot receive unseen contents. Manifest preparation requires actual load/seal/departure evidence.
- [ ] Scan evidence shows the named actor and reference. Derived local records and transport departures are distinguished from submitted checkpoint records.
- [ ] Complete the connected local/shared-Mother/cross-Mother checkout → acceptance → cancellation decline → pickup → destination-sort journey for COD and Wallet.

## B08 — Packing, seals and loose cargo

- [ ] In Mother Bags, choose the actual hub and scan into the next open bag; another eligible waybill reuses its lane.
- [ ] Try a multi-item parcel without outer-package readings; its values remain available for correction and no empty bag is created.
- [ ] Check the 15 kg/60 cm parcel boundary and the 25 kg/0.30 m³/50-parcel bag limits; a rejected scan cannot partly pack or move cargo.
- [ ] Fill an automatic lane; the next eligible parcel opens another bag while the first remains unsealed.
- [ ] Check permanent sack tag, trip reference and numbered seal separately; a used seal cannot be reused.
- [ ] Receive every bag member individually before reusing its permanent sack tag at the actual destination hub.
- [ ] In Manifests, choose loose cargo for an oversized/heavy parcel; check vehicle capacity without a fake bag or seal.
- [ ] Remove loose cargo individually before departure with a reason; receive each loose waybill separately after departure. One receipt cannot finish a partial arrival.
- [ ] Confirm a bag member cannot be split using an individual manifest removal control.
- [ ] Retry uncertain submissions and double click; check the original request and unchanged purchased shipping amount.

## Environment prerequisite

Use the feature branch's normal migration/build/start workflow in the chosen local environment before reviewing B06–B08. Merely pulling code does not add its new tables. This delivery intentionally leaves existing development data intact. Keep PostgreSQL race testing, real payment, live delivery and device checks distinct from the SQLite and rendered-component evidence in the roadmap.
