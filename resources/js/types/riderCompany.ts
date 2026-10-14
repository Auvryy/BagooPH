export interface CompanyRequestPage<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

export interface SharedRiderProfile {
    name: string;
    email: string;
    phone: string | null;
    city: string | null;
    province: string | null;
    vehicle_type: string | null;
    approval_status: string;
}

export interface RiderCompany {
    id: string;
    name: string;
    logo: string | null;
    intake_version: number;
    areas: { city: string | null; province: string | null }[];
}

export interface CompanyJoinRequest {
    id: string;
    reference: string;
    company: { id: string; name: string };
    status: 'pending' | 'withdrawn';
    version: number;
    note: string | null;
    sharing_acknowledged: boolean;
    shared_profile: SharedRiderProfile;
    submitted_at: string;
    withdrawn_at: string | null;
    can_withdraw?: boolean;
    withdrawal_token?: string;
    rider_eligible?: boolean;
}

export type CompanyJoining = { available: false } | {
    available: true;
    can_apply: boolean;
    blocked_reason: string | null;
    filters: { company_search: string; company_province: string; company_page: number; request_page: number };
    provinces: string[];
    companies: CompanyRequestPage<RiderCompany>;
    requests: CompanyRequestPage<CompanyJoinRequest>;
    sharing_summary: SharedRiderProfile;
    request_token: string;
};

export type CompanyRiderIntake = { available: false } | {
    available: true;
    enabled: boolean;
    version: number;
    history_available: boolean;
    has_eligible_hub: boolean;
    request_token: string;
    requests: CompanyRequestPage<CompanyJoinRequest>;
};
