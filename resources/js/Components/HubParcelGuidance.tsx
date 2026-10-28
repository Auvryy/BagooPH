import { Link } from '@inertiajs/react';
import { ArrowRight, LockKeyhole, PackageCheck, UserRound } from 'lucide-react';

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
        <div className="space-y-2 text-xs font-sans text-slate-700">
            <span className="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-slate-50 px-2 py-1 font-semibold text-slate-800"><PackageCheck className="h-3 w-3 text-slate-400" />{guidance.stage}</span>
            <p className="text-[11px]"><span className="text-slate-500">Order:</span> {guidance.order_status}</p>
            <p className="flex items-start gap-1.5 text-[11px] leading-5"><UserRound className="mt-0.5 h-3 w-3 shrink-0 text-slate-400" /><span><span className="text-slate-500">With:</span> {guidance.custodian}</span></p>
        </div>
    );
}

export function HubParcelNextStep({ guidance, hideLink = false }: { guidance: ParcelGuidance; hideLink?: boolean }) {
    return (
        <div className="space-y-2 text-xs font-sans text-slate-700">
            <p className="flex items-start gap-2 font-semibold leading-5 text-slate-900"><ArrowRight className="mt-0.5 h-3.5 w-3.5 shrink-0 text-[#E00D42]" />{guidance.next_step}</p>
            <p className="text-[11px] leading-5"><span className="text-slate-500">Next person:</span> {guidance.responsible}</p>
            {guidance.blocker && <p className="flex items-start gap-1.5 rounded-lg border border-amber-200 bg-amber-50/60 p-2.5 text-[11px] leading-5 text-amber-800"><LockKeyhole className="mt-0.5 h-3 w-3 shrink-0" />{guidance.blocker}</p>}
            {!hideLink && <HubParcelAction guidance={guidance} />}
        </div>
    );
}

export function HubParcelAction({ guidance }: { guidance: ParcelGuidance }) {
    if (!guidance.can_act || !guidance.href) return null;
    return <Link href={guidance.href} className="inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50/50 px-3 py-2 text-xs font-semibold text-[#C20836] hover:bg-rose-50">{actionLabels[guidance.action] ?? 'View next step'}<ArrowRight className="h-3.5 w-3.5" /></Link>;
}
