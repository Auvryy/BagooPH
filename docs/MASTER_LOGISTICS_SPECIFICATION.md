# BagooPH Master Architecture & Technical Specification
## Multi-Tenant Road Logistics & Marketplace Ecosystem
*Platform Design, Entity Hierarchy & Highway Network Delimitation*

> **Source:** Master Architecture Technical Specification & Teacher Brainstorming Notes (September 2026).
> **Implementation Branch:** `user/logistic`

---

## 1. Executive Summary & Foundational Scope

BagooPH couples a multi-vendor retail marketplace with a multi-tenant, land-based parcel network modeled after top-tier e-commerce logistics platforms like Shopee and Lazada. Rather than outsourcing shipping to an unmonitored external black box, the platform integrates logistics into its core transactional lifecycle.

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
├── Seller Profiles                      (e.g., Bagoo Express, J&T)
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
   ▼ (Feeder Shuttle: L300 / Closed Van)
[Regional Mother Hub] (High-Speed Conveyor Sorting)
   │
   ▼ (Line-Haul Highway Trunk: Closed Wing Trucks)
[Destination Mother Hub] (Optional Regional Cross-Dock)
   │
   ▼ (Distribution Shuttle: L300 / Closed Van)
[Destination Local Bayan Hub] (Municipal Delivery Station)
   ├── Option A: Free Buyer Self-Pickup Counter
   └── Option B: Last-Mile Doorstep Delivery via Barangay Rider
```

### Vehicle Categorization & Movement Types
- **Motorcycles & Tricycles:** First-mile collection from sellers and last-mile residential delivery within neighborhood barangays.
- **Light Utility Vehicles (L300 / 4-Wheel Closed Vans):** Short-distance feeder runs moving consolidated batches between Local Bayan Hubs and Regional Mother Hubs.
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
- **Dynamic Scan Prompts:** Handlers do not need to memorize national geography. When scanning a waybill, the screen displays operational instructions, such as: `LOAD TO SHUTTLE: TRUCK-L300-NORTH` or `BIN: BRGY-POBLACION-1`.

### Sorting Center Automation & High-Speed Rails
- **Regional Mother Hubs:** High-throughput facilities handling thousands of packages daily using automated sorting mechanisms (DWS conveyor tunnels for dimensioning, weighing, scanning, and cross-belt sorter tracks to destination chutes).
- **Mobile Batch Inbound & Outbound:** For Local Bayan Hubs without automated conveyors, staff use smartphone cameras (PWA) to scan batches in rapid succession, with audio feedback confirming each scan.

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
   ▼ (Scanned onto L300 Shuttle Manifest)
[IN TRANSIT TO MOTHER HUB]
   │
   ▼ (DWS Tunnel or Rapid Mobile Scan)
[ARRIVED AT REGIONAL MOTHER HUB] ➔ [SORTED TO LINE-HAUL CAGE]
   │
   ▼ (Scanned onto Highway Truck Manifest)
[IN TRANSIT TO DESTINATION HUB]
   │
   ▼ (Inbound Intake Scan at Destination Local Station)
[ARRIVED AT DESTINATION BAYAN HUB]
   │
   ├── IF Hub Self-Pickup: ➔ [READY FOR HUB PICKUP] ➔ [CUSTOMER COLLECTED]
   │
   └── IF Doorstep Delivery: ➔ [SORTED TO BARANGAY BIN]
                                   │
                                   ▼ (Rider Batch Scan Out)
                             [OUT FOR DELIVERY]
                                   │
                                   ▼ (Photo & GPS Proof of Delivery)
                               [DELIVERED]
```

### Exception & Delivery Failure Protocols
- **Delivery Failure:** If a customer is unreachable, the rider logs the failure reason (e.g., `Customer Unreachable - Attempt 1`). The parcel returns to the Bayan Hub in `DELIVERY_FAILED` status.
- **Re-attempt & Return-to-Sender (RTS):** The system permits up to two re-delivery attempts (3 total). If the third attempt fails, the package transitions to `RETURN_TO_SENDER`, routing backward through the hub network to the original merchant.

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

## 6. Integrated Artificial Intelligence Subsystems

1. **Conversational Shopping Assistant (Digital Concierge):** An interactive shopping assistant helps consumers discover products using open-ended natural language queries grounded directly in the store's product database.
2. **Multimodal Address & Document OCR:** Vision models extract address records from government IDs, business permits, and utility bills during merchant and customer KYC verification.
3. **Barangay Rider Density Engine:** Every morning, an automated calculation checks package density across municipal barangays. If a specific barangay exceeds standard parcel volume limits (e.g., 60 packages for one rider), the system alerts the hub manager to assign auxiliary riders to that zone.
4. **Proof of Delivery (POD) Validation:** Computer vision evaluates doorstep delivery photos uploaded by riders, validating package placement, date-time metadata, and GPS geofencing (≤ 100m of the destination pin) before releasing seller payments and processing platform commissions.
5. **Ethical Guardrails & Privacy Protections:** Customer data, uploaded images, and delivery telemetry are governed by strict data privacy guardrails, ensuring automated processes operate transparently without bias or unauthorized data retention.

---

## 7. Teacher & Brainstorming Lecture Notes

Recorded from foundational domain brainstorming:
- **Unified Terminology:** Sorting center, Logistics, and Hub refer to nodes within the same logistics facility hierarchy.
- **Mobile-First Scanning:** Sorting center/hub handlers do not require heavy desktop terminals; operations are driven via mobile/responsive PWA camera barcode/QR waybill scanners.
- **Rider Allocation:** Default baseline is 1 rider dedicated per barangay, with dynamic auxiliary assignment for heavy volume.
- **Free Hub Pickup:** Buyers can choose free self-pickup at their local municipal Bayan Hub rather than doorstep delivery.
- **Vehicle Hierarchy:** Explicit fleet registration including Motorcycles/Tricycles (first/last mile), L300 / Closed Vans (feeder shuttles between Bayan Hub & Mother Hub), and Wing Trucks (inter-hub line-haul).
- **Facility-Hop Dispatching:** Inter-bayan and inter-provincial routing occurs strictly via facility-to-facility hops (Bayan Hub → Mother Hub → Destination Mother Hub → Destination Bayan Hub → Rider/Counter).
