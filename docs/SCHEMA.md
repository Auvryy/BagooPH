# Database Schema

This document defines the database tables and columns for the platform.

---

## Core Tables

### 1. `users`
* `id` (PK)
* `first_name`, `last_name`, `middle_initial`
* `name` (full name)
* `sex` (Male / Female / Other)
* `email` (Unique)
* `phone`
* `birthday` (Date), `age` (Integer)
* `province`, `municipality`, `barangay`, `address` (Street / House No)
* `id_document_path` (Uploaded ID)
* `role` (`buyer`, `seller`, `courier`, `admin`)
* `status` (`pending_approval`, `active`, `suspended`, `deactivated`)
* `password`, `timestamps`

### 2. `shops` (Seller Profile)
* `id` (PK)
* `user_id` (FK -> users)
* `name` (Business name)
* `category_id` (FK -> categories, Line of business)
* `business_permit_path` (Uploaded permit)
* `description`, `logo`, `banner`
* `phone`, `address` (Pickup location)
* `rating`, `status`, `timestamps`

### 3. `courier_profiles` (Courier Vehicle Info)
* `id` (PK)
* `user_id` (FK -> users)
* `vehicle_type` (Motorcycle, Van, Bicycle, Truck)
* `plate_number`
* `or_cr_path` (Uploaded OR/CR document)
* `license_path` (Uploaded Driver's License)
* `is_available` (Boolean), `timestamps`

### 4. `categories` (14 Categories)
* `id` (PK), `parent_id` (FK nullable for subcategories)
* `name`, `slug`, `icon`, `image`, `is_active`, `timestamps`

### 5. `products` & `product_variations`
* `id` (PK)
* `shop_id` (FK -> shops)
* `category_id` (FK -> categories)
* `name`, `slug`, `description`
* `price`, `compare_at_price`, `stock`, `sku`
* `variations` (JSON or table for color/size options)
* `featured_image`, `status` (`active`, `draft`, `archived`), `rating`, `sales_count`, `timestamps`

### 6. `carts` & `cart_items`
* `cart_items`: `id`, `cart_id`, `product_id`, `variation_selected`, `quantity`, `unit_price`, `timestamps`

### 7. `orders` & `order_items`
* `orders`: `id`, `order_number`, `buyer_id`, `subtotal`, `voucher_discount`, `shipping_fee`, `total_amount`, `payment_method` (COD, Card, Bank Transfer, E-Wallet), `payment_status`, `status` (`pending`, `packaging`, `ready_for_pickup`, `shipped`, `delivered`, `cancelled`), recipient address fields, `notes`, `timestamps`
* `order_items`: `id`, `order_id`, `product_id`, `shop_id`, `variation_info`, `quantity`, `unit_price`, `subtotal`, `timestamps`

### 8. `deliveries` (Courier Dispatch)
* `id` (PK)
* `order_id` (FK -> orders)
* `courier_id` (FK -> users, nullable until claimed)
* `tracking_number` (Unique)
* `status` (`unassigned`, `assigned`, `picked_up`, `in_transit`, `out_for_delivery`, `delivered`, `failed`)
* `pickup_address`, `pickup_phone`, `delivery_address`, `delivery_phone`
* `proof_image`, `courier_notes`, `picked_up_at`, `delivered_at`, `timestamps`

### 9. `commissions` (10% Platform Commission)
* `id` (PK)
* `order_id` (FK -> orders)
* `shop_id` (FK -> shops)
* `subtotal_amount`, `commission_rate` (10%), `commission_fee`, `seller_net_amount`, `timestamps`

### 10. `vouchers` (Discounts)
* `id` (PK), `shop_id` (nullable for platform-wide), `code`, `discount_type`, `discount_value`, `min_spend`, `valid_from`, `valid_to`, `timestamps`

### 11. `disputes` & `messages`
* `disputes`: `id`, `order_id`, `complaint_by`, `against_user`, `reason`, `evidence_image`, `status` (`open`, `investigating`, `resolved`), `resolution_notes`, `timestamps`
* `messages`: `id`, `sender_id`, `receiver_id`, `order_id` (optional), `message`, `is_read`, `timestamps`
