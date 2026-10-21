import type { PaginatedData } from '@/types';

export interface AccountSession {
    id: string;
    channel: 'web' | 'rider';
    method: string;
    device: string;
    device_type: 'computer' | 'phone' | 'tablet' | 'unknown';
    network: string | null;
    signed_in_at: string | null;
    last_seen_at: string | null;
    ended_at: string | null;
    active: boolean;
    current: boolean;
    status: 'active' | 'signed_out' | 'expired' | 'ended';
}

export interface AccountSessionProps {
    sessions: PaginatedData<AccountSession>;
    signIns: PaginatedData<AccountSession>;
    lastSignIn: AccountSession | null;
    currentSessionId: string;
    sessionOwnerId: number;
    canConfirmByEmail: boolean;
    canUsePortal: boolean;
    isOnline: boolean;
    historyDays: number;
    scope?: { company?: string | null; hub?: string | null; hub_code?: string | null; barangay?: string | null };
}
