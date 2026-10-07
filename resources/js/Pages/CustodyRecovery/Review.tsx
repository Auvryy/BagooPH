import { Head, useForm } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';

type Proposal = {source_token: string; facts: {delivery_id: number; courier_id: number; phase: string; hub_id: number; custody: {kind: string; user_id: number}}};

export default function RecoveryReview({workId, proposal, requestToken, parcel, holderName, receivingHub, latestGrant}: {workId: number; proposal: Proposal; requestToken: string; parcel: {tracking_number: string; order_number: string}; holderName: string; receivingHub: {name: string; address: string}; latestGrant: {reference: string; expires_at: string} | null}) {
    const form = useForm({reason: '', source_token: proposal.source_token, request_token: requestToken});
    return <DashboardLayout title="Review a restricted handover" subtitle="Authorize only the recorded rider's return to the original receiving facility."><Head title="Restricted handover review" />
        <section className="mx-auto max-w-2xl space-y-4 rounded-lg border border-slate-300 bg-white p-5">
            <p className="text-sm">Order {parcel.order_number}, waybill {parcel.tracking_number}, remains with {holderName}. Its recorded {proposal.facts.phase.replace('_',' ')} handover belongs to {receivingHub.name}, {receivingHub.address}.</p>
            <p className="text-sm text-slate-700">The rider must acknowledge the actual handover and an assigned facility handler must scan receipt. Authorization lasts 24 hours. Cash responsibility stays unchanged.</p>
            {latestGrant && <p className="rounded-lg border border-slate-300 p-3 text-sm">Recorded authorization: {latestGrant.reference}, ending {new Date(latestGrant.expires_at).toLocaleString('en-PH', {timeZone:'Asia/Manila'})}. Rider sign-in: {route('custody-recovery.login')}. Facility receipt: {route('custody-recovery.receiving')}.</p>}
            <form className="space-y-3" onSubmit={event => {event.preventDefault(); form.post(route('custody-recovery.grant', workId), {preserveScroll:true});}}>
                <label className="block text-sm font-semibold">Reason<textarea required minLength={5} maxLength={1000} value={form.data.reason} onChange={event => form.setData('reason',event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" /></label>
                {Object.values(form.errors).map(error => <p key={error} role="alert" className="text-sm text-red-700">{error}</p>)}
                <button disabled={form.processing} className="rounded-lg bg-[#E00D42] px-4 py-2 font-semibold text-white disabled:opacity-50">Authorize the owned handover</button>
            </form>
        </section>
    </DashboardLayout>;
}
