# BP03: Remaining interaction checks

Proposed branch: `test/buyer-interaction-acceptance`.
Active references: `BUY-SRCH-005`, `BUY-PDP-002`, `BUY-PDP-003`, `BUY-CART-008`,
`BUY-CART-009`, and `BUY-RET-002`. Follow the [shared plan](README.md).

## Outcome

Resolve the six particular interactions whose evidence was incomplete. The
57 likely passes stay outside this active checklist; use their existing tests
only when a selected change threatens related behavior.

| Reference | Setup and complete observation |
|---|---|
| `BUY-SRCH-005` | Use literal punctuation, wildcard characters and hostile markup/query text in public search. Check the response, visible text and browser console. No script executes, query syntax expands scope, private listing leaks, server error or unintended write occurs. |
| `BUY-PDP-002` | Configure distinct stock, surcharge and real image data for several color/size pairs. Click between them and verify visible price, stock and image agree with the actual selected pair, including returning to a previous choice. |
| `BUY-PDP-003` | Include a zero-stock pair alongside an available pair sharing one option. Select its color and verify the sold-out size is disabled, cannot be selected/submitted, and does not disable the different available combination. |
| `BUY-CART-008` | Add distinct owned option lines and quantities, sign out/in, then open an independent browser session for the same approved buyer. Both sessions read the same persisted lines. A second buyer sees none of them; changing sessions does not silently merge a foreign Bag. |
| `BUY-CART-009` | Remove selected and unselected Bag lines, then refresh and revisit checkout selection. Rows, totals, selection counts and persistence agree. Removing a foreign identifier is rejected. A failed request preserves the real row and offers retry. |
| `BUY-RET-002` | Open an owned order before delivery and its buyer return area. No actionable post-delivery request is offered and no direct request bypass exists. Record this negative result without declaring the absent full return flow complete. |

## Preparation and safety

- Trace actual routes and components before testing; use existing seeded roles
  and inspect the seeders for local sign-in details. Do not invent credentials or
  place them in evidence or tracked documents.
- Use isolated fixtures for destructive transitions. SQLite `:memory:` remains
  the automated application-test database. Do not reset, reseed or erase the
  development/production database to reproduce a reference setup.
- Distinct role sessions are required where the scenario calls for another
  buyer/seller. User-led normal/private browser windows are suitable; do not
  replace one account's cookie and call it a simultaneous independent session.
- Confirm BP01's control fixes and BP02's applicable expectations before judging
  those repaired areas. Record the exact deployed/local commit and test data
  configuration so a result can be reproduced.

Automated route tests and React interaction/rendering checks may strengthen the
evidence. They must exercise the relevant event and resulting state, not only
find a source string or check that a function exists. They do not establish a
browser console, rendered image switch or separate-device result unless those
specific steps were actually executed.

Browser steps are user-led unless explicitly authorized for the selected task.
Do not open a visible browser, automate one or capture screenshots by default.
If a required observation has not happened, retain Not Run with the missing step;
do not convert it to Pass from confidence in server code.

## Defect handling and exit

Record the trigger, observed result, expected project behavior and affected
side effects. A selected, bounded repair needs its own logical commit and focused
regression checks. Changes to shared stock, pricing, ownership or request handling
need the related existing tests; unrelated passing cases do not automatically
become new manual work.

Keep the buyer portal's style and responsive behavior, with visible pending/error
feedback and Inertia navigation. Match any seller setup page to the seller portal.
Use the local frontend design skill for UI repairs and build after frontend work.

Exit when each of these six has an actual method/outcome or an explicit remaining
blocking observation. A documented unexecuted check is an honest handoff, not a
completed acceptance gate. Keep the positive return prerequisite in
[RET](05-payment-and-return-prerequisites.md#ret-buyer-returns-and-repayment).
