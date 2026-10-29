# Buyer mobile response fixtures

This pack accompanies [the web contract](../BUYER_MOBILE_INTEGRATION.md).
Each JSON fixture contains request method/path/non-secret headers and the HTTP
response status/headers/body. Mobile parsers consume `response.body`.

The current disabled capability fixture is checked against an executed isolated
web response by `CapabilitiesTest`. Additional fixtures must identify their
actual test/source evidence or be labelled as proposals. Passing a parser fixture
does not prove that a route is deployed or a device/provider is configured.

Do not add live cookies, CSRF values, Google tokens, passwords, signing keys,
private evidence URLs or personal records. Existing session and shopping
contracts remain authoritative; optional discovery failure does not block them.
