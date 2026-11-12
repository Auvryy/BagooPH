# B12: reverse transport and shared trips

B12 extends the [B03 custody contract](B03_SHIPMENT_CUSTODY_PRIVACY.md),
[B08 packing](B08_BAGS_SEALS_AND_LOOSE_CARGO.md) and
[B09 dispatch](B09_SORTING_AND_DISPATCH.md). The implemented shared-trip
protocol below does not by itself finish every B12 acceptance case. Current
evidence and remaining work are in [the roadmap](../CORE_FLOW_ROADMAP.md).

## One physical vehicle, independent manifests

Sealed outbound, failed-delivery RTS and approved buyer-return manifests can be
grouped into one explicit vehicle trip. They must have the same eligible
company, actual source hub, exact next destination hub, vehicle, responsible
driver and feeder/line-haul type. Matching a final destination or province is
insufficient. Recovery manifests retain their reviewed dedicated protocol.

Each original manifest retains its direction, parcel identity, route, load
scans, seal requirements, departure and individual arrival receipts. Buyer
returns retain their separate `BRT-` barcode and reverse leg; RTS retains the
original unsuccessful delivery and its evidenced return route. A numeric ID in
one parcel table is never substituted for an ID in another.

Creating a group saves the selected sealed lists, actual actor, request token
and original state. It does not move cargo. Grouped lists cannot be individually
reopened or dispatched. Cancel an unstarted grouping before changing a list;
cancellation preserves its history and moves no parcel.

Departure locks the original orders/parcels, eligible network and manifests,
rechecks the retained lists, total recorded kg, current vehicle capacity,
driver/vehicle assignment and availability, then invokes each original
departure protocol in one database transaction. A failure on any manifest rolls
back all departures. The driver's other transport or final-mile work remains
incompatible. The normal one-manifest departure path remains available for
ungrouped historical workflows.

At destination, receive every actual parcel through its original manifest.
Receiving a buyer return does not receive an RTS parcel or forward shipment on
the same vehicle. A partially arrived group remains open until all expected
receipts and actual discrepancies are resolved. Closing the group preserves
its original references and per-manifest outcomes for review.

Grouping, departure, receipt and closure create no buyer collection, refund,
seller recovery, compensation or new earnings on their own. Original purchased
money terms and physical proof remain authoritative.

## Operator controls

For a buyer return, first open its case at the actual sending hub and choose
**Save actual package readings** before loading. Record weight and all three
outer-package dimensions and confirm the actual observation. Each reading is
retained with the named handler and case event. A correction appends a new
reading before loading; it does not overwrite the intake, earlier readings,
physical custody or purchased price. A loaded return must be removed from its
draft list before changing its readings. Capacity checks use the latest actual
weight; genuinely oversized packages are identified using the B08 limits.

Open **Manifests**, then a sealed manifest. When other compatible sealed lists
exist, **Shared vehicle trip** shows their references and directions. Select the
actual lists and choose **Prepare shared trip**. An authorized source handler
uses **Record shared departure**. **Cancel grouping** is available before
departure; it cannot undo a trip that physically departed.

Use each manifest link for its normal receiving controls. The group shows each
list's outstanding receipt count. An authorized destination manager can choose
**Close received trip** after all receipts and observations are resolved.
Failed requests retain selections and show the actual conflict. Root and hub
subdomain pages use their own existing manifest paths.

## Remaining package boundaries

The shared-trip and package-reading implementations are not evidence that
return sack membership or buyer-return wrong-hub recovery have been completed.
Those remain in the same B11–B13 delivery. Existing dedicated return
transport, original bag limits and supervised RTS/forward recovery remain
enforced while their extensions are implemented and verified. Consolidation
must not refuse a valid physical return merely because a vehicle or bag is not
full. Automatic clock execution remains B14; no timeout proves loss or pays a
refund.
