# System Flow & Role Specifications

---

## 1. System Roles & Functional Specifications

### Buyer:
- **Registration**
  - Last name*
  - First name*
  - Middle initial
  - Sex*
  - E-mail*
  - Contact No.*
  - Birthday*
  - Age (autogen)*
  - Address (API) (Dropdown: Province, Municipality, Barangay) (Manual entry: Street, House number, etc.)
  - Upload ID
  - *After submitting your registration, please wait for the administrator's approval, which will be sent to your email.*
- **Login**
- **Main Menu**
  - Categories
  - Search (search bar) (view product details; choose item, select quantity, choose variations (color, size, etc.) add to cart
  - View cart (select order, finalize order details, apply vouchers and discounts; choose mode of payment; place order)
  - View orders' status (to ship, in transit, out for delivery, rate/feedback, etc)
  - Chat/Messaging
  - Account Management
  - Logout

---

### Seller:
- **Registration**
  - Last name*
  - First name*
  - Middle initial
  - Sex*
  - E-mail*
  - Contact No.*
  - Birthday*
  - Age (autogen)*
  - Address (API) (Dropdown: Province, Municipality, Barangay) (Manual entry: Street, House number, etc.)
  - Business name
  - Line of business (category)
  - Upload ID
  - Upload business permit
  - *After submitting your registration, please wait for the administrator's approval, which will be sent to your email.*
- **Login**
- **Dashboard overview (stats, charts, etc.)**
- **Order Management**
  - Manage inventory (add, update, archive products; set prices, discounts, vouchers; monitor stock levels)
  - Order Notifications (view new orders, review order detail)
  - Prepare orders (pack items, print waybill/shipping label)
  - Hand over to courier (schedule courier pickup, track/monitor shipment status)
  - Confirm delivery (seller will be notified once the customer receives the order)
  - Handle customer feedback
- **Generate Report (financial and profit - date picker as to from and to date; Sales and performance tracking)**
- **Chat/Messaging**
- **Account management**
- **Logout**

---

### Courier:
- **Registration**
  - Last name*
  - First name*
  - Middle initial
  - Sex*
  - E-mail*
  - Contact No.*
  - Birthday*
  - Age (autogen)*
  - Address (API) (Dropdown: Province, Municipality, Barangay) (Manual entry: Street, House number, etc.)
  - Choose vehicle
  - Enter plate number
  - Upload OR/CR
  - Upload ID/driver's license
  - *After submitting your registration, please wait for the Logistic/Sorting Center's approval, which will be sent to your email.*
- **Login**
- **View dashboard for "items for pickup" (check for notifications from sellers)**
- **View dashboard for "items for delivery" (check delivery notifications; view available pickup requests)**
- **Pickup order (actual process of pickup)**
- **Deliver order (actual process of delivery)**
- **Profit dashboard/page**
- **View delivery history**
- **Chat/Messaging**
- **Account management**
- **Logout**

---

### Admin:
- **Login**
- **View dashboard (view platform overview check notif)**
- **Manage account registrations (review buyer/seller/logistics/sorting center applications; verify submitted information and requirements; approve/disapprove registration; notify applicant on decision thru email)**
- **Manage user accounts (view user profiles; Activate, Suspend, or Deactivate Accounts)**
- **Monitor Seller Compliance (Verify Products Belong to the Seller's Registered Category; Identify Prohibited or Inappropriate Products; Issue Warnings or Suspend Seller Accounts for Violations)**
- **Manage Complaints and Disputes (Review Complaint Details and Supporting Evidence; Coordinate with Buyer, Seller, and/or Courier)**
- **Manage Commission (10%) (Calculate Platform Commissions;)**
- **Generate Reports (Sales Summary Report; Commission Report)**
- **Manage Platform Settings (Post Announcements; Update Platform Policies)**
- **Chat/Messaging**
- **Account management**
- **Logout**

---

### Logistics / Sorting Center:
- **Registration**
  - Last name*
  - First name*
  - Middle initial
  - Sex*
  - E-mail*
  - Contact No.*
  - Birthday*
  - Age (autogen)*
  - Address (API) (Dropdown: Province, Municipality, Barangay) (Manual entry: Street, House number, etc.)
  - Business name
  - Upload ID
  - Upload business/DTI permit
  - *After submitting your registration, please wait for the administrator's approval, which will be sent to your email.*
- **Login**
- **Dashboard**
- **Rider management (approve/disapprove rider/courier application; activate/deactivate)**
- **Confirm/approve/verify parcel pickup requests from seller**
- **Management of incoming parcels**
- **Sorting of parcels**
- **Delivery assignment (per area and per rider)**
- **Delivery monitoring**
- **Generation of reports**
- **Chat/messaging**
- **Account management**
- **Logout**

---

## 2. Order Lifecycles & Process Flows

### Sorting Center / Logistics:
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

**Example Routing Table:**
| PARCEL | DELIVERY ADDRESS | AREA | ASSIGNED RIDER |
| :--- | :--- | :--- | :--- |
| #1001 | Santa Cruz, Laguna | Area A | Rider 01 |
| #1002 | Pagsanjan, Laguna | Area B | Rider 02 |
| #1003 | Los Baños, Laguna | Area C | Rider 03 |

**2 main responsibility of the sorting center/logistics:**
1. Sort the parcel according to destination
2. Assign the parcel to the appropriate rider based on the rider's assigned area

---

### Buyer / Customer:
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

**After the seller prepares the order:**
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

### Seller (Receive, Pack & Prepare Order for Pickup):
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

### Rider / Courier (Pickup vs Delivery):

#### Rider/Courier (Pickup):
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

#### Rider/Courier (Delivery):
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

**Then:**
```
Successful Delivery?
- YES
  ↓
  DELIVERED
  ↓
  Buyer Confirms Receipt
  ↓
  COMPLETED

- NO
  ↓
  DELIVERY FAILED
  ↓
  Reason Recorded
  ↓
  Reschedule Delivery / Return Parcel
```

---

## 3. Order Status Definitions & Progression

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
