# Buyer mobile response fixtures

This pack accompanies [the web contract](../BUYER_MOBILE_INTEGRATION.md).
Each JSON fixture contains request method/path/non-secret headers and the HTTP
response status/headers/body. Mobile parsers consume `response.body`.

| Fixtures | Executed evidence |
| --- | --- |
| `capabilities/disabled.json` | `CapabilitiesTest`: exact version-one disabled body/cache policy |
| `capabilities/enabled.json` | `ContractFixturesTest`: explicitly configured routes, Google=true and push=false |
| `google/*.json` | `ContractFixturesTest`: authentication, new challenge, registration shape, link proof, expiry and unavailable states |
| `transport/*.json` | `ContractFixturesTest`: guest, approved/pending/restricted/rejected/wrong-role session recognition, closed-session denial |
| `associations/*.json` | `AppAssociationsTest`: synthetic release identifiers, buyer paths and disabled document |

`transport/session-states.json` contains a `cases` list, each with its own request
and response envelope. Account setup IDs there are synthetic scenario labels,
not a claim that those rows exist in any deployed database. The existing session
response still has no role field; mobile must check authenticated destination
props before accepting a buyer session.

Registration's nonce, expiry and calendar dates are explicitly replaced by
placeholders after assertions on their real types/limits. `expiresAt` is an
integer in a real response. Provider tokens, signatures and RSA private keys are
generated only in test memory and never recorded. Tests compare complete fixture
bodies rather than treating illustrative data as deployed proof.

Additional fixtures must identify their actual test/source evidence or be labelled
as proposals. Passing a parser fixture does not prove that a route is deployed or
a device/provider is configured. Existing unchanged page/command surfaces and
their regression evidence are listed in the combined integration report; this
pack does not pretend to export every business transaction response.

Do not add live cookies, CSRF values, Google tokens, passwords, signing keys,
private evidence URLs or personal records. Existing session and shopping
contracts remain authoritative; optional discovery failure does not block them.
