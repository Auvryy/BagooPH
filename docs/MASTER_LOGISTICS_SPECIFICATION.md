# BagooPH Master Architecture & Technical Specification
## Multi-Tenant Road Logistics & Marketplace Ecosystem
*Platform Design, Entity Hierarchy & Highway Network Delimitation*

> **Source:** Master Architecture Technical Specification & Teacher Brainstorming Notes (September 2026).
> Operational behavior is authoritative in `docs/SORTING_CENTER_LOGISTICS_FLOW.md`. This document describes architecture and future scope.

---

## 1. Executive Summary & Foundational Scope

BagooPH couples a multi-vendor retail marketplace with a multi-tenant, land-based parcel network modeled after established hub-and-spoke e-commerce logistics. Rather than outsourcing shipping to an unmonitored external system, the platform integrates logistics into its core transactional lifecycle.

### Contiguous Land Delimitation
- **100% Road-Based Freight:** All logistics operations are strictly delimited to domestic, contiguous land highway networks (e.g., Mainland Luzon and interconnected provincial roads).
- **Exclusion of Maritime Shipping:** Inter-island sea freight, commercial port terminal manifests, roll-on/roll-off (RORO) ship tracking, and sea cargo containers are entirely excluded from system scope. This guarantees consistent transit tracking, avoids maritime schedule anomalies, and keeps the project defensible.
- **Geographic Service Boundaries:** Delivery addresses outside contiguous road networks (such as remote island municipalities) are rejected at checkout by automated address validation rules.

---

## 2. Multi-Tenant Logistics Structure & Entity Hierarchy

The system operates across three administrative tiers, maintaining separation between marketplace governance, independent courier companies, local facilities, delivery fleets, merchants, and consumers.

```
                  [Platform Super Admin]
                             │
       ┌─────────────────────┴─────────────────────┐
       ▼                                           ▼
[Marketplace Management]                 [Logistics Company Admin]
├── Seller Profiles                      (BagooPH marketplace merchants)
└── Buyer Accounts                                 │
                                                   ▼
                                        [Branch / Hub Network]
                                        ├── Regional Mother Hubs (Sortation Centers)
                                        └── Local Bayan Hubs (Delivery Stations)
                                                   │
                                      ┌────────────┴────────────┐
                                      ▼                         ▼
                                [Hub Handlers]          [Delivery Riders]
                               (Mobile Scanners)       (Assigned per Barangay)
```

### 1. Courier Partner Registration & Accreditation
- **Application Workflow:** Independent logistics providers apply via a public courier registration portal, creating a master corporate account.
- **KYC & Accreditation:** Platform Admins verify corporate legal requirements, such as business permits, tax registration, transport regulatory board accreditations, and fleet insurance.
- **Independent Tenant Portal:** Approved couriers unlock a private Logistics Portal. Couriers cannot view competing logistics companies or marketplace transactions.

### 2. Branch (Hub) Management
Courier Admins register physical branches categorized into two functional tiers:
- **Regional Mother Hub (Sortation Center):** High-throughput, central cross-docking facilities for inter-provincial sorting.
- **Local Bayan Hub (Delivery Station):** Municipal-level branches responsible for seller drop-offs, local pickup consolidation, customer self-pickup counters, and morning rider dispatching.
- Every branch is configured with its geographic location, exact GPS coordinates, physical capacity, and assigned coverage barangays.

### 3. Personnel Onboarding & Role Scoping
- Hub Handlers: Scanners for inbound, binning, and outbound operations.
- Delivery Riders: Dedicated per barangay with dynamic load balancing.

---

## 3. Physical Facilities, Vehicle Fleet & Routing Logic

### The Two-Tier Facility Network

```
[Merchant Shop / Warehouse]
   │
   ▼ (First-Mile Pickup via Motorcycle)
[Origin Local Bayan Hub] (Municipal Intake & Staging)
   │
   ▼ (Feeder Shuttle: Closed Van)
[Regional Mother Hub] (Regional Sorting)
   │
   ▼ (Line-Haul Highway Trunk: Closed Wing Trucks)
[Destination Mother Hub] (Only when the destination uses a different region)
   │
   ▼ (Distribution Shuttle: Closed Van)
[Destination Local Bayan Hub] (Municipal Delivery Station)
   ├── Option A: Free Buyer Self-Pickup Counter
   └── Option B: Last-Mile Doorstep Delivery via Barangay Rider
```

### Vehicle Categorization & Movement Types
- **Motorcycles & Tricycles:** First-mile collection from sellers and last-mile residential delivery within neighborhood barangays.
- **Light Utility Vehicles (4-Wheel Closed Vans):** Short-distance feeder runs moving consolidated batches between Local Bayan Hubs and Regional Mother Hubs.
- **Heavy Freight Trucks (6-to-10 Wheeler Closed Wing Trucks):** High-capacity highway line-haul transit connecting distant Mother Hubs across provincial expressways.

### The Routing Engine: Facility-to-Facility Hops
Parcels do not route via continuous street directions from the merchant's doorstep to the buyer's house. Instead, the routing engine models movement as a sequence of facility codes:
- **Address Resolution:** The buyer’s pinned location identifies the destination Bayan Hub and target barangay.
- **Route Leg Generation:** The system determines the required transit hops:
  - Leg 1: Merchant → Origin Bayan Hub
  - Leg 2: Origin Bayan Hub → Regional Mother Hub
  - Leg 3: Mother Hub → Destination Mother Hub (for long-haul routes)
  - Leg 4: Destination Mother Hub → Destination Bayan Hub
  - Leg 5: Destination Bayan Hub → Customer Doorstep (or Self-Pickup Shelf)
- **Dynamic Scan Prompts:** Handlers do not need to memorize national geography. When scanning a waybill, the screen displays operational instructions, such as: `LOAD TO FEEDER: VAN-NORTH-01` or `BIN: BRGY-POBLACION-1`.

### Baseline Sorting Equipment
- **Regional Mother Hubs:** Operators use authenticated barcode or QR scans, destination cages, and outbound manifests. Automated conveyors and industrial dimensioning equipment are outside the baseline project scope.
- **Mobile Batch Inbound & Outbound:** Hub staff use responsive camera-enabled screens to scan parcel batches with visible confirmation and error feedback.

---

## 4. End-to-End Operational Lifecycle & Order State Machine

Every physical movement must correspond to an authenticated digital scan. Custody cannot change hands without a scan event, preventing missing packages.

```
[ORDER PLACED] (Atomic stock reservation)
   │
   ▼
[SELLER CONFIRMED] ➔ [PREPARING] ➔ [READY FOR PICKUP]
   │
   ▼ (First-Mile Mobile Scan by Pickup Rider)
[PICKED UP BY RIDER]
   │
   ▼ (Inbound Mobile Scan at Origin Local Station)
[ARRIVED AT ORIGIN BAYAN HUB]
   │
   ▼ (Scanned onto Feeder Van Manifest)
[IN TRANSIT TO MOTHER HUB]
   │
   ▼ (Authenticated Inbound Scan)
[ARRIVED AT REGIONAL MOTHER HUB] ➔ [SORTED TO LINE-HAUL CAGE]
   │
   ▼ (Scanned onto Highway Truck Manifest)
[IN TRANSIT TO DESTINATION HUB]
   │
   ▼ (Inbound Intake Scan at Destination Local Station)
[ARRIVED AT DESTINATION BAYAN HUB]
   │
   ├── IF Hub Self-Pickup: ➔ [READY FOR HUB PICKUP] ➔ [CUSTOMER COLLECTED] ➔ [DELIVERED]
   │
   └── IF Doorstep Delivery: ➔ [SORTED TO BARANGAY BIN]
                                   │
                                   ▼ (Hub Assigns Eligible Barangay Rider)
                           [ASSIGNED TO RIDER]
                                   │
                                   ▼ (Assigned Rider Scans Out)
                             [OUT FOR DELIVERY]
                                   │
                                   ▼ (Delivery Proof and Notes)
                               [DELIVERED]
                                   │
                                   ▼ (Buyer Receipt Confirmation)
                               [COMPLETED]
```

### Exception & Delivery Failure Protocols
- **Delivery Failure:** If a customer is unreachable, the rider logs the failure reason (e.g., `Customer Unreachable - Attempt 1`). The parcel returns to the Bayan Hub in `DELIVERY_FAILED` status.
- **Re-attempt & Return-to-Sender (RTS):** The system permits up to two re-delivery attempts (3 total). If the third attempt fails, the delivery transitions internally to `return_to_sender` and routes backward through the Mother-Hub network. The customer-facing order becomes `RETURNED` only after seller receipt.

---

## 5. Core Marketplace & Business Integrity Policies

### Strict Stock Allocation
- **Add-to-Cart Isolation:** Adding an item to a shopping cart reserves zero stock.
- **Input Clamping:** Product quantity pickers are locked to available inventory, preventing users from selecting higher quantities.
- **Atomic Checkout Decrement:** Stock decreases in the database strictly and atomically upon order checkout confirmation. Canceled or expired unpaid orders release stock back to the active catalog immediately.

### Seller Category Enclosure & Multi-Shop Toggling
- **Single Licensed Root Category:** To reduce counterfeit items and streamline tax classification, each merchant profile can sell under only one root category.
- **Multi-Store Switcher:** Merchants wishing to sell across multiple categories do not need separate logins. From their dashboard, a dropdown toggle switches operational context between distinct, approved shop profiles under their master account.

### Address Intelligence & Verification
- **OCR Onboarding:** Automated optical character recognition parses uploaded government IDs during registration to pre-populate text fields (Province, Bayan, Barangay).
- **Mandatory Interactive Pin Drops:** Because provincial Philippine addresses frequently lack standard street numbers, buyers must drop an interactive map pin (Leaflet / OpenStreetMap). Couriers rely on coordinate verification (`lat`, `long`) alongside text landmarks.
- **Admin Verification Queue:** Flagged address discrepancies (such as a text address not matching the map pin's administrative zone) are queued for Admin review.

---

## 6. Optional Future Enhancements

The following ideas are not required for the complete baseline order and logistics flow. They must not delay custody scans, failed delivery, self-pickup, notifications, or COD reconciliation.

1. **Conversational Shopping Assistant (Digital Concierge):** An interactive shopping assistant helps consumers discover products using open-ended natural language queries grounded directly in the store's product database.
2. **Multimodal Address & Document OCR:** Vision models extract address records from government IDs, business permits, and utility bills during merchant and customer KYC verification.
3. **Barangay Rider Density Assistant:** A future calculation may alert the hub manager when a barangay needs an auxiliary rider.
4. **Proof of Delivery Assistance:** Future validation may flag incomplete proof for human review. It must not autonomously release seller payments.
5. **Ethical Guardrails & Privacy Protections:** Customer data, uploaded images, and delivery telemetry are governed by strict data privacy guardrails, ensuring automated processes operate transparently without bias or unauthorized data retention.

---

## 7. Teacher & Brainstorming Lecture Notes

Recorded from foundational domain brainstorming:
- **Unified Terminology:** Sorting center, Logistics, and Hub refer to nodes within the same logistics facility hierarchy.
- **Mobile-First Scanning:** Sorting center/hub handlers do not require heavy desktop terminals; operations are driven via mobile/responsive PWA camera barcode/QR waybill scanners.
- **Rider Allocation:** Default baseline is 1 rider dedicated per barangay, with dynamic auxiliary assignment for heavy volume.
- **Free Hub Pickup:** Buyers can choose free self-pickup at their local municipal Bayan Hub rather than doorstep delivery.
- **Vehicle Hierarchy:** Explicit fleet registration including motorcycles/tricycles for first and last mile, closed vans for feeder shuttles, and closed wing trucks for inter-hub line-haul.
- **Facility-Hop Dispatching:** Inter-bayan and inter-provincial routing occurs strictly via facility-to-facility hops (Bayan Hub → Mother Hub → Destination Mother Hub → Destination Bayan Hub → Rider/Counter).

---

## 8. Enterprise Company Admin Modules & Future Roadmap (Probably Might Add Feature Section)

### Baseline Comparison: What We Have vs. Enterprise Roadmap

#### Core Baseline Modules (Implemented Foundation)
- **Overview & Analytics:** Aggregated metrics, KPI cards, and Catmull-Rom throughput spline charts across all operating hubs.
- **Scan Station / Barcode Scanner Terminal:** Responsive barcode/QR waybill scanning foundation for inbound and outbound custody; remaining transfer controls are listed in the operational specification.
- **Facility Network:** Creating and managing Regional Mother Hubs and Local Bayan Hubs, with real-time capacity and utilization tracking.
- **Fleet Management:** Registering multi-tier vehicles: motorcycles/tricycles, four-wheel closed vans, and closed wing trucks.
- **Parcels & Waybills:** Customer lifecycle, internal scan checkpoints, and public tracking foundation.
- **Counter Self-Pickup:** Counter screen foundation; claim-code, expiry, identity, and COD enforcement remain required.

---

### Critical Enterprise Modules for Corporate Logistics Admins

To manage an entire nationwide courier company, the **Logistics Corporate Admin Portal** includes the following 5 roadmap modules:

#### 1. Personnel & Onboarding Management (Riders & Sorters)
- **Rider Accreditation & KYC:** Dedicated compliance queue to review and approve driver's licenses, vehicle OR/CR, and NBI clearances submitted by riders applying to work across network hubs.
- **Barangay Assignment Matrix:** Spatial matrix interface to assign verified riders to specific Local Bayan Hubs and dedicate them to specific barangays (baseline: 1 rider per barangay).
- **Hub Staff / Sorter Accounts:** Creating and managing user logins (`hub_staff` / `hub_handler`) scoped strictly to individual physical facilities.

#### 2. Cash-on-Delivery (COD) & Financial Remittance Ledger
- **Rider COD Collection Ledger:** Real-time reconciliation of cash collected by last-mile riders upon successful doorstep delivery.
- **Hub Counter Cash Reconciliation:** Logging and balancing COD payments collected at Bayan Hub customer self-pickup counters.
- **Platform & Merchant Remittance:** Tracking shipping fee earnings, deducting marketplace commission, and remitting collected COD funds back to the escrow/merchant settlement accounts.

#### 3. Shipping Rates & Service Zone Mapping
- **Rate Matrix Configuration:** Calculation tiers based on declared package weight, size, and service zone.
- **Service Coverage Matrix:** Interactive administrative toggles for provinces, bayans, and barangays actively serviced by the company, including unserviceable boundary rules and remote exclusions.

#### 4. Exception & Return-to-Sender (RTS) Protocols
- **Delivery Failure Queue:** Real-time monitor for parcels marked `DELIVERY_FAILED` (e.g., customer unreachable, bad weather, or invalid address).
- **RTS Reverse Logistics:** Automated routing engine for packages hitting the 3-attempt failure threshold, generating reverse waybills to return stock back through the hub network to merchants.

#### 5. Optional Density & Dispatch Load Balancing
- **Morning Barangay Density Alert:** Automated 06:00 AM dispatch engine evaluating parcel volume per barangay. If a barangay exceeds threshold (e.g., >60 parcels), the system recommends and provisions auxiliary overflow riders.

---

### Recommended Dashboard Menu Navigation Architecture

To maintain zero UI clutter, navigation strictly separates **Global Corporate Management** from **Hub Station Floor Operations**:

```
Logistics Company Admin Portal
│
├── Corporate & Fleet Management
│   ├── Overview / Global Analytics
│   ├── Facility Network (Mother Hubs & Bayan Hubs)
│   ├── Fleet Management (Trucks, Vans, Motorcycles)
│   ├── Personnel & Riders (KYC Approvals, Barangay Assignments) [Roadmap]
│   └── Service Coverage & Rates (Zone Mapping & Pricing) [Roadmap]
│
├── Parcel Operations & Logistics
│   ├── Master Parcels & Waybill Telemetry
│   ├── Exceptions & RTS (Delivery Failures & Discrepancies) [Roadmap]
│   └── Morning Barangay Density Engine (AI Rider Load Balancing) [Roadmap]
│
├── Financials & Remittances
│   ├── COD Cash Ledger (Rider & Counter Remittances) [Roadmap]
│   └── Platform Payouts & Shipping Earnings [Roadmap]
│
└── Branch Switcher Context (Floor Operations UI)
    └── [Dropdown: Select Specific Hub, e.g., "Santa Cruz Bayan Hub"]
        ├── Mobile PWA / Camera Scan Station (Inbound/Outbound)
        └── Counter Self-Pickup Terminal
```
