# Event Campaign & Flash Deal Specification

> **Status:** Production Ready & Documented  
> **Target Branch:** `feat/category-taxonomy-and-top-products`  
> **Audience:** Core Engineers, Product Leads, Finance & Settlement Auditors, Logistics Operations  
> **Last Updated:** November 2026

---

## 1. Executive Summary

Bagoo's **Event Campaign & Promotion Engine** powers high-conversion buyer shopping events (such as **11.11 Mega Sale**, **12.12 Christmas Gala**, **Halloween Spooky Spree**, and **Flash Deals**) without destabilizing database throughput or distorting platform settlement books.

The architecture emphasizes **four fundamental pillars**:
1. **Purity of Core Financials**: Clear separation of seller margins, platform commission, platform promotional subsidies, and guaranteed logistics courier fees.
2. **Anti-Stress Scalability**: Lean concurrency management using atomic MySQL decrement operations and tagged caching—completely eliminating the need for complex, heavy WebSocket or Redis queue overhead at our current scale.
3. **Loophole Prevention**: Safeguards against fake discounts, decoy return voucher gaming, fat-finger pricing accidents, and unrealistic logistics SLAs during peak demand.
4. **Clean White-Mode User Experience**: High-converting, brand-aligned visual design following Bagoo's Apple-grade minimalism (`bg-[#F5F5F7]`, crisp white cards `#FFFFFF`, and Bagoo crimson `#E00D42`).

---

## 2. Event Campaign Taxonomy & Curation

### 2.1 Supported Event Types

| Event Type | Identifier / Slug Pattern | Description & Curation Logic |
| :--- | :--- | :--- |
| **Mega Double-Digit Sales** | `11-11-mega-sale`, `12-12-christmas-gala` | Site-wide cross-category events featuring massive platform voucher drops, deep seller price cuts, and high-visibility hero carousels. |
| **Seasonal Campaigns** | `halloween-spooky-spree`, `summer-kickoff` | Thematic curation filtering products by seasonal relevance, targeted categories, and exclusive voucher codes. |
| **Flash Deals (Timed Drops)** | `flash-deals` | High-urgency, short-window price drops restricted by strict unit quotas (`quantity` vs `used_quantity`) and countdown clocks. |
| **Top Products / Spotlight** | Integrated in `/campaign/{slug}` & Home | High-converting inventory ranked using sales velocity, buyer rating (≥ 4.5), and real stock depth. |

### 2.2 Voucher Bunches & Interactive Wallet Claiming

Each campaign is accompanied by a curated **Voucher Bunch** displayed in an interactive claim grid:
- **Platform Percentage Vouchers**: (e.g., `1111MEGA` – 11% off min spend ₱999, capped at ₱150).
- **Free Shipping Subsidies**: (e.g., `1111FREESHIP` – ₱75 shipping fee waived, min spend ₱499).
- **Category Specials**: (e.g., `1111FASHION` – 15% off apparel and beauty).

**Claim Mechanics**:
- Authenticated buyers claim vouchers directly into their wallet via `POST /api/vouchers/claim` (persisting to `saved_vouchers`).
- Unauthenticated visitors are prompted with the authentication modal; upon logging in, vouchers are preserved.
- Claimed vouchers automatically surface in the checkout discount picker.

---

## 3. The 4-Way Financial Settlement Ledger & Money Math

The cornerstone of Bagoo’s campaign system is zero-deficit settlement auditing: **promotional campaigns must never erode platform solvency or shortchange couriers**.

### 3.1 Who Subsidizes What?

```
┌────────────────────────────────────────────────────────────────────────┐
│                              ORDER TOTAL                               │
└────────────────────────────────────┬───────────────────────────────────┘
                                     │
         ┌───────────────────────────┴───────────────────────────┐
         ▼                                                       ▼
┌──────────────────┐                                   ┌──────────────────┐
│   ITEM SUBTOT    │                                   │   SHIPPING FEE   │
└────────┬─────────┘                                   └────────┬─────────┘
         │                                                      │
 ┌───────┴───────┐                                              │
 ▼               ▼                                              ▼
Seller         Platform                                 Logistics Escrow
Margin        Commission                                100% Protected
(minus shop    (minus platform                          (Rider + Hub Payout
voucher)       event voucher)                           regardless of voucher)
```

1. **Seller Event Discount (`compare_at_price` vs `price`)**:
   - Funded **100% by the Seller**.
   - The seller chooses to lower their list price (e.g., from ₱1,000 to ₱800) to gain volume and spotlight placement.
2. **Platform Commission Split**:
   - Bagoo charges a standard **10% platform commission** calculated against the actual item transaction price before platform vouchers.
3. **Voucher Funding Sources**:
   - **Seller Shop Vouchers**: Subsidized from the seller's gross payout. Recorded in `voucher_funding_entries` with `funding_source = 'seller'`.
   - **Platform Event Vouchers**: Subsidized **100% from Bagoo platform commission/marketing budget**. Recorded with `funding_source = 'platform'`. Under no circumstances is a platform-sponsored voucher deducted from seller earnings.
4. **Guaranteed Courier & Logistics Settlement**:
   - Riders and sorting hubs receive **100% of their contracted delivery fees** (e.g., ₱75 base).
   - When a buyer applies a `FREE_SHIPPING` voucher, Bagoo platform funds the ₱75 shipping subsidy into the logistics escrow ledger. The rider’s wallet settlement is never discounted.

### 3.2 Concrete Financial Walk-Through

Consider a product with:
- Standard Retail Price (`compare_at_price`): **₱1,000.00**
- Seller Event Deal Price (`price`): **₱800.00**
- Platform Event Voucher applied: **₱100.00 off** (`platform_expense_cents = 10000`)
- Free Shipping Voucher applied: **₱75.00 off** (`platform_expense_cents = 7500`)
- Standard Shipping Fee: **₱75.00**

#### Buyer Ledger:
- Subtotal: ₱800.00
- Platform Voucher: -₱100.00
- Shipping Fee: ₱75.00
- Free Shipping Voucher: -₱75.00
- **Total Paid by Buyer**: **₱700.00**

#### Platform Settlement Ledger (`SellerSettlementService`):
- Transaction Basis: ₱800.00 (80,000 cents)
- Standard Commission (10%): ₱80.00 (8,000 cents)
- Seller Net Payout: ₱800.00 - ₱80.00 = **₱720.00** (72,000 cents)
- Platform Net Revenue: ₱80.00 commission - ₱100.00 voucher subsidy = **-₱20.00** (absorbed by Bagoo Marketing Acquisition Budget)
- Courier Payout: **₱75.00** (funded by Bagoo Logistics Subsidy)

#### Anti-Deficit Guarantee:
In `app/Services/Finance/SellerSettlementService.php`:
```php
// Promotional costs cannot produce a negative seller payout
$basis['net_seller_cents'] = max(0, $basis['seller_cents'] - $promotions['seller_promotion_cents']);
```
Sellers are guaranteed their deal price minus standard commission unless they explicitly configured a shop-specific voucher.

---

## 4. Scalable Flash Deal Engineering (Anti-System Stress)

High-demand events (like midnight drops) often overwhelm relational databases through connection exhaustion, row locks, and race conditions. Bagoo enforces high throughput with zero extra infrastructure overhead.

### 4.1 Native Flash Model (`ProductSale`)

Bagoo utilizes its existing `product_sales` table:
```
product_sales:
  - id: bigint unsigned
  - product_id: bigint unsigned
  - kind: enum('flash', 'store')
  - price: decimal(10,2)
  - quantity: int unsigned         (Total quota allocated)
  - used_quantity: int unsigned    (Units purchased or locked in checkout)
  - starts_at: timestamp
  - ends_at: timestamp
```

### 4.2 Atomic Concurrency Control (Zero Overselling)

To prevent overselling without holding heavy table locks or maintaining external Redis state engines:

```sql
-- Atomic quota lock during checkout initiation
UPDATE product_sales 
SET used_quantity = used_quantity + ? 
WHERE id = ? 
  AND (quantity - used_quantity) >= ? 
  AND starts_at <= NOW() 
  AND ends_at >= NOW();
```
- If rows affected == 1: Quota successfully secured.
- If rows affected == 0: Flash deal quota exhausted or expired. The item reverts to standard price or checkout fails gracefully with `"Flash deal sold out"`.

### 4.3 Cache Tagging for Campaign Landing Pages

Campaign product listings and voucher bunch metadata are cached using short-lived TTLs (60 seconds) with category tag invalidation:
```php
$campaignProducts = Cache::remember("campaign_{$slug}_products", 60, function () use ($campaign) {
    return Product::query()
        ->where('status', 'active')
        ->whereColumn('compare_at_price', '>', 'price')
        ->with(['seller:id,store_name,city,province', 'category:id,name,slug'])
        ->limit(48)
        ->get();
});
```
This serves 10,000+ concurrent page hits directly from application memory without touching MySQL read replicas.

### 4.4 Non-Binding Cart Re-Validation

Items sitting in a buyer's cart do **not** reserve stock. Price and flash validity are strictly re-verified at the exact millisecond of checkout submission:
1. Is the current timestamp within `starts_at` and `ends_at`?
2. Is `used_quantity < quantity`?
3. If valid: Lock quota atomically and proceed to payment escrow.
4. If invalid: Display clear banner: *"Flash deal expired. Price updated to standard seller price."*

---

## 5. Event Management Architecture (Admin & Seller)

### 5.1 Phase 1: Declarative Controller Registry (Current Implementation)

Implemented in `app/Http/Controllers/Buyer/BuyerCampaignController.php`:
- Declarative campaign definitions with SEO title, hero banner asset path, badges, and curated categories.
- Automatic querying of products with real `compare_at_price > price` discounts.
- Automatic hydration of active campaign vouchers via `Voucher::where('is_active', true)`.
- Fallback route handling with graceful redirect to home for unknown campaigns.

### 5.2 Phase 2: Administrative Control Portal (Roadmap)

1. **Admin Campaign Manager (`/admin/campaigns`)**:
   - Create and schedule campaign window (`starts_at`, `ends_at`).
   - Upload desktop & mobile responsive banners.
   - Attach platform-funded voucher bunches.
   - Define minimum discount criteria (e.g., minimum 10% below 30-day average).
2. **Seller Nomination Portal (`/seller/campaigns/nominate`)**:
   - Sellers browse upcoming platform campaigns.
   - Select eligible SKUs and input proposed campaign price.
   - Allocate specific stock quota (`quantity`) dedicated to the event.
   - One-click bulk submission.
3. **Automated Approval Rules**:
   - Auto-approve nominations meeting price floor criteria.
   - Flag anomalous discounts (> 80% off) for manual admin review.

---

## 6. Anti-Abuse & Loophole Prevention Matrix

| Risk / Attack Vector | Exploit Scenario | Bagoo System Defense |
| :--- | :--- | :--- |
| **Fake Discounts** | Seller marks up price from ₱500 to ₱1,000 right before 11.11, then lists at ₱600 claiming "40% OFF". | **30-Day Historical Baseline**: `compare_at_price` is strictly validated against the SKU’s lowest selling price over the preceding 30 days. |
| **Decoy Return Gaming** | Buyer purchases ₱800 item + ₱200 decoy item to meet ₱1,000 threshold for ₱150 voucher, then returns the ₱200 item. | **Pro-Rated Voucher Refunds**: Voucher discounts are apportioned proportionally across each order item. When returning an item, only its net paid amount is refunded: `Refund = Item_Price - (Item_Price / Subtotal * Voucher_Discount)`. |
| **Fat-Finger Pricing** | Seller accidentally enters ₱50 instead of ₱500 on a flash deal, losing huge sums. | **Discount Floor Safeguard**: Any nomination exceeding a 75% discount requires explicit secondary modal confirmation and a quota cap (maximum 5 units). |
| **Logistics Surge & Rider Penalties** | Mega-events double delivery volumes, causing riders and hubs to exceed normal 24-hour SLAs. | **Dynamic Campaign SLA Buffer**: When orders are placed during an active campaign period, automated delivery SLAs are extended by +24 hours to prevent unfair seller or rider penalties. |
| **Routing 404 Inconsistency** | Product cards linking to plural `/products/{slug}` instead of singular `/product/{slug}`. | **Canonical Route Enforcement**: All frontend cards and controller routes strictly reference the singular `route('product.show', $product->slug)`. |

---

## 7. Verification & Quality Standards

- **Theme Compliance**: Clean White Mode only (`bg-[#F5F5F7]`, `#FFFFFF` cards, `#111827` text, `#E00D42` crimson accents).
- **Automated Feature Tests**:
  - `tests/Feature/Buyer/BuyerCampaignEventTest.php` validates route accessibility, product card rendering, voucher listing, and 404 fallback handling.
  - `tests/Feature/Buyer/BuyerSubCategoryDiscoveryTest.php` validates category taxonomy navigation and spotlight algorithms.
- **Visual Regression**: Puppeteer end-to-end verified with zero layout shifts or missing image assets.
