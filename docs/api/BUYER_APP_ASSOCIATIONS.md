# Buyer HTTPS app associations

The server supports owner-configured Android App Links and Apple Universal Links.
Association hosting is a web feature; approved release signing, entitlements and
installed-device acceptance remain separate owner/mobile work.

| Platform | Guest route | Enabled response |
| --- | --- | --- |
| Android | GET `/.well-known/assetlinks.json` | JSON array with approved package, SHA-256 fingerprints and buyer path rules |
| Apple | GET `/.well-known/apple-app-site-association` | JSON `applinks.details` with approved team/bundle and buyer components |

These routes have no web session/CSRF/Inertia middleware, authentication, database
dependency or login redirect. Enabled documents use `application/json`, `nosniff`
and `public, max-age=300`; no session cookie is issued. Disabled/incomplete/wrong
origin requests return 404 JSON, `private, no-store`, without publishing placeholders.
Android and Apple identity readiness are independent.

## Owner configuration

Start with the `.env.example` defaults, which disable associations:

```dotenv
BUYER_MOBILE_LINKS_ENABLED=false
BUYER_MOBILE_LINK_ORIGIN=
BUYER_MOBILE_ANDROID_APPLICATION_ID=
BUYER_MOBILE_ANDROID_SHA256_FINGERPRINTS=
BUYER_MOBILE_APPLE_TEAM_ID=
BUYER_MOBILE_IOS_BUNDLE_ID=
```

Supply the actual public **HTTPS buyer origin**, without a trailing slash, path,
query or fragment. Requests must use that exact host and standard HTTPS port.
Worker portal hosts and IP/localhost origins are rejected. Configure explicit
trusted TLS-terminating proxies through the existing deployment setup when
applicable; an arbitrary forwarded-protocol header is not proof of HTTPS.

The mobile template currently identifies Android as
`shop.bagooph.bagoo_buyer_mobile` and iOS as `shop.bagooph.bagooBuyerMobile`.
These are observed identifiers, not approval of their production registration.
Use only the owner-approved release certificate fingerprints (up to eight,
comma-separated, each 32 hexadecimal bytes separated by colons), and a 10 character
uppercase alphanumeric Apple Team ID. Package/bundle fields have a 255 character
limit. No fingerprint or team is supplied automatically. Enable only after the
owner checks the host, release identity and mobile configuration together.

[Android's association documentation](https://developer.android.com/training/app-links/configure-assetlinks)
explains certificate selection, including Play signing, and Android 15 dynamic
rules. [Apple's associated-domain documentation](https://developer.apple.com/documentation/xcode/supporting-associated-domains)
covers the HTTPS document and application entitlement. A local debug signature
must not silently become the production association.

## Paths and navigation

The generated documents include Home, tracking, product/shop/category, promotion,
Bag/checkout, buyer search/profile/orders/messages/addresses/saved/followed pages,
notifications, account status/settings/security, owned returns, wallet payment
details, password reset and signed email verification families.

No broad `/buyer/*`, `/account/*`, admin, seller, courier or hub portal rule is
advertised. Reset-password rules first accept explicit `role=buyer`, exclude other
present role values, then allow a missing role. A final exclusion rejects all
unmatched paths. Query values and signed URLs are never rewritten by the server
association endpoints.

OS wildcard matching is coarser than the native `BuyerLink` parser: nested or
malformed IDs, actions, role/query conflicts, encoded separators, foreign origins
and overlong URLs still require strict native rejection. Android 15+ can consume
the generated dynamic rules; older Android versions rely on the matched native
manifest filters. The server cannot expand the installed manifest's scope.

Opening a link only navigates. Guest private destinations require native login,
and the backend still checks the current buyer, owner and state. Links cannot
perform checkout, payment, receipt, return or account mutations automatically.
Browser destinations remain usable without the app.

## Verification and rollback

`AppAssociationsTest` checks exact public formats, no session cookies/database
queries, valid/invalid configuration, HTTPS/host/worker rejection, independent
platform disablement and explicit buyer/role path rules. The test identifiers and
fingerprints are synthetic and never approve a live release.

After publication, verify both documents directly on the actual buyer origin
without redirects, then check Android/iOS cold and warm launch, guest sign-in,
account switch/wrong owner, unsupported paths and signed/expired links on real
devices. Linux preview and isolated web JSON tests cannot establish those results.
Neither device test nor production association publication is claimed here.

Rollback by setting `BUYER_MOBILE_LINKS_ENABLED=false` and rebuilding the existing
configuration cache. Cached association decisions may persist on devices/platform
CDNs; the server's disable switch is not instantaneous revocation there. Native
allowlists and server authorization remain necessary throughout.
