import { refreshPage } from '@/utils/accountRequests';
import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';

type Work = {
    order_id: number; order_number: string; order_status: string | null;
    parcel: { id: number; status: string | null; courier_id: number | null; assigned_rider_id: number | null; current_hub_id: number | null } | null;
    cash: { method: string; expected_amount: string; evidence: string };
};

export type RestrictionSubject = {
    type: string; id: number; name: string; role?: string; status: string; approval?: string;
    source_token: string; actions: string[]; affected_work: Work[]; legacy_activity: boolean; cash_note: string;
    parents?: { label: string; name: string; status: string; eligible: boolean }[];
    reactivation_status?: string; reactivation_mode_recorded?: boolean; work_note?: string | null;
    state?: { courier?: { is_available: boolean; assigned_hub_id: number | null; vehicle_id: number | null } | null };
    history: { id: number; action: string; reason: string; actor: string; decided_at: string; before_status: string; after_status: string; affected_work_count: number }[];
};

export default function RestrictionReview({ subject, endpoint }: { subject: RestrictionSubject; endpoint: string }) {
    const [action, setAction] = useState(subject.actions[0] ?? '');
    const [reason, setReason] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [message, setMessage] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [conflict, setConflict] = useState(false);

    const submit = async (event: React.FormEvent) => {
        event.preventDefault();
        setProcessing(true); setMessage(''); setErrors({}); setConflict(false);
        try {
            await axios.post(endpoint, { action, reason, affected_work_confirmed: confirmed, source_token: subject.source_token }, { headers: { Accept: 'application/json' } });
            await refreshPage();
        } catch (error) {
            if (axios.isAxiosError(error)) {
                setErrors(error.response?.data?.errors ?? {});
                setConflict(error.response?.status === 409);
                setMessage(error.response?.data?.message ?? 'The decision could not be saved. Review the current activity and try again.');
            } else setMessage(error instanceof Error ? error.message : 'The decision could not be saved. Please try again.');
        } finally { setProcessing(false); }
    };

    return <div className="space-y-6">
        <section className="rounded-xl border border-slate-300 bg-white p-5">
            <h2 className="text-lg font-semibold text-slate-900">{subject.name}</h2>
            <div className="mt-4 grid gap-4 sm:grid-cols-3">
                <div><p className="text-xs text-slate-500">Activity</p><p className="mt-1 font-semibold capitalize">{subject.status || 'Unknown'}</p></div>
                <div><p className="text-xs text-slate-500">{subject.type === 'account' ? 'Registered role' : 'Resource'}</p><p className="mt-1 font-semibold capitalize">{subject.role ?? subject.type}</p></div>
                <div><p className="text-xs text-slate-500">Approval</p><p className="mt-1 font-semibold capitalize">{subject.role === 'admin' ? 'Controlled admin access' : subject.approval?.replaceAll('_', ' ') ?? 'Parent eligibility required'}</p></div>
            </div>
            {subject.legacy_activity && <p className="mt-4 text-sm text-slate-600">No recorded activity decision exists yet. This does not establish a past approval or restriction reason.</p>}
            {subject.state?.courier && <p className="mt-4 text-sm text-slate-600">Rider duty: {subject.state.courier.is_available ? 'On duty' : 'Off duty'}. Assigned hub: {subject.state.courier.assigned_hub_id ?? 'Unassigned'}. Vehicle: {subject.state.courier.vehicle_id ?? 'Personal vehicle'}. This decision preserves those assignments and duty.</p>}
            {subject.parents?.map(parent => <div key={parent.label} className="mt-3 rounded-lg border border-slate-300 p-3 text-sm"><span className="font-semibold">{parent.label}: {parent.name}</span><p className="mt-1 capitalize">{parent.status.replaceAll('_', ' ')} — {parent.eligible ? 'Eligible' : 'Unavailable for new work'}</p></div>)}
        </section>

        <section className="rounded-xl border border-slate-300 bg-white p-5">
            <h2 className="font-semibold">Work requiring attention ({subject.affected_work.length})</h2>
            <p className="mt-2 text-sm text-slate-600">A restriction preserves each order, parcel assignment and recorded handover. The decision records who is responsible for arranging authorized recovery; this action does not move a parcel or money.</p>
            <p className="mt-2 text-sm text-amber-800">{subject.cash_note}</p>
            {subject.work_note && <p className="mt-2 text-sm text-slate-600">{subject.work_note}</p>}
            {subject.affected_work.length === 0 ? <p className="mt-4 text-sm text-slate-500">No affected work was found in the available records.</p> : <div className="mt-4 max-h-96 space-y-3 overflow-y-auto">
                {subject.affected_work.map(work => <article key={work.order_id} className="rounded-lg border border-slate-300 p-3 text-sm">
                    <p className="font-semibold">{work.order_number} <span className="font-normal capitalize text-slate-600">— {work.order_status?.replaceAll('_', ' ') || 'Unknown'}</span></p>
                    <p className="mt-1 text-slate-600">{work.parcel ? `Parcel #${work.parcel.id}: ${work.parcel.status?.replaceAll('_', ' ') || 'Unknown'}. Pickup rider: ${work.parcel.courier_id ?? 'Unassigned'}; delivery rider: ${work.parcel.assigned_rider_id ?? 'Unassigned'}; current hub: ${work.parcel.current_hub_id ?? 'Not recorded'}.` : 'No parcel record exists.'}</p>
                    {work.cash.method === 'cod' && <p className="mt-1 text-amber-800">Expected COD: ₱{Number(work.cash.expected_amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}. Cash holder and reconciliation require evidence.</p>}
                </article>)}
            </div>}
        </section>

        <form onSubmit={submit} className="rounded-xl border border-slate-300 bg-white p-5">
            <h2 className="font-semibold">Record a separate activity decision</h2>
            <p className="mt-2 text-sm text-slate-600">Suspension and deactivation stop new work. Reactivation checks approval and current scope, and preserves separate restrictions, custody, stock and account roles.</p>
            {subject.type === 'fleet' && <p className="mt-2 text-sm text-slate-600">{subject.reactivation_mode_recorded ? `A valid reactivation restores the recorded ${subject.reactivation_status} state.` : 'No prior operating state was recorded. A valid, separate reactivation sets the vehicle active.'} Maintenance and idle vehicles remain unavailable for new work.</p>}
            {subject.actions.length === 0 ? <p className="mt-4 text-sm text-amber-800">This activity state needs controlled review before a routine decision is available.</p> : <>
                <label htmlFor="restriction-action" className="mt-4 block text-sm font-medium">Action</label>
                <select id="restriction-action" value={action} onChange={event => setAction(event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]">
                    {subject.actions.map(value => <option key={value} value={value}>{value.charAt(0).toUpperCase() + value.slice(1)}</option>)}
                </select>
                <label htmlFor="restriction-reason" className="mt-4 block text-sm font-medium">Reason</label>
                <textarea id="restriction-reason" value={reason} onChange={event => setReason(event.target.value)} maxLength={1000} rows={4} placeholder="Explain the issue and the work that needs attention." className="mt-1 w-full rounded-lg border border-slate-300 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]" />
                <label className="mt-4 flex items-start gap-3 text-sm"><input type="checkbox" checked={confirmed} onChange={event => setConfirmed(event.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" />I reviewed the current approval, resource scope and affected work. I understand that this decision does not complete a recovery or handover.</label>
                <button type="submit" disabled={processing || conflict || !confirmed || reason.trim().length < 5} className="mt-4 rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{processing ? 'Saving decision...' : `${action.charAt(0).toUpperCase() + action.slice(1)} ${subject.type}`}</button>
            </>}
            {message && <p role="alert" className="mt-4 text-sm text-rose-700">{message}</p>}
            {Object.entries(errors).map(([field, messages]) => <p key={field} role="alert" className="mt-2 text-sm text-rose-700">{messages.join(' ')}</p>)}
            {conflict && <button type="button" onClick={() => router.reload({ only: ['subject'] })} className="mt-3 rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">Reload current review</button>}
        </form>

        <section className="rounded-xl border border-slate-300 bg-white p-5">
            <h2 className="font-semibold">Recorded activity decisions</h2>
            {subject.history.length === 0 ? <p className="mt-3 text-sm text-slate-500">No recorded decisions.</p> : <div className="mt-4 space-y-4">{subject.history.map(item => <article key={item.id} className="border-b border-slate-300 pb-4 last:border-0 last:pb-0">
                <p className="text-sm font-semibold capitalize">{item.action} — {item.before_status} to {item.after_status}</p>
                <p className="mt-1 whitespace-pre-line text-sm text-slate-700">{item.reason}</p>
                <p className="mt-2 text-xs text-slate-500">{item.actor} · {new Date(item.decided_at).toLocaleString('en-PH', { timeZone: 'Asia/Manila' })} · {item.affected_work_count} affected records</p>
            </article>)}</div>}
        </section>
    </div>;
}
