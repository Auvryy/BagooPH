# Native rider settings API, version 1

This is the backend contract for the rider app's phone, password and additional-email settings. It uses the same account records, contact validation, reviewed-identity protections and email/OTP services as website settings. The app uses bearer authentication; website cookies and Inertia pages are not a native API.

All paths below are relative to `/api/v1`. Requests and responses are JSON. Successful responses and errors use `Cache-Control: no-store, private` and `Pragma: no-cache`; authentication failures never redirect to a website page.

## Availability and authentication

- `POST auth/tokens` advertises `data.user.settings_api_version: 1`; `GET rider/me` advertises `data.settings_api_version: 1`. The field is absent until the contact-revision migration and email registry are installed. Settings routes return 503 while that schema is unavailable.
- New sessions receive `rider:account`, `rider:logout`, `rider:settings:read`, `rider:settings:profile`, `rider:settings:password` and `rider:settings:emails`. Existing account-only tokens keep their original abilities and must sign in again for settings.
- Every settings request needs a live, unexpired bearer token, `rider:account` and the corresponding settings ability. The password fingerprint and fresh account eligibility are checked again. Commands recheck both abilities on the authenticated token under the account lock. Malformed, nonpositive or out-of-range token ID prefixes return 401 before an integer database lookup.
- Only approved, active, open, age-eligible couriers may use these settings. Pending/rejected applicants retain the existing account-status/holding API, without settings access. A company or hub assignment is not required to edit ordinary account contact details.
- The app must check the supported version, `can_access_portal` and the settings snapshot's capabilities. Client flags never authorize a server command.

## Routes

Send only the listed body fields. Unknown fields, including null identity, owner, approval, assignment or vehicle fields, receive 422.

| Method and path | Required body | Success |
|---|---|---|
| GET `rider/settings` | None | Settings snapshot |
| PATCH `rider/settings/profile` | `phone`: string or null; `revision`: string | Fresh snapshot with canonical saved phone |
| PUT `rider/settings/password` | `current_password`, `password`, `password_confirmation`: strings | `{"data":{"password_changed":true,"reauthentication_required":true}}` |
| POST `rider/settings/emails/send` | `email`, `current_password`: strings | `success`, `message`, integer `cooldown`, integer `expires_in` |
| POST `rider/settings/emails/confirm` | `email`, `current_password`, `code`: strings | Fresh snapshot |
| PATCH `rider/settings/emails/{id}/preferred` | `current_password`: string | Fresh snapshot |
| DELETE `rider/settings/emails/{id}` | `current_password`: string | Fresh snapshot |

IDs are positive decimal strings within the database's signed 64-bit range, up to `9223372036854775807`. Route IDs identify an address; they never select a different account. An unsupported or out-of-range address ID returns 404 before lookup; a supported but missing address also returns 404. An existing foreign address cannot be managed (403).

## Settings snapshot

The following example is synthetic. `revision` is an opaque 64-character value; clients store and send it without calculating or interpreting it.

```json
{
  "data": {
    "account_id": "7",
    "revision": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
    "profile": {
      "name": "Example Rider",
      "email": "rider@example.test",
      "email_verified": true,
      "phone": null
    },
    "capabilities": {
      "update_contact": true,
      "change_password": true,
      "manage_emails": true
    },
    "emails": [
      {
        "id": "11",
        "email": "rider@example.test",
        "is_original": true,
        "verified": true,
        "preferred": true
      }
    ],
    "managed_details": {
      "available": false,
      "company": null,
      "hub": null,
      "hub_code": null,
      "barangay": null,
      "vehicle_type": null,
      "vehicle_model": null,
      "plate_number": null,
      "fleet_status": null,
      "license_number": null,
      "registration_status": null
    }
  }
}
```

The snapshot belongs to the bearer account. Name and email retain the exact stored identity. Addresses are ordered original first, then by ID; each address ID is a string. There is one original address and one preferred address. Only verified additional addresses can be preferred. An unavailable/unverified stored preference falls back to the original, without claiming that the original email is verified.

`managed_details.available` means the account has a stored courier profile. True with null fields means those values are not provided. Company/hub, assigned barangay, fleet or personal vehicle and credential fields match the website's own-account presentation. These facts do not imply that an assignment or vehicle is operational. Private document URLs, password hashes, OTPs and token fingerprints are never returned.

## Contact and password rules

- Contact editing accepts only a present `phone` and the last fetched `revision`. Null clears the phone. Philippine mobile formatting uses `ProfileInputService`, for example `0917 123 4567` saves as `+639171234567`; foreign numbers, landlines, look-alike digits and controls fail the courier's existing validation.
- Saving requires the current revision. A newer website/native/SQL phone edit returns 409 and the current snapshot under `data`; it preserves the saved phone. Same-second edits and a phone changed away and back are detected by a persisted revision counter. Fetch/review the current values before submitting again.
- Name, original email, birth date, legal identity, company/hub, service area, vehicle and evidence cannot be changed through this API. Reviewed corrections remain in the website's established settings workflow.
- Password changes require a verified original email, the actual token owner's current password, matching confirmation and a new password of 12–128 characters. Website and native changes share the same password service and hash cast.
- Successful password changes atomically revoke all personal access tokens belonging to that account. The success response confirms the change, after which the app clears its native session and requires login. Old tokens receive 401. If the response is lost, the result is unknown: do not automatically retry or claim success.

## Additional emails and OTPs

- Adding, preferring and removing addresses requires the authenticated account's current password. A browser guard for another user cannot satisfy that check.
- The original sign-in address is immutable and cannot be removed. Additional addresses are contact/recovery addresses, not new sign-in aliases. At most five additional addresses may be kept; ownership/capacity/password are rechecked under the account lock.
- Send normalizes accepted addresses to lowercase and uses the existing `account_email` purpose and actor ID. Successful send returns cooldown 60 and expiry 600 seconds. A six-ASCII-digit code is required for confirmation; wrong-purpose/actor, expired, exhausted and consumed challenges cannot add an address. Failed verification attempts remain recorded.
- Confirmation of an already-owned verified address is the website's harmless retry: it returns current settings and adds no second address. After removal, an old challenge cannot restore the address or transfer it to another owner.
- An additional preferred address must be verified and owned. Removing it deletes its account/recovery OTP challenges, invalidates pending password-broker recovery tokens and restores original-contact fallback when necessary.
- Mail failure returns 503 with `success: false`, without claiming delivery or leaving the failed challenge active. The app must not treat a request being sent as proof that an email was delivered or verified.

## Failures and throttling

| Status | Meaning |
|---|---|
| 401 | Missing, expired, revoked or password-invalidated native session |
| 403 | Ineligible account, foreign address or missing settings ability |
| 404 | Missing address or unsupported route ID |
| 409 | Stale contact revision; current settings are returned |
| 422 | Validation error, including wrong current password or unverified original email for password changes |
| 429 | Request limit or OTP cooldown; inspect integer `cooldown` or numeric `Retry-After` seconds |
| 503 | Settings schema unavailable or verification mail could not be sent |

422 responses use `message` and Laravel `errors` arrays keyed by field. The existing account middleware may revoke a token when it observes a restriction: its first denied request is 403 and later uses of the deleted token are 401. Validation errors do not revoke an otherwise valid session.

The existing native API limit is 30 requests/minute per network source. Settings writes use the existing authenticated 10/minute throttle, with confirmation allowed 20/minute; these authenticated throttle counters are shared across applicable writes. OTP resend additionally enforces 60 seconds and five requests per email/purpose/actor in 15 minutes, and verification allows five wrong attempts per challenge.

## Deployment and mobile handoff

Publish and merge the backend branch through the normal repository workflow. From the Azure repository, fast-forward the reviewed `main`, then run:

```sh
git status --short
git pull --ff-only origin main
./bagoo.sh deploy
./bagoo.sh verify
git rev-parse HEAD
```

The additive `2026_10_08_190000_add_contact_settings_revision` migration must be Ran, alongside `2026_10_08_000001_create_account_emails_table`. No database reset, seed rerun or identity rewrite is required. Until the migration is installed, native settings remain unavailable and are not advertised.

Record the actual deployed commit separately from local source commits. A local passing test run, published branch or running old site does not prove deployment.

After deployment, use a fresh native login and verify version 1, own settings, phone save/reload, stale-edit rejection, emailed OTP verification, preferred/removal results and password change/re-login against the HTTPS API. Keep all live credentials and codes in the private client session. Confirm that website settings observe native writes and vice versa. The mobile AI can then complete real Flutter save, refresh, secure-session clearing and error handling; fake/local repository responses do not establish live integration.

The contract review preserved the proposed paths and snapshot. Clarifications are deployment-gated version advertising, field-level errors for unverified preferred emails, harmless confirmation retries, exact opaque revision shape, inherited shared throttles and current-password checks bound to the fresh bearer owner. Native identity editing and Flutter repository changes remain outside this backend delivery.
