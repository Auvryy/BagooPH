# Buyer interaction acceptance evidence

This records the six selected BP03 observations after the return implementation
at `9daf9aa`. It is an interpretation of the project acceptance work, not a
replacement for its original manual results. The other 57 likely passes remain
outside this active checklist.

| Observation | Established support | Remaining browser observation |
|---|---|---|
| Literal search safety | The selected discovery test treats wildcard and escape characters as literal search values. | Rendered hostile text, console errors and absence of script execution. |
| Option changes | A synthetic eligible product has independent pair prices, stock and distinct actual image assets in an isolated HTTP fixture. | Click between pairs, return to an earlier choice, and observe loaded image, amount and stock together. |
| Sold-out pair | The fixture includes one unavailable pair alongside available pairs sharing its options. Existing server stock rejection remains intact. | Observe the disabled choice and verify the other color's corresponding size remains usable. |
| Saved Bag across sessions | Three independent real HTTP cookie jars establish same-buyer persistence and other-buyer isolation through the actual Laravel kernel. | Repeat sign-out/sign-in and compare the visible Bag in independent browser contexts. |
| Bag removal and recovery | Owned removal persists across the two same-buyer HTTP sessions; a foreign line identifier rejects. | Remove selected and unselected rows, compare saved totals/selection and interrupt then retry a visible request. |
| Return before delivery | A real Placed checkout fixture rejects the direct return form. The selected return test also rejects unsupported physical evidence. | Observe the actual purchase/return controls before delivery after R01–R03. |

The selected Docker PHP 8.4 checks passed **26 tests / 271 assertions** in SQLite
`:memory:`. They cover quantity/ownership, literal search, selection and the
pre-delivery return denial. The additional independent-cookie HTTP probe uses
host PHP **8.5.11**, the production frontend assets and a separately prepared
SQLite `:memory:` database. It uses the testing middleware configuration; it
does not establish production CSRF, session or deployment behavior.

The HTTP fixture has two synthetic buyers, an explicitly approved shop, a pair
matrix with different prices/stock and real generated image assets, and an actual
pre-delivery checkout. Its data disappears when the process stops. No browser,
screenshot, development reset or production mutation was used for this evidence.
The fixture process was stopped after the supporting checks.

All six **browser observations remain Not Run** pending explicit authorization or
user-led execution. The [procedure](03-interaction-checks.md) remains the gate;
route/HTTP evidence is not relabelled as a manual Pass. No implementation or
overall buyer-readiness rating increases from this preparation.
