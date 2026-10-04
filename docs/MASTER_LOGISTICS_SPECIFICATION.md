# BagooPH Master Architecture & Technical Specification

## Road Logistics and Marketplace Architecture

*Platform Design, Entity Hierarchy & Highway Network Delimitation*

> **Source:** Master Architecture Technical Specification & Teacher Brainstorming Notes (September 2026).
> Operational behavior is authoritative in `docs/SORTING_CENTER_LOGISTICS_FLOW.md`. Canonical statuses and role ownership are authoritative in `docs/SYSTEM_FLOW_AND_SPECIFICATIONS.md`. Validation and failure behavior are authoritative in `docs/CORE_FLOW_VALIDATION_AND_EDGE_CASES.md`. Current implementation status belongs only in `docs/CORE_FLOW_ROADMAP.md`. This document describes architecture and future scope.

---

## 1. Executive Summary & Foundational Scope

BagooPH demonstrates a realistic multi-shop ecommerce transaction through a small, company-scoped road parcel network. A modest set of accounts, hubs, vehicles, and supported routes is sufficient. Thousands of users, national fleet operations, enterprise infrastructure, and purchasing warehouse equipment are not project requirements. Keep the existing application and the roadmap's core phases as the delivery boundary.

### Contiguous Land Delimitation
- **100% Road-Based Freight:** All logistics operations are strictly delimited to domestic, contiguous land highway networks (e.g., Mainland Luzon and interconnected provincial roads).
- **Excluded Transport:** Boats, inter-island sea freight, ports, RORO, sea cargo containers, and air freight are outside scope. A sea crossing cannot be treated as a road leg.
- **Geographic Service Boundaries:** Checkout must reject destinations without supported contiguous road coverage and a complete facility route. A map pin or free-text province alone cannot prove serviceability. Current enforcement evidence belongs in the roadmap.

---

## 2. Multi-Tenant Logistics Structure & Entity Hierarchy

The system separates platform governance, logistics companies, and facility operations. This responsibility hierarchy does not grant one actor another actor's custody permissions.

```
[Platform Admin: account review and read oversight]
├── Marketplace: buyer accounts and seller shops
└── Logistics company review
    └── Approved Logistics Company Admin: own network
        ├── Mother Hubs and Bayan Hubs
        │   └── Active facility-scoped Hub Handlers
        └── Eligible couriers placed by hub and barangay
            └── Pickup or delivery assignments
```

### 1. Logistics Company Registration and Review
- **Application Workflow:** Logistics providers use the logistics-company application, distinct from individual courier registration.
- **Platform Review:** Active Platform Admin reviews the documented company details, business permit, and applicable franchise evidence. This reference adds no separate tax, insurance, or accreditation service.
- **Company Scope:** An approved active company manages its own network and related shipments. It cannot view competing companies' private operations or unrelated marketplace transactions.
- **Authority:** [ADMIN_FLOW.md](ADMIN_FLOW.md) defines marketplace approval and suspension. Company acceptance/placement of an already platform-approved courier cannot grant KYC approval or undo a global restriction.

### 2. Branch (Hub) Management
Logistics Company Admins manage their own physical facilities in two tiers:
- **Regional Mother Hub (Sortation Center):** Regional sorting and transfers between Bayan Hubs or road regions.
- **Local Bayan Hub (Delivery Station):** Municipal-level branches responsible for seller drop-offs, local pickup consolidation, customer self-pickup counters, and morning rider dispatching.
- Facilities need validated location, active company scope, and supported coverage. Stored coordinates and capacity may assist planning; they do not require live GPS or an automated warehouse.

### 3. Personnel Onboarding & Role Scoping
- Hub Handlers: Eligible `logistics` accounts with active assigned-facility access for inbound, sorting, and outbound scans; no additional public staff role.
- Couriers: Already platform-approved, active riders placed within an eligible company/hub and barangay. Assignments and configured capacity govern new work; automated load balancing is not a baseline requirement.

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
- **Address Resolution:** The validated textual destination, barangay, and configured service coverage identify the destination Bayan Hub. Valid stored coordinates may assist; they cannot replace address or company/facility authorization.
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
- **Shopping Bag Isolation:** Adding an item to the Shopping Bag reserves zero stock.
- **Quantity Validation:** Pickers may constrain quantities, but the server revalidates quantity and current stock.
- **Atomic Checkout Decrement:** Checkout validates and decrements stock atomically. Authorized seller cancellation before pickup claim/custody restores stock once; no automatic unpaid-order expiry or restocking policy is added here.

### Seller Category Enclosure & Multi-Shop Toggling
- **Approved Shop Root Category:** Each shop sells within its approved root category from the 14 master categories. Account approval does not automatically approve another shop or category.
- **Multi-Store Switcher:** Merchants wishing to sell across multiple categories do not need separate logins. From their dashboard, a dropdown toggle switches operational context between distinct, approved shop profiles under their master account.

### Address Validation
- Validate the required recipient, phone, textual address, barangay, postal code, and supported road route on the server.
- Validate coordinate range when a pin is supplied. Missing optional coordinates must not replace the textual address with a guessed location.
- Human document review remains the approval baseline. OCR, automated map/text discrepancy review, and compulsory pin capture are not introduced by this architectural reference.

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

These notes provide context; the normative contracts and current roadmap determine requirements. Fleet examples do not require procuring or operating a national commercial fleet.

- **Unified Terminology:** Sorting center, Logistics, and Hub refer to nodes within the same logistics facility hierarchy.
- **Mobile-First Scanning:** Sorting center/hub handlers do not require heavy desktop terminals; operations are driven via mobile/responsive PWA camera barcode/QR waybill scanners.
- **Rider Allocation:** Barangay placement and one eligible assigned rider per parcel govern delivery. The earlier one-rider-per-barangay suggestion is an example configuration, not a volume target or an automated staffing requirement.
- **Free Hub Pickup:** Buyers can choose free self-pickup at their local municipal Bayan Hub rather than doorstep delivery.
- **Vehicle Hierarchy:** Explicit fleet registration including motorcycles/tricycles for first and last mile, closed vans for feeder shuttles, and closed wing trucks for inter-hub line-haul.
- **Facility-Hop Dispatching:** Inter-bayan and inter-provincial routing occurs strictly via facility-to-facility hops (Bayan Hub → Mother Hub → Destination Mother Hub → Destination Bayan Hub → Rider/Counter).

---

## 8. Company Administration Within the Core Project

Company administration supports the existing road flow with ordinary lists, scoped assignments, and recorded evidence. It does not require a corporate ERP or enterprise dashboard.

| Core responsibility | Boundary |
|---|---|
| Network and personnel | Own active Mother/Bayan Hubs, facility-scoped handlers, and placement of already platform-approved riders |
| Parcel operations | Expected-facility scans, ordered Mother-Hub transfers, manifests, and one current custodian |
| Exceptions and self-pickup | Recorded attempts, hub return before retry, reverse checkpoints on the original waybill, seller return receipt, and secure counter release |
| Operational COD | Recorded rider/counter collection, remittance, and discrepancies; Platform Admin owns platform reconciliation and seller settlement |
| Overview | Real pending work and recorded totals; no sample success, invented analytics, or guessed cash balances |

Platform review remains separate from company placement. Handler access uses the existing `logistics` role and facility assignments; it does not add `hub_staff` or `hub_handler` public account roles. Restrictions preserve existing custody and cash while blocking new work, as defined in the admin and validation contracts.

Basic remittance, failure recovery, and self-pickup are required roadmap phases, not optional enterprise modules. Advanced rate matrices, density automation, AI dispatch, and analytics are deferred ideas requiring separate approval. They cannot appear as required navigation, silently allocate riders, or delay the baseline.

For a company portal, group a small number of pages around overview, own facilities/personnel, parcels/manifests, exceptions/counter work, and remittance. Only an authorized handler with an active facility assignment can use floor scan actions; choosing a branch in a switcher does not confer that authority. See [ADMIN_FLOW.md](ADMIN_FLOW.md) for the authority matrix and [CORE_FLOW_ROADMAP.md](CORE_FLOW_ROADMAP.md) for actual implementation status and phase order.
