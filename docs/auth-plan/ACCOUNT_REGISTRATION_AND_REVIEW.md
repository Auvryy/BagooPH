# Account registration and review

This delivery repairs account identity selection and company registration, then
adds clear status notices and an owner request for review. It uses one branch
with separate commits for independently reviewable concerns.

## Account identity

An account has one fixed role and its own password, approval and owned records.
The same normalized sign-in email may be used by different roles. Within one
role, an original or verified recovery email belongs to one account only.
Registration checks and database constraints must enforce the same scope.

Every sign-in form identifies its account type. Portal hosts enforce their
allowed roles. An older request without a role may continue only when exactly
one eligible identity matches. A shared email must never select the first row.
Password links, codes and Google identity selection must preserve that boundary;
the native rider API always selects the courier account. Changing or recovering
one password must leave other roles' passwords and tokens alone.

## Company registration

Shop and company names are unique within their respective directories after
case, Unicode and whitespace normalization. Warnings, step checks and final
submissions must agree; database conflicts return useful field errors. Existing
conflicting company records require deliberate correction before migration,
without silently deleting or renaming them.

New logistics company applications require a TIN using 12 ASCII digits including
the branch suffix, entered compactly or as `000-000-000-000`. Save one canonical
value and reject duplicate company TINs. This is the Bagoo form contract, not a
claim that every current tax service accepts only that length. Private TINs stay
out of public company lists and notifications. Existing missing values remain
missing until the owner supplies them for review.

Applicants can select multiple Philippine service regions. Save the complete
selection independently of the office address. Declared regions do not create
facilities, enable unsupported transport, or bypass the existing land-route
eligibility rules. Registration, correction and admin review show the same data.

## Status and owner review requests

After authenticating, each owner sees an understandable approval, rejection,
suspension or deactivation message. Restricted sign-in opens owned Account status. Permitted correction/review
actions and existing documented recovery remain available.
Ordinary operations retain their approval and activity gates.

Approval, rejection, suspension, deactivation and reactivation produce owned
in-app notices and email delivery intents. Send mail after the decision commits;
failed delivery must be visible and retryable without repeating the decision.
Do not send another status email merely because someone tries signing in.

Rejected, suspended and deactivated owners can submit a bounded explanation
through **Request review** and see its saved state and outcome. Prevent duplicate
open requests for the same current restriction/rejection. Preserve the source
decision and reject stale reviews. Eligible admins other than the owner review the request with a
reason; application approval and account reactivation continue through their
existing evidence and eligibility checks. A request or its acceptance alone
cannot unlock an account, approve missing evidence or reopen a closed account.

## Acceptance

- Register the same email across public roles; reject same-role and
  case-equivalent duplicates, direct-request bypasses and persistence races.
- Sign in on local aliases and portal hosts; check wrong-role passwords,
  protected access, ambiguous legacy requests and independent recovery tokens.
- Check Google sign-in, additional recovery addresses and native rider entry.
- Reject duplicate shop/company names and TINs at final submission. Check
  invalid lengths, letters, missing required values, normalized equivalents,
  rollback and unchanged owner values.
- Save several selected regions, reload them in correction/admin review, and
  reject missing, duplicate, unknown or oversized selections.
- Check every role's status message, recipient ownership, committed delivery,
  retry and failed-transaction behavior.
- Check review request ownership, allowed states, duplicate requests, restricted
  operational denial, admin authority, stale decisions and truthful outcomes.
- Run isolated SQLite checks and the production frontend build. Record actual
  evidence, scoped ratings and deployment limitations in the roadmap. Prepare
  local commits and a separate pull request draft; publication remains user-owned.

## Review behavior and rollout

One pending request is retained for each owner and subject. An identical retry
returns it; a changed source supersedes it. Declined requests for an unchanged
state may be resubmitted after 24 hours. Owner explanations allow 20–2,000
characters; admin responses allow 5–1,000, with server validation and rate limits.
Application reopening returns to pending evidence review. Reactivation uses the
existing approval, age, scope and affected-work checks. Requests and decisions
are retained, and the admin cannot resolve their own request.

Shared owner feedback is separate from historic internal restriction reasons.
New activity decisions label the feedback as sent to the owner; existing private
notes are not copied into emails or status pages. Missing older feedback is
reported honestly and never prevents an appeal.

Normal migrations, configured mail delivery and the existing notification
scheduler are required. See [deployment checks](../WEB_AUTH_DEPLOYMENT.md#account-registration-and-review-update)
and the dated [roadmap evidence](../CORE_FLOW_ROADMAP.md#october-18-2026-account-identity-and-registration-review).
