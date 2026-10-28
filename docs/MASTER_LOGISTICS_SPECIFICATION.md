# Master Logistics Specification

The current contract is the user-selected [Logistics Architecture and Algorithms Specification v2.0.0](new-docs-era/LOGISTICS_SPECIFICATION_V2.pdf), normalized in [New Docs Era](new-docs-era/README.md). This specification describes version-two purchases. Retained version-one orders keep their original route, money, individual-manifest and buyer-receipt evidence.

## Roles and approval

The platform admin reviews a registered logistics company and its owner's application. An approved company owner creates that company's physical facilities, named hub operators and riders, and its published shipping tariff. These are permissions within the existing `logistics` and `courier` roles; account roles never change after creation.

A child hub has a physical address, a parent Mother Hub in its own company and served postal areas. Optional barangay restrictions narrow that coverage. Mother Hubs organize regional transfers. Existing city-based coverage remains usable only where the selected company and required parent are unambiguous. Never choose an arbitrary first hub from overlapping coverage.

Operators and riders receive temporary passwords that expire after seven days. The worker must replace the temporary password, acknowledge the physical assignment and verify the registered email before operational access. The company owner can replace an unused temporary login after confirming the owner's own password. No plaintext password is stored or returned by the server. Initial provisioning does not turn an operator into a company owner.

## Purchase and route

The buyer chooses an approved company serving every selected shop and the delivery address. Checkout validates company, hub coverage, stock, prices, vouchers and ownership on the server. Each shop order freezes the selected tariff, original company, ordered hub IDs and readable route codes.

Regional routing is:

`Seller -> Pickup rider -> Origin child hub -> Origin Mother Hub -> Destination Mother Hub when different -> Destination child hub -> Delivery rider or collection counter -> Buyer`.

An identical origin/destination child hub uses:

`Seller -> Pickup rider -> Same child hub intake -> Local sorting -> Delivery rider or collection counter -> Buyer`.

The local bypass never permits movement directly between different child hubs. Later hub, coverage or tariff edits affect future quotes; they do not rewrite purchased routes. Unsupported, ambiguous, inactive or foreign-company selections fail without reserving stock.

The seller confirms the order, records packing, prints the purchased waybill and marks it ready. Assignment alone is not custody. The assigned pickup rider scans the actual waybill at collection. An origin operator's receiving scan transfers custody to that child hub. For a local bypass, the same committed intake also records local destination receipt; no Mother-Hub vehicle or manifest is invented.

## Weight and rates

Packed catalogue dimensions are per purchased unit. Aggregate actual grams and aggregate volumetric grams across quantities. Volumetric kilograms equal `length_cm * width_cm * height_cm / 3500`. Billable weight is the greater total. The saved calculation records the divisor, actual/volumetric/billable grams, tariff version, included grams, base cents and additional started kilograms.

The default tariff is PHP 60. The source supplies no excess-weight price, so the default additional-kilogram surcharge is zero. An owner or platform admin may configure a published surcharge for future quotes. A weight-priced quote requires actual weight and all dimensions; missing measurements must not silently undercharge bulky items. The flat baseline can retain explicitly unknown legacy measurements. Self-pickup retains its existing zero doorstep charge. Product subtotal alone never grants free shipping; funded shipping offers remain separate.

## Mother Bags and transport

1. An assigned sending operator creates a Mother Bag for the actual next receiving hub in the same company.
2. The operator scans each eligible waybill. A parcel cannot belong to two active bags, another route or another company.
3. A nonempty bag is sealed with a new numbered seal. The retained seal event freezes its member list. Used seal numbers cannot be reused after reopening.
4. The operator scans the master bag tag and observed seal onto a matching draft vehicle/driver manifest. Every frozen member loads in one transaction. A wrong tag, changed parcel, foreign actor or invalid member rolls the batch back.
5. Seal the manifest list and record actual departure. Its retained vehicle, driver, master-tag load and member scans support the atomic transit update. Preparing or loading a manifest alone does not move custody.
6. At the receiving station, verify the actual master tag and intact numbered seal and open the bag. Scan every individual arriving waybill. Partial receipt never implies that missing parcels arrived.
7. Close the received manifest after supported receipts and discrepancy handling. The Mother Hub's next destination/cage and the child's local delivery bin remain visible at scanning and in the parcel registry.

An undeparted draft may unload the complete sealed bag with its actual tag, seal and reason. An unloaded sealed bag may be reopened with a reason, corrected and given a new seal. Departed membership cannot be silently edited. Historical individual-manifest parcels and separately authorized recovery/buyer-return manifests retain their own protocol.

## Misroutes, failures and returns

Every hub scan checks the actual station against the expected next stop. A wrong receiving hub for a dispatched parcel records a retained misroute observation and blocks ordinary movement. Set the parcel aside and use Parcel recovery. The company authorizes the evidence-backed route; the actual observing operator records intake. Recovery goes through the relevant Mother Hub(s), receives at the originally expected hub and resumes the frozen forward route. It does not erase original transport or invent arrival from a note.

An incorrect pickup-hub scan has no receiving authority and shows the correct origin. An unknown waybill cannot create a parcel. A broken/mismatched seal requires a discrepancy and supported recovery, not a false intact-seal acknowledgement.

The first two supported failed delivery attempts can receive a recorded retry after actual destination-hub return. Refusal or the third unsuccessful attempt freezes the reverse of the purchased route. Every regional return leg uses real transport/receipts. A one-child local return stays at that hub and proceeds to actual seller collection. Only the owning seller's actual scan/receipt finishes Returned.

An ordinary buyer return remains available for seven days from the original delivered or counter-collected checkpoint, including completed orders. It uses its own return label, actual intake/photo, reversed frozen path, seller staging and actual seller receipt. A one-child buyer return requires no fictitious manifest. Original delivery evidence remains unchanged.

## Delivery, completion and earnings

The assigned final-mile rider scans the parcel out of the destination hub. Valid photo POD, recipient evidence, correct original COD with change or verified prepaid handover record physical delivery and automatically complete a new order in the same transaction. A buyer receipt click is not required. Retried commands cannot duplicate completion, cash or earnings. Counter collection uses the existing private code, identity and original payment evidence and follows the same new-order completion rule.

Product proceeds before original funding/return adjustments are 90% seller and 10% Bagoo. Shipping is a distinct source pool:

| Model | Pickup | Delivery | Company |
|---|---:|---:|---:|
| Standard, PHP 60 | PHP 0 | PHP 30 | PHP 30 |
| Dedicated fleet, PHP 60 | PHP 10 | PHP 25 | PHP 25 |

Dedicated pickup earnings require actual origin intake. Valid completion releases seller/platform and company entitlements. Delivery earnings are available immediately for prepaid orders; COD rider earnings become available only after counted, acknowledged hub remittance. A shortage, pending offer or unresolved rider discrepancy prevents release and duty closure. The company's remainder conserves every shipping cent when a fee is not exactly divisible.

Rider shifts retain duty start, exact collected/remitted/held COD and closing evidence. Going off duty cannot clear cash responsibility. Historical obligations remain visible. No external bank/cash payout is fabricated: the existing actual cash reconciliation, seller-payment reference and proof remain necessary. Wallet proceeds automatically use their balanced virtual ledger, with scheduled retry after legitimate blockers clear.

Owner, seller and rider earnings views are scoped to their own company/account. The platform sees the global ledger. Approved buyer-return adjustments reduce net product earnings using original units and funding while retaining original earning sources and actual refund records.

## Status and evidence compatibility

`local_bayan_hub`, `origin_bayan_hub_id`, `destination_bayan_hub_id` and internal `arrived_at_*_hub` statuses remain compatible database/API names for the PDF's child-hub concepts. Physical parcel status remains Delivered or Customer collected while the associated commercial order becomes Completed. No role conversion, rewritten historical checkpoint or silent old-payment recalculation is permitted.

See [acceptance](new-docs-era/ACCEPTANCE.md), [deployment](new-docs-era/DEPLOYMENT.md) and [current implementation evidence](CORE_FLOW_ROADMAP.md). Specifications define behavior; current test and release status belongs in the roadmap.
