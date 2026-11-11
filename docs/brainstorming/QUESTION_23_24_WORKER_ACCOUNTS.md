# B02: named logistics workers and scanner accounts

Decision date: November 11, 2026. Delivery remains on `feat/brainstorm-routing-feedback`, together with Q1. The logistics scanner repository uses the same branch name; separate Git repositories require separate PRs when published. No extra web branch is needed.

## Accepted behavior

An approved logistics company owner creates a named operator or rider, selects an active hub owned by that company, and enters the worker's own email, name, adult birth date and Philippine contact number. Riders belong to a child hub. This retains the existing company provisioning authority; subordinate operators cannot create workers or impersonate their owner.

The owner does not choose or receive a worker's password. Creation emails a six-digit setup code. The worker verifies it, reviews the company, hub and fixed role, accepts that assignment and chooses a personal password. Email verification and activation commit together. Code verification does not itself grant portal access. Passwords require 12–128 characters, mixed case, a number and a symbol, without hidden controls.

Invitations last seven days; codes last ten minutes and permit at most five verification attempts. Existing resend cooldown and request throttles apply. A verified setup token is single use. Resending invalidates earlier codes and setup tokens. The reviewed assignment is signed against its company, hub, role and credential generation; changed/revoked assignments cannot be accepted using an earlier review. Verification and acceptance lock the relevant accounts, company, hub and assignment records.

If email delivery fails, the pending account remains visible once. The owner sees an error and can resend after confirming their current password. A retry never requires another account. A pending account remains unable to work. Completing setup revokes previous sessions; a worker already signed in through the legacy flow is signed out cleanly and receives the success response.

## Corrections to the brainstorming proposals

- Topic 23: retain central rider registration and the existing company application/offer/acceptance flow. Joining an employer is distinct from creating an account. Existing rider evidence, company eligibility, vehicle and duty requirements remain authoritative. This change does not introduce a new mandatory NBI upload, declare existing documents verified, or let sellers employ logistics riders.
- Topic 24 hierarchy: retain approved company-owner provisioning of named operators and riders. Giving every hub manager account-creation rights is not approved by this implementation. Hub assignments identify where a worker can act; managerial visibility alone does not grant scanning authority.
- Topic 24 credentials: use the worker's own email and the existing OTP mail service. Phone is a validated contact field; no new SMS service is introduced. No starter password is emailed, displayed, cached or shared.
- Topic 24 emergency control: impersonation is deferred. The owner uses existing authorized management views under their own identity. No login-as control, invented second actor, or silent transfer of custody is introduced. A later emergency delegation design needs its own explicit authority, reason, expiry and actor records.
- Topic 14 identity: scanner actions use the authenticated named operator. Separate accounts are not proof of two separate physical people; independent custody policy belongs to B03/B07.

These are decision records, not claims that every original proposal was implemented verbatim.

## Logistics scanner integration

The old scanner called the courier token endpoint, read the wrong token envelope, defaulted to hub 1 and could replace HTML/error responses with demonstration results. Live mode now uses the operator API and server-confirmed assignments:

| Method | Path under `/api/v1/logistics` | Result |
|---|---|---|
| POST | `/auth/tokens` | Valid operator credentials return `data.token`, expiry, user and assigned hubs. |
| GET | `/session` | Revalidate the bearer session and refresh eligible assigned hubs. |
| DELETE | `/auth/token` | Revoke the current native session. |
| POST | `/activation/send` | Send an operator invitation code using the same email service. |
| POST | `/activation/verify` | Return a verified token and signed assignment review. |
| POST | `/activation/complete` | Accept the reviewed assignment and set a personal password. |
| POST | `/scan` | Require an explicit assigned hub and reuse authoritative parcel inspection/confirmation. |

Web activation is available at `/work-account/setup`; its send, verify and activate actions use web CSRF protection. Native activation uses the JSON endpoints above. No browser cookie grants native scanner access. Expired/revoked sessions, wrong abilities, courier tokens, inactive assignments and changed passwords cannot authorize scans. Native sessions appear as Logistics scanner in the existing private session list and support its revocation controls.

The scanner keeps its bearer token in platform secure storage, removes obsolete plaintext preference tokens, and disables Android app backup. Switching servers or signing out clears account/hub state. One eligible assignment is selected automatically; multiple assignments require a choice. Startup revalidates live sessions before entering the camera screen. Explicit Demo mode remains available separately.

Continuous parcel scanning inspects first, then sends the server's action and expected status. A retry retains the server's original custody evidence. Unknown/malformed/HTML responses never become successful mock scans. Bag identification displays returned reference/seal/count only; native bulk receipt is blocked because seal verification and member receipts belong to the existing hub receiving workflow. Bag identification does not manufacture physical receipt. Existing backend DWS/slotting values are guidance/estimates, not newly verified hardware measurements.

## Local acceptance evidence

- Isolated SQLite account, provisioning, invitation and actual native custody tests cover owner scope, role separation, email failure/retry, code expiry, replay, stale assignment, consent, password validation, native activation-to-login, session revocation, token limits and real parcel inspection/intake. The legacy session/routing/checkout/bag regression suites were also checked.
- Three new web interaction tests cover assignment consent submission, password clearing after failed activation, retry and successful sign-in navigation. TypeScript and isolated Vite build passed.
- Flutter tests cover nested token parsing, secure storage, multiple assigned hubs, revoked sessions, server changes, HTML rejection, inspect/confirm payloads, logout, invitation errors, actual bag response parsing and activation/chooser widgets. Flutter analysis passed. An Android arm64 debug APK compiled in the background.
- Six existing scanner/counter frontend expectations fail identically on the pre-B02 `HEAD` snapshot: four scanner result render assumptions, the registry date-copy expectation and the counter's `0 ready` text expectation. They are recorded separately from these new passing checks; the broader frontend suite is not all green.
- No production/development database was reset or seeded. No phone, visible browser or desktop app was controlled. No new API key or mail provider was added. Real inbox delivery and on-device camera/secure-storage behavior remain deployment/device acceptance checks; fake-mail and host tests do not prove those.

## How to review

1. Run the web app with its existing local configuration and frontend development server. Open the company owner's Company setup → Staff section.
2. Invite a worker at an owned active hub. Confirm the list shows a pending invitation and no owner-entered password field.
3. Use an existing configured mail delivery transport; do not enable OTP logging. Open `/work-account/setup`, verify the emailed code, review the assignment and activate.
4. Sign in as that worker. Operators see their authorized work; they cannot provision staff. Riders retain their courier role and ordinary vehicle/duty requirements.
5. In the logistics scanner, enter the configured backend origin. Choose Activate an invited work account for a pending operator; otherwise sign in. Multiple assignments show a chooser. A courier account cannot sign into the operator scanner.
6. Inspect a real assigned parcel, then confirm its offered action. Check that an unassigned hub and stale/expired session are rejected and that repeating an intake does not add another custody checkpoint.

Deploy the web endpoints before distributing the updated scanner. Existing migrations already contain the invitation/session fields; B02 adds no migration. Existing pending temporary-password accounts retain their legacy route and can also request the new email setup flow when their invitation is still eligible. Publication and merge remain pending until the user pushes and reviews the two repositories.
