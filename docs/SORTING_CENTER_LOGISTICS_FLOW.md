# Sorting Center and Logistics Flow

The current routing, bagging, company setup and financial contract is [Master Logistics Specification](MASTER_LOGISTICS_SPECIFICATION.md), based on the user-selected [version-two source](new-docs-era/LOGISTICS_SPECIFICATION_V2.pdf). “Child Hub” and the existing “Bayan Hub” label refer to the same municipal facility. Existing database and API names are retained for compatibility.

## Station work

| Station | Receive | Prepare the next handoff |
|---|---|---|
| Origin child | Scan the picked-up waybill and confirm actual rider-to-hub receipt | Local bypass: sort locally. Regional: scan parcels into a sealed Mother Bag for the configured parent. |
| Mother | Verify/open arriving bag seals; individually scan parcels | Read the destination child/Mother and cage guidance, sort, bag, master-tag load and dispatch the next recorded truck/van leg. |
| Destination child | Verify/open the arriving bag; scan every parcel and finish its manifest receipt | Sort into the saved delivery-area bin and assign an eligible local rider, or stage secure buyer collection. |
| Delivery rider | Scan the assigned actual parcel out of the hub | Record recipient, original COD/prepaid evidence and private POD at actual handover. |
| Counter | Stage an actually received self-pickup parcel | Verify private code, identity and original payment before actual release. |

An assigned station operator can receive and prepare manifests at that physical hub. The approved company owner creates the network, personnel, tariffs and fleet. A platform administrator's oversight access does not authorize ordinary physical scans.

## Scanning

Scan Station works directly without visiting Overview. Its prompt distinguishes inspection from a permitted confirmation. An inspection or camera preview never transfers custody. Follow the actual next action returned by the server. For a bag transfer, prepare a matching draft manifest, pack/seal in Mother Bags, scan the master tag onto that draft, seal its list and record departure. At arrival, verify/open the bag first, scan individual waybills and close the received manifest.

The same-child optimization records local destination receipt with the original intake, then permits local sort and dispatch. It creates no transport manifest or Mother-Hub arrival. A different-child journey must traverse the configured hierarchy.

## Exceptions

- A wrong hub produces an alarm/error and the expected stop. A dispatched parcel's unexpected physical scan retains a misroute hold. Set it aside and follow company-authorized Parcel recovery through Mother Hub(s).
- Missing, damaged, duplicate and unexpected parcel observations preserve original custody. Supported receiving/recovery evidence is required to resolve them.
- An intact-seal checkbox is an actual observation. A broken or mismatched seal needs discrepancy handling.
- A missed historical handoff uses the existing reviewed Handover issues/recovery process. It does not become a free-form status edit. Bag membership is released only by supported receiving or corrective recovery.
- Refusal/third failure returns through the reversed frozen route, ending with actual seller receipt. A local return stays at its one child hub.
- Completed-order buyer returns retain the seven-day delivery window, separate return barcode, actual intake/photo, route receipts, seller receipt and original refund funding.

## Money at the station

The original buyer payment, rider-to-hub cash offer, counted receipt, difference review, hub-to-platform handover, platform reconciliation and external seller payment are separate facts. The exact rider shift cannot close while COD is held, a handover is pending or a rider difference is unresolved. A counted hub receipt releases that COD rider's delivery entitlement; it does not claim that a bank payout happened.

New valid deliveries complete automatically and release the authoritative earnings ledger. Use the selected company's frozen standard or dedicated model. Historical version-one orders and money keep their original buyer-confirmation/settlement contract. See [deployment](new-docs-era/DEPLOYMENT.md) and [the roadmap](CORE_FLOW_ROADMAP.md) for release evidence.
