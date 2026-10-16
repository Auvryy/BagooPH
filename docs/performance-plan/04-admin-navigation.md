# PF04: Admin Navigation

## Outcome and dependency

Extend the accepted [shared policy](POLICY.md) to selected Platform Admin reads
without making stale decisions faster to submit. Preserve the admin workspace
and the authority in [ADMIN_FLOW.md](../ADMIN_FLOW.md). This performance batch
does not reopen or replace the completed numbered admin implementation plans.

## Planned work

Review actual routes and controllers for lists under `resources/js/Pages/Admin`
before permitting preparation. Start with reference/audit browsing and exact
search/filter/page navigation that has no acknowledgement or workflow effects.
Use same-component partial responses and backend lazy evaluation only where
their coherence and avoided work have been demonstrated.

| Surface | Policy |
|---|---|
| Allowed reference/audit lists | Short-lived, context-scoped intent preparation after current access is established |
| Dashboard counts and live review queues | Profile the current fresh read; show current counts and filters, not cached decision authority |
| KYC/shop/identity correction review | New activation requires current authorization and source state; do not speculate private identity evidence or reviewed documents |
| Account/product restrictions and closure | Preserve current state, independent review and existing safeguards; never pre-submit or enable from a stale snapshot |
| Advertisement/voucher review and reports | Keep actual views/clicks meaningful; do not load tracking destinations or approval actions while preparing navigation |
| COD, settlement and proof | Fresh authorized reads and actions; private proofs and payment mutations are excluded from data prefetching |

Recheck shared flash/error props, session transitions, current admin access and
changes made by another reviewer. A decision page opened from a list must
resolve the actual current decision/source revision before an action becomes
available. A denial or changed record must remain a real denial or conflict,
not an old cached successful page.

Invalidate affected lists/details/summaries after a successful local review or
restriction decision. Do not replace server-side authority with a cache tag or
hide inconsistent counts through a decorative loader.

## Acceptance and stopping point

- Non-admin, restricted, expired-session and foreign-context reads retain
  existing denials. Signing out/in as another account reuses no admin response.
- Search, filters, pagination, Back/Forward and current-page indicators match
  the exact returned records; preparation respects the global request budget.
- Two reviewers changing the same item cannot submit a stale cached decision;
  the actual existing conflict/refresh behavior remains available.
- Hover/idle creates no approval, restriction, closure, advertisement activity,
  private-document access, financial event or consumed success/error feedback.
- Fresh counts/current forms stay coherent after successful actions and failed
  requests. Speculation can be disabled without breaking ordinary navigation.
- Run the relevant governance/finance tests for touched behavior, frontend
  behavior checks and a production build. Record measured scope and remaining
  deployment checks; do not raise the whole admin security/readiness rating.

Stop after the selected admin reads. New governance, reporting and financial
features are outside this branch.
