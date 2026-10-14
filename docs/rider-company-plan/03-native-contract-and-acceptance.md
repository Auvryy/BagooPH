# RC03 — Native Contract and Final Acceptance

Start after [requests](01-company-discovery-and-requests.md) and
[accepted placement](02-offers-consent-and-placement.md) pass their gates. This
repository owns backend/native contracts. The Flutter app remains with its own
delivery session; backend verification cannot establish device readiness.

## Native approach

Inspect [account discovery](../../app/Services/RiderAccountService.php),
[current bearer middleware](../../app/Http/Middleware/EnsureRiderAccountToken.php),
[command retries](../../app/Services/Courier/RiderCommandService.php),
[API inputs](../../app/Services/Courier/RiderApiInput.php) and
[versioned route registration](../../routes/api.php). Reuse shared RC01/RC02
domain services and authorization rather than rebuilding the web decisions.

The exact endpoint names, token abilities and schemas are proposed work and must
be defined from the implemented domain contract. The required native surfaces are:

| Surface | Required behavior |
|---|---|
| Feature discovery | Advertise only when required schema/services exist; keep unrelated native access working when onboarding is unavailable |
| Company directory/detail | Bounded eligible company/service-area summaries with current intake; no private administrator or unrelated rider data |
| Owned applications | Create, list, read and withdraw the authenticated rider's requests |
| Owned offers | Read current terms; accept or decline only the current offer addressed to the rider |
| Command reconciliation | Recover the original committed write after a lost response, using one key for the same intent |
| Current assignment | Return saved membership/hub/readiness, with existing assignment provenance distinct from new accepted-offer evidence |

Joining must be available to an approved rider who has no company/hub. Keep
account eligibility distinct from operational placement eligibility; obtaining
the first assignment cannot require an existing company assignment. Pending,
rejected, suspended and closed accounts gain no new operational permissions.

Issue dedicated least-privilege onboarding abilities only when supported and
currently eligible. Preserve old tokens' existing permissions; do not silently
upgrade them. Explain when fresh sign-in is needed. Company review, fleet/hub
offers and management remain on the company web portal.

Use existing actor-scoped command journal patterns where their authorization
supports unassigned riders. Otherwise adapt the shared enrollment retry service
explicitly; do not invent a parcel to fit a command signature. Preserve owned
reconciliation, exact/changed retries, retention limits and authority checks.

## Wire and error contract

- Publish executable schemas, actual sanitized examples, field limits, current
  actions, safe sharing summaries, versions and expiry behavior.
- Resource IDs are bounded positive strings and timestamps carry UTC offsets.
  True acceptance must be a JSON boolean, bound to the exact version/terms.
- Apply RC01/RC02 text, page, ownership and unexpected-field rules on the server.
  Enrollment adds no uploads; original private KYC documents stay protected.
- Return private JSON validation/conflict/authentication/rate-limit errors even
  when callers omit an Accept header. Never expose framework errors or secrets.
- Use one retained idempotency key per intent. Reconcile before repeating an
  uncertain write; changed sources require refresh and explicit new intent.
- Show saved membership separately from current eligibility. A retained accepted
  result cannot restore a subsequently suspended company or rider.

## Final acceptance gate

1. Exercise the real web/native account sequence from unassigned approved rider
   through company request, company web offer and native rider acceptance.
2. Verify native discovery without placement, bounded directory/history reads,
   declined/withdrawn/rejected/expired outcomes and real current assignment.
3. Exercise wrong role/owner/company, forged managed fields, missing/narrow/old/
   revoked/expired tokens, lost current eligibility and stale/changed offer terms.
4. Verify lost responses, original-result replay, changed intent, concurrent
   decision inputs and injected rollback at every linked enrollment writer.
5. An accepted on-duty rider handles valid work through the original seller,
   origin Bayan Hub, Mother Hub and destination Bayan Hub chain. Another company
   cannot claim that rider's work; buyer completion and COD/settlement stay separate.
6. Existing seeded/company-associated riders still sign in and operate with
   their original records and no invented historical acceptance.
7. Validate the published executable contract against real response shapes and
   verify the scoped regression and production frontend build where changed.

SQLite tests cover deterministic competing/stale inputs and rollback. They do
not prove simultaneous PostgreSQL requests. Keep live environment, real database
contention, rendered-device, notification scheduler and Flutter checks separately
unverified until actually executed under their respective authorization.

## Rollout and mobile handoff

Apply additive migrations through the ordinary deploy path after user review
and publication. Preserve existing assignments, backups, immutable history and
private evidence. No fresh/reset database or synthetic legacy consent is needed.
Until the completed workflow is installed, feature discovery stays unavailable;
stopping new intake never deletes membership, acceptance, custody or money data.

Provide the Flutter AI with the accepted API document, schemas and examples;
capability/old-token behavior; original assignment handling; request/offer states;
field limits; lost-response reconciliation; and deployment prerequisites. Ask it
to preserve the rider app's established navigation/style and verify the actual
device journey. Do not edit its repository from this delivery session.

Stop after the backend handoff. User publication, Azure rollout and actual
Flutter acceptance are distinct from local completion and require their own evidence.
