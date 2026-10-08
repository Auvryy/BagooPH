import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Building2, CheckCircle2, Landmark, LogOut, Receipt, Wallet } from 'lucide-react';
import { type PropsWithChildren } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import CourierLayout from '@/Layouts/CourierLayout';
import BagooLogo from '@/Components/BagooLogo';
import { courierPath } from '@/utils/courier';
import { PageProps } from '@/types';

export type CashMode = 'admin' | 'hub' | 'courier' | 'recovery';
export type CashStatus = 'unverified' | 'reconciled' | 'difference' | 'awaiting_receipt' | 'at_platform' | 'cash_held';
export interface CashHolder { user_id: number; name: string; stage: string; amount_cents: number; restricted: boolean; can_offer: boolean }
export interface CashPending { reference: string; holder_id: number; recipient_id: number; holder_name: string; recipient_name: string; from_stage: string; to_stage: string; amount_cents: number; can_receive: boolean; can_cancel: boolean }
export interface CashHistory { reference: string; sequence: number; event_type: string; amount_cents: number; expected_cents: number; received_cents: number; discrepancy_cents: number; actor_name: string; actor_role: string; from_name: string | null; to_name: string | null; from_user_id: number | null; to_user_id: number | null; from_stage: string | null; to_stage: string | null; source_reference: string | null; evidence_reference: string | null; reason: string | null; created_at: string }
export interface CashDifference { difference_cents: number; holder_id: number; recipient_id: number; from_stage: string; to_stage: string; resolved_by: string | null; recovery_evidence: string | null }
export interface CashAccount {
    id: number; reference: string; order_number: string; tracking_number: string; hub_name: string; company_name: string;
    source_kind: string; status: CashStatus; version: number; expected_cents: number; product_subtotal_cents: number; discount_cents: number;
    shipping_cents: number; collected_cents: number; reconciled_cents: number; excess_cents: number; holders: CashHolder[]; pending: CashPending | null;
    recovery: { holder_id: number; recipient_id: number; expires_at: string } | null; updated_at: string;
}
export interface CashDetail extends CashAccount {
    history: CashHistory[]; discrepancies: Record<string, CashDifference>;
    excess_holders: { name: string; stage: string; amount_cents: number }[];
    recipients: { hub: { id: number; name: string }[]; platform: { id: number; name: string }[] }; can_authorize_recovery: boolean;
}
export const cashButton = 'inline-flex min-h-12 items-center justify-center gap-2 rounded-[8px] border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 transition-colors hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42] disabled:cursor-not-allowed disabled:opacity-60';
export const cashPrimary = `${cashButton} !border-[#E00D42] !bg-[#E00D42] !text-white hover:!bg-[#C20836]`;
export const cashInput = 'mt-2 min-h-12 w-full rounded-[8px] border border-slate-300 bg-white px-3 py-3 text-base text-slate-900 placeholder:text-slate-500 focus:border-[#E00D42] focus:ring-[#E00D42]';
export const cashSurface = 'rounded-[8px] border border-slate-300 bg-white shadow-[0_2px_8px_rgba(15,23,42,0.035)]';
export const cashMoney = (cents: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(cents / 100);
export const cashDate = (date: string) => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(new Date(date));
export const cashEventLabel: Record<string, string> = { rider_collection: 'Rider collection', counter_collection: 'Counter collection', handover_offered: 'Handover offered', handover_received: 'Cash received', handover_cancelled: 'Handover cancelled', adjustment: 'Difference reviewed', platform_reconciled: 'Platform reconciliation', legacy_review: 'Older record reviewed', recovery_authorized: 'Recovery handover authorized' };

export function CashFrame({ children, mode, title, subtitle, isOnline = false }: PropsWithChildren<{ mode: CashMode; title: string; subtitle: string; isOnline?: boolean }>) {
    const { auth, flash } = usePage<PageProps>().props;
    const content = <><Head title={title} />{children}</>;
    if (mode === 'courier') return <CourierLayout title={title} subtitle={subtitle} isOnline={isOnline} pageLabel="Cash handover" actions={<Link href={courierPath('/deliveries')} className={cashButton}><ArrowLeft className="h-4 w-4" aria-hidden="true" />Deliveries</Link>}>{content}</CourierLayout>;
    if (mode !== 'recovery') return <DashboardLayout title={title} subtitle={subtitle}>{content}</DashboardLayout>;
    return <div className="min-h-screen bg-[#FFFAFB] font-sans text-slate-900"><Head title={title} /><header className="border-b border-slate-300 bg-white"><div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-4"><div className="flex items-center gap-3"><BagooLogo className="h-10 w-10" /><div><p className="font-bold">BagooPH</p><p className="text-xs text-slate-600">Recorded cash handover</p></div></div><button className={cashButton} onClick={() => router.post(route('logout'))}><LogOut className="h-4 w-4" aria-hidden="true" />Sign out</button></div></header><main className="mx-auto max-w-6xl space-y-5 px-4 py-6 sm:py-8"><div><p className="text-sm text-slate-600">{auth.user?.name}</p><h1 className="mt-1 text-2xl font-bold">{title}</h1><p className="mt-2 text-sm text-slate-600">{subtitle}</p></div><p className="rounded-[8px] border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">This access covers existing cash responsibilities. Your account and assignment restrictions remain in place.</p>{flash?.success && <p role="status" className="rounded-[8px] border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-800">{flash.success}</p>}{content}</main></div>;
}

export function CashBadge({ status }: { status: CashStatus }) {
    const labels: Record<CashStatus, string> = { unverified: 'Collection unverified', reconciled: 'Reconciled', difference: 'Difference to review', awaiting_receipt: 'Awaiting receipt', at_platform: 'Ready for review', cash_held: 'Cash held' };
    const colors = status === 'reconciled' ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : status === 'difference' || status === 'unverified' ? 'border-amber-300 bg-amber-50 text-amber-900' : status === 'at_platform' ? 'border-rose-300 bg-rose-50 text-[#C20836]' : 'border-slate-300 bg-slate-50 text-slate-700';
    return <span className={`inline-flex items-center gap-1.5 rounded-[8px] border px-2.5 py-1 text-xs font-semibold ${colors}`}>{status === 'reconciled' && <CheckCircle2 className="h-3.5 w-3.5" aria-hidden="true" />}{labels[status]}</span>;
}

export function CashJourney({ account }: { account: CashAccount }) {
    const at = (stage: string) => account.holders.filter(holder => holder.stage === stage).reduce((sum, holder) => sum + holder.amount_cents, 0);
    const steps = [
        { label: 'Collected', Icon: Receipt, amount: account.collected_cents, complete: account.collected_cents > 0 },
        { label: 'With riders', Icon: Wallet, amount: at('rider'), complete: account.collected_cents > 0 && at('rider') === 0 },
        { label: 'With hub', Icon: Building2, amount: at('hub'), complete: at('platform') === account.expected_cents },
        { label: 'At platform', Icon: Landmark, amount: at('platform'), complete: account.reconciled_cents > 0 },
    ];
    return <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{steps.map(({ label, Icon, amount, complete }) => <div key={label} className="rounded-[8px] border border-slate-300 bg-slate-50/60 p-3"><div className="flex items-center justify-between gap-2"><span className="text-xs font-semibold text-slate-600">{label}</span><Icon className={`h-4 w-4 ${complete ? 'text-emerald-700' : 'text-slate-500'}`} aria-hidden="true" /></div><p className="mt-2 text-lg font-bold tabular-nums">{cashMoney(amount)}</p></div>)}</div>;
}

export function CashLink({ href, children }: PropsWithChildren<{ href: string }>) {
    return <Link href={href} className="inline-flex min-h-12 items-center gap-2 rounded-[8px] px-3 py-2 text-sm font-semibold text-[#C20836] hover:bg-rose-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]">{children}<ArrowRight className="h-4 w-4" aria-hidden="true" /></Link>;
}
