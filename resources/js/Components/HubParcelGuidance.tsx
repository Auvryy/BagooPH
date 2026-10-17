import { Link } from '@inertiajs/react';

const actionLabels: Record<string, string> = { scan: 'Open scanner', manifest: 'Open manifests', counter: 'Open counter', sort: 'Open parcel sorting', assign: 'Assign rider', review: 'Open delivery review', recovery: 'Open recovery review' };

export interface ParcelGuidance {
    stage: string;
    order_status: string;
    custodian: string;
    custody_recorded_at: string | null;
    next_step: string;
    responsible: string;
    blocker: string | null;
    action: string;
    can_act: boolean;
    href: string | null;
    eligible_rider_ids: number[];
    ready_for_collection: boolean;
}

export function HubParcelState({ guidance }: { guidance: ParcelGuidance }) {
    return (
        <div className="space-y-1 text-xs font-sans text-slate-700">
            <span className="inline-block rounded-xs border border-slate-300 bg-slate-50 px-2 py-1 font-semibold text-slate-900">{guidance.stage}</span>
            <p><span className="text-slate-500">Order:</span> {guidance.order_status}</p>
            <p><span className="text-slate-500">Recorded custodian:</span> {guidance.custodian}</p>
        </div>
    );
}

export function HubParcelNextStep({ guidance, hideLink = false }: { guidance: ParcelGuidance; hideLink?: boolean }) {
    return (
        <div className="space-y-1 text-xs font-sans text-slate-700">
            <p className="font-semibold text-slate-900">{guidance.next_step}</p>
            <p><span className="text-slate-500">Responsible:</span> {guidance.responsible}</p>
            {guidance.blocker && <p className="text-amber-800">{guidance.blocker}</p>}
            {!hideLink && guidance.can_act && guidance.href && (
                <Link href={guidance.href} className="inline-flex rounded-xs border border-slate-300 bg-white px-3 py-2 font-semibold text-[#E00D42] hover:bg-rose-50">
                    {actionLabels[guidance.action] ?? 'View next step'}
                </Link>
            )}
        </div>
    );
}
