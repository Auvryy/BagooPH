# Bagoo — Multi-Role E-Commerce & Logistics Platform

<div align="center">
  <h3>Next-Generation Full-Stack E-Commerce Platform</h3>
  <p>Built with <strong>Laravel</strong>, <strong>React</strong>, <strong>TypeScript</strong>, <strong>Tailwind CSS</strong>, <strong>PostgreSQL</strong>, and <strong>Docker</strong>.</p>
</div>

---

## 🌟 Overview & Architecture

**Bagoo** is a modern e-commerce application featuring Role-Based Access Control (RBAC) across distinct user workflows:

- 🛒 **Buyer / Customer**: Browse catalog by category, search & filter products, shopping cart, multi-step checkout, real-time courier shipment tracking.
- 🏪 **Seller / Merchant**: Dedicated Storefront management, product catalog & inventory control (stock, SKU, pricing), incoming orders list, and "Ready for Courier Pickup" dispatch trigger.
- 🚚 **Courier / Rider**: Live delivery assignment board, pickup from merchant stores, drop-off to buyer destination, and delivery status milestones (`picked_up`, `in_transit`, `out_for_delivery`, `delivered`).
- 🛡️ **Administrator**: Platform overview metrics, GMV & transaction telemetry, user and role governance (upgrade/downgrade roles, suspend accounts), and global product moderation.
- 📦 **Extensible Logistics Provider**: Dedicated `deliveries` / `shipments` database schema, tracking numbers, and role interfaces structured to integrate 3rd-party logistics / fleet management hubs.

---

## 🛠️ Technology Stack

| Layer | Technologies |
|---|---|
| **Backend Framework** | Laravel 11 / 12 (PHP 8.3+) |
| **Frontend Framework** | React 18 / 19 + TypeScript + Vite |
| **Monolith Bridge** | Inertia.js (Zero-API boilerplate SPA) |
| **Styling & UI** | Tailwind CSS + Lucide Icons |
| **Database** | PostgreSQL 16 (`pdo_pgsql`) |
| **Containerization** | Docker & Docker Compose (PHP-FPM, Nginx, Postgres) |

---

## 🚀 Quick Start with Docker

### 1. Launch Containers
```bash
# Using the Bagoo CLI helper
./bagoo.sh start

# Or directly with Docker Compose
docker compose up -d --build
```

The application will be accessible at: **`http://localhost:8000`**

### 2. Run Database Migrations & Seed Demo Data
```bash
./bagoo.sh fresh
# or: docker compose exec app php artisan migrate:fresh --seed
```

---

## 🔑 Pre-Configured Demo Credentials

All test accounts use the password: **`password`**

| Role | Email | Password | Access Portal |
|---|---|---|---|
| **Administrator** | `admin@bagoo.test` | `password` | `/admin/dashboard` |
| **Seller / Merchant** | `seller@bagoo.test` | `password` | `/seller/dashboard` |
| **Buyer / Customer** | `buyer@bagoo.test` | `password` | `/` (Marketplace) |
| **Courier / Rider** | `courier@bagoo.test` | `password` | `/courier/deliveries` |

> 💡 *Tip: The Login page also includes 1-click demo login buttons for each role!*

---

## 📋 Useful Helper Commands (`./bagoo.sh`)

| Command | Action |
|---|---|
| `./bagoo.sh start` | Start all Docker services in background |
| `./bagoo.sh stop` | Stop all containers |
| `./bagoo.sh fresh` | Re-run migrations and seed fresh demo catalog & accounts |
| `./bagoo.sh artisan [command]` | Run any `php artisan` command inside the container |
| `./bagoo.sh npm [command]` | Run npm scripts (e.g. `./bagoo.sh npm run build`) |
| `./bagoo.sh bash` | Open an interactive shell inside the PHP app container |

---

## 🗄️ Database Schema & Logistics Design

```
users (role: admin | seller | buyer | courier | logistics)
  ├── shops (1:1 with seller)
  │    └── products (1:N with category)
  │         └── product_images & reviews
  ├── carts & cart_items (1:1 with user)
  ├── orders & order_items (1:N with buyer)
  └── deliveries (1:1 with order, assigned to courier / logistics)
```

- **Logistics Extensibility**: `deliveries` contains `logistics_partner`, `tracking_number`, `pickup_address`, `delivery_address`, status history, and notes. This allows assigning deliveries to internal couriers or external 3rd-party freight and logistics APIs.
