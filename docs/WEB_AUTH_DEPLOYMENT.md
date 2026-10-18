# Web Authentication Deployment

Use this guide when local sign-in works but the deployed HTTPS login or logout does not. Inspect the running environment and generated URLs before changing account credentials or approval rules.

For the application and container boundaries, see [ARCHITECTURE.md](ARCHITECTURE.md)
and [TECH_STACK.md](TECH_STACK.md). This guide describes operator actions; source
configuration and a local check do not establish the live server state.

## Production configuration

Keep these values in the server's private `.env`, replacing the domain placeholder with the deployed Bagoo domain:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<BAGOO_DOMAIN>
SESSION_SECURE_COOKIE=true
```

Keep the session cookie path and domain consistent with the existing portal subdomain setup. Development on local HTTP keeps its local environment and cookie settings.

Production mode makes Laravel generate HTTPS URLs. Client route generation also uses the browser's actual origin, and current-host Inertia redirects preserve that origin when HTTPS terminates before PHP. Successful web login and logout request a fresh document after the session changes. Invalid credentials and expired sign-in pages show validation feedback; role, approval, restriction, and CSRF checks still apply.

## After the user publishes and pulls the selected branch

Run the existing deployment helper from the repository:

```bash
./bagoo.sh deploy
./bagoo.sh verify
```

The deploy helper builds assets, applies additive migrations, clears old caches, and rebuilds configuration/views. Verification requires the asset manifest and no pending migrations; a pending migration returns a failure instead of reporting success. To inspect individual migrations, including `2026_10_07_180000_create_notification_deliveries`, run `./bagoo.sh artisan migrate:status --no-ansi` and confirm **Ran**. If only environment settings changed, clear and rebuild the configuration cache:

```bash
docker compose exec -T app php artisan optimize:clear
docker compose exec -T app php artisan config:cache
```

Reload the login page before retrying. Check successful login, wrong-password feedback, logout, and the next unauthenticated page across the applicable portal hosts. A local build or protocol test does not establish a deployed browser result.

## Voucher funding migration

Deploy the voucher funding migration with the ordinary migration step; do not
reset the database. It retains order totals, original voucher links, usage counts
and recorded seller payments. Earlier campaigns are archived because their payer
and spending budget were not recorded. New offers should be created as properly
funded drafts and published through the current seller/platform campaign
workspaces when eligible; see [voucher rollout](voucher-plan/ROLLOUT.md). Do not
reactivate an older offer by changing database flags or inventing
funding/redemption history.

## Build memory on small servers

A `Reached heap limit` failure during `tsc && vite build` can leave deployment unfinished before migrations run. The helper now passes `NODE_OPTIONS=--max-old-space-size=1024` into the build container, giving Node a 1 GiB heap cap. The October 8 server build succeeded with this setting and existing swap; the cap is not a reservation or a guarantee that every server has enough memory.

An explicit `NODE_OPTIONS` value is preserved. For a server with suitable memory, override it from the shell running the helper:

```bash
NODE_OPTIONS=--max-old-space-size=1536 ./bagoo.sh deploy
./bagoo.sh verify
```

If deployment stops during the build, complete a successful deployment and verification before treating new backend routes as ready. A database reset or dependency upgrade is not required to recover a Node heap-limit failure.

## Database host access

Compose binds the PostgreSQL host port to `127.0.0.1`, including when
`FORWARD_DB_PORT` selects another port. Host tools can connect locally; Laravel
continues to connect to `db:5432` over the shared Docker network. Keep the app's
`DB_HOST=db`; the host binding does not change the database credentials or the
`bagoo_pgdata` volume.

After publishing and pulling this change, `./bagoo.sh deploy` recreates the
database container as needed to apply the new binding while retaining its named
volume. This can briefly interrupt database connections. Run the usual deployment
verification, then inspect the actual published address:

```bash
docker compose port db 5432
```

Expect `127.0.0.1:5432`, or `127.0.0.1:<FORWARD_DB_PORT>` for a custom port. A
restart alone does not apply a changed Compose port mapping. Remote database
administration can use an SSH tunnel to localhost instead of a public database
port. Do not remove the named volume or reset the database for this change.

Review the deployed Docker version, host firewall and Azure network security
group's effective inbound rules separately. Database ports must not have an
Internet allow rule, including broad port ranges; SSH access should be scoped to
the administrator's source addresses. See the
[Docker port publishing guide](https://docs.docker.com/engine/network/port-publishing/)
and [Azure network security group guide](https://learn.microsoft.com/en-us/azure/virtual-network/manage-network-security-group).
Docker versions before 28 have a documented localhost-publishing caveat for
hosts on the same network segment. Local Compose checks do not establish the
server's Docker version, firewall rules or Internet reachability.

## Notification scheduling

The existing Laravel scheduler needs an actual invocation every minute, or a managed `schedule:work` process. `routes/console.php` registers pickup holding checks, `notifications:deliver-pending` and rider-company offer expiry every minute, plus advertisement raw-event cleanup daily. Immediate notification delivery happens after the business transaction commits. `schedule:list` shows registered schedules but does not prove that a scheduler process is running.

Compose starts only the app, web and database services. A scheduler or queue worker needs separate operator configuration; the database queue setting alone does not start a worker. Verify the actual environment without copying private credentials into logs or documentation.

## Products exist but the catalogue is empty

Git publication does not copy database rows. Before considering demo setup, compare the deployed total with the actual sale-eligible product count:

```bash
docker compose exec -T app php artisan tinker --execute='dump(["total_products" => App\Models\Product::count(), "visible_products" => App\Models\Product::availableForSale()->where("status", "active")->count(), "shops" => App\Models\Shop::get(["status", "review_status", "root_category_id", "review_decision_id"])->toArray()]);'
```

A shop needs an active approved seller, one of the 14 active master categories, and its current matching Platform Admin approval record. An `active` shop flag alone is insufficient. Legacy demo shops with missing category/review records can have products in the database while contributing no public catalogue entries.

Department browsing includes active child categories. The reserved demo products
live in an accessory child category, so an exact-category-only filter previously
hid them when browsing the parent department, even with valid approval. Publish
and deploy the department-filter fix before considering any data repair. It
requires no product deletion, reseeding or database reset. Inactive categories,
restricted shops and products remain excluded by the existing eligibility rules.

Each seller now has one shop created at registration. The initial seller application review covers that shop; the seller opens it automatically after approval. Ordinary legacy shops still need the actual owner evidence and Platform Admin review.

After publishing and pulling the one-shop repair, the deployment helper must install `2026_10_08_100000_enforce_one_shop_per_seller`. It adds unique ownership without moving data. If it reports existing duplicate ownership, resolve those accounts through a reviewed data decision before deployment; do not delete shops or orders to make migration pass.

For the deliberately reserved demo seller, use the targeted repair after deployment:

```bash
docker compose exec -T app php artisan db:seed --class=SellerDemoSeeder --force
```

This repair targets `seller@bagoo.test`, its sole shop and known catalogue fixtures. Fresh demo setup uses Men's Apparel and an active accessory child category. A previously reviewed shop keeps its chosen root and real review history; the repair only reclassifies known fixture products from the active legacy `backpacks-and-bags` root. Existing product IDs, orders, stock, prices, images, custom products, passwords and restrictions are retained. Unavailable/rejected/restricted demo shops fail explicitly instead of being reapproved.

A fresh or unreviewed original demo fixture receives clearly labelled **Synthetic demo setup** provenance, the actual setup time, a fictional adult date and private placeholder images. These are demo fixtures, not evidence of a human identity/document review or a historical approval. Real accounts and non-demo shops never receive this setup. Run the visibility check again to confirm the deployed result. A database reset or general seed rerun is unnecessary; the general seeder also configures the broader demo network.

Current implementation evidence and remaining limits belong in [CORE_FLOW_ROADMAP.md](CORE_FLOW_ROADMAP.md).

## Catalogue navigation preparation

PF01 uses the existing Inertia client to prepare selected buyer catalogue pages
and update settled search queries. Deploy its reviewed code/assets through the
normal workflow; no schema migration or reseeding is required for this feature.
Keep the current private/no-store response headers and normal server-side stock,
price, approval and ownership checks.

`CATALOGUE_PREFETCH_ENABLED=false`, followed by the normal deployment/config-cache
refresh, disables new speculative data work after the client receives an ordinary
updated page. Normal links and search still work. Existing Vite asset preparation
is separate. Use the [PF01 acceptance guide](performance-plan/ACCEPTANCE.md) to
check page 2, search/history, hover/focus, network recovery, account changes and
the disable path. Its local build/test evidence does not establish Azure timing
or a completed browser/device check.

## Account settings update

After publishing and pulling the settings branch, run the same deployment and verification commands above. Confirm `2026_10_08_000001_create_account_emails_table` is **Ran** before opening settings. It adds the email ownership registry and scoped challenges while retaining original account IDs, email strings and verification. Ambiguous legacy addresses block installation for explicit ownership resolution; do not reset the database or delete accounts to force deployment.

The existing secret-mail transport must be configured for actual OTP delivery. A log/array mailer cannot deliver codes to a person's inbox, and secret-mail checks reject unsafe delivery. Verify adding an address with the current password and emailed code, choosing it for contact, recovering through it, and retaining sign-in with the original address. Removing an additional address revokes its pending recovery credentials. No additional mail provider is required by this change.

Check the saved profile photo in both settings and the header, direct shop contact/branding changes without lost approval, future-address edits, and checkout opening the purchases/tracking workspace. Local tests and a production build do not establish these deployed browser or mail results.

## Waybill scanner update

Publish and pull the reviewed scanner changes, then use the normal deployment
and verification commands above to install their PHP code and rebuilt frontend.
This update needs no new migration, reseeding or database reset. Keep existing
HTTPS, session and facility permissions in place.

Use an assigned hub handler for floor scans. The company administrator manages
the network and manifests; that responsibility alone does not grant handler
scans. The [demo account table](../GETTING_STARTED.md#verified-seed-accounts)
identifies the origin, Mother Hub and destination handlers separately.

Verify these cases on the deployed browser/device:

1. After signing in, open `/hub/scan` directly, or `/scan` on the configured hub
   host. Check the displayed facility. A preceding Overview visit is unnecessary.
   A genuinely expired session has a Reload scanner action and may require login.
2. Read a raw tracking number, either code on a newly printed waybill, and an
   older QR containing the platform's tracking-page URL. New barcode and QR zones
   are separated and both contain the actual parcel identifier. The separate
   View tracking link remains available. Foreign URLs and joined codes fail
   validation; two different visible parcel codes require a closer camera view.
3. A lookup displays Waybill checked, the actual custodian, next actor and step.
   Only a deliberate permitted confirmation records custody. Parcels still held
   by the seller must first be collected and confirmed by their pickup rider.
4. Confirm a physical origin receipt and follow Open manifests. Incoming
   transport opens its actual manifest; receiving and closure remain separate.
   At the destination, follow Open parcel sorting, then Assign rider from the
   parcel registry. An assigned rider records departure using that rider's own
   account. Counter collection and supervised recovery retain their dedicated
   verification pages and evidence requirements.
5. Use Check for updates to recheck a waiting parcel and Scan next parcel to
   return to barcode entry. A failed lookup must remove the previous parcel's
   confirmation. Changing facility stops the old camera and pending lookup.
6. After a recorded handover, check the logistics queue and an open rider web
   Trips page. Relevant queues refresh every 15 seconds while visible and online,
   pausing during submitted actions. This is web queue freshness, not live GPS or
   a change to the separate Flutter application.

Camera use requires a secure browser context, permission and supported native
barcode detection. Unsupported clients retain manual or handheld-scanner entry
with visible feedback. Test actual print size, camera distance and device focus;
automated component checks do not certify optical recognition on every device.


## Account registration and review update

After publishing and pulling this branch, use the normal `deploy` and `verify`
commands above. The five October 18 `090000` through `090400` migrations preserve
existing records; a fresh database or reseed is unnecessary. Check migration
status before testing shared-role email sign-in or Account status.

The identity migration makes email and Google identity uniqueness role-specific
and retains original account IDs/emails. Its reverse migration intentionally
refuses to collapse those identities: use a reviewed roll-forward repair.
Existing duplicate normalized company names stop migration with conflicting IDs;
correct those records deliberately rather than deleting them. Existing companies
without TINs remain missing them until an owner supplies a correction. Re-running
a normal deployment does not run the demo seeder or invent a real tax identity.
Old unconsumed email-only reset links and worker registration codes may require a
new request after the identity migration.

Keep the existing real mail transport configured privately, and run the existing
Laravel scheduler. `notifications:deliver-pending` retries both in-app notices and
required account-status emails. Failed mail leaves the decision committed and an
owned pending-email message on Account status. A retry does not repeat approval
or reactivation; SMTP delivery can still be at least once if a process stops after
sending but before recording success. Never use a log mailer as proof of delivery.

Check a same-email pair of separate roles, independent passwords/recovery links,
several selected service regions, duplicate company/shop names and a duplicate
TIN. Sign in with rejected, suspended and deactivated owners: they must see their
own status and Request review while protected work remains blocked. Check admin
review, an outdated request, reopening an application without approval, ordinary
reactivation eligibility and the owner response. Verify actual inbox delivery on
the deployed mail service; local mail fakes and SQLite tests do not establish it.
