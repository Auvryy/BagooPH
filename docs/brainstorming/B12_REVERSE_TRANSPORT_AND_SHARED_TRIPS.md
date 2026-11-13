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

## Actual return sacks

Baggable returns on a vehicle trip use the existing physical Mother Bag/sack
identity with separate typed return-member rows. A sack retains at most 50
parcels, 25 kg and 0.30 cubic metres; a baggable parcel is at most 15 kg with no
side above 60 cm. Outer-package readings determine these limits, not the item
count or declared product dimensions alone. Oversized returns stay individually
identified loose cargo. A single return can prepare a trip; no minimum batch
count delays an eligible return.

Packing requires an actual return barcode and confirmed permanent sack tag.
It does not move custody. A physical sack has one active trip; its last actual
received location governs reuse. An unstarted sack can also be emptied at its
recorded source with reasons, retaining that location instead of inventing an
arrival. Numbered seals cannot be reused. Sealed membership is retained, and
unpacking requires reopening before departure. Cancelling the trip requires
actual unpacking first.

The assigned destination operator verifies the master tag and intact numbered
seal before opening. Opening does not receive the listed parcels. Each return
still needs its own original manifest receipt; the sack's physical reservation
is released only when all its actual contents have their receipts. Damaged,
wrong or unconfirmed seals do not manufacture a successful opening or receipt.
Forward/RTS and return-member numeric IDs remain in their separate tables.

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

Within the trip, **Return sacks and loose cargo** lists the actual handling
classification. **Pack one actual return** asks for its separate barcode and
permanent sack tag. Each sack shows its master tag, numbered seal, retained
load and parcel codes. Use **Sack action → Seal sack** after the physical scan;
before departure, **Reopen before departure** and **Unpack a return** require a
reason. At destination, **Verify and open at destination** requires the actual
master/seal check before the original manifest receives individual returns.
Failed forms keep their scans and retry identity, including after a validation
redirect refreshes the page's command token.

## Wrong-hub buyer returns

The Scan Station recognizes the separate return barcode at an eligible hub in
the original company. An unexpected-hub inspection retains an observation on
the original manifest and links a recovery review. Inspection does not receive
the parcel, move the recorded holder or decide financial fault. A company owner
or independent platform administrator reviews the original source and proposed
corridor. A stale source or changed corridor requires a fresh review. An
unreceived observation can be withdrawn without changing custody.

After authorization, the actual observed-hub handler scans the separate return
barcode and supplies a real private intake photo and bounded handover details.
The original dispatcher cannot acknowledge this receiving handover. Intake
retains the original manifest and sack membership as unreceived history, then
records the actual new hub holder. Any extracted sack member remains linked to
its original physical sack and seal; the sack is not silently emptied, opened
or relocated.

Recovery follows the actual child and Mother Hub corridor to the original
expected hub. It uses new, dedicated manifests and vehicle trips, actual package
readings, sack/loose classification and individual departure/receipt scans.
There is no direct shortcut between different child hubs. Each corrective leg
has its own authority; the original return route and cursor stay unchanged
until actual receipt at the expected hub proves the complete recovery chain.
That receipt advances the original route once and resolves the original
observation. The successful forward delivery remains unchanged.

A recovered parcel can resume its original route before its original outer
sack arrives. The physical sack reservation and original transport resources
remain held until its actual arrival and seal verification account for the
sack separately. The manifests, driver candidates and placement guards use the
same outstanding physical-receipt check. Parcel recovery cannot impersonate an
outer-sack arrival or release the original driver prematurely.

The return case links **Open route recovery**. The company reviewer chooses
**Authorize recovery route** or **Withdraw observation**. The observed-hub
handler chooses **Receive actual parcel**. Once held, open the return case to
save its actual package readings, then choose the marked **Authorized recovery**
entry under **Manifests → Buyer item return**. Sending and receiving handlers
continue the recorded manifest and sack controls. Review and intake forms keep
failed input and their retry identity; links respect the account's facility
scope on root and hub-subdomain pages.

## Recovered forward and RTS parcels

An authorized unexpected forward or RTS parcel has its own actual intake scan
and custody checkpoint. When its original sealed Mother Bag is elsewhere,
retain a separate extraction record linked to that exact original bag member,
recovery authority, intake event, checkpoint and handler. This releases only
the parcel's active packing claim. It cannot change the original packing,
seal, route or bag location, or record an unperformed original bag opening.

Actual completed recovery at the originally expected hub can account for that
parcel's original manifest and bag-member receipt. It does not establish an
arrival for the outer bag. The physical sack reservation and original
driver/vehicle remain held until the arriving bag's own master tag and seal are
verified and its members are accounted for. This independent bag arrival can
be recorded after the parcel manifest's accounting is closed. A shared trip
also retains its original unarrived forward/RTS bags before closing.

An approved financial loss retains the separate B13 finding/quarantine hold;
ordinary route recovery cannot substitute for its independent reconciliation.

## Remaining package boundaries

Current implementation evidence, regression limitations and the remaining
B11–B13 delivery are recorded in the roadmap. Consolidation
must not refuse a valid physical return merely because a vehicle or bag is not
full. Automatic clock execution remains B14; no timeout proves loss or pays a
refund.
