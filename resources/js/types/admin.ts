import type { PaginatedData } from '@/types';

export interface UnavailableFinance {
    key: string;
    label: string;
    amount: null;
    reason: string;
}

export interface AdminOverviewProps {
    stats: {
        totalUsers: number;
        usersByRole: Record<'buyer' | 'seller' | 'courier' | 'logistics' | 'admin', number>;
        unknownRoles: number;
        totalOrders: number;
        totalProducts: number;
        paidOrderGross: string;
        paidOrderCount: number;
        openParcels: number;
        deliveredAwaitingBuyer: number;
        companies: number;
        eligibleCompanies: number;
        hubs: number;
        eligibleHubs: number;
        onDutyEligibleRiders: number;
    };
    queues: { label: string; count: number; url: string }[];
    finance: UnavailableFinance[];
    orderStates: { status: string; label: string; count: number }[];
    workReferences: { order_id: number; number: string; status: string; order_url: string; decision_url: string }[];
    recentOrders: { id: number; order_number: string; total_amount: string; status: string; payment_status: string; buyer_name: string | null; pickup_rider_name: string | null; delivery_rider_name: string | null; url: string }[];
    recentUsers: { id: number; name: string; email: string; role: string; status: string; kyc_status: string | null; url: string }[];
}

export interface AdminLogisticsProps {
    deliveries: PaginatedData<{
        id: number;
        tracking_number: string;
        status: string;
        pickup_store_name: string | null;
        delivery_recipient_name: string | null;
        delivery_address: string | null;
        order: { id: number; order_number: string; status: string } | null;
        courier: { id: number; name: string } | null;
        assigned_rider: { id: number; name: string } | null;
        current_hub: { id: number; name: string } | null;
        order_url: string | null;
    }>;
    couriers: {
        id: number; name: string; phone: string | null; status: string; kyc_status: string | null;
        on_duty: boolean | null; network_eligible: boolean; company_id: number | null; hub_id: number | null;
        linked_open_parcels: number; live_presence: null;
    }[];
    filters: { search: string; status: string };
    statusOptions: { value: string; label: string; count: number }[];
    stats: { total: number; open: number; awaitingPickupAssignment: number; handoverStatus: number; exceptions: number; courierAccounts: number; onDutyEligibleRiders: number };
    finance: UnavailableFinance[];
}
