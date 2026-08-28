# Milestone M1 Technical Investigation & Database Architecture Report

**Investigator:** Explorer 1 (`explorer_m1_db`)  
**Target Platform:** BagooPH (Laravel 11/12 + Inertia.js React + PostgreSQL 16 / SQLite)  
**Milestone:** M1 (Database Schema, Migrations, Models & Field Consistency)  
**Date:** 2026-08-27  

---

## 1. Observation

A comprehensive, line-by-line inspection of all database migrations (`database/migrations/`), Eloquent models (`app/Models/`), enums (`app/Enums/`), seeders (`database/seeders/`), controllers (`app/Http/Controllers/`), and frontend TypeScript definitions (`resources/js/types/index.d.ts`) was executed.

Below are the verified direct observations:

### 1.1 `users` Table & KYC Columns
- **File:** `database/migrations/0001_01_01_000000_create_users_table.php` (lines 14–29)
  ```php
  Schema::create('users', function (Blueprint $table) {
      $table->id();
      $table->string('name');
      $table->string('email')->unique();
      $table->string('role')->default('buyer')->index();
      $table->string('phone')->nullable();
      $table->string('avatar')->nullable();
      $table->string('address')->nullable();
      $table->string('city')->nullable();
      $table->string('postal_code')->nullable();
      $table->string('status')->default('active');
      $table->timestamp('email_verified_at')->nullable();
      $table->string('password');
      $table->rememberToken();
      $table->timestamps();
  });
  ```
- **Observations:**
  1. `users` table already has `phone`, `address`, `city`, and `postal_code`.
  2. `status` defaults to `'active'`.
  3. `users` table is **missing** KYC verification columns:
     - `kyc_status` (required enum/string: `'pending_approval'`, `'approved'`, `'rejected'`, default `'pending_approval'`)
     - `id_document_path` (nullable string for Government ID)
     - `business_permit_path` (nullable string for Seller DTI/SEC permit)
     - `driver_license_path` (nullable string for Courier Driver's License)
     - `or_cr_path` (nullable string for Courier Vehicle OR/CR document)
     - `kyc_feedback` (nullable text for rejection/admin review reason)
     - `kyc_submitted_at` (nullable timestamp)
     - `kyc_reviewed_at` (nullable timestamp)
- **Model:** `app/Models/User.php` (lines 15–26, 33–39)
  - `$fillable` does not contain any of the KYC fields.
  - `$casts` only casts `email_verified_at` and `password`.
  - No `courierProfile()` relationship exists.
  - No KYC status helper methods (`isKycApproved()`, `isKycPending()`, `isKycRejected()`) exist.
- **Enum:** `app/Enums/KycStatus.php` does not exist.

---

### 1.2 `courier_profiles` Table & Model
- **Observations:**
  1. No migration exists for `courier_profiles` in `database/migrations/`.
  2. No Eloquent model exists for `CourierProfile` in `app/Models/`.
  3. In `app/Http/Controllers/Courier/CourierDeliveryController.php` (lines 209–222), courier vehicle and license data is hardcoded as mock array data:
     ```php
     'fleetData' => [
         'vehicle_type' => 'Motorcycle (Express Dispatch)',
         'plate_number' => 'NCS-8892',
         'license_number' => 'N02-18-092831',
         'license_status' => 'Verified (Class A/A1/B)',
         'or_cr_status' => 'Valid & Registered',
         'zone' => 'Metro Manila & Rizal Corridor',
         'completed_deliveries' => $completedCount,
         'rating' => 4.95,
     ]
     ```
  4. In `resources/js/types/index.d.ts`, no `CourierProfile` interface exists.

---

### 1.3 `cart_items` and `order_items` Tables (Variant Fields)
- **File:** `database/migrations/2026_01_01_000005_create_carts_and_cart_items_tables.php` (lines 18–25)
  ```php
  Schema::create('cart_items', function (Blueprint $table) {
      $table->id();
      $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
      $table->foreignId('product_id')->constrained()->cascadeOnDelete();
      $table->integer('quantity')->default(1);
      $table->decimal('unit_price', 12, 2);
      $table->timestamps();
  });
  ```
- **File:** `database/migrations/2026_01_01_000006_create_orders_and_order_items_tables.php` (lines 32–42)
  ```php
  Schema::create('order_items', function (Blueprint $table) {
      $table->id();
      $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
      $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
      $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
      $table->integer('quantity');
      $table->decimal('unit_price', 12, 2);
      $table->decimal('subtotal', 12, 2);
      $table->timestamps();
  });
  ```
- **Frontend Submission:** `resources/js/Pages/Buyer/ProductDetail.tsx` (lines 116–121) submits:
  ```tsx
  router.post(route('cart.store'), {
      product_id: product.id,
      quantity: quantity,
      color: selectedColor?.name,
      size: selectedSize?.name,
  });
  ```
- **Controller Validation:** `app/Http/Controllers/Buyer/CartController.php` (lines 51–52) validates:
  ```php
  'color' => 'nullable|string|max:50',
  'size' => 'nullable|string|max:50',
  ```
- **Observations:**
  1. Neither `cart_items` nor `order_items` table contains `color`, `size`, or `sku_snapshot`.
  2. `CartItem` model (`app/Models/CartItem.php`) and `OrderItem` model (`app/Models/OrderItem.php`) `$fillable` arrays omit `color`, `size`, and `sku_snapshot`.
  3. `CartController::store` matches existing items only by `product_id` (line 63: `$cart->items()->where('product_id', $product->id)->first()`), failing to distinguish different variants of the same product.
  4. `CheckoutController::store` (lines 137–144) does not copy `color`, `size`, or `sku_snapshot` from cart items to `OrderItem::create`.

---

### 1.4 Field Mismatch Analysis (`delivery_phone` vs `recipient_phone`)
- **Database Schemas:**
  - `orders` table (`2026_01_01_000006_create_orders_and_order_items_tables.php` line 24):
    `$table->string('recipient_phone');`
  - `deliveries` table (`2026_01_01_000007_create_deliveries_table.php` line 26):
    `$table->string('delivery_phone');`
- **Models:**
  - `Order` model (`app/Models/Order.php` line 25): `$fillable` contains `'recipient_phone'`.
  - `Delivery` model (`app/Models/Delivery.php` line 24): `$fillable` contains `'delivery_phone'`.
- **Bugs in Controllers:**
  - `app/Http/Controllers/Buyer/CheckoutController.php` line 165:
    ```php
    Delivery::create([
        ...
        'delivery_recipient_name' => $validated['recipient_name'],
        'delivery_address' => $validated['shipping_address'] . ', ' . $validated['shipping_city'],
        'recipient_phone' => $validated['recipient_phone'], // <--- BUG: should be 'delivery_phone'
        'estimated_delivery_at' => now()->addDays(3),
    ]);
    ```
  - `app/Http/Controllers/Seller/SellerOrderController.php` line 100:
    ```php
    Delivery::create([
        ...
        'delivery_recipient_name' => $order->recipient_name ?? $order->buyer?->name ?? 'Customer',
        'delivery_address' => ($order->shipping_address ?? 'Customer Address') . ', ' . ($order->shipping_city ?? 'Metro Manila'),
        'recipient_phone' => $order->recipient_phone ?? $order->buyer?->phone ?? '+63 900 000 0000', // <--- BUG: should be 'delivery_phone'
    ]);
    ```
- **Impact:**
  - Because `Delivery::$fillable` only permits `'delivery_phone'`, passing `'recipient_phone'` is silently stripped by Eloquent.
  - In `resources/js/Pages/Courier/Deliveries.tsx` line 275:
    `<span>{delivery.delivery_phone}</span>` renders as empty or blank.
  - In `resources/js/Pages/Seller/Orders.tsx` line 140:
    `<span>{selectedOrderForWaybill.order?.recipient_phone}</span>` reads from `order.recipient_phone`.

---

### 1.5 Database Seeders Analysis
- **File:** `database/seeders/DatabaseSeeder.php` (lines 21–76)
- **Observations:**
  1. Default seeded accounts (`admin@bagoo.test`, `seller@bagoo.test`, `buyer@bagoo.test`, `courier@bagoo.test`) only specify `'status' => 'active'`. None have `'kyc_status' => 'approved'`.
  2. The 5th system role (`logistics`) is completely missing from `DatabaseSeeder.php` (`logistics@bagoo.test`).
  3. No `CourierProfile` record is seeded for `courier@bagoo.test`.
  4. No demo pending (`pending_approval`) or rejected (`rejected`) users are seeded for testing the Admin KYC queue.

---

## 2. Logic Chain

```
[Observation 1.1] users table lacks KYC columns; status defaults to 'active'
       │
       ▼ (Requirement R4)
[Step 1] Add migration for users: kyc_status, id_document_path, business_permit_path, driver_license_path, or_cr_path, kyc_feedback, kyc_submitted_at, kyc_reviewed_at. Create KycStatus enum.

[Observation 1.2] courier_profiles table and CourierProfile model are missing; CourierDeliveryController uses mock fleet data
       │
       ▼ (Requirement R1, R4)
[Step 2] Add migration for courier_profiles (user_id FK unique, vehicle_type, plate_number, license_number, or_cr_status, is_available). Create CourierProfile model & User::courierProfile() relation.

[Observation 1.3] cart_items and order_items lack color, size, sku_snapshot despite frontend submitting variant choices
       │
       ▼ (Requirement R1)
[Step 3] Add migration adding color, size, sku_snapshot to cart_items and order_items. Update models CartItem & OrderItem. Update CartController::store and CheckoutController::store.

[Observation 1.4] CheckoutController:165 and SellerOrderController:100 pass 'recipient_phone' into Delivery::create
       │
       ▼ (Field Consistency Bugfix)
[Step 4] Correct parameter key to 'delivery_phone' in Delivery::create calls. Ensure orders.recipient_phone and deliveries.delivery_phone are correctly populated and mapped.

[Observation 1.5] DatabaseSeeder lacks kyc_status='approved', lacks logistics@bagoo.test, lacks CourierProfile seeding
       │
       ▼ (Seeder & Test Consistency)
[Step 5] Update DatabaseSeeder to seed all 5 roles with kyc_status='approved' & status='active', seed CourierProfile for courier, and add demo pending/rejected accounts.
```

---

## 3. Caveats

1. **Database Engine Portability:** The application runs on PostgreSQL 16 in production/docker and SQLite in test/development environments. All migrations must use portable schema methods (e.g. `$table->string('kyc_status')->default('pending_approval')->index()`) and avoid database-specific raw SQL enum definitions that fail in SQLite.
2. **Column Ordering:** In SQLite, `->after('column')` clauses in schema migrations are ignored by SQLite's table alterer without error, but function properly in PostgreSQL and MySQL.
3. **Foreign Key Integrity:** `courier_profiles.user_id` must have `cascadeOnDelete()` and a `unique()` index to enforce 1-to-1 integrity between a user and their courier fleet profile.

---

## 4. Conclusion & Actionable Implementation Plan

### 4.1 New Migrations Blueprint

#### Migration 1: Add KYC Columns to Users Table
**File:** `database/migrations/2026_08_27_000001_add_kyc_fields_to_users_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('kyc_status')->default('pending_approval')->index()->after('status');
            $table->string('id_document_path')->nullable()->after('kyc_status');
            $table->string('business_permit_path')->nullable()->after('id_document_path');
            $table->string('driver_license_path')->nullable()->after('business_permit_path');
            $table->string('or_cr_path')->nullable()->after('driver_license_path');
            $table->text('kyc_feedback')->nullable()->after('or_cr_path');
            $table->timestamp('kyc_submitted_at')->nullable()->after('kyc_feedback');
            $table->timestamp('kyc_reviewed_at')->nullable()->after('kyc_submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'kyc_status',
                'id_document_path',
                'business_permit_path',
                'driver_license_path',
                'or_cr_path',
                'kyc_feedback',
                'kyc_submitted_at',
                'kyc_reviewed_at',
            ]);
        });
    }
};
```

#### Migration 2: Create Courier Profiles Table
**File:** `database/migrations/2026_08_27_000002_create_courier_profiles_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('vehicle_type')->default('Motorcycle');
            $table->string('plate_number')->nullable();
            $table->string('license_number')->nullable();
            $table->string('or_cr_status')->default('valid');
            $table->boolean('is_available')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_profiles');
    }
};
```

#### Migration 3: Add Variant Fields to Cart Items & Order Items
**File:** `database/migrations/2026_08_27_000003_add_variant_fields_to_cart_items_and_order_items_tables.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->string('color')->nullable()->after('unit_price');
            $table->string('size')->nullable()->after('color');
            $table->string('sku_snapshot')->nullable()->after('size');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('color')->nullable()->after('subtotal');
            $table->string('size')->nullable()->after('color');
            $table->string('sku_snapshot')->nullable()->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn(['color', 'size', 'sku_snapshot']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['color', 'size', 'sku_snapshot']);
        });
    }
};
```

---

### 4.2 Enums & Models Blueprint

#### Enum: `app/Enums/KycStatus.php`
```php
<?php

namespace App\Enums;

enum KycStatus: string
{
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match($this) {
            self::PENDING_APPROVAL => 'Pending Approval',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
        };
    }

    public function badgeClass(): string
    {
        return match($this) {
            self::PENDING_APPROVAL => 'bg-amber-50 text-amber-800 border-amber-200',
            self::APPROVED => 'bg-emerald-50 text-emerald-800 border-emerald-200',
            self::REJECTED => 'bg-rose-50 text-rose-800 border-rose-200',
        };
    }
}
```

#### Model: `app/Models/CourierProfile.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'vehicle_type',
        'plate_number',
        'license_number',
        'or_cr_status',
        'is_available',
    ];

    protected $casts = [
        'is_available' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

#### Updates to `app/Models/User.php`
1. Add to `$fillable`:
   ```php
   'kyc_status',
   'id_document_path',
   'business_permit_path',
   'driver_license_path',
   'or_cr_path',
   'kyc_feedback',
   'kyc_submitted_at',
   'kyc_reviewed_at',
   ```
2. Add to `casts()`:
   ```php
   'kyc_submitted_at' => 'datetime',
   'kyc_reviewed_at' => 'datetime',
   ```
3. Add relationships & helper methods:
   ```php
   public function courierProfile(): HasOne
   {
       return $this->hasOne(CourierProfile::class);
   }

   public function isKycApproved(): bool
   {
       return $this->isAdmin() || $this->kyc_status === 'approved';
   }

   public function isKycPending(): bool
   {
       return $this->kyc_status === 'pending_approval';
   }

   public function isKycRejected(): bool
   {
       return $this->kyc_status === 'rejected';
   }
   ```

#### Updates to `app/Models/CartItem.php`
Add to `$fillable`:
```php
'color',
'size',
'sku_snapshot',
```

#### Updates to `app/Models/OrderItem.php`
Add to `$fillable`:
```php
'color',
'size',
'sku_snapshot',
```

---

### 4.3 Controller Code Corrections Blueprint

#### Correction 1: `app/Http/Controllers/Buyer/CheckoutController.php` (Line 165)
- **Before:**
  ```php
  'recipient_phone' => $validated['recipient_phone'],
  ```
- **After:**
  ```php
  'delivery_phone' => $validated['recipient_phone'],
  ```
- **Order Item Variants Transfer (Lines 137–144):**
  ```php
  OrderItem::create([
      'order_id' => $order->id,
      'product_id' => $item->product_id,
      'shop_id' => $product->shop_id,
      'quantity' => $item->quantity,
      'unit_price' => $product->price,
      'subtotal' => $item->quantity * $product->price,
      'color' => $item->color,
      'size' => $item->size,
      'sku_snapshot' => $item->sku_snapshot,
  ]);
  ```

#### Correction 2: `app/Http/Controllers/Seller/SellerOrderController.php` (Line 100)
- **Before:**
  ```php
  'recipient_phone' => $order->recipient_phone ?? $order->buyer?->phone ?? '+63 900 000 0000',
  ```
- **After:**
  ```php
  'delivery_phone' => $order->recipient_phone ?? $order->buyer?->phone ?? '+63 900 000 0000',
  ```

#### Correction 3: `app/Http/Controllers/Buyer/CartController.php` (Lines 63–79)
- **Update store method:**
  ```php
  $color = $request->input('color');
  $size = $request->input('size');

  $cart = $this->getCart($request);
  $item = $cart->items()
      ->where('product_id', $product->id)
      ->where('color', $color)
      ->where('size', $size)
      ->first();

  if ($item) {
      $newQuantity = $item->quantity + $quantity;
      if ($product->stock < $newQuantity) {
          return back()->with('error', "Cannot add more. Stock limit of {$product->stock} reached.");
      }
      $item->quantity = $newQuantity;
      $item->unit_price = $product->price;
      $item->save();
  } else {
      $skuSnapshot = $product->sku . ($color ? "-{$color}" : '') . ($size ? "-{$size}" : '');
      $cart->items()->create([
          'product_id' => $product->id,
          'quantity' => $quantity,
          'unit_price' => $product->price,
          'color' => $color,
          'size' => $size,
          'sku_snapshot' => $skuSnapshot,
      ]);
  }
  ```

---

### 4.4 Database Seeder Updates Blueprint
**File:** `database/seeders/DatabaseSeeder.php`

1. **Seed All 5 Roles with Approved Status:**
   ```php
   $admin = User::updateOrCreate(
       ['email' => 'admin@bagoo.test'],
       [
           'name' => 'Bagoo Super Admin',
           'password' => Hash::make('password'),
           'role' => 'admin',
           'phone' => '+63 917 000 0001',
           'address' => '100 Bagoo HQ Way, Floor 12',
           'city' => 'Taguig, Metro Manila',
           'postal_code' => '1634',
           'status' => 'active',
           'kyc_status' => 'approved',
           'kyc_reviewed_at' => now(),
       ]
   );

   $sellerUser = User::updateOrCreate(
       ['email' => 'seller@bagoo.test'],
       [
           'name' => 'Sarah Merchant',
           'password' => Hash::make('password'),
           'role' => 'seller',
           'phone' => '+63 917 000 0002',
           'address' => '456 Artisan District, Suite B',
           'city' => 'Makati, Metro Manila',
           'postal_code' => '1226',
           'status' => 'active',
           'kyc_status' => 'approved',
           'business_permit_path' => 'kyc/sample_permit.pdf',
           'id_document_path' => 'kyc/sample_seller_id.jpg',
           'kyc_submitted_at' => now()->subDays(5),
           'kyc_reviewed_at' => now()->subDays(4),
       ]
   );

   $buyer = User::updateOrCreate(
       ['email' => 'buyer@bagoo.test'],
       [
           'name' => 'Alex Customer',
           'password' => Hash::make('password'),
           'role' => 'buyer',
           'phone' => '+63 917 000 0003',
           'address' => '789 Sunset Blvd, Apt 4C',
           'city' => 'Quezon City, Metro Manila',
           'postal_code' => '1105',
           'status' => 'active',
           'kyc_status' => 'approved',
           'id_document_path' => 'kyc/sample_buyer_id.jpg',
           'kyc_submitted_at' => now()->subDays(10),
           'kyc_reviewed_at' => now()->subDays(9),
       ]
   );

   $courier = User::updateOrCreate(
       ['email' => 'courier@bagoo.test'],
       [
           'name' => 'Dave Speed Courier',
           'password' => Hash::make('password'),
           'role' => 'courier',
           'phone' => '+63 917 000 0004',
           'address' => '12 Dispatcher Ave',
           'city' => 'Pasig, Metro Manila',
           'postal_code' => '1600',
           'status' => 'active',
           'kyc_status' => 'approved',
           'id_document_path' => 'kyc/sample_courier_id.jpg',
           'driver_license_path' => 'kyc/sample_license.jpg',
           'or_cr_path' => 'kyc/sample_orcr.pdf',
           'kyc_submitted_at' => now()->subDays(3),
           'kyc_reviewed_at' => now()->subDays(2),
       ]
   );

   $logistics = User::updateOrCreate(
       ['email' => 'logistics@bagoo.test'],
       [
           'name' => 'Hub Logistics Dispatcher',
           'password' => Hash::make('password'),
           'role' => 'logistics',
           'phone' => '+63 917 000 0005',
           'address' => 'Central Sorting Hub Gate 3',
           'city' => 'Paranaque, Metro Manila',
           'postal_code' => '1700',
           'status' => 'active',
           'kyc_status' => 'approved',
           'kyc_reviewed_at' => now(),
       ]
   );
   ```

2. **Seed Courier Profile for Dave Courier:**
   ```php
   \App\Models\CourierProfile::updateOrCreate(
       ['user_id' => $courier->id],
       [
           'vehicle_type' => 'Motorcycle (Express Dispatch)',
           'plate_number' => 'NCS-8892',
           'license_number' => 'N02-18-092831',
           'or_cr_status' => 'Valid & Registered',
           'is_available' => true,
       ]
   );
   ```

3. **Seed Demo Pending & Rejected KYC Users for Admin Queue Testing:**
   ```php
   User::updateOrCreate(
       ['email' => 'pending.seller@bagoo.test'],
       [
           'name' => 'Maria New Merchant',
           'password' => Hash::make('password'),
           'role' => 'seller',
           'phone' => '+63 917 999 0001',
           'address' => '88 Greenhills Mall',
           'city' => 'San Juan, Metro Manila',
           'postal_code' => '1500',
           'status' => 'pending_approval',
           'kyc_status' => 'pending_approval',
           'id_document_path' => 'kyc/pending_id.jpg',
           'business_permit_path' => 'kyc/pending_permit.pdf',
           'kyc_submitted_at' => now()->subHours(2),
       ]
   );

   User::updateOrCreate(
       ['email' => 'pending.courier@bagoo.test'],
       [
           'name' => 'Juan Rider Applicant',
           'password' => Hash::make('password'),
           'role' => 'courier',
           'phone' => '+63 917 999 0002',
           'address' => '42 Bonifacio St',
           'city' => 'Mandaluyong, Metro Manila',
           'postal_code' => '1550',
           'status' => 'pending_approval',
           'kyc_status' => 'pending_approval',
           'id_document_path' => 'kyc/pending_rider_id.jpg',
           'driver_license_path' => 'kyc/pending_rider_license.jpg',
           'or_cr_path' => 'kyc/pending_rider_orcr.pdf',
           'kyc_submitted_at' => now()->subHours(5),
       ]
   );

   User::updateOrCreate(
       ['email' => 'rejected.seller@bagoo.test'],
       [
           'name' => 'Rejected Store Applicant',
           'password' => Hash::make('password'),
           'role' => 'seller',
           'phone' => '+63 917 999 0003',
           'address' => '10 Dimasalang Rd',
           'city' => 'Manila',
           'postal_code' => '1008',
           'status' => 'pending_approval',
           'kyc_status' => 'rejected',
           'id_document_path' => 'kyc/rejected_id.jpg',
           'business_permit_path' => 'kyc/rejected_permit.pdf',
           'kyc_feedback' => 'Business permit expired on 2025-12-31. Please upload an active 2026 Mayor\'s Permit or DTI registration certificate.',
           'kyc_submitted_at' => now()->subDays(2),
           'kyc_reviewed_at' => now()->subDay(),
       ]
   );
   ```

---

### 4.5 TypeScript Definitions Blueprint
**File:** `resources/js/types/index.d.ts`

Add:
```ts
export type KycStatus = 'pending_approval' | 'approved' | 'rejected';

export interface CourierProfile {
    id: number;
    user_id: number;
    vehicle_type: string;
    plate_number?: string | null;
    license_number?: string | null;
    or_cr_status?: string | null;
    is_available: boolean;
    created_at?: string;
    updated_at?: string;
}
```

Update `User`:
```ts
export interface User {
    id: number;
    name: string;
    email: string;
    role: Role;
    phone?: string | null;
    avatar?: string | null;
    address?: string | null;
    city?: string | null;
    postal_code?: string | null;
    status?: string;
    kyc_status?: KycStatus;
    id_document_path?: string | null;
    business_permit_path?: string | null;
    driver_license_path?: string | null;
    or_cr_path?: string | null;
    kyc_feedback?: string | null;
    kyc_submitted_at?: string | null;
    kyc_reviewed_at?: string | null;
    courier_profile?: CourierProfile | null;
    email_verified_at?: string;
    shop?: Shop | null;
}
```

Update `CartItem` and `OrderItem`:
```ts
export interface CartItem {
    id: number;
    cart_id: number;
    product_id: number;
    quantity: number;
    unit_price: string | number;
    color?: string | null;
    size?: string | null;
    sku_snapshot?: string | null;
    product: Product;
}

export interface OrderItem {
    id: number;
    order_id: number;
    product_id: number;
    shop_id: number;
    quantity: number;
    unit_price: string | number;
    subtotal: string | number;
    color?: string | null;
    size?: string | null;
    sku_snapshot?: string | null;
    product: Product;
    shop?: Shop;
    order?: Order;
}
```

---

## 5. Verification Method

To independently verify these findings and migration plans:

1. **Verify Migrations & Model Schemas:**
   - Execute: `php artisan migrate:status` to verify migration order.
   - Run: `php artisan migrate:fresh --seed` to confirm seamless SQLite / PostgreSQL execution without syntax errors.
   - Run: `php artisan tinker` and test:
     ```php
     $user = User::where('email', 'courier@bagoo.test')->with('courierProfile')->first();
     $user->courierProfile->vehicle_type; // 'Motorcycle (Express Dispatch)'
     $user->isKycApproved(); // true
     ```

2. **Verify Variant Fields Persistence:**
   - Add item with color and size via `CartController::store` and check `CartItem::first()->color`.
   - Complete checkout via `CheckoutController::store` and verify `OrderItem::first()->color` and `OrderItem::first()->sku_snapshot` match.

3. **Verify `delivery_phone` Field Consistency:**
   - Inspect created `deliveries` row after checkout: verify `Delivery::latest()->first()->delivery_phone` is populated (not null).
   - In `resources/js/Pages/Courier/Deliveries.tsx`, verify phone number renders cleanly without error.

4. **Verify KYC Queue Accounts:**
   - Run: `php artisan tinker` and verify:
     ```php
     User::where('kyc_status', 'pending_approval')->count(); // >= 2 (pending.seller, pending.courier)
     User::where('kyc_status', 'rejected')->count(); // >= 1 (rejected.seller)
     ```
