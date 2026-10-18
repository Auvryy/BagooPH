export type ReviewSource = {
    type: 'account' | 'shop' | 'company'; id: number; name: string;
    kind: 'restriction' | 'rejection'; status: string; reason: string;
    source_token: string; pending_request_id: number | null;
};

export type ReviewRequest = {
    id: number; subject_type: string; subject_id: number; message: string;
    status: 'pending' | 'accepted' | 'declined' | 'superseded'; created_at: string; resolved_at: string | null;
    decision: { action: string; reason: string; reviewer: string | null; reviewed_at: string } | null;
};

export const reviewStatusLabels: Record<string, string> = {
    pending: 'Waiting for review', accepted: 'Review accepted', declined: 'Request declined', superseded: 'Status changed',
};

export const reviewActionLabels: Record<string, string> = {
    reactivate: 'Reactivate', reopen_application: 'Return application to review', decline: 'Decline request', close_stale: 'Close outdated request',
};
