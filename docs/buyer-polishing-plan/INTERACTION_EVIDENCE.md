# Buyer interaction acceptance evidence

This records the six selected BP03 observations after the return implementation
and the confirmed Bag-removal recovery repair. It is an interpretation of the project acceptance work, not a
replacement for its original manual results. The other 57 likely passes remain
outside this active checklist.

| Observation | Established support | Authorized browser result |
|---|---|---|
| Literal search safety | The selected discovery test treats wildcard and escape characters as literal search values. | Passed: wildcard/escape text, SQL-like text and hostile image markup remain literal, return no matches, execute no script and make no unintended write. No JavaScript exception occurred. |
| Option changes | A synthetic eligible product has independent pair prices, stock and distinct actual image assets in an isolated HTTP fixture. | Passed: Red/Small PHP 500 and stock 3, Blue/Small PHP 525 and stock 2, Blue/Large PHP 575 and stock 7, then Red/Small again. Each main image actually loaded with the corresponding color. |
| Sold-out pair | The fixture includes one unavailable pair alongside available pairs sharing its options. Existing server stock rejection remains intact. | Passed: Red/Large is disabled; Blue/Large remains usable. Direct addition of the sold-out pair rejects with 422. |
| Saved Bag across sessions | Three independent real HTTP cookie jars establish same-buyer persistence and other-buyer isolation through the actual Laravel kernel. | Passed: two independent contexts show the same saved option lines and quantities across sign-out/sign-in; a third context for another buyer has an empty Bag. |
| Bag removal and recovery | Owned removal persists across the two same-buyer HTTP sessions; a foreign line identifier rejects. | Passed after repair: selected/unselected removal persists, a failed request retains the row, selection and PHP 625 total, readable retry succeeds, and the independent session sees the removal. A foreign identifier rejects with 403. |
| Return before delivery | A real Placed checkout fixture rejects the direct return form. The selected return test also rejects unsupported physical evidence. | Passed: the Placed order offers no return action or form; the direct form request rejects with 422. |

The selected Docker PHP 8.4 checks passed **26 tests / 271 assertions** in SQLite
`:memory:`. They cover quantity/ownership, literal search, selection and the
pre-delivery return denial. The additional independent-cookie HTTP probe uses
host PHP **8.5.11**, the production frontend assets and a separately prepared
SQLite `:memory:` database. It uses the testing middleware configuration; it
does not establish production CSRF, session or deployment behavior.

The HTTP fixture has two synthetic buyers, an explicitly approved shop, a pair
matrix with different prices/stock and real generated image assets, and an actual
pre-delivery checkout. Its data disappears when the process stops. After explicit
authorization on October 19, headless Chromium exercised the actual production
frontend assets through fresh independent browser contexts. The fixture resets
per-request authentication, queued cookies and route-script state so its long-lived
test kernel does not leak those request-local values between contexts. This is
isolated browser evidence, not production middleware or a real-device result.

All six selected browser procedures passed on October 19, finishing at 17:06
Asia/Manila. The baseline failed-removal check reproduced an uncaught network
exception, no retry feedback and premature deselection. The repair waits for
server confirmation, limits concurrent removal, preserves the real row/selection
on failure and offers retry or reload. Its final browser run had no uncaught
JavaScript exceptions. Expected sign-in redirects, deliberately rejected requests
and the deliberately aborted request remain recorded separately from errors.

The focused frontend suites passed **38 checks** and the TypeScript/Vite
production build passed in Docker. The host build first encountered existing
root-owned asset permissions; it is not counted as a passing build. No screenshot
or development database reset was used. Original manual results are unchanged.
Scoped failed-removal recovery assessment: **3/10 to 8/10**. This closes the six
selected interaction gates; it does not certify other browser/device flows.
