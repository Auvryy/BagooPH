# System Flow & Specifications

> **Official Curriculum Reference Specification**
> This document records the exact system flows, role responsibilities, order state machines, and routing rules as mandated by the updated curriculum document, retaining the 14 master product categories.

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
Seller → Rider Pickup → Sorting Center → Assigned Rider → Buyer
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
WAIT FOR RIDER ASSIGNMENT
↓
RIDER ARRIVES
↓
HAND OVER PARCEL
↓
CONFIRM RIDER PICKUP
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
Buyer orders → Seller prepares → Rider picks up → Sorting Center sorts → Sorting Center assigns Rider → Rider delivers → Buyer confirms → Order completed.
```

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
