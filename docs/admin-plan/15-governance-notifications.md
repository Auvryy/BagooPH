# B15: Persistent In-App Governance Notices

This is a bounded execution plan, not an implementation-status report. Use the [index](README.md) for order, [workflow](WORKFLOW.md) for Git/testing, and [roadmap](../CORE_FLOW_ROADMAP.md) for current evidence.

## Delivery boundary

| Item | Requirement |
|---|---|
| Git branch | `admin/governance-notifications` |
| Phase | 4 |
| Minimum prerequisites | [B13](13-phase0-acceptance.md), [B14](14-exception-oversight.md); verified Phase 4 persistent notification foundation |
| Result | Real governance events notify the correct recipient once, after commit. |

## Purpose

Connect review, restriction, correction, closure where applicable, and accountable recovery decisions to persistent in-app notices. Reuse the phase's unread/read infrastructure; a notification failure must never undo a committed governance decision.

## Contracts and inspection targets

Read [ADMIN_FLOW.md](../ADMIN_FLOW.md), [CORE_FLOW_VALIDATION_AND_EDGE_CASES.md](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md), and [SYSTEM_FLOW_AND_SPECIFICATIONS.md](../SYSTEM_FLOW_AND_SPECIFICATIONS.md).

- [BUYER_FLOWCHART.md](../BUYER_FLOWCHART.md)
- [SELLER_FLOW.md](../SELLER_FLOW.md)
- [COURIER_FLOW.md](../COURIER_FLOW.md)

Inspect these existing files before proposing schema or routes. The list is a starting point; trace their callers, migrations, middleware, and tests. Planned persistence is not a claim that a model already exists.

- [app/Services/KycDecisionService.php](../../app/Services/KycDecisionService.php)
- [app/Services/KycSubmissionService.php](../../app/Services/KycSubmissionService.php)
- [app/Http/Middleware/HandleInertiaRequests.php](../../app/Http/Middleware/HandleInertiaRequests.php)
- [resources/js/Pages/Auth/PendingApproval.tsx](../../resources/js/Pages/Auth/PendingApproval.tsx)
- [resources/js/Pages/Admin/Users.tsx](../../resources/js/Pages/Admin/Users.tsx)
- [routes/web.php](../../routes/web.php)
- [tests/Feature/Buyer/ChatUnreadNotificationTest.php](../../tests/Feature/Buyer/ChatUnreadNotificationTest.php)

## Implementation sequence

1. Inspect the actual Phase 4 event/notification implementation and recipient authorization. Existing chat unread behavior is context, not proof that a general durable governance-notice model exists.
2. Map committed decision/recovery events to exact recipient and safe message content. Use event identity plus recipient uniqueness; repeated decision/retry cannot duplicate a notice.
3. Dispatch notice creation after the governance transaction commits using a durable retry approach supported by the phase foundation. Notification outage must preserve the committed decision and permit later delivery.
4. Expose owned unread/read lists and acknowledgement with pagination and current authorization. Restricted applicants may see permitted own review/recovery notices without gaining transactional or admin powers.
5. Link to existing authorized holding/audit/exception records. Do not put raw evidence paths, OTP/passwords, plaintext pickup codes, or other companies' private context in message payloads.
6. Verify per-recipient visibility, retry/read idempotency, post-commit failure recovery, and empty/unavailable behavior; avoid a parallel chat-specific notification subsystem.

## Decision and scope rules

- The event is the committed decision, not a button click or duplicate request. Identical retries preserve decision and notice identity.
- Post-commit notice failure does not roll back or falsely report that the decision never happened.
- Notice read acknowledgement is owned and idempotent. Seeing a notice does not authorize its linked mutation.
- Security/privacy restrictions still apply to recipients and links; no leakage through notification text.
- Use persistent in-app only. Do not add paid/external email/SMS/push services or unsupported announcement campaigns.

## Data, legacy records, and recovery

- Retain real event identity and delivered/read state according to existing persistence. Never fabricate notices for imagined historic decisions.
- Legacy events without a durable record are not backfilled as current success. Any backfill needs explicit provenance and separate review.
- Failure/retry tests use isolated records and queues; do not send messages to real users or external services during verification.

## Exclusions

- Building a missing Phase 4 foundation ad hoc, chat redesign, marketing broadcasts, and external delivery providers.
- Changing approval/restriction outcomes to match delivery failures or exposing private evidence inline.
- Financial receipt/remittance notices before the real B16/B17 events exist.

## Acceptance cases

| Case | Required result |
|---|---|
| Committed review/restriction | Correct account receives one safe persistent notice. |
| Rolled-back decision | No success notice exists. |
| Duplicate event/retry | One recipient/event notice; original decision unchanged. |
| Notice persistence outage | Decision stays committed; durable retry delivers later without duplicate. |
| Owned unread/read | Accurate state; repeat acknowledgement is safe. |
| Foreign recipient ID | Read/list/acknowledgement denies with no information leak. |
| Restricted own applicant | Permitted holding notice visible without granting checkout/privileged action. |
| Current linked access | Changed authorization denies linked sensitive record as required. |
| Secret/privacy payload | No paths/passwords/tokens/plaintext counter code or foreign context. |
| Empty/missing infrastructure | Honest empty/unavailable or prerequisite blocker; no simulated success. |

## Verification and review

Start with the verified Phase 4 notification suites and governance-decision tests, with ChatUnreadNotificationTest retained as a regression reference. Add focused behavior tests for uncovered acceptance cases; do not assume an existing suite covers the proposed feature.

Use isolated SQLite `:memory:`. Follow [WORKFLOW.md](WORKFLOW.md) for explicit environment overrides, broader affected tests, exact failure-identity comparison, and the frontend build when UI changes. Review transaction/lock ordering separately; SQLite does not prove simultaneous PostgreSQL concurrency.

Split backend/domain tests, affected UI, and documentation evidence into small logical commits where useful. Report every local commit and actual check. Keep implementation status and scoped before/after ratings in the roadmap, not in this plan.

## Stopping point and bounded prompt

Stop after governance event notices integrate with the actual Phase 4 foundation and survive retries/outages. Do not expand into messaging services or finance before its event sources exist.

```text
Implement only B15 from docs/admin-plan/15-governance-notifications.md on admin/governance-notifications.
Read AGENTS.md and the plan's contracts. Verify dependencies and inspect executable code first.
Complete this branch's acceptance cases, required checks, local commits, and roadmap evidence.
Preserve unrelated changes. Do not push, merge, or start the next branch. Report blockers without expanding scope.
```
