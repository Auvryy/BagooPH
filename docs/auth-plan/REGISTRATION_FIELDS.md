# Registration field review

Review initial application controls, server validation, stored values and the
pending/rejected correction form together. A field offered only after submission
is an incomplete application experience. This review is essential whenever an
account form changes; use the [form review method](../FORM_VALIDATION_REVIEW.md)
for rejection, retry, permissions and persistence checks.

## Role coverage

| Entry | Identity and contact | Application details and evidence |
|---|---|---|
| Buyer web signup | First name, optional middle initial, last name, required sex, registration email, password/confirmation; optional birthday, mobile and postal code | Address controls and optional private identity document; identity approval still gates purchases |
| First Google signup | Verified provider email is fixed; required first/last names and sex; optional middle initial, birthday, mobile and postal code | Optional address controls, required policy acknowledgement; private ID and approval use the buyer application afterward |
| Seller web signup | Separated names, required sex, adult birthday, email, password/confirmation, Philippine business contact; optional postal code | One shop name/root category, pickup address, private ID and business permit |
| Courier web/native signup | Names, required sex, adult birthday, email, mobile, password/confirmation; optional postal code | Application address, vehicle, plate and licence identifiers, private ID, driver’s licence and OR/CR; native submission also needs verified email and device name |
| Logistics web signup | Separated names, required sex, adult birthday, email, business contact, password/confirmation; optional postal code | Company name/initials, application address, declared fleet/service details, business permit and optional franchise evidence |
| Platform Admin | Controlled account provisioning and existing login | No public admin signup or role-conversion option |

Sex and postal code are visible on all four public forms and the first Google
application. Sex is required; postal code remains optional. Browser
location selectors guide entry; submitted names do not establish serviceability.
Worker birthday and approval remain mandatory. Buyer birthday does not acquire
the worker age requirement.

On web signup, sex follows the name fields in the first step. Postal code sits
beside barangay in the address section, with the street address below; these
controls stack on narrow screens. This grouping also applies to first Google
signup. Name limits remain enforced through input bounds and inline feedback,
without a permanent paragraph of instructions underneath the names.

## Field contracts

| Field | Limits and handling |
|---|---|
| First/last names | 2–50 characters each; Latin letters/accents, spaces, apostrophes, hyphens and periods. No digits, markup, hidden characters or punctuation-only values. Derive the display name on the server; combined limit 100, including spaces. |
| Middle initial | Optional one Latin letter, optionally followed by a period; maximum 2 characters. Keep its control narrower than the other names. Existing full middle names remain supported at 1–50 characters in legacy requests/corrections. |
| Email | Maximum 255; trim outer ASCII spaces and lowercase new signup addresses. Reject internal whitespace/controls and malformed syntax. Ownership comparison is case-insensitive; existing sign-in identity stays fixed. |
| Mobile | Ten ASCII national digits beginning with 9 after `+63`; supported whole-number prefixes work on paste. Reject extra digits, letters, unsupported prefixes and extensions without shortening a different number into validity. |
| Business contact | Philippine mobile or supported area-coded landline; raw application input maximum 24 characters before canonicalization. |
| Birthday | Real calendar date before today; workers must be at least 18 in Asia/Manila. Age is derived, never client authority. |
| Sex | Required `Female`, `Male` or `Other`; reject missing, null, blank, unknown choices and non-string values. Check it on the first web step, full preflight, final signup, Google signup and native rider submission. |
| Postal code | Optional exactly four ASCII digits, stored as text so a leading zero survives. Reject overlong paste, non-ASCII digits, letters and numeric notation. |
| Street/pickup/company address | Meaningful plain text, 5–500 characters when supplied; mandatory for workers. |
| City/province/municipality/barangay | Meaningful plain text, 2–255 characters when supplied. Worker city is required; existing server optionality for other components is preserved. |
| Shop/company display name | Meaningful plain text, 2–255 characters; separate from personal-name syntax. |
| Shop category | Existing active master root from the 14 categories; positive integer, checked again on the server. |
| Company initials | 2–10 ASCII letters, stored uppercase; reject numbers/symbols and duplicate company codes. This is a Bagoo application limit, not a national acronym rule. |
| Vehicle type/categories | Exact existing allowlists. Fleet categories are distinct, at most three; reject nested arrays and unknown values. |
| Plate | Supported Philippine visible ASCII formats, at most seven letters/digits plus one optional space/hyphen: maximum eight entered characters. New motorcycle/scooter applications accept the supported five/six/seven-symbol legacy/current shapes; sedan/van supports three letters and two to four digits. |
| Driver’s licence | Required for new courier applications: one letter and ten digits, either compact or grouped as `N01-12-345678`. Maximum 13 displayed characters; a valid compact identifier is stored in the grouped form without dropping symbols. |
| Declared fleet size | Optional whole number 1–10,000; reject booleans, decimals, exponents, signs, leading zeroes and arrays. Do not silently substitute 1 for invalid input. |
| Franchise declaration | Optional visible ASCII code, 2–100; no fabricated `PENDING-LTFRB` value. Optional evidence does not imply verified accreditation. |
| New password | 8–128 characters with uppercase, lowercase, number and punctuation/symbol. Spaces alone do not satisfy the symbol requirement; controls are rejected. Preserve passphrase spaces and verify the whole password. Existing sign-in passwords still work. |
| Private application upload | JPG/JPEG/PNG/WEBP/PDF only, matching actual MIME content and extension. Non-empty, at most 5,242,880 bytes each. Images must decode, at most 10,000 pixels per side and 20 million pixels total. Private storage and authorized inspection remain required. |
| Policies | Required unchecked acknowledgement, current Terms/Privacy versions, server acceptance timestamp and source. Account/profile/acceptance writes commit together; rejected or failed signup must not create an acceptance record or consume usable verification. |

Plate/licence checks bound supported formats; they do not prove that an identifier
was issued or matches a person/vehicle. Review the supplied evidence. Do not add
global plate, phone or personal-name uniqueness without its own policy and
existing-record conflict review.

The correction screen uses the same field limits and offers separate names,
sex and postal code. It does not offer a second editable full-name input.
Changing a component derives the combined name; unchanged legacy identifiers
remain readable and eligible under their existing rules. Changed company/vehicle
identifiers and fleet declarations must pass the new application limits.
Corrections do not approve the account or replace the original sign-in email.

## Registration email checks

Buyer, seller, courier and logistics signup check email availability after a
500 ms typing pause or on blur. Local format feedback, the read-only lookup,
preflight and final signup must agree on normalized addresses, malformed input,
controls and length limits. Ownership checks include case/space equivalents,
other roles and verified additional addresses. An earlier successful check
never reserves an address or bypasses final validation or database uniqueness.

Blank or incomplete input does not request availability. Editing, changing the
active step or opening verification cancels pending checks; outdated responses
cannot replace current feedback. Requests have a ten-second timeout and respect
the server retry cooldown. Failed checks must not claim availability. Existing
field errors take priority, and lookup results reveal no account details.
Google signup retains its fixed provider email.

Mailbox ownership requires the existing OTP verification. A syntactically valid
address does not establish that the mailbox exists. Guest/session restrictions,
original sign-in identity and approval requirements remain independent of the
address entered. Do not silently sign out an existing account to submit signup.

## Existing sessions and guest pages

Every login and registration entry, including role subdomains and path aliases,
must return a signed-in user to that account's own portal. Apply the existing
approval, identity and restriction rules before choosing the destination:
unapproved applicants return to their holding page, and denied accounts retain
their existing access restrictions. An old intended URL must not return a user
to an authentication form or an unrelated website. Guest authentication posts
cannot switch an existing session to another account or create an extra account.

Guest-only layouts read `/auth/session` on entry, focus, page restoration and
returning to a visible tab, without polling. The private, rate-limited response
uses the same account access rules. Probe requests time out after ten seconds;
unreadable or failed replies preserve form input. Stale signup, email or OTP
requests that report an existing session navigate to the supplied safe account
destination instead of asking the user to sign out. Authenticated password
confirmation, verification and approval pages remain available under their
existing rules.

## Standards and interpretation

Supported plate shapes reflect the LTO’s
[plate standardization circular](https://www.lto.gov.ph/wp-content/uploads/2023/10/MC_vpt_2013_1772.pdf)
and its [2025 treatment of permanent six-character motorcycle plates](https://lto.gov.ph/wp-content/uploads/2025/08/MC-GGP-2025-5004.pdf).
The optional sedan/van shapes also account for the LTO’s
[driver’s manual discussion of special plates](https://www.lto.gov.ph/wp-content/uploads/2023/10/FDM-vol.-2-2nd-Edition.pdf).
The DFA-hosted [LTO enrollment guide](https://phnompenhpe.dfa.gov.ph/images/Annex_B_-_LTO_Steps_to_enroll_to_the_LTMS_Portal.pdf)
shows a compact eleven-symbol licence number. The grouped display and eight-character
plate input cap are application format decisions based on those sources, not
proof of authenticity or exhaustive coverage of every historic plate series.

The [NPC right-to-be-informed guidance](https://privacy.gov.ph/the-right-to-be-informed/)
describes information a privacy notice must provide. The operator still needs
to supply its controller identity/privacy contact, legal processing basis and
retention decisions before broader public operation. Do not invent those details
or treat a working checkbox as a finding of legal compliance. Privacy notice
acknowledgement remains separate from optional marketing consent.

## Acceptance and release

Cover every relevant field below/at/above its bounds, malformed types, Unicode
look-alikes, safe punctuation, reload persistence, direct requests, unchanged
legacy records, correction review and original-email ownership. Real-content
upload tests must inspect actual content; framework fake files can report MIME
from their filename and cannot establish disguise protection by themselves.

The only new table stores policy versions, source and server time. Do not
backfill invented historical acceptance for existing accounts or seeds. Retain
this history under the existing account-closure review. Bump policy versions
when content changes and keep accepted historical documents available.

Use [ROLLOUT.md](ROLLOUT.md) for additive deployment, native handoff and the
separate live checks. Current outcomes and scoped ratings belong in the
[roadmap](../CORE_FLOW_ROADMAP.md).
