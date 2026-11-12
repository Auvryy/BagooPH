# B09: Sorting guidance, manifests and dispatch

This package connects topic 15 and local staging from topic 27 to the existing B01 route, B02 workers, B07 custody and B08 packing commands. It changes guidance and closes obsolete writer shortcuts; it does not create physical scans, seals, departures or receipts on inspection.

## Retained route and staging

Version-two sorting and forward manifest loading use the parcel's purchased ordered hub IDs. A later parent-hub setting or a conflicting legacy endpoint field cannot redirect that shipment. A foreign or absent station produces no guessed next hop. Mother Hub transfers still need their actual inbound manifest receipt. Historical version-one journeys retain their earlier routing contract.

At the destination child hub, the scanner shows **Local delivery staging** and its recorded bin or proposed local shelf. Local bypass orders never enter a Mother Bag corridor. Different children still follow their purchased Mother Hub path. Reverse cargo and recovery remain owned by their explicit services; B12 will handle mixed forward/reverse loads.

The read-only **Outbound staging** panel groups eligible outbound cargo by its actual next hub. It excludes active manifests, received/removed members, held parcels and parcels without actual source custody. Open and sealed bags remain distinct. Unpacked freight is not automatically called loose cargo: that designation requires a retained single-package declaration showing it exceeds the bagging limits. Unknown combined-package dimensions stay unknown.

Weights and volumes come from B08 member snapshots or complete purchased single-package declarations. They are never parcel counts multiplied by assumed weights. Existing packing previews retain recorded membership, propose only a bag with available count/kg/volume capacity, and explain when measurements or individual loose loading are required. A proposal does not save membership. The separately labelled synthetic handling calculator is not a packing or shipping authority.

## Fleet choices and commands

The required class remains closed van for a feeder leg and wing truck for a Mother-to-Mother leg. Guidance checks the sending hub, company, active fleet/driver eligibility, exact assignment, available driver, current work, sealed/dispatched transport and configured kg capacity. It does not substitute a motorcycle, borrow a remote vehicle or invent a driver assignment. If the corridor exceeds an available vehicle, the operator must split its checked load or configure an eligible vehicle.

Only configured kg capacity is displayed. Summed package boxes do not establish actual vehicle fit, nesting or a vehicle volume rating. All actual manifest commands independently recheck their current authority, route, cargo, vehicle and version; a displayed suggestion is not a reservation.

**Open packing** leads to Mother Bags. **Prepare manifest** opens Transfers & manifests only when the corridor has sealed or declared loose cargo and an eligible vehicle. Its query hint is accepted only for a current corridor at a hub managed by that actor. The form retains those purchased sending/receiving hubs, including an original next hop whose parent setting later changed; vehicle selection and creation remain explicit. Malformed, inaccessible or unrelated hints do not preselect a route. Operators must still prepare the trip, load actual cargo, seal, depart and receive each arriving parcel. Partial arrivals, damaged seals, misroutes and recovery retain the existing manifest and custody records. The old service methods that invented packing/seals/departure now reject with instructions to use the existing commands, matching the already guarded HTTP shortcut.

No new migration, provider, API key, database reset or reseed is needed. B06/B08 prerequisite migrations remain necessary on a deployment that has not received them. Current verification, limitations and the scoped assessment belong in [the roadmap](../CORE_FLOW_ROADMAP.md); manual checks are in [B09/B10 review](MANUAL_REVIEW_B09_B10.md).
