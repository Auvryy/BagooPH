import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, AlertTriangle } from 'lucide-react';
import { type PropsWithChildren } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import CourierLayout from '@/Layouts/CourierLayout';
import { CourierFieldError, courierButton, courierInput, courierPrimary } from '@/Components/CourierUI';
import { PageProps } from '@/types';

export { CourierFieldError as IssueError };
export const issueButton = `${courierButton} !border-slate-300`;
export const issuePrimary = courierPrimary;
export const issueInput = `${courierInput} !border-slate-300`;
export const issueSurface = 'rounded-[8px] border border-slate-300 bg-white p-5 shadow-[0_2px_8px_rgba(15,23,42,0.035)] sm:p-6';

export interface IssueSummary {
    id: number; reference: string; state: 'pending_review' | 'needs_recovery' | 'dismissed'; kind: 'missed_ready' | 'missed_scan';
    tracking_number: string; order_number: string; reporter_name: string; created_at: string;
}
export interface ParcelSnapshot {
    delivery_status: string; order_status: string; custody_label: string; checkpoint_reference: string | null;
}
export interface IssueDetail extends IssueSummary {
    version: number; reported_holder: string; reported_location: string; notes: string;
    original: ParcelSnapshot; current: ParcelSnapshot; source_token: string; can_review: boolean; evidence_url: string;
    events: Array<{ id: number; type: 'reported' | 'confirmed' | 'dismissed'; actor_name: string; actor_role: string; notes: string; created_at: string; parcel_snapshot: ParcelSnapshot }>;
}

export const issueKind = { missed_ready: 'Readiness step missed', missed_scan: 'Handover scan missed' };
export const issueState = { pending_review: 'Awaiting review', needs_recovery: 'Needs recovery', dismissed: 'Dismissed' };
export const issueDate = (date: string) => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(new Date(date));
const stage = (value: string) => value.replaceAll('_', ' ').replace(/^./, letter => letter.toUpperCase());

export function IssueFrame({ children, title, isOnline = false }: PropsWithChildren<{ title: string; isOnline?: boolean }>) {
    const { auth } = usePage<PageProps>().props;
    const content = <><Head title={title} />{children}</>;
    const subtitle = 'Report what happened and keep the original parcel history clear.';
    if (auth.user?.role === 'courier') return <CourierLayout title={title} subtitle={subtitle} isOnline={isOnline} pageLabel="Handover issues">{content}</CourierLayout>;
    return <DashboardLayout title={title} subtitle={subtitle}>{content}</DashboardLayout>;
}

export function IssueBadge({ state }: { state: IssueSummary['state'] }) {
    return <span className={`inline-flex rounded-[8px] border px-3 py-1.5 text-xs font-semibold ${state === 'dismissed' ? 'border-slate-300 bg-slate-50 text-slate-700' : 'border-amber-300 bg-amber-50 text-amber-900'}`}>{issueState[state]}</span>;
}

export function IssueNotice() {
    return <div className="flex gap-3 rounded-[8px] border border-amber-300 bg-amber-50 p-4 text-sm leading-relaxed text-amber-950"><AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" /><p>Reporting or reviewing an issue does not record a parcel handover. Do not repeat a physical transfer just to recreate a missing scan. Your company must verify what happened before arranging recovery.</p></div>;
}

export function RecordedParcel({ snapshot, title }: { snapshot: ParcelSnapshot; title: string }) {
    return <section className={issueSurface}><h2 className="font-bold">{title}</h2><dl className="mt-4 space-y-3 text-sm"><div><dt className="text-slate-500">Recorded custody</dt><dd className="mt-1 font-semibold">{snapshot.custody_label}</dd></div><div><dt className="text-slate-500">Parcel stage</dt><dd className="mt-1">{stage(snapshot.delivery_status)}</dd></div><div><dt className="text-slate-500">Order stage</dt><dd className="mt-1">{stage(snapshot.order_status)}</dd></div><div><dt className="text-slate-500">Original custody record</dt><dd className="mt-1 break-words">{snapshot.checkpoint_reference ?? 'No custody scan recorded'}</dd></div></dl></section>;
}

export function IssueBack() {
    return <Link href="/handover-issues" className={issueButton}><ArrowLeft className="h-4 w-4" aria-hidden="true" />Handover issues</Link>;
}
