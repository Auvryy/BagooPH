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

Use the seller's shop submission and Platform Admin review workflow with the required evidence. Missing legacy demo evidence needs a deliberate demo-data repair; it cannot be replaced with an invented approval. A database reset or general seed rerun is unnecessary for diagnosing visibility and can overwrite demo accounts, stock, or referenced products.

Current implementation evidence and remaining limits belong in [CORE_FLOW_ROADMAP.md](CORE_FLOW_ROADMAP.md).
