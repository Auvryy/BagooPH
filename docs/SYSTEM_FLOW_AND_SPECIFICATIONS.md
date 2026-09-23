# System Flow & Specifications

> **Official Curriculum Reference Specification**
> This document records the customer-facing lifecycle and cross-role responsibilities. The physical custody, mother-hub, exception, self-pickup, notification, and COD rules are authoritative in `docs/SORTING_CENTER_LOGISTICS_FLOW.md`.

## Documentation Authority

When documents disagree, use this order:

1. This document for the canonical customer-facing order statuses.
2. `docs/CORE_FLOW_VALIDATION_AND_EDGE_CASES.md` for input, authorization, concurrency, idempotency, and recovery rules.
3. `docs/SORTING_CENTER_LOGISTICS_FLOW.md` for parcel custody and logistics operations.
4. The buyer, seller, courier, and admin documents for role-specific screens and actions.
5. `docs/CORE_FLOW_ROADMAP.md` for current gaps and approved implementation order.
6. `docs/MASTER_LOGISTICS_SPECIFICATION.md` for architecture and future scope only.

Internal delivery statuses and checkpoints may be more detailed than the 13 customer-facing statuses, but they must map back to this lifecycle.

## Core Transaction Contract

The complete baseline transaction is:

```text
Buyer checkout
-> Seller fulfillment
-> Pickup Rider
-> Origin Bayan Hub
-> at least one Mother Hub
-> Destination Bayan Hub
-> Delivery Rider or Self-Pickup Counter
-> Buyer confirmation
-> COD reconciliation
-> Seller settlement
```

The following rules apply across every portal:

- A multi-shop Shopping Bag creates one independent order, parcel, waybill, logistics route, and shipping fee per shop.
- A shop voucher affects only its matching shop order. A platform voucher is divided proportionally among the generated orders without exceeding its calculated checkout discount.
- Pickup and delivery riders are assignment phases of the same approved `courier` account, not separate account roles.
- Every parcel passes through at least one Regional Mother Hub. Direct seller-to-buyer and Bayan-Hub-to-Bayan-Hub transport are prohibited.
- Only the buyer may advance `DELIVERED` to `COMPLETED`.
- Parcel status and COD custody are separate. Delivery does not prove platform remittance.
- Seller settlement requires both `COMPLETED` and platform-level COD reconciliation.
- The 90% seller and 10% platform split applies only to product subtotal. Shipping, handling, logistics revenue, and rider earnings remain separate.

### Status Ownership

| Transition | Authorized actor | Required evidence or condition |
|---|---|---|
| Checkout -> `PLACED` | Buyer through validated checkout | Stock, price, voucher, address, delivery type, and route validated atomically |
| `PLACED -> CONFIRMED` | Seller | Seller owns the complete shop order |
| `CONFIRMED -> PREPARING` | Seller | Accepted order |
| `PREPARING -> READY_FOR_PICKUP` | Seller | Packed parcel and attached waybill |
| `READY_FOR_PICKUP -> PICKED_UP` | Assigned pickup rider | Authenticated seller handoff scan |
| `PICKED_UP -> AT_SORTING_CENTER` | Origin Bayan Hub Handler | Expected-hub inbound scan |
| `AT_SORTING_CENTER -> SORTED` | Destination Bayan Hub Handler | Required Mother-Hub checkpoints and destination-bin scan complete |
| `SORTED -> ASSIGNED_TO_RIDER` | Destination Bayan Hub Handler or scoped Logistics Company Admin | One eligible final-mile rider assigned |
| `ASSIGNED_TO_RIDER -> OUT_FOR_DELIVERY` | Assigned delivery rider | Destination-hub outbound scan |
| `OUT_FOR_DELIVERY -> DELIVERED` | Assigned delivery rider | Handover proof and COD collection when applicable |
| Self-pickup ready -> `DELIVERED` | Destination Bayan Hub Counter Handler | Valid one-time code, identity, destination hub, ready state, and COD collection |
| `OUT_FOR_DELIVERY -> DELIVERY_FAILED` | Assigned delivery rider | Reason, notes, attempt number, and proof |
| `DELIVERY_FAILED -> SORTED` | Destination Bayan Hub Handler | Failed parcel returned to hub and retry approved |
| RTS in progress -> `RETURNED` | Seller through authenticated receipt | Reverse route complete and parcel handed back |
| `DELIVERED -> COMPLETED` | Buyer | Buyer confirms receipt |

Seller cancellation is allowed only before pickup and before any rider has claimed parcel custody. Cancellation is a terminal commercial outcome and cannot replace a delivery or return scan.

### Custody Handoffs

| Current custodian | Handoff evidence | Next custodian |
|---|---|---|
| Seller | Pickup rider scans the waybill at the seller | Pickup Rider |
| Pickup Rider | Assigned Origin Bayan Hub scans inbound | Origin Bayan Hub |
| Origin Bayan Hub | Parcel scanned onto a feeder manifest | Feeder manifest and vehicle |
| Feeder manifest and vehicle | Mother Hub receives manifest and parcel | Origin Mother Hub |
| Origin Mother Hub | Line-haul manifest when regions differ, otherwise destination feeder manifest | Destination Mother Hub or destination feeder manifest |
| Destination Mother Hub or feeder manifest | Destination Bayan Hub receives parcel | Destination Bayan Hub |
| Destination Bayan Hub | Assigned rider scans outbound | Delivery Rider |
| Delivery Rider | Recipient handoff, proof, and COD record | Buyer |
| Destination Bayan Hub Counter | One-time claim verification, identity, and COD record | Buyer |
| Reverse network | Seller return-receipt scan | Seller |

Platform Admin governs marketplace approval, policy, financial audit, and traceable corrections. Logistics Company Admin manages only its own facilities, personnel, manifests, exceptions, and operational remittance. Hub Handlers perform scans only at facilities assigned to them.

Maritime and air freight, live GPS, AI routing, automated warehouses, advanced analytics, complete dispute/refund/exchange processing, and external notification services are outside the core baseline.

All inputs, actor permissions, transitions, duplicate requests, concurrent operations, and recovery paths must satisfy `docs/CORE_FLOW_VALIDATION_AND_EDGE_CASES.md`.

---

## 1. Sorting Center / Logistics

### Operational Process Flow:
```
Receive Parcel
↓
Scan Parcel
↓
Read Delivery Address
↓
Determine Delivery Area
↓
Sort Parcel According to Destination Area
↓
Identify Rider Assigned to That Area
↓
Assign Parcel to Rider
↓
Rider Receives Delivery Assignment
```

### Destination Area Routing Table (Example):
| PARCEL | DELIVERY ADDRESS | AREA | ASSIGNED RIDER |
| :--- | :--- | :--- | :--- |
| **#1001** | Santa Cruz, Laguna | Area A | Rider 01 |
| **#1002** | Pagsanjan, Laguna | Area B | Rider 02 |
| **#1003** | Los Baños, Laguna | Area C | Rider 03 |

### 2 Main Responsibilities of the Sorting Center / Logistics:
1. **Sort the parcel according to destination**
2. **Assign the parcel to the appropriate rider based on the rider's assigned area**

The authoritative scan, waybill, custody, rider-notification, hub-transfer, self-pickup, and failure sequence is defined in `docs/SORTING_CENTER_LOGISTICS_FLOW.md`.

---

## 2. Buyer / Customer

### Customer Ordering Flow:
```
Buyer Registration
↓
Admin Approval
↓
Login
↓
Browse Products
↓
View Product Details
↓
Add to Cart
↓
Checkout
↓
Enter/Confirm Delivery Address
↓
Select Payment Method
↓
Place Order
↓
Wait for Seller to Prepare Order
```

### Post-Preparation Logistics Chain & Handover:
```
Seller → Pickup Rider → Origin Bayan Hub → Mother Hub → Destination Bayan Hub → Delivery Rider → Buyer
↓
Receive Product
↓
Confirm Order Received
↓
Transaction Completed
```

---

## 3. Seller (Receive, Pack & Prepare Order for Pickup)

### Seller Order Fulfillment Flow:
```
BUYER PLACES ORDER
↓
SELLER RECEIVES ORDER NOTICE
↓
VIEW ORDER DETAILS
↓
CHECK PRODUCT/STOCK
↓
ACCEPT / CONFIRM ORDER
↓
PREPARE ORDER
↓
PACK PRODUCT
↓
PRINT/ATTACH SHIPPING LABEL
↓
MARK AS READY FOR PICKUP
↓
WAIT FOR PICKUP JOB CLAIM
↓
RIDER ARRIVES
↓
HAND OVER PARCEL
↓
RIDER SCANS WAYBILL AND CONFIRMS PICKUP
↓
STATUS: PICKED UP
```

---

## 4. Rider / Courier (Pickup vs Delivery)

### Rider/Courier (Pickup):
```
Rider Logs In
↓
View Pickup Assignments
↓
Accept Pickup
↓
Go to Seller
↓
Pick Up Parcel
↓
Scan/Confirm Parcel
↓
Deliver Parcel to Sorting Center
```
*The Rider is responsible for collecting the parcel from the seller and bringing it to the sorting center.*

---

### Rider/Courier (Delivery):
*After the sorting center assigns the parcel:*
```
Rider Receives Assignment
↓
View Delivery Address
↓
Pick Up Parcel from Sorting Center
↓
Mark as Out for Delivery
↓
Travel to Customer
↓
Deliver Parcel
↓
Customer Receives Parcel
```

#### Delivery Outcome Resolution:
```
Successful Delivery?

[ YES ]
↓
DELIVERED
↓
Buyer Confirms Receipt
↓
COMPLETED

[ NO ]
↓
DELIVERY FAILED
↓
Reason Recorded
↓
Reschedule Delivery / Return Parcel
```

---

## 5. Order Status Definitions & Progression

### Canonical 13 Statuses:
| Status | Meaning |
| :--- | :--- |
| **PLACED** | Buyer successfully placed the order |
| **CONFIRMED** | Seller accepted the order |
| **PREPARING** | Seller is preparing the product |
| **READY_FOR_PICKUP** | Parcel is ready for rider pickup |
| **PICKED_UP** | Rider collected the parcel |
| **AT_SORTING_CENTER** | Parcel arrived at logistics/sorting center |
| **SORTED** | Parcel has been sorted by destination |
| **ASSIGNED_TO_RIDER** | Delivery rider has been assigned |
| **OUT_FOR_DELIVERY** | Rider is delivering the parcel |
| **DELIVERED** | Parcel successfully delivered |
| **COMPLETED** | Buyer confirmed receipt |
| **DELIVERY_FAILED** | Delivery attempt failed |
| **RETURNED** | Parcel returned to seller |

### Process Summary:
```
Buyer orders → Seller prepares → Pickup Rider collects → Origin Bayan Hub receives → Mother Hub sorts → Destination Bayan Hub receives and sorts → Hub assigns Delivery Rider → Rider delivers → Buyer confirms → Order completed.
```

`AT_SORTING_CENTER` covers the internal Bayan-Hub and Mother-Hub transfer checkpoints shown to the buyer through the tracking timeline. The system must not add internal transport statuses to the 13 customer-facing order statuses.

---

## 6. Master Product Categories (14 Categories)

As preserved from curriculum specifications (see `docs/CATEGORIES.md`):

| # | Master Category | Subcategories |
|---|---|---|
| **1** | **Pet Supplies** | Dog Food & Treats, Cat Litter & Accessories, Aquariums & Fish Supplies, Bird Feeders & Food, Pet Grooming Products, Pet Health & Wellness |
| **2** | **Electronics and Gadgets** | Mobile Phones & Accessories, Laptops, Desktops & Monitors, Audio & Video Equipment, Smart Home Devices, Cameras & Photography, Wearable Technology |
| **3** | **Women's Apparel** | Dresses & Skirts, Tops & Blouses, Activewear & Yoga Pants, Lingerie & Sleepwear, Jackets & Coats, Shoes & Accessories |
| **4** | **Men's Apparel** | Suits & Blazers, Casual Shirts & Pants, Outerwear & Jackets, Activewear & Fitness Gear, Shoes & Accessories, Grooming Products |
| **5** | **Kids and Baby** | Baby Clothes & Accessories, Toys & Games, Educational Materials, Strollers & Gear, Nursery Furniture, Safety and Health |
| **6** | **Home and Garden** | Kitchen Appliances, Furniture & Decor, Gardening Tools, Outdoor Living, Home Improvement Tools, Bedding & Bath |
| **7** | **Sports and Outdoors** | Fitness Equipment, Camping & Hiking Gear, Sports Apparel, Cycling & Bikes, Water Sports, Team Sports Equipment |
| **8** | **Health and Beauty** | Skincare Products, Haircare Solutions, Makeup & Cosmetics, Personal Care Appliances, Men's Grooming, Health Supplements |
| **9** | **Books and Media** | Fiction & Non-Fiction Books, Magazines & Periodicals, Music CDs & Vinyl Records, Movie DVDs & Blu-ray, Video Games & Consoles, Educational DVDs |
| **10** | **Food and Gourmet** | Baking Supplies & Ingredients, Coffee, Tea & Beverages, Snacks & Candy, Specialty Foods & International Cuisine, Organic and Health Foods, Meal Kits & Prepped Foods |
| **11** | **Automotive & Motorcycle** | Protective Gear, Maintenance & Repair Tools, Parts & Accessories, Electrical Components, Tires, Wheels, and Fluids |
| **12** | **Furniture and Office Equipment** | Office Desks & Chairs, Storage Cabinets & Shelving, Conference & Meeting Furniture, Computer Tables & Workstations, Ergonomic Accessories, Office Lighting & Fixtures |
| **13** | **Jewelry and Watches** | Necklaces & Pendants, Rings & Earrings, Bracelets & Bangles, Watches for Men & Women, Fashion Jewelry, Jewelry Storage & Care |
| **14** | **Office and School Supplies** | Notebooks & Paper Products, Writing Instruments, Office Furniture, Printers & Printing Supplies, School Bags & Backpacks, Arts & Craft Materials |
