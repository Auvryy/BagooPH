import { Head, Link, useForm } from '@inertiajs/react';
import WaybillCamera from '@/Components/WaybillCamera';
import NotificationLink from '@/Components/NotificationLink';
import { RecoveryActionForm, RecoveryGrantSummary } from '@/Components/HandoverRecoveryPanel';
import { type RecoveryGrant } from '@/Components/HandoverUI';

type Grant = {id: number; reference: string; order_number: string; tracking_number: string; hub: {name: string; address: string}; expires_at: string; acknowledged: boolean};

function Handover({grant, requestToken, receiving}: {grant: Grant; requestToken: string; receiving: boolean}) {
    const form = useForm({barcode: '', notes: '', request_token: requestToken});
    return <section className="space-y-3 rounded-lg border border-slate-300 bg-white p-5 shadow-sm">
        <h2 className="font-bold">Order {grant.order_number}</h2>
        <p className="text-sm text-slate-700">Receiving facility: {grant.hub.name}, {grant.hub.address}</p>
        <p className="text-sm text-slate-700">Authorization ends {new Date(grant.expires_at).toLocaleString('en-PH', {timeZone:'Asia/Manila'})}.</p>
        {grant.acknowledged && <p className="text-sm font-semibold">Rider handover acknowledged. The facility must record its actual receipt scan.</p>}
        {(!grant.acknowledged || receiving) && <form className="space-y-3" onSubmit={event => {event.preventDefault(); form.post(route(receiving ? 'custody-recovery.receive' : 'custody-recovery.acknowledge', grant.id), {preserveScroll: true});}}>
            <label className="block text-sm font-semibold">Actual waybill<input required autoComplete="off" value={form.data.barcode} onChange={event => form.setData('barcode',event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" /></label>
            <WaybillCamera onScan={value => form.setData('barcode',value)} disabled={form.processing} />
            <label className="block text-sm font-semibold">Handover notes<textarea required minLength={5} maxLength={1000} value={form.data.notes} onChange={event => form.setData('notes',event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" placeholder={receiving ? 'Describe the original parcel you actually received.' : 'Describe your handover to the named facility.'} /></label>
            {Object.values(form.errors).map(error => <p key={error} role="alert" className="text-sm text-red-700">{error}</p>)}
            <button disabled={form.processing || (receiving && !grant.acknowledged)} className="rounded-lg bg-[#E00D42] px-4 py-2 font-semibold text-white disabled:opacity-50">{receiving ? 'Record actual facility receipt' : 'Acknowledge my owned handover'}</button>
        </form>}
    </section>;
}

export default function OwnedRecovery({grants, handoverGrants = [], requestToken, receiving = false}: {grants: Grant[]; handoverGrants?: RecoveryGrant[]; requestToken: string; receiving?: boolean}) {
    return <main className="min-h-screen bg-[#FFFAFB] px-4 py-10 text-slate-900"><Head title="Authorized parcel handovers" /><div className="mx-auto max-w-3xl space-y-5">
        <div className="flex flex-wrap items-center justify-between gap-3"><h1 className="text-2xl font-bold">{receiving ? 'Receive authorized handovers' : 'My authorized handover'}</h1><div className="flex items-center gap-3"><Link href="/notifications" className="text-sm font-semibold text-[#E00D42]">Account updates</Link><NotificationLink /></div></div>
        <p className="text-sm text-slate-700">Authorization keeps the current parcel and cash responsibilities in place. The receiving facility's scan records the physical parcel handover.</p>
        {grants.map(grant => <Handover key={grant.id} grant={grant} requestToken={requestToken} receiving={receiving} />)}
        {handoverGrants.map(grant => <section key={`handover-${grant.id}`} className="space-y-4 rounded-[8px] border border-slate-300 bg-white p-5 shadow-sm">
            <h2 className="font-bold">Order {grant.order_number}</h2><RecoveryGrantSummary grant={grant} />
            <p className="text-sm leading-relaxed text-slate-700">This parcel stays on hold until the named hub receives it. This access allows only your authorized handover.</p>
            {grant.can_acknowledge && <RecoveryActionForm url={`/custody-recovery/handover/${grant.id}/acknowledge`} title="Confirm my current possession" button="Acknowledge possession" metadata={grant} barcode
                description="Scan the waybill on the parcel you physically hold now. Return this parcel to the named hub for its actual receipt scan."
                confirmations={[{ name: 'possession_verified', label: 'I physically hold this exact parcel now and will hand it to the named receiving hub.' }]} />}
        </section>)}
        {grants.length === 0 && handoverGrants.length === 0 && <p className="rounded-lg border border-slate-300 bg-white p-5 shadow-sm">No current handover is available. Ask the logistics manager to review the parcel's existing custody and authorization.</p>}
    </div></main>;
}
