# Authentication and Registration Improvement Plan

Improve the existing account flows in bounded deliveries. Apply the
[essential form review](../FORM_VALIDATION_REVIEW.md),
[validation contract](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md) and relevant
role specification before implementation. This is planned work; current gaps
and evidence remain in [the roadmap](../CORE_FLOW_ROADMAP.md).

## Boundaries

Preserve fixed account roles, the original sign-in email, one approved shop per
seller, the master category rules and current approval authorities. Email
verification does not grant buyer purchasing access or worker approval. A courier
application remains separate from company placement. Controlled admin access
does not become public registration.

Use current normative limits rather than inventing restrictions from example
values. The selected password policy requires 8–128 characters with uppercase,
lowercase, a number and a punctuation/symbol character, while retaining
passphrases and existing sign-in compatibility. Worker adulthood cannot
automatically become a buyer age restriction.
Name components and full names need explicit, consistent limits. Additional
contact emails remain verified contact/recovery addresses rather than sign-in aliases.

Shared contacts and public shop/company names do not become globally unique by
default. Review a proposed phone, display-name, plate or licence uniqueness rule
against its purpose and existing legitimate records before enforcing it. Any
new constraint needs a conflict report and migration plan, with no automatic
deletion, renaming or reassignment of identity.

New tax/corporate identifiers, evidence categories, licence-expiry fields,
invitation-only onboarding and broader location coverage need separate policy
and schema scope. Do not add placeholder values or silently replace the existing
registration and review lifecycle to imply those features already exist.

## Proposed deliveries

| Batch | Scope and priority | Acceptance |
|---|---|---|
| AF01: Access and verification recovery | First verify any reported cross-role access using the exact protected endpoint, account state and deployment. Check both portal hosts and local aliases. Check login/logout sessions and OTP request failures, including non-JSON responses, stale sessions and genuine mail failures. Repair only reproduced gaps. | Other roles cannot read protected data or mutate it; public entry screens remain understandable. Verification failures preserve safe progress, explain a recoverable next action and never claim delivery success. Successful sign-in/logout and existing recovery paths retain their ownership gates. |
| AF02: Shared field validation | Align step feedback, backend rules and saved values across buyer, seller, courier and logistics registration. Apply the documented password policy to account creation/reset writers. Preserve raw phone input long enough to reject invalid or overlong values. Check complete email syntax, personal text, dates, required/optional fields, location dependencies, enums and supported operational identifiers. | Direct requests reject invalid values even with frontend checks bypassed. Valid normalized values survive reload without truncation. Invalid names, emails and phone values are rejected at the relevant step; no avoidable verification request starts for an already-invalid form. Errors return to the correct step. Existing legitimate records and sign-in passwords continue to work. |
| AF03: Terms and privacy acknowledgement | Add readable Bagoo terms/privacy content and an explicit required acknowledgement to public registration. Use existing pages if available; otherwise include real content and navigation in this delivery. Define how the accepted policy version and server timestamp are recorded. Keep optional promotional consent separate. | The control starts unchecked, works by keyboard and has a clear field error. Missing/false acknowledgement rejects on the server. Acceptance persists atomically with the account; a failed registration or retry cannot create misleading consent records. Existing accounts and demo fixtures have an explicit compatibility policy and no fabricated historical acceptance. |
| AF04: Cross-role acceptance and deployment | Complete field, lifecycle and recovery coverage for every public registration role plus controlled admin login. Cover pending, rejected, approved, restricted and closed states, original-email recovery, private evidence and current company/hub ownership. Prepare an additive deployment and retest handoff. | Every scoped check has an outcome and relevant evidence. Isolated PHP coverage and affected frontend builds pass. Live session and mail behavior are verified separately when access is available; a blocked live check stays visible. No development or production reset is needed to hide a defect. |

Each batch includes its own focused verification. AF04 checks the combined
result; it is not permission to postpone security or rejection tests until the end.
AF02 can proceed while AF01 gathers deployment evidence. AF03 depends on AF02's
shared submission/error contract; AF04 accepts the selected implemented batches.

Include first-time OAuth account creation in acknowledgement coverage. An
alternate entry point cannot bypass a required registration gate. When a shared
backend writer also serves the native rider API, review that API's compatibility
and prepare a contract handoff; native client implementation remains separate scope.

## Recommended next selection

Start with AF01 and AF02 together if they fit a bounded delivery, keeping access,
mail recovery and field-rule changes in separate logical commits. Verify the
access allegation before changing an already-protected route. Prioritize the
password contract mismatch and lossy phone transformations over cosmetic additions.
Then deliver AF03 and the combined AF04 acceptance. Selection and implementation
remain separate; this plan does not authorize all new field extensions.

Preserve each portal's existing split authentication layout, typography, accent,
spacing and error treatment. Reuse existing application fields, phone/date inputs,
OTP handling and server services where their contracts fit. Strengthen those
components instead of creating an independent validation system for every role.

Use the [authentication rollout](ROLLOUT.md) for password-hash compatibility,
deployment and the native account contract handoff. Implementation evidence and
remaining acceptance work belong in the roadmap.

Use the [registration field review](REGISTRATION_FIELDS.md) to check every
initial role application against its correction form, including sex, postal
code, operational identifiers and private upload limits. Preserve each portal’s
layout and field error treatment when adding shared controls.

## Planning windows

Windows are proposed work allocations in Asia/Manila, not logged activity or
promises about unattended work. Confirm capacity and dependencies when selecting
implementation, and reserve part of each window for checks and fixes.

| Date | Window | Proposed purpose |
|---|---|---|
| October 13, 2026 | 09:00 to 11:00 | AF01 reproduction, access checks and targeted recovery repairs |
| October 13, 2026 | 13:00 to 16:00 | AF02 field alignment, focused regression and review |
| October 14, 2026 | 09:00 to 11:30 | AF03 readable policies and acknowledgement behavior |
| October 14, 2026 | 13:00 to 15:30 | AF04 combined checks and deployment handoff |

These windows assume the existing lifecycle remains intact. Unavailable mail
delivery, additional schema decisions or newly reproduced defects may require
rescheduling within the documented delivery window. Preserve truthful task dates
and actual measured time; do not backfill a proposed schedule as completed work.
