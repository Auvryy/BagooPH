# 📌 Project Plan & System Requirements

This document details the functional specifications for the e-commerce platform based on the instructor's project guidelines.

---

## 🎯 1. Overview

An e-commerce platform with 4 separate user roles (**Buyer**, **Seller**, **Courier**, **Admin**) built with a unified UI theme (`#E00D42`).

---

## 👥 2. Features by User Role

### 🛒 Buyer
1. **Registration:** Last name, First name, Middle initial, Sex, Email, Contact No, Birthday, Age (autogen), Address (Dropdown: Province, Municipality, Barangay; Manual: Street, House number), Upload ID.
2. **Approval Notice:** After registration, account is in `pending_approval` until Admin approves.
3. **Login & Main Menu:**
   - 14 Categories & Subcategories.
   - Search bar (view product details, choose variations like color/size, select quantity, Add to Cart).
   - View Cart (finalize details, apply vouchers and discounts, select payment mode, place order).
   - Order Status (to ship, in transit, out for delivery, rate/feedback).
   - In-app Chat/Messaging.
   - Account Management & Logout.

### 🏪 Seller
1. **Registration:** Personal info, Business name, Line of business (category), Upload ID, Upload business permit. Requires Admin approval.
2. **Dashboard Overview:** Sales stats and performance charts.
3. **Order & Product Management:**
   - Manage inventory (add, update, archive products, prices, discounts, vouchers, stock levels).
   - Order notifications and order detail review.
   - Prepare orders (pack items, print waybills/shipping labels).
   - Hand over to courier (schedule pickup, track shipment status).
   - Delivery confirmation notice & customer feedback.
4. **Generate Report:** Financial and profit report with date picker (`from_date` to `to_date`), sales and performance tracking.
5. **Chat/Messaging, Account Management, Logout.**

### 🚚 Courier
1. **Registration:** Personal info, Vehicle selection, Plate number, Upload OR/CR, Upload Driver's License. Requires Admin approval.
2. **Delivery Dashboard:** Delivery notifications and available pickup requests.
3. **Accept Delivery Requests:** First-Come, First-Served (system assigns delivery to first courier who accepts).
4. **Pick Up Order:** Proceed to seller location, verify order info, confirm item pickup.
5. **Deliver Order & Complete Delivery:** In transit updates, doorstep drop-off, proof of delivery notes.
6. **Profit / Earnings Page & Delivery History.**
7. **Chat/Messaging, Account Management, Logout.**

### 🛡️ Admin
1. **Dashboard Overview:** Platform stats and notification feed.
2. **Manage Account Registrations:** Review buyer, seller, and courier applications; verify uploaded IDs, permits, and licenses; approve/disapprove with email notification.
3. **Manage User Accounts:** View user profiles; activate, suspend, or deactivate accounts.
4. **Monitor Seller Compliance:** Verify products belong to seller's registered category; take down prohibited items; issue warnings or suspend accounts.
5. **Manage Complaints and Disputes:** Review complaint details and evidence; coordinate with buyer, seller, or courier.
6. **Manage Commission (10%):** Calculate and track platform 10% commission.
7. **Generate Reports:** Sales Summary Report, Commission Report.
8. **Platform Settings:** Post announcements, update platform policies.
9. **Chat/Messaging, Account Management, Logout.**
