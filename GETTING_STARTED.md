# 🛍️ Bagoo — Getting Started Guide

Welcome to **Bagoo**! This guide will help you get the project up and running in less than 2 minutes after cloning the repository.

---

## 📋 Prerequisites

You only need **one tool** installed on your computer:
* **Docker Desktop** (or Docker Engine with Docker Compose)

> 💡 *You do **not** need to manually install PHP, Composer, PostgreSQL, or Node on your computer. Docker handles all of them inside isolated containers.*

---

## 🚀 Quick Setup (For New Clones)

When you or someone else clones this repository onto a computer, run these 4 commands in your terminal:

```bash
# 1. Copy the environment config file
cp .env.example .env

# 2. Start the Docker containers (Nginx, PHP 8.4, PostgreSQL 16)
./bagoo.sh start

# 3. Install packages and generate the app security key
./bagoo.sh composer install
./bagoo.sh npm install
./bagoo.sh artisan key:generate

# 4. Run database migrations & load test products & accounts
./bagoo.sh fresh
```

Open your browser and navigate to:
👉 **`http://localhost:8000`**

---

## 🔑 Test Accounts & Logins

All test accounts use the password: **`password`**

| Role | Test Email | Password | Access Portal |
|---|---|---|---|
| 🛒 **Buyer** (Customer) | `buyer@bagoo.test` | `password` | `http://localhost:8000` (Marketplace) |
| 🏪 **Seller** (Merchant) | `seller@bagoo.test` | `password` | `http://localhost:8000/seller/dashboard` |
| 🚚 **Courier** (Rider) | `courier@bagoo.test` | `password` | `http://localhost:8000/courier/deliveries` |
| 🛡️ **Admin** (System Control) | `admin@bagoo.test` | `password` | `http://localhost:8000/admin/dashboard` |

> ⚡ **Quick Tip:** The [Sign In page](http://localhost:8000/login) includes **1-Click Demo Buttons** so you don't even have to type the email and password manually during testing or school demos!

---

## 💻 Daily Development Commands (`./bagoo.sh`)

Instead of typing long Docker commands, use the included `./bagoo.sh` shortcut script:

```bash
# Start the project in background
./bagoo.sh start

# Stop the project
./bagoo.sh stop

# Reset database with fresh sample products & orders
./bagoo.sh fresh

# Recompile frontend changes (React/Tailwind)
./bagoo.sh npm run build

# Run any Laravel artisan command (e.g. create a controller)
./bagoo.sh artisan make:controller MyController

# View live container logs
./bagoo.sh logs
```

---

## 🏗️ How the Project is Structured

```
bagoo/
├── app/
│   ├── Enums/               # UserRole, OrderStatus, DeliveryStatus
│   ├── Http/Controllers/   # Marketplace, Seller, Courier, and Admin controllers
│   ├── Http/Middleware/    # RoleMiddleware (controls access per role)
│   └── Models/             # User, Shop, Product, Order, Delivery, Cart, Review
├── database/
│   ├── migrations/         # PostgreSQL table blueprints
│   └── seeders/            # Demo catalog and user accounts
├── resources/
│   └── js/
│       ├── Layouts/        # MarketplaceLayout and DashboardLayout (role sidebar)
│       └── Pages/          # React TS pages (Marketplace, Cart, Checkout, Portals)
├── docker-compose.yml      # Docker container definitions
└── bagoo.sh                # Helper script for running commands in Docker
```

Enjoy building on **Bagoo**! 🎉
