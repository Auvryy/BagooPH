# Merged pull request summary — PR #144

Title: Restore original Wallet evidence and versioned rider delivery guidance

## Description

After a valid paid Wallet delivery, Rider Trips omitted the original recipient
and returned 404 from its proof link because it required a COD collection. The
API now reads the immutable Wallet handover receipt for the same retained
delivery, checkpoint and actor. Original evidence survives later contact edits
and exact command replay. Assignment windows still keep a previous rider's
failed attempt separate from a later rider's successful delivery. Wallet reads
create no cash account.

Private image reads retain safe-path, owner, no-store and original-hash checks.
An invalid replacement image now returns the same 409 evidence conflict as other
changed bytes. Missing or foreign evidence remains 404. Task guidance describes
automatic version-two completion and no Wallet cash collection, while retaining
historical buyer confirmation. Handover acceptance now checks completed/paid
version-two results independently of pending COD remittance and rider earnings.

## Validation

- 452 affected PHP tests passed with 16,115 assertions; PHP 8.4.24, 127 MB
  PHPUnit peak memory, 6 minutes 1 second.
- Coverage includes all native Rider API groups, Wallet, historical COD cash
  custody and version-two local/recovery flows. New cases exercise original
  recipient/proof after contact changes and replay, actual retry reassignment,
  foreign and mismatched evidence, unsafe paths and changed/missing images.
- PHP formatting for all five changed PHP files, documentation links, privacy
  and Git whitespace checks passed.

Tests use isolated SQLite memory and separate private test storage in the
configured PHP 8.4 container. Checks run sequentially with a 512 MB PHP limit.
No frontend files or dependencies changed. Deployment, production PostgreSQL
concurrency and physical Android acceptance remain separate; phone testing is
deferred. Existing client write gates stay unchanged.

## Merge status

The branch started at `f001d39`. The code and regressions are in `15d7fc3`
(`fix(api): retain Wallet trip proof and versioned delivery guidance`), followed
by documentation commits `9e58312` and `a54c76a`. PR #144 merged the branch
into Bagoo `main` at `9ca5db5` on November 2, 2026.

The recorded 452-test run predates the merge and remains branch-level evidence;
this documentation update did not rerun it. Merge confirms source inclusion,
not Azure deployment, migrations, live cross-role acceptance, or Android device
acceptance. Existing Rider write gates remain unchanged.
