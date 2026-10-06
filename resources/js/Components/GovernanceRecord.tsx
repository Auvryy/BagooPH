import React from 'react';

export interface GovernanceEvent {
    id: number;
    source: string;
    source_label: string;
    subject: { type: string; id: number; name: string | null; name_provenance: string };
    actor: { id: number; name: string; role: string | null };
    action: string;
    reason: string | null;
    occurred_at: string;
    before: Record<string, unknown>;
    after: Record<string, unknown>;
    prior_decision: { source: string; id: number } | null;
    documents: { kind: string; url: string }[];
    context_url: string | null;
    detail_url: string;
}

const labels: Record<string, string> = {
    kyc_status: 'Account approval', status: 'Activity status', is_available: 'On duty',
    compliance_restricted: 'Platform restriction', root_category_id: 'Root category ID',
    logistics_company_id: 'Company ID', assigned_hub_id: 'Assigned hub ID',
    is_active: 'Active', role: 'Registered role', review_status: 'Shop approval',
    price: 'Product price (PHP)', total: 'Recorded order total (PHP)',
};

export function recordLabel(value: string): string {
    return labels[value] ?? value.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
}

export function governanceTime(value: string | null | undefined): string {
    return value ? new Date(value).toLocaleString('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' }) : 'Not recorded';
}

export function RecordedValues({ value }: { value: unknown }): React.ReactElement {
    if (value === null || value === undefined) return <span className="text-slate-500">Not recorded</span>;
    if (typeof value === 'boolean') return <span>{value ? 'Yes' : 'No'}</span>;
    if (Array.isArray(value)) {
        return value.length ? <div className="space-y-3">{value.map((item, index) => <div key={index} className="rounded-lg border border-slate-300 p-3"><RecordedValues value={item} /></div>)}</div> : <span className="text-slate-500">No entries recorded</span>;
    }
    if (typeof value === 'object') {
        const entries = Object.entries(value);
        return entries.length ? <dl className="space-y-3">{entries.map(([key, item]) => <div key={key}>
            <dt className="mb-1 text-xs font-semibold text-slate-500">{recordLabel(key)}</dt>
            <dd className="break-words text-sm text-slate-800"><RecordedValues value={item} /></dd>
        </div>)}</dl> : <span className="text-slate-500">No values recorded</span>;
    }
    return <span className="whitespace-pre-wrap break-words">{String(value)}</span>;
}
