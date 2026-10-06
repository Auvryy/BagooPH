import React from 'react';
import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { RecordedValues, recordLabel, governanceTime } from '@/Components/GovernanceRecord';

interface Context {
    account: { id: number; name: string; role: string; status: string | null; kyc_status: string | null; email?: string; phone?: string | null; birthday?: string | null; closed_at?: string | null };
    record_state: string;
    approval_provenance: string;
    identity_provenance: string;
    scope: { companies?: unknown[]; handlers?: unknown[]; courier?: unknown } | null;
    shops: { id: number; name: string; status: string; review_status: string; root_category_id: number | null; eligible: boolean; history_url: string }[];
    work_count: number;
    work: unknown[];
    history_url: string;
    closure_url: string | null;
}
const provenance: Record<string, string> = {
    controlled_admin_access: 'Controlled platform admin access', recorded_application_review: 'A recorded application review exists',
    legacy_without_recorded_review: 'Legacy account; no application review record exists', recorded_reviewed_correction: 'A reviewed identity correction is recorded',
    current_account_unavailable: 'Current account no longer exists', retained_closure_only: 'Only the retained closure identifies this account',
};

export default function AccountContext({ context, backUrl }: { context: Context; backUrl: string }) {
    const { account } = context;
    return <DashboardLayout title="Account context" subtitle={`${account.name} · Account #${account.id}`} actions={<Link href={backUrl} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold">Back to accounts</Link>}>
        <Head title="Account context — Bagoo Admin" />
        <div className="space-y-6">
            <section className="rounded-xl border border-slate-300 bg-white p-6">
                <h2 className="font-semibold text-slate-900">Current account and identity</h2>
                <p className="mt-2 text-sm text-slate-500">Read only · {recordLabel(context.record_state)}. Registration approval, activity and rider duty are separate states.</p>
                <dl className="mt-5 grid gap-5 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    {[
                        ['Registered role', recordLabel(account.role)], ['Account approval', account.role === 'admin' ? 'Controlled admin access' : (account.kyc_status ? recordLabel(account.kyc_status) : 'Current state unavailable')],
                        ['Activity', account.status ? recordLabel(account.status) : 'Current state unavailable'], ['Email', account.email ?? 'Not available'],
                        ['Phone', account.phone ?? 'Not recorded'], ['Birthday', account.birthday ?? 'Not recorded'],
                    ].map(([label, value]) => <div key={label}><dt className="text-slate-500">{label}</dt><dd className="mt-1 break-words font-semibold text-slate-800">{value}</dd></div>)}
                </dl>
                <div className="mt-5 space-y-1 text-sm text-slate-600"><p>Approval provenance: {provenance[context.approval_provenance] ?? recordLabel(context.approval_provenance)}</p><p>Identity provenance: {provenance[context.identity_provenance] ?? recordLabel(context.identity_provenance)}</p>{account.closed_at && <p>Closed: {governanceTime(account.closed_at)}</p>}</div>
                <div className="mt-5 flex flex-wrap gap-4 text-sm font-semibold text-[#E00D42]"><Link href={context.history_url} className="hover:underline">View account decision history</Link>{context.closure_url && <Link href={context.closure_url} className="hover:underline">View retained closure record</Link>}</div>
            </section>
            <section className="rounded-xl border border-slate-300 bg-white p-6"><h2 className="mb-5 font-semibold text-slate-900">Shops and reviewed category scope</h2>{context.shops.length ? <div className="grid gap-4 lg:grid-cols-2">{context.shops.map((shop) => <div key={shop.id} className="rounded-lg border border-slate-300 p-4"><h3 className="font-semibold">{shop.name} · #{shop.id}</h3><p className="mt-2 text-sm text-slate-600">Approval: {recordLabel(shop.review_status)} · Activity: {recordLabel(shop.status)}</p><p className="text-sm text-slate-600">Root category: {shop.root_category_id ? `#${shop.root_category_id}` : 'Not assigned'} · Can sell: {shop.eligible ? 'Yes' : 'No'}</p><Link href={shop.history_url} className="mt-3 inline-block text-sm font-semibold text-[#E00D42] hover:underline">View shop decision history</Link></div>)}</div> : <p className="text-sm text-slate-500">No current shops are recorded for this account.</p>}</section>
            <section className="rounded-xl border border-slate-300 bg-white p-6"><h2 className="mb-5 font-semibold text-slate-900">Company, handler placement and rider duty</h2>{context.scope ? <RecordedValues value={{ companies: context.scope.companies, handlers: context.scope.handlers, courier: context.scope.courier }} /> : <p className="text-sm text-slate-500">Current placement is unavailable after safe closure.</p>}</section>
            <section className="rounded-xl border border-slate-300 bg-white p-6"><h2 className="font-semibold text-slate-900">Current work references</h2><p className="mb-5 mt-2 text-sm text-slate-600">{context.work_count} current references. {context.work_count > 10 ? 'Showing the first 10.' : ''} These links and counts do not change assignments, custody or payments.</p><RecordedValues value={context.work} /></section>
        </div>
    </DashboardLayout>;
}
