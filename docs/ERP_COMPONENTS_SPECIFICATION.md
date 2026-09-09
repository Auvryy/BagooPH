# ERP Components Specification

> Official specification transcribed verbatim from docs/ERP-Components-updated.pdf and docs/ERP-Flow.pdf.
> Zero additions, zero cuts, retaining all 14 master product categories.

---

## 1. Buyer

### Registration
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
- Notice: After submitting your registration, please wait for the administrator's approval, which will be sent to your email.

### Post-Registration Features
- **Login**
- **Main Menu**
  - **Categories**
  - **Search (search bar)**: View product details; choose item, select quantity, choose variations (color, size, etc.), add to cart
  - **View cart**: Select order, finalize order details, apply vouchers and discounts; choose mode of payment; place order
  - **View orders' status**: To ship, in transit, out for delivery, rate/feedback, etc.
  - **Chat/Messaging**
  - **Account Management**
  - **Logout**

---

## 2. Seller

### Registration
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
- Notice: After submitting your registration, please wait for the administrator's approval, which will be sent to your email.

### Post-Registration Features
- **Login**
- **Dashboard overview**: Stats, charts, etc.
- **Order Management**:
  - Manage inventory (add, update, archive products; set prices, discounts, vouchers; monitor stock levels)
  - Order Notifications (view new orders, review order detail)
  - Prepare orders (pack items, print waybill/shipping label)
  - Hand over to courier (schedule courier pickup, track/monitor shipment status)
  - Confirm delivery (seller will be notified once the customer receives the order)
  - Handle customer feedback
- **Generate Report**: Financial and profit - date picker as to from and to date; Sales and performance tracking
- **Chat/Messaging**
- **Account management**
- **Logout**

---

## 3. Courier (Rider)

### Registration
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
- Notice: After submitting your registration, please wait for the Logistic/Sorting Center's approval, which will be sent to your email.

### Post-Registration Features
- **Login**
- **View dashboard for "items for pickup"**: Check for notifications from sellers
- **View dashboard for "items for delivery"**: Check delivery notifications; view available pickup requests
- **Pickup order**: Actual process of pickup
- **Deliver order**: Actual process of delivery
- **Profit dashboard/page**
- **View delivery history**
- **Chat/Messaging**
- **Account management**
- **Logout**

---

## 4. Admin

### Features & Capabilities
- **Login**
- **View dashboard**: View platform overview, check notifications
- **Manage account registrations**:
  - Review buyer/seller/logistics/sorting center applications
  - Verify submitted information and requirements
  - Approve/disapprove registration
  - Notify applicant on decision through email
- **Manage user accounts**:
  - View user profiles
  - Activate, Suspend, or Deactivate Accounts
- **Monitor Seller Compliance**:
  - Verify products belong to the seller's registered category
  - Identify prohibited or inappropriate products
  - Issue warnings or suspend seller accounts for violations
- **Manage Complaints and Disputes**:
  - Review complaint details and supporting evidence
  - Coordinate with Buyer, Seller, and/or Courier
- **Manage Commission (10%)**: Calculate platform commissions
- **Generate Reports**: Sales Summary Report; Commission Report
- **Manage Platform Settings**: Post announcements; update platform policies
- **Chat/Messaging**
- **Account management**
- **Logout**

---

## 5. Logistics / Sorting Center

### Registration
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
- Notice: After submitting your registration, please wait for the administrator's approval, which will be sent to your email.

### Post-Registration Features
- **Login**
- **Dashboard**
- **Rider management**:
  - Approve/disapprove rider/courier application
  - Activate/deactivate riders
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

## 6. Official Operational Life Cycle (ERP-Flow.pdf)

### 6.1 Sorting Center / Logistics
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

#### Area Routing Example:
| Parcel | Delivery Address | Area | Assigned Rider |
| :--- | :--- | :--- | :--- |
| **#1001** | Santa Cruz, Laguna | Area A | Rider 01 |
| **#1002** | Pagsanjan, Laguna | Area B | Rider 02 |
| **#1003** | Los Baños, Laguna | Area C | Rider 03 |

**2 main responsibilities of the sorting center/logistics:**
1. Sort the parcel according to destination
2. Assign the parcel to the appropriate rider based on the rider's assigned area

---

### 6.2 Buyer / Customer
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

**Post-Preparation Logistics Chain:**
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

### 6.3 Seller Fulfillment
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

### 6.4 Rider / Courier

#### Pickup Operations:
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

#### Delivery Operations:
```
After the sorting center assigns the parcel:
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

#### Delivery Outcome:
- **YES**: DELIVERED → Buyer Confirms Receipt → COMPLETED
- **NO**: DELIVERY FAILED → Reason Recorded → Reschedule Delivery / Return Parcel

---

### 6.5 13 Canonical Statuses
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
