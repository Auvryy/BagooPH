# Deploying logistics version 2

Deploy the complete branch together. Its controllers, quote fields, bagging screens and ledger readers depend on the additive `2026_10_27_220000_add_new_era_logistics` and `2026_10_27_220100_retain_rider_shift_cash_event_boundaries` migrations. Do not deploy only the frontend or only the migration.

## Existing installation

1. Take the normal database and private-upload backup.
2. Install the reviewed branch through the existing Docker deployment process (`./bagoo.sh deploy`). Apply pending migrations with `php artisan migrate --force` inside the application container, then clear/rebuild the application caches and frontend assets.
3. Keep the scheduler running. `wallet:release-delivered` retries automatic virtual proceeds after legitimate account/return blockers clear; the existing payment-expiry, pickup-holding and notification jobs remain necessary.
4. Open Company setup as the approved company owner. Check each child hub's actual Mother Hub parent, postal coverage, optional barangay restrictions, shipping tariff and earnings model. Existing single-Mother networks retain their unambiguous city/province fallback until the owner supplies explicit coverage. Configure parents before adding another Mother Hub to that region.
5. Check physical operator assignments and source-hub vehicles/drivers in Fleet. A company owner manages the network; actual parcel scans still require an eligible operator at the physical station.
6. Verify one regional delivery and one same-child delivery using the operations guide. Use separate labelled demo records when checking proof and cash handovers.

Never run `migrate:fresh`, reset the database, or rerun `DatabaseSeeder` on an existing installation. The demo seeder recreates initial accounts and is intended for a new isolated installation. This migration does not create purchases, change existing passwords, rewrite old routes, recalculate historical shipping, or invent old earnings.

Version-one orders keep their existing delivery/receipt contract and saved money. New checkout creates version-two orders with an immutable purchase-time tariff and ordered route. A local bypass applies only to a new order whose origin and destination resolve to the identical child hub.

## Money and recovery

An automatic earnings release is a retained entitlement. It does not assert that a bank transfer or cash payout occurred. COD collector balances, acknowledged hub receipts, platform reconciliation and actual external seller-payment proof remain separate records. Virtual wallet credits use their balanced original journal and never create COD cash.

Approved buyer-return adjustments reduce the displayed net product earnings using the original purchased units and original promotion funding. Original earned amounts remain retained. Return approval, actual repayment and receipt are separate facts.

If deployment is interrupted, complete the migration and rebuild the same release. The migration deliberately refuses destructive rollback of retained bag, staff, shift and financial evidence. Use a reviewed forward repair if an installed release needs correction.

## Verification environment

Automated feature tests use SQLite `:memory:`. The headless browser acceptance environment uses a separate PHP process and persistent SQLite in-memory connection, synthetic accounts and private synthetic proof uploads. Its controls exercise the real web routes; it does not reset or create orders in the normal PostgreSQL development database. Browser evidence proves software behavior, not physical parcel movement or real cash transfers.

The local PostgreSQL upgrade was applied on October 28, 2026, including the already-pending reserved demo-transport repair from main. The new user eligibility scope supports that older data migration before the staff columns exist. This was an additive local schema upgrade, not an Azure release or a PostgreSQL concurrency test. The separate test suites still use only SQLite memory.

When running separate PHP test jobs concurrently, give each a distinct `TEST_TOKEN` in the application container as well as `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`. Laravel uses that token to isolate fake proof-upload disks. Ordinary `./bagoo.sh test` remains suitable for one sequential test job. Do not mistake a shared fake-disk collision for a product receipt or evidence mutation.
