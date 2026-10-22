# Hub-managed parcel transfers

A manifest records the parcels, sending hub, receiving hub, vehicle, responsible driver and physical handover evidence for one transport leg. Existing hub staff operate it; no extra logistics administrator login is needed.

## Responsibilities

| Existing account | Work |
|---|---|
| Origin Bayan Hub handler | Confirm pickup intake, prepare and scan the outbound manifest, seal it, record departure |
| Mother Hub handler | Receive incoming parcels and close their manifest; prepare the next transfer; manage the company's network and personnel |
| Destination Bayan Hub handler | Receive and close the incoming manifest, sort parcels and assign eligible final-mile riders |
| Pickup and delivery riders | Confirm their own physical handovers and required delivery/COD proof |
| Platform administrator | Review account/company eligibility and independent governance; no routine custody scans |

Company management does not confer another facility's physical scan permission. Legacy company-owner accounts and immutable historical records remain compatible; existing accounts are not deleted or converted to another role.

## Doorstep journey

1. Buyer checks out; seller confirms, prepares and marks ready for pickup.
2. Pickup rider claims, scans and confirms collection using the original waybill.
3. Origin hub handler scans and confirms physical intake.
4. Sending handler opens **Manifests**, selects the next required hub and its eligible source-hub vehicle/driver, and prepares the draft.
5. Sending handler records each actual outbound waybill, seals the list and records departure.
6. Receiving handler opens that incoming manifest, records each actual parcel receipt and closes the manifest when its required receipts and discrepancies are resolved.
7. Repeat steps 4–6 through every required Mother Hub, then destination Bayan Hub. Separate Mother Hubs require separate legs.
8. Destination handler sorts and assigns the delivery rider. The rider records departure and actual delivery, including required COD and proof.
9. Buyer confirms receipt to complete the order.

The generic scanner identifies parcels and links the required manifest. Previewing a barcode, assigning a rider or preparing a draft never transfers custody. The manifest reference identifies a transport list; the parcel waybill remains unchanged.

Feeder transport uses a closed van. Mother-to-Mother transport uses a wing truck. A driver must have a current matching courier profile, company, source hub and vehicle assignment and be available for new work. Busy or suspended drivers cannot take a new departure.

## Existing demo transport repair

Deploying code does not copy seed data into an existing database. For the reserved BGX demo network, an explicit bounded repair is available after deployment:

```bash
./bagoo.sh artisan db:seed --class=LogisticsTransportSeeder --force
```

This creates missing demo transport drivers and source-hub vans without resetting the database or changing existing passwords, restrictions or custody. It can attach the missing driver to a matching unused demo van. Conflicting assignments or retained vehicle history stop the transaction with a message for manual review. Do not replace this command with `fresh` or a full reseed of operational data.

New complete demo seeds use the existing Mother Hub handler for company ownership and management, alongside the Bayan handlers, pickup rider, delivery rider and actual transport drivers. Existing deployments can use their eligible Mother Hub assignments for management without transferring legal company ownership or deleting the former owner.
