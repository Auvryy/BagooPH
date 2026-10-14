# RC02 — Offers, Rider Consent and Protected Placement

Start after [RC01](01-company-discovery-and-requests.md) passes. Complete the web
journey by connecting an independently issued company offer to the rider's own
acceptance and the existing placement service. Read the [policy](README.md).

## Existing placement boundary

Reuse [LogisticsPlacementService](../../app/Services/Logistics/LogisticsPlacementService.php)
and [its controller](../../app/Http/Controllers/Logistics/LogisticsHubWorkstationController.php).
Company ownership, current approved courier, hub, vehicle, covered barangay,
stale placement and active parcel/manifest guards remain enforceable. Placement
stays separate from Platform Admin KYC and leaves the rider off duty.

Inspect [retained placement records](../../app/Models/LogisticsPlacementRecord.php),
[original COD responsibilities](../../app/Services/Finance/CodCashService.php),
[governance notices](../../app/Services/Notifications/GovernanceNoticeService.php)
and [current notification privacy](../../app/Services/Notifications/NotificationCenterService.php)
before adding associations or notices.

## Deliverable

- Add the company's own pending requests, offers and a paginated current rider
  roster to Network. Show saved hub/vehicle/duty/eligibility separately. Roster
  discovery is limited to the company's associated riders and actual applicants.
- The owning eligible company admin may reject with a meaningful reason or
  issue an assignment offer for an open request. Handlers cannot review offers.
- Derive current rider placement from server records. Do not make an operator
  guess the current hub, expose unrelated accounts or choose a busy/foreign
  vehicle as if it were available.
- An offer shows company, eligible hub, optional covered barangay, personal or
  fleet vehicle choice, issue/expiry time and readable operational information.
  Use 5–2,000 meaningful plain-text characters for that information; supplied
  decision reasons use 5–500. The server owns versions, references and dates.
- The rider sees the exact offer and its terms in Assignment, with explicit
  **Accept assignment** and **Decline** actions. Capture a true acknowledgement
  of the displayed version; never treat an administrator as accepting for a rider.
- Company withdrawal, rider withdrawal/decline, rejection and server expiry
  retain history. Reopening means a new reviewed offer/request, not rewriting
  an old acceptance or terminal decision.
- Send owned persistent in-app notices after commit for request, offer and
  terminal decisions. Include useful current links and no private documents.
  Delivery failures retry without rolling back or duplicating placement.

## Proposed state contract

| Current request state | Authorized action | Proposed result |
|---|---|---|
| `pending` | Rider withdraws | `withdrawn` |
| `pending` | Owning company rejects with reason | `rejected` |
| `pending` | Owning company issues a current assignment offer | `offered` |
| `offered` | Rider accepts the current valid offer | `accepted`, with placement committed atomically |
| `offered` | Owning company replaces an unaccepted offer | `offered`, with a new current version and retained superseded offer |
| `offered` | Rider declines or withdraws | `declined` or `withdrawn` |
| `offered` | Owning company withdraws with reason | `cancelled` |
| `offered` | Server expiry is reconciled | `expired` |

An offer expires seven calendar days after issue. All reads present truthful
effective expiry; every acceptance rejects after expiry even if the scheduler
has not run. Reads do not append mutation events. Reconcile expired state through
an explicit command or the existing scheduler pattern, then permit a new request
without relying on a constantly running worker for safety. Record actual event
time; do not invent a historical job execution time.

Replacing an unaccepted offer retains its prior version as superseded and issues
a new version under the same open request. Terminal requests cannot be reopened;
another application requires a new request. Company intake, actor approval,
rider reviewed identity/vehicle and recorded placement,
placement, hub and fleet sources are captured and checked again. A changed
source or disable/re-enable cycle cannot silently revive a stale offer.

Offers reserve neither a rider nor a fleet vehicle. Recheck availability at
acceptance; a competing accepted assignment produces a conflict without partial
consent. Ordinary contact/photo edits preserve approval and are not treated as
reviewed identity changes merely because a generic update timestamp changed.

## Consent enforcement and retained records

Additive persistence retains the exact offer, recipient, issuer, source versions,
term text/hash, acknowledgement and server acceptance time. Match them to the
same rider, company and placement intent. Enforce unique open requests and
one company association using consistent locks and database constraints.

Acceptance creates the consent event and invokes the existing placement writer
inside one transaction. Audit, vehicle, notice-outbox or placement failure rolls
back the whole mutation. Identical accepted retries return the original outcome;
changed keys/intent or competing offers cannot create a second membership.

Every path that creates a **new company association** must require the accepted
source in the shared backend service, including direct service calls, root/
subdomain routes and the old email-based form. Update that form to direct new
joins into the offer workflow. A request ID, hidden button, admin confirmation
or client `accepted=true` is insufficient authority.

Preserve existing associated riders and seeded accounts without inventing past
consent. Same-company hub/vehicle changes continue through their current reviewed
placement path, with original sources and existing custody guards. Cross-company
transfers, automatic departure and cash-responsibility reassignment are excluded.
Suspension and closure retain their existing narrow parcel/cash recovery paths.

## Acceptance gate

1. Demonstrate approved unassigned rider → request → company offer → rider
   acceptance → saved hub/vehicle placement → deliberate duty activation.
2. A request, company approval or unaccepted offer grants no new membership,
   parcel access, KYC decision, role change or cash authority.
3. Foreign owners/requests/hubs/vehicles, another rider, inactive resources,
   unapproved/suspended accounts, stale/expired offers and false acknowledgement
   reject on both portal contexts and direct service entry points.
4. The old placement form cannot bypass consent for new company associations;
   handler assignment and legitimate existing same-company placement remain valid.
5. Check exact expiry, disable/re-enable sources, changed terms, competing
   acceptances and lost-response replay. Assert the original actor/time/offer.
6. Inject failures during consent, fleet, placement, history and outbox writes;
   verify no partial association, duplicate record or unwanted duty activation.
7. Preserve existing assignments, immutable placement history, held cash,
   manifests, parcel custody and private registration evidence.
8. Verify company roster pagination, preselected recorded values, field limits,
   retained rejected input, decision notices and readable responsive states.

Run focused company/placement/notice tests, shared courier/finance/governance
regression and the frontend build. Repeat the canonical parcel journey using a
newly accepted rider; approval and membership never substitute for actual scans.
Stop after the web contract passes. Flutter integration follows RC03 separately.
