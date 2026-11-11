# Outdated and Superseded Documentation Policy

> **CRITICAL INSTRUCTION FOR DEVELOPERS AND AI AGENTS:**
> **DO NOT READ OR CITE OUTDATED DOCUMENTS FOR IMPLEMENTATION AUTHORITY OR BUSINESS RULES.**
> The concepts in the outdated documents listed below have already been implemented, demonstrated, or superseded by modern production architecture. Reading them as current requirements will lead to regression bugs, obsolete single-tier assumptions, and conflicting logic.

---

## 1. Authority Hierarchy

When seeking implementation rules, always consult the **Canonical Documents**, never the **Outdated / Historical Documents**:

```text
CANONICAL (Read These)                          OUTDATED (Do NOT Read)
--------------------------------------          -----------------------------------------
docs/new-docs-era/README.md             <---    docs/ERP_COMPONENTS_SPECIFICATION.md
docs/SYSTEM_FLOW_AND_SPECIFICATIONS.md   <---    docs/PROGRESS.md (historical logs)
docs/MASTER_LOGISTICS_SPECIFICATION.md   <---    docs/DOCUMENTATION_REVIEW.md
docs/SORTING_CENTER_LOGISTICS_FLOW.md   <---    .codex/*/PR.md (historical sprint logs)
docs/CORE_FLOW_VALIDATION_AND_EDGE_CASES.md
docs/BAGOO_WALLET.md
docs/TECH_STACK.md
```

---

## 2. Inventory of Outdated Documents & Concepts

### A. `docs/ERP_COMPONENTS_SPECIFICATION.md` & Original Curriculum PDFs (`ERP-Flow.pdf`, `ERP-Components-updated.pdf`)
* **Status:** **OUTDATED & HISTORICAL PRESERVATION ONLY.**
* **DO NOT TOUCH OR REMOVE:** Retained purely as historical curriculum evidence.
* **Why it is outdated:**
  * **Original Concept:** 1-Tier Linear Sorting Center (`Seller -> Rider -> Single Sorting Center -> Rider -> Buyer`).
  * **Live Production Reality:** Multi-tenant logistics network featuring **Bayan (Child) Hubs** and regional **Mother Hubs**, connected by automated linehaul manifests and tamper-evident sealed Mother Bags.
  * **Original Concept:** Buyer-only manual confirmation (Order sits waiting for buyer to click a button).
  * **Live Production Reality:** Automatic commercial completion triggered by valid digital Proof of Delivery (POD) photo capture and payment verification.
  * **Original Concept:** Flat ₱50 shipping and vague rider compensation.
  * **Live Production Reality:** Volumetric billable weight formula (`L × W × H / 3,500`), base ₱60 tariff, and exact integer-cent earnings split (`1/6` pickup rider, `5/12` delivery courier, `5/12` logistics company).
  * **Original Concept:** Universal single courier role.
  * **Live Production Reality:** Scoped courier assignment phases (First-mile pickup rider, linehaul vehicle driver, final-mile delivery courier).

---

### B. `docs/PROGRESS.md`
* **Status:** **HISTORICAL SPRINT LOG ONLY.**
* **Why it is outdated:** Contains dated milestone notes from early development phases. It describes legacy states and temporary mock routes that are no longer permitted or used in the live application.
* **Instruction:** Do not read for active features. The active roadmap and audit live in [`docs/CORE_FLOW_ROADMAP.md`](CORE_FLOW_ROADMAP.md).

---

### C. `docs/DOCUMENTATION_REVIEW.md`
* **Status:** **HISTORICAL AUDIT ARCHIVE.**
* **Why it is outdated:** This document records an audit conducted on October 16, 2026. The fixes identified therein have already been merged into `main`. It serves as a historical record, not a guide for future features.

---

### D. Historical `.codex/` Directories
* **Status:** **RETAINED COMMIT ARTIFACTS.**
* **Why it is outdated:** These directories (`.codex/buyer-batch*`, `.codex/pf01`, `.codex/b13`, etc.) contain historical Docker build logs, temporary verification scripts, and PR drafts from previous milestones.
* **Instruction:** Never use `.codex/` logs as references for live system architecture.

---

## 3. Key Concepts That Are Already Live (Do Not Re-implement or Re-document)

The following architectural concepts are **already completed, presented, and running live** in the codebase. Do not treat them as future ideas or missing features:

1. **Intra-Hub Local Bypass:**
   * Parcels originating and delivering within the same Bayan Hub skip the Mother Hub corridor entirely.
   * *Code Reference:* `App\Services\Logistics\LogisticsRouteService`

2. **Continuous Logistics Scanning & DWS:**
   * Automated inbound receipt, bag opening, and conveyor binning across all hub tiers.
   * *Code Reference:* `App\Services\Logistics\ContinuousScanService`, `MotherBagService`

3. **Multi-Tenant Company Staff Provisioning:**
   * Logistics Company Owners self-provision their own hubs, coverage areas, station handlers, and fleet riders without Platform Admin intervention.
   * *Code Reference:* `App\Http\Controllers\Logistics\StaffController`, `CompanyManagementService`

4. **Decoupled COD Cash Reconciliation:**
   * Cash custody is completely separated from parcel status. Riders cannot end their shift duty until all collected COD cash is physically remitted and acknowledged at a hub cash desk.
   * *Code Reference:* `App\Services\Finance\CodCashService`, `RiderDutyService`

5. **Integrated Bagoo Wallet (Prepaid Credits):**
   * Double-entry immutable virtual ledger with automatic escrow release upon verified delivery and instant refund handling.
   * *Code Reference:* `App\Services\Wallet\WalletPaymentService`

---

## 4. Strict Instruction for AI Agents (Antigravity / Gemini)

When an AI assistant is assigned a task in this repository:
1. **Never read `docs/ERP_COMPONENTS_SPECIFICATION.md`** to determine how logistics, shipping rates, or order completions should behave.
2. **Always route to `docs/new-docs-era/README.md`** for logistics and routing architecture.
3. **Always route to `docs/SYSTEM_FLOW_AND_SPECIFICATIONS.md`** for order lifecycle rules.
4. **Never alter or delete the historical curriculum files** (`ERP_COMPONENTS_SPECIFICATION.md`, `ERP-Components-updated.pdf`, `ERP-Flow.pdf`). They remain permanent read-only curriculum artifacts.
