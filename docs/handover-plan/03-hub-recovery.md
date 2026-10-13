# H03 — Hub and manifest recovery

Start after H02 and the existing parcel recovery checks. Cover missed origin intake, manifest loading/dispatch/receipt and final-mile handover at the correct facility.

Use the [writer and receipt contract](03-writer-and-receipt-contract.md) for
individual/batch holds, corrective manifest counts and existing recovery links.

## Approach

- Extend reviewed holds to each affected manifest, sorting, assignment and counter mutation. Inventory batch writers as well as individual parcel commands.
- Match the actual parcel, original manifest member, transport source, facilities and eligible handler. A company administrator authorizes; an assigned handler records actual receipt.
- Reuse existing wrong-hub, missing-parcel and restricted-custody recovery where their retained source requirements apply. Record new corrective observations where they do not; never fabricate a manifest load, receiving scan or transport vehicle.
- Preserve original expected/received counts and unresolved discrepancies. Connect corrective evidence to the original record rather than rewriting it.
- Route any physical recovery through the documented Mother Hub path. Release a hold only after the correct facility has actual custody and the compatible next step is verified.

## Acceptance gate

Test wrong facility/company, unrelated handlers, lost approval, missing source evidence, partial manifest receipt, competing scans, stale authorization, exact retries, cash separation, buyer-only completion and truthful parcel counts. Include restricted and failed-delivery return routes. Unknown custody remains open.
