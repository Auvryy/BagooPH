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
    status: 'pending' | 'withdrawn' | 'offered' | 'accepted' | 'rejected' | 'declined' | 'cancelled' | 'expired';
    version: number;
    note: string | null;
    sharing_acknowledged: boolean;
    shared_profile: SharedRiderProfile;
    submitted_at: string;
    withdrawn_at: string | null;
    can_withdraw?: boolean;
    withdrawal_token?: string;
    rider_eligible?: boolean;
    effective_status?: CompanyJoinRequest['status'];
    can_accept?: boolean;
    offer?: CompanyAssignmentOffer | null;
    offers?: (CompanyAssignmentOffer & { state: string })[];
    decisions?: { action: string; reason: string | null; at: string; offer_id: string | null }[];
    accepted_at?: string | null;
    decision_token?: string;
}

export interface CompanyAssignmentOffer {
    id: string;
    reference: string;
    version: number;
    hub_id: string;
    vehicle_id: string | null;
    barangay: string | null;
    terms: string;
    terms_hash: string;
    assignment: { company: string; hub: string; hub_code: string; vehicle: string };
    issued_at: string;
    expires_at: string;
}

export interface CompanyRiderAssignment {
    id: string;
    name: string | null;
    email: string | null;
    hub_id: string | null;
    hub: string | null;
    vehicle_id: string | null;
    vehicle: string;
    barangay: string | null;
    on_duty: boolean;
    eligible: boolean;
    provenance: string;
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
    offers_available?: boolean;
    hubs?: { id: string; name: string; tier: string; barangays: string[] }[];
    vehicles?: { id: string; hub_id: string; plate: string }[];
    roster?: CompanyRequestPage<CompanyRiderAssignment> | null;
};
