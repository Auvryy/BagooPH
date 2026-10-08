# Web Authentication Deployment

Use this guide when local sign-in works but the deployed HTTPS login or logout does not. Inspect the running environment and generated URLs before changing account credentials or approval rules.

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

The deploy helper builds assets, applies additive migrations, clears old caches, and rebuilds configuration/views. Confirm that `2026_10_07_180000_create_notification_deliveries` appears as **Ran** in the migration output. If only environment settings changed, clear and rebuild the configuration cache:

```bash
docker compose exec -T app php artisan optimize:clear
docker compose exec -T app php artisan config:cache
```

Reload the login page before retrying. Check successful login, wrong-password feedback, logout, and the next unauthenticated page across the applicable portal hosts. A local build or protocol test does not establish a deployed browser result.

## Notification scheduling

The existing Laravel scheduler needs an actual invocation every minute, or a managed `schedule:work` process. It runs both pickup holding checks and `notifications:deliver-pending`; immediate notification delivery happens after the business transaction commits. `schedule:list` shows registered schedules but does not prove that a scheduler process is running.

## Products exist but the catalogue is empty

Git publication does not copy database rows. Before considering demo setup, compare the deployed total with the actual sale-eligible product count:

```bash
docker compose exec -T app php artisan tinker --execute='dump(["total_products" => App\Models\Product::count(), "visible_products" => App\Models\Product::availableForSale()->where("status", "active")->count(), "shops" => App\Models\Shop::get(["status", "review_status", "root_category_id", "review_decision_id"])->toArray()]);'
```

A shop needs an active approved seller, one of the 14 active master categories, and its current matching Platform Admin approval record. An `active` shop flag alone is insufficient. Legacy demo shops with missing category/review records can have products in the database while contributing no public catalogue entries.

Each seller now has one shop created at registration. The initial seller application review covers that shop; the seller opens it automatically after approval. Ordinary legacy shops still need the actual owner evidence and Platform Admin review.

After publishing and pulling the one-shop repair, the deployment helper must install `2026_10_08_100000_enforce_one_shop_per_seller`. It adds unique ownership without moving data. If it reports existing duplicate ownership, resolve those accounts through a reviewed data decision before deployment; do not delete shops or orders to make migration pass.

For the deliberately reserved demo seller, use the targeted repair after deployment:

```bash
docker compose exec -T app php artisan db:seed --class=SellerDemoSeeder --force
```

This repair targets `seller@bagoo.test`, its sole shop and known catalogue fixtures. Fresh demo setup uses Men's Apparel and an active accessory child category. A previously reviewed shop keeps its chosen root and real review history; the repair only reclassifies known fixture products from the active legacy `backpacks-and-bags` root. Existing product IDs, orders, stock, prices, images, custom products, passwords and restrictions are retained. Unavailable/rejected/restricted demo shops fail explicitly instead of being reapproved.

A fresh or unreviewed original demo fixture receives clearly labelled **Synthetic demo setup** provenance, the actual setup time, a fictional adult date and private placeholder images. These are demo fixtures, not evidence of a human identity/document review or a historical approval. Real accounts and non-demo shops never receive this setup. Run the visibility check again to confirm the deployed result. A database reset or general seed rerun is unnecessary; the general seeder also configures the broader demo network.

Current implementation evidence and remaining limits belong in [CORE_FLOW_ROADMAP.md](CORE_FLOW_ROADMAP.md).

## Account settings update

After publishing and pulling the settings branch, run the same deployment and verification commands above. Confirm `2026_10_08_000001_create_account_emails_table` is **Ran** before opening settings. It adds the email ownership registry and scoped challenges while retaining original account IDs, email strings and verification. Ambiguous legacy addresses block installation for explicit ownership resolution; do not reset the database or delete accounts to force deployment.

The existing secret-mail transport must be configured for actual OTP delivery. A log/array mailer cannot deliver codes to a person's inbox, and secret-mail checks reject unsafe delivery. Verify adding an address with the current password and emailed code, choosing it for contact, recovering through it, and retaining sign-in with the original address. Removing an additional address revokes its pending recovery credentials. No additional mail provider is required by this change.

Check the saved profile photo in both settings and the header, direct shop contact/branding changes without lost approval, future-address edits, and checkout opening the purchases/tracking workspace. Local tests and a production build do not establish these deployed browser or mail results.
