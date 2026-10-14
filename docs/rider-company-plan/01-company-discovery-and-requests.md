# RC01 — Company Discovery and Requests

Give an approved, unassigned rider a truthful way to choose a company and submit
a request. Follow the [shared plan](README.md) and [workflow](WORKFLOW.md).
RC01 does not activate company membership or enforce the later consent boundary.

## Reuse and proposed additions

Inspect these existing anchors before implementing:

- [Company eligibility](../../app/Models/LogisticsCompany.php) and
  [network authority](../../app/Services/Logistics/LogisticsEligibilityService.php).
- [Rider profile](../../resources/js/Pages/Courier/Profile.tsx),
  [company Network page](../../resources/js/Pages/Hub/Network.tsx) and
  [web route registration](../../routes/web.php).
- [Registration validation](../../app/Services/AccountRegistrationService.php),
  [private evidence](../../app/Services/VerificationDocumentService.php) and
  [application values](../../app/Services/ApplicationValidationService.php).

Add proposed company intake preferences, join requests and append-only request
events through additive migrations. Exact table, route and class names are
implementation decisions. These records must not reuse KYC review states or
parcel custody states.

## Deliverable

- The owning company administrator can enable or pause rider requests. Record
  changes and current revision without changing company approval or activity.
- A rider directory exposes only eligible companies with enabled intake and an
  eligible hub. Show name, existing logo when present and recorded service
  localities; derive areas from eligible hubs rather than advertising national
  coverage, vacancies or delivery rates that have not been configured.
- Validate search/filter inputs, use stable pagination of 15 companies and
  retain filters. A missing logo uses the existing fallback; an empty directory
  explains that no company is accepting requests.
- The rider chooses one company, sees the profile-sharing explanation and
  confirms submission. An optional plain-language note may describe relevant
  availability or preferred locality; it cannot set approval or assignment.
- Save the original company, rider, safe profile summary, actor, server time,
  sharing acknowledgement and actor-scoped submission key. The current account
  and company are rechecked at submission rather than trusting displayed flags.
- Show the rider's own request history and allow withdrawal of an open request.
  The company's own pending-request read is available to its administrator;
  the working review/offer interface follows in RC02.

## Input and privacy contract

Use [the form review](../FORM_VALIDATION_REVIEW.md) for valid, invalid, boundary,
permission, persistence and recovery cases. Proposed bounds are search 0–100
characters, supplied application note 5–500 normalized meaningful characters
and page 1–1,000,000. Blank optional notes normalize to absence; reject unsafe
text, malformed identifiers and unexpected managed inputs on the server.

An active, approved courier without an operational placement must still be able
to use joining controls. Do not require assigned-company permission to obtain
the first assignment. Pending/rejected applicants continue to use their holding
screen. An existing company association, an incomplete legacy profile or lost
eligibility explains the appropriate blocker rather than clearing saved data.

The note does not replace the registration name, sign-in email, contact fields,
vehicle review or private documents. Search must not become a global account
directory. Company administrators only read requests addressed to their company.

## State and retry boundary

RC01 implements proposed `pending` and `withdrawn` request outcomes. It reserves
the later offer/terminal transitions in RC02 without exposing fake success.
One open request per rider is checked under a locked account/profile and an
enforceable database constraint. Different companies competing for the same
rider cannot both produce open requests.

An identical create/withdraw retry returns its original result; changing the
company, note or intent under the same key conflicts. A transaction or audit
failure saves neither a request nor a partial profile/approval change. Turning
off company intake blocks new requests, preserves existing history and never
makes an existing rider lose their assignment.

## Acceptance gate

1. On both local path and courier-subdomain portals, an eligible unassigned
   rider discovers and applies to a real eligible company without placement.
2. Closed intake, ineligible owner/company/hub, stale choices, assigned riders,
   another role, suspended/closed accounts and fabricated IDs reject safely.
3. A company owner can manage only their intake and read only their requests;
   a handler, foreign company and Platform Admin cannot impersonate the owner.
4. Request/withdrawal state persists across reload/sign-in, preserves history
   and has correct actor/time/summary boundaries without private file access.
5. Exact retries, changed intent, competing request inputs and injected failures
   retain one open request and unchanged courier/vehicle/parcel/cash records.
6. Check text/page boundaries, meaningful errors, retained input, disabled repeat
   submission, no-results states and accessible responsive controls.

Run focused SQLite `:memory:` tests and the frontend build; broaden eligibility
and registration regression when affected. Keep unexecuted device, deployment
and simultaneous PostgreSQL checks explicit.

Stop at **Request submitted**, with no placement mutation. Record implementation
and its limits in the roadmap. RC02 must pass before describing company joining
as a complete mutually accepted workflow; existing manual placement remains
unchanged in RC01.
