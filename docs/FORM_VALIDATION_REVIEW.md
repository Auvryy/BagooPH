# Essential Form Validation and Verification

Form validation is a crucial acceptance requirement. A form is not complete
because a valid submission succeeds: invalid input, rejected permissions,
retries, saved values and recovery must also behave correctly. Apply this review
to registration, login, account settings, application corrections, catalogue
forms, checkout and operational controls whenever their behavior changes.

This guide defines a review method. The [validation contract](CORE_FLOW_VALIDATION_AND_EDGE_CASES.md),
[system lifecycle](SYSTEM_FLOW_AND_SPECIFICATIONS.md) and relevant role documents
remain the authority for business rules. A checklist or example cannot silently
change an age requirement, uniqueness scope, approval authority or field limit.
Current gaps and verified implementation belong in [the roadmap](CORE_FLOW_ROADMAP.md).

## Define each field before checking it

Inspect the existing request, service, model, migration and interface. Describe
the field's contract before deciding whether an observed result is a defect.

| Contract item | What must be clear |
|---|---|
| Purpose | Whether the value identifies an account, describes a contact, proves identity, selects a resource or records an amount. Similar-looking fields may have different rules. |
| Presence | Required, optional, conditional, derived or read-only; define what blank, whitespace and explicit null mean. |
| Type | String, integer, decimal, date, file, enum or collection. Reject unexpected arrays, objects, booleans and ambiguous coercion. |
| Format | Permitted characters, complete structure, normalization and deliberately unsupported input. Check the whole value. |
| Limits | Minimum and maximum length, value, precision, item count or file size, with the unit and inclusive boundaries specified. |
| Normalization | Which equivalent representations can become one canonical value without changing its meaning. |
| Uniqueness | Whether uniqueness is required and whether it is global, per account, per shop, per company or per parent record. |
| Dependencies | Which earlier selection or current server state makes a value available, required or invalid. |
| Authority | Who may submit it, which record they own, and whether it needs review rather than an ordinary owner edit. |
| Persistence | What is actually saved, what stays unchanged, and what happens if a later operation fails. |
| Feedback | The field message, pending state, success state, next action and retry behavior. Help text must describe the enforced rule. |

Do not copy one field's rule to every field with a similar label. A contact number
does not automatically become a unique sign-in identifier, and a public display
name does not automatically become a unique legal business identifier.

## Check the complete submission path

1. Check typing, pasting, selection, file replacement and movement between steps.
   Known invalid input should produce feedback at the relevant step. Where the
   form is already invalid, avoid opening verification or sending unnecessary mail.
2. Submit directly to the existing endpoint with frontend checks bypassed.
   The server must independently validate format, limits, permissions and state.
3. Inspect the saved result and reload it. A visible success message is not proof
   that the intended value or related records persisted.
4. Repeat the request and simulate an ordinary failure. Confirm that safe input
   remains usable and that records, uploads, counters and messages do not duplicate.

Formatting may add readable spacing or normalize an approved prefix. It must not
silently delete invalid characters, truncate an overlong value or turn a different
identifier into an accepted one. Validate raw input before a transformation can
hide the reason it was invalid. Preserve passwords exactly as entered.

For every required field, check a completely empty form and then one missing
field among otherwise valid values. For optional fields, check absence, an empty
value and a supplied invalid value separately. Optional never means unvalidated.

## Use meaningful boundaries and input classes

For an inclusive range, check one below the minimum, the minimum, one above it,
an ordinary valid value, one below the maximum, the maximum and one above it.
Calculate actual lengths, sizes and dates; labels alone do not establish a
boundary. Include both valid and invalid cases so a restrictive fix cannot pass
by rejecting everything.

| Field family | Essential checks |
|---|---|
| Personal names | Permitted diacritics and punctuation, meaningful text, normalized length, and rejection of digits, markup, controls and invisible characters. Distinguish full-name and component-name limits. |
| Email addresses | Missing local part/domain, internal whitespace, malformed input, permitted surrounding-space normalization, case-equivalent duplicates and cross-role ownership. Sign-in and recovery must preserve the original account identity. |
| Phone numbers | Accepted national/international representations, correct mobile prefix, complete digit count, unsupported characters and mobile versus landline purpose. Equivalent formats must share the same canonical value. |
| Passwords | Contract length boundaries, every required character group, confirmation, valid passphrases, no trimming, masked entry and meaningful reset errors. Check whitespace-only symbols and hidden/control characters. Apply the same creation rule to registration, reset and other password writers; ordinary login must continue to accept an existing valid password. |
| Birth dates and other dates | Real calendar dates, impossible dates, future dates, leap days and exact age boundaries using the server's Philippine calendar. Derive age on the server and apply adult requirements only to their documented roles. |
| Enums and selectors | Missing selection, supported values, fabricated IDs, inactive choices, foreign ownership and stale selections. Changing a parent must clear invalid descendants; the server rechecks the relationship. |
| Addresses | Meaningful street text, supported location relationships, postal format and actual serviceability. A matching label or selected dropdown is not proof that a parcel route exists. |
| Operational identifiers | Supported visible-character format, separators, case normalization, meaningful length and documented uniqueness. Define accepted formats before adding a more restrictive pattern. |
| Amounts and quantities | Zero where permitted, negative values, fractions where integers are required, excessive precision, exponent notation, maximums and current stock. Server calculations remain authoritative. |
| Files and images | Show subtle allowed-format and maximum-MB guidance beside every upload control, matching its actual server limit. Check required evidence, allowed content and extensions, videos and renamed invalid content, empty files, exact byte limits, count limits, private access and replacement/rollback cleanup. Browser file filters never replace server validation. |
| Confirmation controls | Default unchecked state, keyboard use, missing/false values and direct-request bypass. A required acknowledgement needs actual readable content and server-side acceptance. |

For a delivery address book, review the complete sequence as well as each field:
save and reload actual recipient details, change parent locations, reach the
saved-address limit, switch the default, attempt a forbidden default deletion,
and try another owner's identifier. Repeat optional address saves through
checkout so that a second entry point cannot bypass the limit. Place an order
before editing or deleting its saved address, then compare its buyer view, seller
view and printed recipient details with the original snapshot. Exercise failed
location loading, retry and out-of-order responses; a delayed response must not
restore a selection from a previous parent. These checks concern delivery contacts
and must not silently change reviewed legal identity.

Treat a duplicate at its documented scope as a separate case from an invalid
format. Test equivalent representations, another role's account, a different
owner and the race between validation and persistence. A database constraint
must enforce identity uniqueness where the contract requires it. Do not add
global uniqueness to ordinary shared contacts or display names without changing
and reviewing that policy.

## Check access and lifecycle separately

Review buyer, seller, courier, logistics and controlled admin entry points on
both the configured portals and their local path aliases. For each protected
page and mutation, check guests, the intended role, another role, unverified or
unapproved accounts, suspended/closed accounts and a stale authenticated session.
Include another owner's record and another company's or hub's resources.

Separate a public login or registration screen from protected operational data.
Record the exact requested path, authenticated role, response and data returned.
A public screen is not proof of a privileged-data leak; a hidden menu is not proof
that the endpoint is protected.

Email verification, application submission, approval, account activity and
company placement are distinct gates. Verification cannot grant approval, a
placement cannot change a role, and a resubmission cannot remove a restriction.
Keep the documented narrow existing-work exceptions intact.

## Check failure, retry and feedback

- Keep safe fields and the relevant step after rejection; focus the first useful
  error. Do not preserve or echo passwords, codes or private evidence in logs.
- Distinguish field rejection, an expired session, unavailable mail delivery,
  an unreadable response and a failed network request. Preserve enough safe
  diagnostic context to identify the failing layer.
- Disable repeated submission while pending, and independently protect repeated
  requests on the server. Account/profile creation and upload writes must roll
  back together when a required later write fails.
- Recheck verification expiry, resend cooldown, attempt limits, old-code
  invalidation and single-use tokens. An email address change must not retain a
  token for the previous address or purpose.
- A failed external delivery must provide an honest retry or recovery path.
  Never claim a message was sent merely because a request was accepted locally.
- Use the owning portal's established layout and controls. Keep error messages,
  focus states, password visibility, uploads and multi-step navigation usable
  without creating a different visual style for each new feature.

## Record evidence and decide the next action

For each finding, record the affected field/action, applicable contract,
observed behavior, failing layer, saved side effects, reproduction conditions,
priority and a concrete acceptance check. Exclude private input, credentials,
tokens and unrelated source material from shared documentation.

Separate confirmed defects, interface feedback gaps, contract disagreements,
deployment-only failures and work that has not been checked. Lack of execution
does not establish either success or failure. A local automated result does not
prove deployed browser behavior or a real email delivery.

Before handing off a changed form:

- Run focused, meaningful rejection and valid-submission tests with isolated
  SQLite `:memory:`. Include rollback, ownership and repeat submission when the
  affected operation requires them.
- Run the production build for frontend changes. Broaden tests when the shared
  rule affects other writers or roles; avoid implementation-mirroring tests.
- Verify that the interface, endpoint and saved values agree and that help text
  reflects the contract. Record any missing live-environment evidence.
- Follow repository rules for browser checks: routine verification uses tests,
  builds and static inspection; browser automation needs the user's explicit request.

Passing the happy path alone is insufficient. Completion requires the affected
valid, invalid, boundary, permission and recovery cases to have concrete evidence.
