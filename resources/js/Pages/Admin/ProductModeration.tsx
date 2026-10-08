import { refreshPage } from '@/utils/accountRequests';
import React, { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout';

interface Subject {
    id: number; name: string; source_token: string; actions: string[]; note: string;
    state: {
        product: { status: string; compliance_restricted: boolean; price: string; stock: number };
        shop: { name: string; status: string; review_status: string | null };
        seller: { status: string; kyc_status: string } | null;
        shop_eligible: boolean; category_eligible: boolean;
    };
    history: { id: number; action: string; reason: string; actor: string; decided_at: string; before_restricted: boolean; after_restricted: boolean; prior_decision_id: number | null }[];
}

export default function ProductModeration({ subject, endpoint, backUrl }: { subject: Subject; endpoint: string; backUrl: string }) {
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [message, setMessage] = useState('');
    const [errors, setErrors] = useState<string[]>([]);
    const [conflict, setConflict] = useState(false);
    const action = subject.actions[0];
    const blockedParent = action === 'reinstate' && (!subject.state.shop_eligible || !subject.state.category_eligible);
    useEffect(() => { setReason(''); setMessage(''); setErrors([]); setConflict(false); }, [subject.source_token]);

    const submit = async (event: React.FormEvent) => {
        event.preventDefault(); setProcessing(true); setMessage(''); setErrors([]);
        try {
            await axios.post(endpoint, { action, reason, source_token: subject.source_token }, { headers: { Accept: 'application/json' } });
            await refreshPage();
        } catch (error) {
            if (axios.isAxiosError(error)) {
                setMessage(error.response?.data?.message ?? 'The compliance decision could not be saved.');
                setErrors(Object.values(error.response?.data?.errors ?? {}).flat() as string[]);
                setConflict(error.response?.status === 409);
            } else setMessage(error instanceof Error ? error.message : 'The compliance decision could not be saved. Please try again.');
        } finally { setProcessing(false); }
    };

    return <DashboardLayout title="Review product compliance" subtitle={subject.name}>
        <Head title="Product review — Bagoo Admin" />
        <Link href={backUrl} className="mb-5 inline-block text-sm font-semibold text-[#E00D42]">Back to products</Link>
        <div className="space-y-5">
            <section className="rounded-xl border border-slate-300 bg-white p-5">
                <h2 className="font-semibold text-slate-900">Current listing and selling scope</h2>
                <dl className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div><dt className="text-xs text-slate-500">Seller listing status</dt><dd className="mt-1 capitalize">{subject.state.product.status}</dd></div>
                    <div><dt className="text-xs text-slate-500">Platform compliance</dt><dd className="mt-1 font-semibold">{subject.state.product.compliance_restricted ? 'Restricted' : 'No restriction'}</dd></div>
                    <div><dt className="text-xs text-slate-500">Seller approval / activity</dt><dd className="mt-1 capitalize">{subject.state.seller?.kyc_status.replaceAll('_', ' ') ?? 'Unavailable'} / {subject.state.seller?.status ?? 'Unavailable'}</dd></div>
                    <div><dt className="text-xs text-slate-500">Shop and category</dt><dd className="mt-1">{subject.state.shop.name}<p className="mt-1 text-xs text-slate-600">{subject.state.shop_eligible && subject.state.category_eligible ? 'Eligible selling scope' : 'Selling prerequisites unresolved'}</p></dd></div>
                </dl>
                <p className="mt-4 text-sm text-slate-600">{subject.note}</p>
            </section>
            <form onSubmit={submit} className="rounded-xl border border-slate-300 bg-white p-5">
                <h2 className="font-semibold">{action === 'reinstate' ? 'Review reinstatement' : 'Record compliance removal'}</h2>
                {action ? <>
                    <label htmlFor="moderation-reason" className="mt-4 block text-sm font-medium">Reason and feedback for the seller</label>
                    <textarea id="moderation-reason" value={reason} onChange={event => setReason(event.target.value)} rows={4} maxLength={1000} className="mt-2 w-full rounded-lg border border-slate-300 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]" placeholder="Explain what needs correction or why the listing can be reinstated." />
                    {blockedParent && <p className="mt-3 text-sm text-amber-800">Resolve the seller, shop and category prerequisites before reinstating this listing.</p>}
                    <button disabled={processing || conflict || blockedParent || reason.trim().length < 5} className="mt-4 rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{processing ? 'Saving decision...' : action === 'reinstate' ? 'Reinstate compliance eligibility' : 'Remove from new purchases'}</button>
                </> : <p className="mt-3 text-sm text-amber-800">This listing state has no permitted routine action. An archived restricted listing must return to a valid seller listing state before reinstatement review.</p>}
                {message && <p role="alert" className="mt-4 text-sm text-rose-700">{message}</p>}
                {errors.map((error, index) => <p key={index} role="alert" className="mt-2 text-sm text-rose-700">{error}</p>)}
                {conflict && <button type="button" onClick={() => router.reload({ only: ['subject'] })} className="mt-3 rounded-lg border border-slate-300 px-3 py-2 text-sm">Reload current review</button>}
            </form>
            <section className="rounded-xl border border-slate-300 bg-white p-5">
                <h2 className="font-semibold">Recorded compliance decisions</h2>
                {subject.history.length === 0 ? <p className="mt-3 text-sm text-slate-500">No recorded compliance decisions. Seller draft or archive status alone does not establish an admin restriction.</p> : <div className="mt-4 space-y-4">{subject.history.map(item => <article key={item.id} className="border-b border-slate-300 pb-4 last:border-0">
                    <p className="text-sm font-semibold">{item.action === 'remove' ? 'Compliance removal' : 'Compliance reinstatement'} · {item.before_restricted ? 'Restricted' : 'Unrestricted'} to {item.after_restricted ? 'restricted' : 'unrestricted'}</p>
                    <p className="mt-2 whitespace-pre-line text-sm text-slate-700">{item.reason}</p>
                    <p className="mt-2 text-xs text-slate-500">{item.actor} · {new Date(item.decided_at).toLocaleString('en-PH', { timeZone: 'Asia/Manila' })}{item.prior_decision_id ? ` · Previous decision #${item.prior_decision_id}` : ''}</p>
                </article>)}</div>}
            </section>
        </div>
    </DashboardLayout>;
}
