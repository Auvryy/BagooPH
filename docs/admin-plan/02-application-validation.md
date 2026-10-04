# B02: Shared Canonical Application Validation

This is a bounded execution plan, not an implementation-status report. Use the [index](README.md) for order, [workflow](WORKFLOW.md) for Git/testing, and [roadmap](../CORE_FLOW_ROADMAP.md) for current evidence.

## Delivery boundary

| Item | Requirement |
|---|---|
| Git branch | `admin/application-validation` |
| Phase | 0 |
| Minimum prerequisites | [B01](01-seller-category-approval.md) |
| Result | Registration, correction, and approval validate the same canonical application fields. |

## Purpose

Create one server-side meaning for names, contact details, addresses, postal codes, identifiers, and role-specific application enums. Reuse the completed birth-date and private-file rules; an admin should see the same readiness criteria that applicants must satisfy.

## Contracts and inspection targets

Read [ADMIN_FLOW.md](../ADMIN_FLOW.md), [CORE_FLOW_VALIDATION_AND_EDGE_CASES.md](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md), and [SYSTEM_FLOW_AND_SPECIFICATIONS.md](../SYSTEM_FLOW_AND_SPECIFICATIONS.md).

- [SELLER_FLOW.md](../SELLER_FLOW.md)
- [COURIER_FLOW.md](../COURIER_FLOW.md)
- [SORTING_CENTER_LOGISTICS_FLOW.md](../SORTING_CENTER_LOGISTICS_FLOW.md)

Inspect these existing files before proposing schema or routes. The list is a starting point; trace their callers, migrations, middleware, and tests. Planned persistence is not a claim that a model already exists.

- [app/Http/Controllers/Auth/RegisteredUserController.php](../../app/Http/Controllers/Auth/RegisteredUserController.php)
- [app/Services/KycSubmissionService.php](../../app/Services/KycSubmissionService.php)
- [app/Services/KycDecisionService.php](../../app/Services/KycDecisionService.php)
- [app/Rules/BirthDate.php](../../app/Rules/BirthDate.php)
- [app/Services/BirthDateEligibility.php](../../app/Services/BirthDateEligibility.php)
- [app/Models/User.php](../../app/Models/User.php)
- [app/Models/Shop.php](../../app/Models/Shop.php)
- [app/Models/LogisticsCompany.php](../../app/Models/LogisticsCompany.php)
- [resources/js/Pages/Auth/PendingApproval.tsx](../../resources/js/Pages/Auth/PendingApproval.tsx)

## Implementation sequence

1. Inventory actual application fields, database length/nullability constraints, current role form payloads, and resubmission paths. Build a field-to-contract matrix before choosing reusable rules or services.
2. Normalize approved text to NFKC and trim before validation. Reject controls, bidi/zero-width manipulation, markup, and meaningless required text; never truncate to fit a column.
3. Apply person names 2-100 characters, meaningful addresses 5-500, notes 1-1,000 when required, four ASCII postal digits, Philippine mobile canonical +639 form, and valid canonical business contacts. Keep limits within actual schema or use a justified non-destructive migration.
4. Validate exact role-specific enums, supported locations, email uniqueness under case-insensitive comparison, and scoped IDs. Distinguish permissible free text from server-backed serviceability; do not trust client coordinates.
5. Use the same rules for new applications, permitted unreviewed corrections, and KYC readiness. Bind changed canonical submission fields to the review snapshot; keep rejected error messages field-specific.
6. Align only affected registration/holding/inspection inputs with backend errors and formats. Test existing valid Philippine names/addresses and private upload rollback alongside adversarial inputs.

## Decision and scope rules

- Buyer optional birth date does not inherit the worker adult minimum. Existing BirthDateEligibility remains the authority for completed years in Asia/Manila.
- Email domain normalization and case-insensitive duplicate checks must not silently rewrite verified legacy identity; inspect uniqueness/index effects first.
- Business contact landlines remain permitted where the contract allows them. Do not apply a mobile-only rule indiscriminately.
- Canonicalization of reviewed identity is a controlled correction in B08. New validators cannot quietly overwrite old approved values or remove independent restrictions.

## Data, legacy records, and recovery

- Do not bulk rewrite existing profiles. Identify invalid/ambiguous legacy records for controlled review; distinguish a validation blocker from a fabricated replacement value.
- Inspect existing column lengths before accepting the normative maximum. Reject safely until the needed schema change is explicitly implemented.
- Preserve immutable KYC evidence and earlier decisions; failed validation creates no changed application, partial upload, or approval.

## Exclusions

- General checkout/cart/product validators outside application reuse, national address datasets, third-party phone services, or new accreditation requirements.
- Independent shop review (B03), controlled reviewed-identity repair (B08), and active-work restrictions/closure.
- New role values, public admin signup, approval from client flags, or broad authentication redesign.

## Acceptance cases

| Case | Required result |
|---|---|
| Legitimate names/contact formats | Approved diacritics/punctuation and permitted mobile/landline inputs normalize consistently. |
| Unsafe text | Controls, bidi, zero-width, executable markup, digits in names, and meaningless required values reject. |
| Length boundaries | Minimum/maximum are honored without silent truncation or database exceptions. |
| Postal look-alikes | Four ASCII digits pass; mixed scripts, signs, decimals, or wrong lengths reject. |
| Email duplicate/race | Case-equivalent account identity cannot produce duplicate registrations; no partial profile. |
| Enum and ID injection | Unknown vehicle/state or foreign resource ID fails server-side. |
| Same values through three entry points | Registration, allowed correction, and readiness accept/reject the same canonical fields. |
| Changed field after inspection | Review token and inspection are stale; completed history remains immutable. |
| Reviewed legacy record | Ordinary edits cannot silently normalize reviewed identity into a new approval. |
| Failure/retry and role gates | Rollback preserves files/restrictions; role and adult eligibility still gate root/subdomain access. |

## Verification and review

Start with KycRegistrationTest, KycDecisionGovernanceTest, WorkerBirthDateEligibilityTest, GoogleOAuthTest, and SharedPortalAccessTest with focused shared-field cases. Add focused behavior tests for uncovered acceptance cases; do not assume an existing suite covers the proposed feature.

Use isolated SQLite `:memory:`. Follow [WORKFLOW.md](WORKFLOW.md) for explicit environment overrides, broader affected tests, exact failure-identity comparison, and the frontend build when UI changes. Review transaction/lock ordering separately; SQLite does not prove simultaneous PostgreSQL concurrency.

Split backend/domain tests, affected UI, and documentation evidence into small logical commits where useful. Report every local commit and actual check. Keep implementation status and scoped before/after ratings in the roadmap, not in this plan.

## Stopping point and bounded prompt

Stop after the actual application-field matrix has one verified server rule set, affected forms display useful errors, and the roadmap records legacy limits. Resource gating and reviewed repair are not included.

```text
Implement only B02 from docs/admin-plan/02-application-validation.md on admin/application-validation.
Read AGENTS.md and the plan's contracts. Verify dependencies and inspect executable code first.
Complete this branch's acceptance cases, required checks, local commits, and roadmap evidence.
Preserve unrelated changes. Do not push, merge, or start the next branch. Report blockers without expanding scope.
```
