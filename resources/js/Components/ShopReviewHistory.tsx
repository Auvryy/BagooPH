import React from 'react';

export interface ShopDecision {
    id: number;
    decision: string;
    reason: string | null;
    reviewer: string;
    reviewed_at: string;
    shop_status: string;
    source: string;
    documents: Record<string, string | null>;
}

export default function ShopReviewHistory({ decisions }: { decisions: ShopDecision[] }) {
    if (!decisions.length) return <p className="text-sm text-slate-500">No recorded shop decision. Existing activity is not proof of approval.</p>;

    return <ol className="space-y-3">
        {decisions.map(decision => <li key={decision.id} className="rounded-lg border border-slate-300 p-3 text-sm">
            <p className="font-semibold capitalize">{decision.decision} · {decision.reviewer}</p>
            <p className="text-slate-500">{new Date(decision.reviewed_at).toLocaleString('en-PH', { timeZone: 'Asia/Manila' })} · {decision.source}</p>
            <p>Shop activity after review: {decision.shop_status}</p>
            {decision.reason && <p className="mt-2 whitespace-pre-wrap">{decision.reason}</p>}
            <div className="mt-2 flex gap-4">
                {Object.entries(decision.documents).map(([kind, href]) => href && <a key={kind} href={href} target="_blank" rel="noopener noreferrer" className="text-[#E00D42] underline">Reviewed {kind === 'id' ? 'identity document' : 'business permit'}</a>)}
            </div>
        </li>)}
    </ol>;
}
