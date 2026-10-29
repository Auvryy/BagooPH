# PR title

Connect buyer mobile capabilities, Google sign-in and HTTPS app associations

# PR description

The native buyer app needs explicit optional-feature discovery and a verified
Google identity exchange while retaining Bagoo's existing session, approval and
shopping contracts. Add capability v1, session/CSRF-based native Google sign-in
with explicit existing-account password proof and expiring registration, and
stateless owner-configured Android/iOS association documents. Defaults keep
Google and app associations disabled; device push remains deferred.

Reuse existing age, address and policy validation. Preserve fixed account roles,
original sign-in email, buyer approval, server-owned money/stock/custody and
existing browser OAuth. Add private JSON failure handling, bounded verified key
retrieval and executed sanitized fixtures. The handoff also identifies the mobile
session adapter's missing `/buyer` Home alias without changing the web redirect.

Validation: broader isolated groups passed 239/3,459, 210/4,941 and 15/648; after the
final verifier/fixture changes the focused contract/browser/policy/session group
passed 129/1,223. These overlap. PHP formatting, Composer validation and whitespace
checks passed. No shared PostgreSQL reset or production mutation was used.

Anonymous localhost session/capability reads pass; disabled Google and association
responses match their configured states. Azure probes returned 403, so deployed
version is unverified. Owner client/signing configuration, mobile Google SDK and
installed-device acceptance remain prerequisites. No new migration/worker is
required; install locked Composer dependencies and refresh existing application
caches during deployment.

Details: [acceptance report](BUYER_MOBILE_INTEGRATION_ACCEPTANCE.md) and
[implemented contracts](BUYER_MOBILE_INTEGRATION.md).

This is a **local PR draft**. No GitHub PR description was saved and this branch
was not pushed by this session.
