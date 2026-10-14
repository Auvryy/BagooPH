# H04 — Native contract and final verification

Start after the reporting and recovery services pass their separate gates. This repository owns the backend contract; changes to the Flutter rider app need its own task and session.

Use the [writer and API contract](04-writer-and-api-contract.md) for the rider
surface, role-owned boundaries, command retries and private evidence acceptance.

## Approach

- Expose scoped report, evidence, review status and permitted recovery actions under the existing versioned bearer API patterns. Reuse web domain services and recheck authority on every command.
- Define bounded upload errors, stale-source conflicts, request-token retries and read-only history. Never expose private storage paths or unrelated roles' records.
- Provide a clear handoff with endpoint shapes, examples and deployed prerequisites. Do not claim mobile readiness until the rider application integrates and verifies it.
- Complete cross-role checks from seller observation to rider acknowledgement, authorized hub receipt and valid route continuation. Confirm that reporting alone changes no custody or cash.

## Acceptance gate

Check bearer scope, revoked/expired tokens, current resource authority, upload limits, lost responses, duplicate requests, stale pages, each hold and actual receipt. Record unavailable real database concurrency and device checks separately. Demonstrate the canonical route and review history without backdated scans or unsupported shortcuts.
