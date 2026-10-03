# Verification Document Storage and Deployment

The privacy and reviewer contracts come from `CORE_FLOW_VALIDATION_AND_EDGE_CASES.md` and `ADMIN_FLOW.md`. Current implementation evidence and remaining work belong in `CORE_FLOW_ROADMAP.md`.

## Storage and Access

Registration, role resubmission, and buyer ID uploads use the existing `local` disk under `storage/app/private/kyc_documents`. Never link that private directory into the public web root.

The application supplies document links by applicant ID and allowlisted document kind. Stored paths and client-provided filenames are not download parameters. An authenticated applicant may read their own documents while active or awaiting approval. An active Platform Admin may review applicants. Other accounts and suspended/inactive reviewers are denied. Logistics company membership alone does not grant platform KYC review access.

Generic user/company data excludes stored paths. Owner pages and the admin review queue receive authenticated links for identity, permit, license, vehicle registration, and franchise documents. A legacy public reference cannot be read through the new route until it has been migrated; there is no public-storage fallback.

## Deployment Order

Apply these steps on each environment that has legacy verification files. They are deployment operations; automated tests use fake storage and SQLite memory only.

1. Back up the database and verification files into access-controlled storage. Keep backups outside the public web root and repository.
2. Deploy and reload the web-server rule denying `/storage/kyc_documents` and everything below it. The repository supplies Nginx and Apache rules. Other hosting configurations must enforce the same denial; Laravel cannot intercept an existing file served directly by a web server.
3. Pause verification uploads/review during migration to keep files and references stable. Run the dry run:

   ```sh
   docker compose exec -T app php artisan verification:privatize --dry-run
   ```

4. Resolve missing files, unexpected public references, or conflicting private copies. The command fails with aggregate counts and never prints file contents or storage paths. Do not overwrite conflicting evidence. Use authorized database/storage access to reconcile metadata or request a fresh document from the applicant.
5. Run the migration:

   ```sh
   docker compose exec -T app php artisan verification:privatize
   ```

   Copies are checksum-verified before public originals are retired. User and company references change to private relative paths; account status and KYC approval do not change. Unreferenced files in the legacy document folder are preserved privately. Copies and already-updated references can be resumed safely after interruption. Repeat until the command succeeds.
6. Configure a real, non-logging mail transport and rebuild Laravel's configuration cache. Reopen verification operations after checking owner/reviewer access and public denial. With a different deployment layout, run the same Artisan commands in that environment's application runtime.

The migration handles the application's legacy `/storage/kyc_documents/` namespace. Unexpected references outside that namespace require reconciliation; the command does not move unrelated public assets. Do not roll back to application code that reads private relative paths from public storage, or restore public copies. Rollback/recovery must preserve the deny rule and evidence backups.

## Secret Mail and Logging

OTP, verification-link, and password-reset delivery require a transport that does not log message contents. `MAIL_MAILER=log` is rejected, as are failover/round-robin chains containing a log transport. The default and example configuration use SMTP; the built-in failover configuration no longer includes a logging fallback. The array transport is allowed only in testing. Existing SMTP/provider configuration can be used; no additional external service is required by this change.

If delivery fails, the application reports failure and logs only safe identifiers and the exception class. Newly created OTP/reset credentials are removed when their delivery fails. Secret values are excluded from old-input flash, and OTP hashes/tokens are hidden from generic model serialization. Existing registration accounts are retained when their verification email cannot be delivered.

Historical logs may contain messages written by the old log mailer. This implementation does not inspect, print, or erase existing logs. Restrict access and handle historical mail logs according to the environment's retention policy; rotate/invalidate any still-valid credentials that were exposed.

## Verification Limits

Automated tests cover applicant/reviewer access, wrong actors, suspended accounts, root/subdomain access, private storage, disguised uploads, storage failures, migration dry runs/conflicts/retries, and secret-mail failures. They never use the PostgreSQL development/production database. Nginx syntax and a synthetic existing-file denial are checked separately. Apache rules are supplied but were not executed in an Apache runtime. Browser UI testing and real production migration were not performed.
