# Implement version-two logistics routing, bagging and delivery earnings

New orders now follow the version-two logistics contract from company selection to verified delivery. Checkout freezes the serving company's ZIP/parent route, volumetric rate inputs and separate product/shipping amounts. Different-child routes use sealed Mother Bags through the required Mother Hubs; identical-child routes proceed from local intake to local dispatch. Misroutes and returns preserve the original custody and purchased route.

Valid recipient, POD and original COD/prepaid evidence complete new orders and create retained earnings once. Pickup earnings require origin receipt; COD final-mile release and duty closure require counted hub remittance. Standard and dedicated-fleet shipping splits remain separate from 90/10 product proceeds, actual external payouts and balanced virtual-wallet settlement. Historical version-one orders retain their saved financial and buyer-receipt contract.

Approved company owners can create hubs and assigned named workers with expiring temporary passwords and enforced password/email activation. The shadcn-style logistics shell connects company setup, scanners, bags, manifests and scoped earnings, with readable phone tables and keyboard navigation.

Validation:

- Final focused regression: 418 tests / 18,009 assertions passed in SQLite memory, covering new algorithms, checkout, legacy compatibility, activation/auth, native rider API, tracking and finance.
- Broader logistics/returns/wallet batch: 981/983 passed initially. The proof-storage collision cleared with isolated test storage (32 attempt cases passed); the reviewed-hold priority was corrected and all 65 handover cases / 5,313 assertions passed. The entire 983-case batch was not repeated after the narrow correction.
- Headless Puppeteer: a complete regional order and a same-child order reached verified delivery, buyer/seller/earnings reflection, counted hub COD receipt and duty closure through actual UI controls. Both orders are completed/paid; two regional manifests are closed, two bags received and ten earning entries released. Fixtures, cash and photo proofs are explicitly synthetic.
- Company account creation, password replacement and email-gated resume/re-login passed in the browser. Five logistics pages passed 1440px/390px overflow and keyboard-drawer checks.
- Production TypeScript/Vite build, 47 rider frontend checks and validation of all 110 OpenAPI schemas / 15 sanitized examples passed.
- Additive migrations applied to local PostgreSQL. No test reset or browser purchase touched that database. Azure rollout, production concurrency/load and physical Flutter/device acceptance remain separate.

Deploy this branch together using [DEPLOYMENT.md](DEPLOYMENT.md); no existing-installation reset/reseed is needed. Keep the scheduler running for legitimate deferred virtual proceeds. [OPERATIONS.md](OPERATIONS.md) names the exact station controls, and the [roadmap](../CORE_FLOW_ROADMAP.md#new-docs-era-october-28-2026) records the dated acceptance evidence and limits.

This title/body is a local draft. It has not been saved on GitHub, and no commits were pushed.
