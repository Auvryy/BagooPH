# Rider Company Onboarding Plan

An approved rider should be able to choose a Bagoo logistics company, request
membership and accept a proposed working assignment. This is part of arranging
parcel fulfillment. Keep it small and use the existing company placement rules.

This plan describes proposed work. It does not implement a company picker,
application, offer, consent record or native endpoint. Current findings and
implementation evidence belong in [the roadmap](../CORE_FLOW_ROADMAP.md).
Selecting a later batch requires its own scope decision.

Read the [documentation map](../README.md), [courier flow](../COURIER_FLOW.md),
[admin authority](../ADMIN_FLOW.md), [custody contract](../SORTING_CENTER_LOGISTICS_FLOW.md)
and [validation contract](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md). Those
contracts retain authority over approval, account roles, custody and money.

## Intended experience

1. The user creates a separate courier account and completes the existing
   identity, licence and vehicle review. Buyer and seller accounts keep their roles.
2. After Platform Admin approval, an unassigned rider opens **Profile →
   Assignment → Join a company**.
3. The rider searches approved companies accepting requests, checks their actual
   service areas and applies to one company, such as the demo Bagoo Express.
4. That company's administrator reviews the request and proposes an eligible
   hub, optional covered barangay and suitable personal or fleet vehicle.
5. The rider reads the exact assignment offer and accepts or declines it.
6. Acceptance saves the company/hub placement atomically. The rider remains off
   duty until choosing to go on duty through the existing control.

Application history and notifications show the result. Submitting a request or
receiving an offer never grants parcel access, approval or company membership.

## Three bounded deliveries

RC means Rider Company. These batches are separate from the admin and handover plans.

| Batch | Plan | Suggested branch | Dependency and stopping point |
|---|---|---|---|
| RC01 | [Company discovery and requests](01-company-discovery-and-requests.md) | `feat/rider-company-requests` | Existing eligibility and private registration review; stop at saved requests and owned history |
| RC02 | [Offers, consent and placement](02-offers-consent-and-placement.md) | `feat/rider-company-consent-and-placement` | RC01 acceptance; stop at mutually accepted web placement and company roster |
| RC03 | [Native contract and final acceptance](03-native-contract-and-acceptance.md) | `feat/rider-company-api-contract` | RC01 and RC02 contracts verified; stop at backend contract and Flutter handoff |

Start with RC01. RC01 and RC02 are candidates for an explicitly selected combined
delivery, with separate logical commits and gates. RC03 follows the settled
placement contract. Default to one batch per goal; completing one does not start
the next. Follow [the delivery workflow](WORKFLOW.md).

The effort planning ranges are RC01 **4–6 hours**, RC02 **6–9 hours** and RC03
**3–5 hours**, including focused verification and handoff. They are estimates,
not logged work or commitments. Schedule only selected work in dated Asia/Manila
windows after checking capacity and publication dependencies; keep deadlines
within November 20, 2026 and reserve time for later demonstration fixes.

## Proposed first-release policy

| Decision | Proposed rule |
|---|---|
| Eligibility | Active, email-verified, Platform Admin-approved adult couriers may apply without a company assignment. Company/hub eligibility is checked separately. |
| Intake | The owning eligible company admin explicitly enables requests. Eligible companies with at least one eligible hub can appear; intake defaults closed until deliberately enabled. |
| Membership | One active company association per rider, based on the existing courier profile. One open request across companies; withdrawal or a terminal outcome permits another request while unassigned. |
| Choice and consent | The rider requests the company and accepts the current offered assignment. An admin-entered email or checkbox is never evidence of rider consent. |
| Offer | Required eligible hub, optional covered barangay, current personal/fleet vehicle choice and readable operational information. No invented wages, earnings or employment promises. |
| Expiry | Proposed offer window: seven calendar days from issue, checked against the server clock. Expiry cannot be extended by the client or an identical retry. |
| Existing assignments | Retain their original placement evidence. Display them as existing assignments; do not manufacture past applications, contracts, acceptance or consent timestamps. |
| Company changes | Cross-company transfers, leaving a company and automated employment termination are deferred. A currently associated rider cannot use this feature to replace that company. |
| Same-company moves | Preserve existing reviewed hub/vehicle placement and custody guards. Present recorded current placement automatically; administrative changes are not a new company membership. |

Policy acknowledgement at account registration remains separate from acceptance
of a company's working assignment. This plan records operational membership
consent, not an employment contract or a determination of employment status.

## Privacy and required documents

Reuse the existing registration review. The rider already supplies three
required uploads: government-issued ID, driver's licence and vehicle OR/CR.
They use real JPG/JPEG, PNG, WEBP or PDF content, up to **5 MB per file**, and
private storage. Company application adds no upload requirement.

The receiving company sees only the applying rider's necessary work summary:
name, registered work-contact email/phone, locality, reviewed vehicle type and
current approval/assignment eligibility. Clearly explain that sharing before
submission. Do not expose original ID/licence/OR/CR files, storage paths, birth
date, private review notes, password data or another company's applications.
Existing private-document download authority stays unchanged.

Requests are visible to their rider and the owning company administrator.
Handlers, sellers, buyers and other companies cannot review them. Platform
Admin keeps existing approval/governance authority; a new unrestricted
application-review or placement override is outside this plan.

## Persistence and retained authority

Request, offer, expiry and consent records described here are proposed additive
persistence, not existing models or fields. Determine exact schema and routes
from executable code when implementing. Preserve company, rider, actor, source
versions, exact offer terms, server time, original request and decision history.

Use current eligibility, consistent locks, database uniqueness and actor-scoped
request keys. Offer acceptance and the existing placement transaction must commit
together. A failed write cannot leave a membership without its consent evidence,
or consent claiming a placement that rolled back. Notifications follow commit
and retry without duplicating the business result.

Do not change original sign-in identity, account role, KYC, private evidence,
parcel history, cash responsibility, stock or settlement through this feature.

## Design and boundaries

Rider controls belong in the existing Assignment section and courier shell:
Plus Jakarta Sans, local 8px corners, the `#FFFAFB` canvas, existing accent and
controls, responsive navigation and accessible focus/error feedback. Avoid
creating a separate visual identity for company joining.

Company requests, offers and the bounded rider roster belong in the existing
logistics Network workspace, using its own layout, surfaces and controls. Keep
facility/vehicle selection and readable status feedback familiar. Rider styling
must not restyle logistics pages or global theme tokens.

Dedicated recruitment chat, a public job board, payroll, salary calculations,
contract generation/signing, new KYC authorities, handler onboarding, external
services and Flutter application edits remain separate scope. Application
decisions and existing in-app notices provide the first-release communication.
