# Essential web accessibility fixes — November 4, 2026

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

Authentication/session handling, form payloads, role/approval guards, review
actions and chat recipient/draft behaviour are unchanged by this diff.

## Verification

| Check | Result and scope |
|---|---|
| Focused existing frontend tests | 76 passed, zero failures/skips; six files below |
| Solid-colour calculation | All 11 selected Tailwind foreground/background pairs exceed 4.5:1; minimum 4.70:1 |
| Production build | `tsc && vite build` passed; Vite completed in 13.35 seconds |
| Resource control | One local test/build batch at a time; build used lower CPU priority and a 1,024 MB Node heap cap, not a measured total-process RAM limit |
| Independent review | Seven source files reviewed; no handler, route, payload, guard or financial change found |
| Whitespace | `git diff --check` passed |

Representative calculated pairs: slate-600 on white **7.58:1**; placeholder
slate-500 on white **4.76:1**; white on emerald-700 **5.48:1**; white on
amber-700 **5.02:1**; white on rose-600 **4.70:1**.

The earlier rendered scan contained 44 low-contrast nodes. Source comparison
shows six hub nodes were already addressed by the previous shared-header/helper
changes; the remaining 38 old targets map to classes changed here. This is not
a new rendered scan and does not establish a zero-violation count. Equivalent
helper/control states were corrected alongside those targets.

The scoped engineering assessment for this essential readability/keyboard slice
is **5/10 → 8/10**, supported by source review, label associations, calculated
colours, existing regression checks and build. Overall product, deployed-service
and physical-device readiness are not changed by this rating.

Exact commands:

```sh
node --test --test-concurrency=1 \
  tests/Frontend/auth-session.test.mjs \
  tests/Frontend/auth-validation.test.mjs \
  tests/Frontend/portal-consistency.test.mjs \
  tests/Frontend/portal-navigation.test.mjs \
  tests/Frontend/seller-message-interactions.test.mjs \
  tests/Frontend/logisticsWorkspace.test.mjs

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

No fresh browser/axe scan, actual Tab/arrow interaction, screen-reader run or
contrast measurement over rendered images was performed. Existing tests do not
directly exercise KYC keyboard scrolling. Backend/full-suite reruns, dependency
updates, Azure deployment and mobile feature gates are outside this UI diff.

## Local PR draft

Title: Improve sign-in readability and keyboard access in KYC

Body: Make shared authentication and seller chat helper text readable, associate
the four operational sign-in forms' labels with their inputs, and darken their
button/control states. Give the KYC application table a named keyboard focus
stop with a visible inset indicator, and improve its status/empty/review text.
Existing authentication and approval logic is unchanged.

Validation: 76 focused existing frontend tests, 11 calculated colour pairs,
independent source review, whitespace check and production build passed. Manual
keyboard use and a fresh rendered accessibility scan remain follow-up checks.
This is a local PR description draft; no PR or deployment was created by this work.
