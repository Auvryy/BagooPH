# Essential web accessibility and KYC safeguards — November 4, 2026

## Fresh base and scope

GitHub `main` was fetched and its remote head read before branching. Web local
and remote `main` both matched `2fd6486733eef49792990264db109b1d18c9285b` with
zero divergence. Rider local and remote `main` likewise matched
`998cf0da3de1f5dc74a74a7feefc9b9af3a75ce8`.

The web branch is `fix/final-essential-accessibility-2026-11-04`. Three agents
performed source-only reviews; focused checks and the build ran sequentially.
No browser or native window was opened. Rider source/configuration and Buyer
Mobile were not changed. Existing untracked web files were preserved.

## User-visible changes

- Shared authentication footer/helper text and seller chat timestamps/empty-state
  text use darker slate. Shared Buyer web authentication footers benefit through
  the common layout; no Buyer-specific file was edited.
- Seller, Courier, Hub and Admin sign-in labels now target the existing email and
  password inputs. Their placeholders and password-toggle icons are more readable.
- Courier/Admin sign-in buttons retain their existing role colours with darker
  backgrounds. Hover/active states keep readable white labels.
- KYC selected Pending/Approved controls, Rejected labels, inactive descriptions,
  empty states and document-review helper text have stronger contrast. The enabled
  approval button is darker; its existing disabled/approval conditions are unchanged.
- The KYC table's horizontal scroll wrapper is a named, tabbable region with an
  inset focus indicator. It remains a native table with native keyboard scrolling;
  no custom key interception or additional row focus was added.
- Every KYC inspector entry path now clears the previous applicant's evidence
  acknowledgement and review error. A document-chip opener previously retained
  them, which could enable approval without a fresh checkbox confirmation.
- Inspector/rejection overlays now use the existing Headless UI dialog manager,
  with named titles/close buttons and an associated feedback field. Rejection is
  nested when opened from inspection, so cancellation returns to that inspector.
- A synchronous pending guard blocks duplicate/competing decisions, applicant
  changes and closing during submission. Failure retains the original reason/token
  for retry; success still closes the matching review.

Backend authorization, form payload fields, role/approval rules and chat
recipient/draft behaviour are unchanged. Client review state and pending guards
changed as described above. Four compatible dependency updates also clear the
fresh audit findings; see [the dependency evidence](2026-11-04-dependency-patches.md).

## Verification

| Check | Result and scope |
|---|---|
| Latest focused frontend tests | 119 passed, zero failures/skips; includes six new KYC regressions and broader auth/request/navigation checks |
| KYC red/green evidence | All six new cases failed before the state/dialog fix, then passed afterward |
| Auth/KYC backend selection | 51 passed, 261 assertions; isolated SQLite memory and a unique test-storage namespace |
| Installed dependency checks | Composer/npm audits report zero; MIME, redirect and query-string offline probes passed |
| Solid-colour calculation | All 11 selected Tailwind foreground/background pairs exceed 4.5:1; minimum 4.70:1 |
| Latest production build | `tsc && vite build` passed after all follow-ups; updated Vite completed in 14.62 seconds |
| Resource control | One local test/build batch at a time; build used lower CPU priority and a 1,024 MB Node heap cap, not a measured total-process RAM limit |
| Independent review | Original seven-file UI diff and follow-up KYC state/callback/dialog diff reviewed; no blocking finding |
| Whitespace | `git diff --check` passed |

Representative calculated pairs: slate-600 on white **7.58:1**; placeholder
slate-500 on white **4.76:1**; white on emerald-700 **5.48:1**; white on
amber-700 **5.02:1**; white on rose-600 **4.70:1**.

The earlier rendered scan contained 44 low-contrast nodes. Source comparison
shows six hub nodes were already addressed by the previous shared-header/helper
changes; the remaining 38 old targets map to classes changed here. This is not
a new rendered scan and does not establish a zero-violation count. Equivalent
helper/control states were corrected alongside those targets.

The scoped engineering assessment for the readability/keyboard slice is
**5/10 → 8/10**. Client KYC decision-state isolation is separately **5/10 → 8/10**,
supported by the reproduced acknowledgement/error leaks, pending-decision
regressions and retained backend guards. These do not raise overall product,
deployed-service or physical-device readiness.

Exact commands:

```sh
node --test --test-concurrency=1 \
  tests/Frontend/kyc-review-interactions.test.mjs \
  tests/Frontend/auth-session.test.mjs \
  tests/Frontend/auth-validation.test.mjs \
  tests/Frontend/portal-consistency.test.mjs \
  tests/Frontend/portal-navigation.test.mjs \
  tests/Frontend/seller-message-interactions.test.mjs \
  tests/Frontend/logisticsWorkspace.test.mjs \
  tests/Frontend/catalogue-runtime.test.mjs \
  tests/Frontend/catalogue-navigation.test.mjs \
  tests/Frontend/marketplace-composer.test.mjs \
  tests/Frontend/core-commerce-usability.test.mjs

docker compose exec -T -e NODE_OPTIONS=--max-old-space-size=1024 \
  app nice -n 10 npm run build
```

Local logs and the colour-pair JSON are retained in ignored
`storage/app/qa-essential-accessibility-2026-11-04/`.

## Manual follow-up

1. Seller/Courier/Hub/Admin sign-in: click each email/password label, Tab through
   the form, inspect placeholders/password toggle and submit-button states.
2. Seller Messages: inspect contact/message timestamps and empty/helper text.
3. Admin KYC at a narrow viewport: Tab to **KYC application table**, confirm its
   visible focus indicator, scroll horizontally with the keyboard and Tab onward.
   Include an empty filter and a populated table; do not trigger a review decision.
4. Inspect Pending/Approved/Rejected/All filters and the document-review helpers.
5. Acknowledge applicant A, close it, then open applicant B through a document
   chip. B must start unchecked without A's error. Cancel nested rejection back
   to inspection. During a pending decision, duplicate/competing actions and
   dismissal must remain blocked. Use prepared demo records; do not approve real
   applicants merely to check navigation.

No fresh browser/axe scan, actual Tab/arrow interaction, screen-reader run or
contrast measurement over rendered images was performed. Existing tests do not
directly exercise KYC keyboard scrolling or Headless UI focus trapping. The new
interaction harness mocks the dialog components and proves state/command
behaviour, not browser focus. Full-suite reruns, Azure/device verification and
mobile feature-gate acceptance remain outside this work.

## Local PR draft

Title: Prevent stale KYC decisions and improve portal accessibility

Body: Make shared authentication and seller chat helper text readable, associate
the four operational sign-in forms' labels with their inputs, and darken their
button/control states. Give the KYC application table a named keyboard focus
stop with a visible inset indicator, and improve its status/empty/review text.
Reset review acknowledgement/errors for every applicant opener, use managed
nested review dialogs and serialize pending decisions without changing server
authorization or request fields. Update four existing dependencies within
compatible constraints; both dependency audits now report zero findings.

Validation: 119 frontend tests (including six KYC red/green regressions), 51
backend auth/KYC tests, 11 calculated colour pairs, offline dependency probes,
clean Composer/npm audits, independent source review and production build passed.
Manual keyboard use and a fresh rendered accessibility scan remain follow-up checks.
This is a local PR description draft; no PR or deployment was created by this work.
