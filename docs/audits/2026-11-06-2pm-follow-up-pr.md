# PR title
Fix seller tag selection, promotion dates and AI failure feedback

# PR body
Seller product tags now wait for Gemini relevance approval before selection and preserve rejected input. Promotion editing shows the linked voucher's Manila date period, bounds scheduling, and reports invalid dates beside each field. AI provider HTTP failures now produce the existing actionable assistant feedback. The seller listing shortcut sits inside dashboard content.

Validation: promotion frontend 29 tests; promotion backend 20 tests / 370 assertions; tag backend 23 tests / 186 assertions and seller frontend 18 tests; account/KYC/product backend 130 tests / 1,237 assertions; broader 2pm frontend 217 tests; voucher/AI audit backend 48 tests / 514 assertions and voucher frontend 28 tests; focused AI regression 4 tests / 17 assertions. One sanitized live AI JSON connection check passed. Product-route test fixtures now follow the approved root department policy.

Manual browser acceptance, deployed Azure verification and comprehensive live moderation quality were not tested. The final Docker production build passed TypeScript and Vite compilation. No database reset or seed is required. This PR description is a local draft; it has not been saved on GitHub.
