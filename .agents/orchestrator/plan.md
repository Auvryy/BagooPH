# Project Orchestration Plan: BagooPH Multi-Role Interconnectedness & Lifecycle

## Overview
Implement complete end-to-end data interconnectedness across Buyer, Seller, Courier Rider, Logistics Sorting Hub, and Platform Admin with real database workflows, packaging/dispatch approvals, barcode/location scanning simulator, fast-forward progression controls, Admin KYC gating, and 10% platform commission / financial split ledger.

## Execution Strategy
1. **Phase 0 — Comprehensive Survey (3 Explorers / Spec Miners)**:
   - Explorer 1 (Architecture & Database): Inspect existing schemas, migrations, Prisma/Drizzle/Postgres models, API route structure, authentication, role access control, and financial ledger models.
   - Explorer 2 (Frontend Roles & Workflows): Inspect Buyer (/buyer, checkout, order tracking), Seller (/seller/orders, packaging, waybills), Courier (/courier/deliveries, dispatch board), Sorting Hub (/hub, sorting checkpoints), and Admin (/admin/users, KYC queue, dashboard).
   - Explorer 3 / Spec Miner (Simulation & Test Infra): Inspect current fast-forward/simulator controls, scanning checkpoints, existing unit/E2E test setup, dev servers, seed data.

2. **Phase 1 — Project Decomposition (PROJECT.md)**:
   - Create comprehensive Feature Inventory mapping R1 (Order Lifecycle), R2 (Fast-Forward Simulator), R3 (Barcode & Location Checkpoints), R4 (Multi-Role KYC & Admin Approval Gate), R5 (10% Commission Split & Ledgers).
   - Establish module boundaries, shared types/interfaces, and milestone schedule.

3. **Phase 2 — Dual-Track Execution**:
   - **Track A (E2E Testing Track)**: E2E Testing Orchestrator builds opaque-box requirement-driven test suite (Tiers 1-4).
   - **Track B (Implementation Track)**: Sub-orchestrators for decomposed milestones (Auth & KYC Gate, Order & Packaging Workflow, Logistics & Hub Checkpoints, Dispatch & Delivery Lifecycle, Commission & Financial Ledgers, Fast-Forward Simulator).

4. **Phase 3 — Final Acceptance & Hardening**:
   - 100% E2E test pass across all 4 Tiers.
   - Phase 2 Tier 5 Adversarial coverage hardening via Challenger -> Worker -> Reviewer loop.
   - Full Forensic Audit & Completion Report.
