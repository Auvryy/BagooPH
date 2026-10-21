# Login activity and session controls

Every account can review **Login activity** within its existing settings view.
Buyers expand **My Account & Security** in the profile sidebar and choose
**Login activity**. Sellers use their account settings, couriers use
**Profile -> Privacy and security**, and logistics/admin users use their existing
profile settings. The portal header and navigation remain in place. An admin sees
their own history here; it is not a directory of other people's devices.

The buyer sidebar lists personal information, email addresses, password,
**Identity correction**, then Login activity. Verification is inside the identity
group, alongside correction requests and their decisions. Content headings use
matching icons, and buyer correction controls follow the surrounding input sizes,
boundaries and focus treatment. The review, evidence and upload limits stay intact.

Selecting a section scrolls the desktop settings pane or the document on smaller
screens, moves keyboard focus to that section and respects reduced motion. The
highlight follows the section actually in view during manual or automatic
scrolling, including a short final section at the bottom. Viewport and content-size
changes trigger a fresh position check. These checks do not move keyboard focus,
change history or make network requests. The submenu is visible while the account
tab is active. Section links retain actual anchor destinations for opening or
copying a link; older verification anchors resolve to the identity group.

Session lists load privately when the relevant settings view is opened; a hidden
courier privacy tab does not load them. Refresh and both independent page controls
update the embedded panel without leaving settings. Loading, failure and retry
states remain inside the panel. Changing accounts hides old data immediately and
aborts outstanding requests. The direct `/account/security` page remains available
for older links and applicants/restricted accounts that need narrow security access.

## What the owner sees

- The latest recorded successful sign-in and recent sign-ins from the last
  90 days, displayed in Philippine time.
- Active website sessions and existing rider-app tokens, including the current
  browser, original sign-in time when known, last activity and approximate
  browser/device description. Both lists use independent 12-entry pages.
- A partly hidden network address. Device descriptions come from the reported
  browser or app name and can be inaccurate. A VPN, mobile connection or shared
  network can change the address. No city, exact location or GPS is inferred.

An entry represents a session, not a unique physical device. Multiple browsers
on one computer appear separately. A page visit updates activity without changing
the successful sign-in time. Sessions that predate this feature have an unknown
sign-in time and do not manufacture new login-history events. Expiry follows the
existing browser lifetime or rider token expiry.

## Ending access

The owner can sign out the current session using the existing logout action, a
selected other session, or all other sessions. Remote sign-out requires the
current password or a six-digit code sent to the verified original sign-in
email. Email confirmation also supports accounts that normally use Google
sign-in. Unverified accounts can confirm with their password. The existing
ten-minute expiry, resend limits and five incorrect attempts apply.

Confirmation belongs to the account, its fixed role and this purpose. A contact
address, another account's code, a registration code or a password-reset code
cannot authorize device sign-out. The server chooses the owner and destination
email; client-supplied identity or token IDs are rejected.

Remote sign-out revokes actual authentication. A selected browser is refused on
its next request, and a selected rider token is deleted. Remembered sign-ins are
rotated so a revoked browser cannot silently return through an old saved-login
cookie; saved-login cookies are reset across devices, including the current
browser. Existing active sessions remain open unless selected; signing in again
after expiry can save the browser again. Sign out others keeps the current browser
and invalidates all other tracked sessions, rider tokens and untracked older
browser sessions.

Each remote action has one account-and-session-bound request identifier and a
retained result. A retry returns that original result rather than revoking a
session created later. Reusing it for another selection is rejected. Failed
confirmation makes no revocation; a failed save rolls back revocation, token
deletion, the account generation and the result together.

Changing a password from authenticated settings retains that browser, ends other
browser/app sessions and rotates saved sign-ins. A verified password reset ends
all older sessions. Token issuance rechecks the credential and session generation
under the account lock so stale authentication cannot create fresh access after
a competing password change or bulk revocation.

## Account and business boundaries

Pending, rejected, suspended and deactivated accounts retain their own narrow
security page without gaining checkout, operational or admin access. Closed
accounts cannot use it. Existing account status and approval rules still govern
the surrounding portal.

Signing out affects authentication only. It does not mark a rider off duty,
release an assignment, transfer parcel custody, confirm receipt, change an order,
or post money. Those actions keep their existing authorized workflows.

The web page controls rider tokens through the shared Laravel backend. Existing
native abilities and response envelopes remain in place. Flutter interface
changes and physical-device acceptance belong to the mobile client separately.

## Privacy and retention

Session IDs are hashed. The account view never receives raw cookie IDs, bearer
tokens, password fingerprints, raw user agents or full network addresses. The new
login records encrypt network and user-agent metadata with Laravel casts; the
existing Laravel session store still retains its standard request metadata.
That store is never exposed as an account API. Only the configured
trusted request address is recorded; this feature does not change proxy trust or
add a location provider. Private list responses use no-store headers. Embedded
lists live in component state rather than the settings page's Inertia props or
persisted history. The direct security page enables Inertia history encryption on
HTTPS and localhost origins, where modern browsers support it. Plain HTTP demos
remain accessible without that extra history protection; use HTTPS for deployed
private account access.

`account-security:minimise-metadata` clears network/user-agent data and replaces
the device label after a session has been ended or expired for 90 days. The daily
scheduled command retains minimal session tombstones and action receipts to
prevent old authentication and retries from becoming valid again. Active-session
metadata is retained while access remains active. Keep the existing application
encryption key stable when deploying or restoring a backup.

## Deployment and verification

The additive migration `2026_10_21_090000_create_account_login_sessions` creates
session/action records and the account generation. Normal deployment must apply
it before serving the feature, build frontend assets and keep the scheduler
running. `./bagoo.sh deploy` performs those steps; `./bagoo.sh verify` checks the
existing deployment prerequisites. No database reset or reseeding is needed.

Retain the migration, session tombstones and action receipts when fixing a
release. Dropping them can discard revocation and retry protection. A downgrade
needs a separate authentication invalidation plan rather than a routine schema
rollback.

Acceptance checks live in `tests/Feature/Security/AccountSessionsTest.php`,
`AccountSessionBrowserTest.php`, `AccountSessionBoundariesTest.php` and
`tests/Frontend/account-sessions.test.mjs`. The cookie tests use Laravel HTTP
requests with separate cookie jars and SQLite in memory; they do not automate a
browser. Current results and remaining environment limits belong in
[CORE_FLOW_ROADMAP.md](CORE_FLOW_ROADMAP.md).
