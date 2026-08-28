# Scope: Milestone M2 - Unified 7-Stage Order Lifecycle, Packaging & Waybill Dispatch

## Architecture
- Backend: Laravel order state transitions, delivery job creation, race condition protection (DB transaction / locking on claim).
- Frontend:
  - Buyer Checkout & Order Details (`/buyer/orders`, `/buyer/orders/{id}`) with 7-stage live tracking timeline.
  - Seller Cockpit (`/seller/orders`) with status transition controls and 4x6 thermal waybill modal with barcode/COD badge.
  - Courier Dispatch Board (`/courier/deliveries`) for claiming jobs, marking picked up, in-transit, out for delivery, and completed with COD/proof upload.

## Milestones & Status
| # | Milestone | Scope | Dependencies | Status |
|---|-----------|-------|-------------|--------|
| M2 | Unified 7-Stage Order Lifecycle | Checkout -> Pending -> Packaging -> Ready for Pickup -> Courier Claim -> Picked Up -> In Transit -> Out for Delivery -> Delivered | M1 | IN_PROGRESS |

## Interface Contracts
- Order statuses: `pending`, `packaging` (or `processing`), `ready_for_pickup`, `shipped` (or `in_transit`), `out_for_delivery`, `delivered`, `cancelled`.
- Delivery statuses: `unassigned`, `assigned`, `picked_up`, `in_transit`, `out_for_delivery`, `delivered`, `failed`.
- Endpoints:
  - `POST /api/orders` (checkout)
  - `GET /api/seller/orders`, `POST /api/seller/orders/{id}/pack`, `POST /api/seller/orders/{id}/ready-for-pickup`
  - `GET /api/courier/available-deliveries`, `POST /api/courier/deliveries/{id}/claim`, `POST /api/courier/deliveries/{id}/status`
  - `GET /api/orders/{id}`, `GET /api/orders`
