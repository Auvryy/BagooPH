import { useRef, useState, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { X } from 'lucide-react';
import CompanyRequestPagination from '@/Components/CompanyRequestPagination';
import { AssignmentOfferDetails, CompanyDecisionHistory, companyRequestStatus } from '@/Components/CompanyAssignmentOffer';
import useCourierRequestError from '@/hooks/useCourierRequestError';
import { companyTextError } from '@/utils/riderCompany';
import type { CompanyJoinRequest, CompanyRiderAssignment, CompanyRiderIntake } from '@/types/riderCompany';

type Workspace = Extract<CompanyRiderIntake, { available: true }>;
const input = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white text-sm focus:border-[#E00D42] focus:ring-[#E00D42]';
const button = 'inline-flex min-h-10 items-center justify-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42] disabled:cursor-not-allowed disabled:opacity-50';
const primary = `${button} border-transparent bg-[#E00D42] text-white hover:bg-[#C20836]`;

export function CompanyRequestReview({ request, workspace }: { request: CompanyJoinRequest; workspace: Workspace }) {
    const [action, setAction] = useState<'offers' | 'reject' | 'cancel' | null>(null);
    const [unconfirmed, setUnconfirmed] = useState(false);
    const pending = useRef(false);
    const form = useForm({ expected_version: request.version, request_token: request.decision_token ?? '',
        hub_id: request.offer?.hub_id ?? '', vehicle_id: request.offer?.vehicle_id ?? '', barangay: request.offer?.barangay ?? '',
        terms: request.offer?.terms ?? '', reason: '' });
    useCourierRequestError(pending, (message) => { setUnconfirmed(true); form.setError('expected_version', message); });
    const state = request.effective_status ?? request.status;
    const hub = workspace.hubs?.find((item) => item.id === form.data.hub_id);
    const termsError = companyTextError(form.data.terms, 5, 2000) || (!form.data.terms.trim() ? 'Enter the working arrangement.' : undefined);
    const reasonError = companyTextError(form.data.reason, 5, 500) || (!form.data.reason.trim() ? 'Explain this decision.' : undefined);
    const invalid = action === 'offers' ? !form.data.hub_id || Boolean(termsError) : Boolean(reasonError);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!action || pending.current || invalid) return;
        pending.current = true;
        form.transform((data) => action === 'offers' ? { expected_version: data.expected_version, request_token: data.request_token,
            hub_id: data.hub_id, vehicle_id: data.vehicle_id || null, barangay: data.barangay || null, terms: data.terms }
            : { expected_version: data.expected_version, request_token: data.request_token, reason: data.reason });
        form.post(route(`hub.rider-company-requests.${action}`, { companyRequest: request.id }), {
            preserveScroll: true,
            onSuccess: () => { setAction(null); setUnconfirmed(false); },
            onError: () => setUnconfirmed(false),
            onFinish: () => { pending.current = false; },
        });
    };
    return <div className="mt-3">
        <p className="text-sm font-semibold text-slate-700">{companyRequestStatus(request)}</p>
        {request.offer && <div className="mt-3 rounded-lg border border-slate-300 bg-white p-4"><AssignmentOfferDetails offer={request.offer} /></div>}
        <CompanyDecisionHistory request={request} />
        {state === 'offered' && !request.can_accept && request.rider_eligible && <p role="status" className="mt-3 text-sm text-amber-800">The saved offer needs a replacement because an assignment detail or eligibility source changed.</p>}
        {workspace.offers_available && (state === 'pending' || state === 'offered') && <div className="mt-3 flex flex-wrap gap-2">
            <button type="button" disabled={!request.rider_eligible || !workspace.enabled || !workspace.history_available || !workspace.has_eligible_hub} onClick={() => { form.clearErrors(); setAction('offers'); }} className={primary}>{state === 'offered' ? 'Replace offer' : 'Make offer'}</button>
            <button type="button" onClick={() => { form.clearErrors(); setAction(state === 'offered' ? 'cancel' : 'reject'); }} className={button}>{state === 'offered' ? 'Withdraw offer' : 'Decline request'}</button>
        </div>}
        {action && <div className="mt-4 rounded-lg border border-slate-300 bg-white p-4">
            <div className="mb-4 flex items-center justify-between gap-3"><h5 className="text-sm font-bold">{action === 'offers' ? 'Assignment offer' : 'Record decision'}</h5>{!unconfirmed && <button type="button" aria-label="Close assignment form" disabled={form.processing} onClick={() => setAction(null)} className={button}><X className="h-4 w-4" aria-hidden="true" /></button>}</div>
            <form onSubmit={submit} className="space-y-4">
                {action === 'offers' ? <>
                    <label className="block text-sm font-semibold">Facility<select required value={form.data.hub_id} disabled={form.processing || unconfirmed} onChange={(event) => form.setData({ ...form.data, hub_id: event.target.value, vehicle_id: '', barangay: '' })} className={input}><option value="">Choose a facility</option>{workspace.hubs?.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
                    <label className="block text-sm font-semibold">Vehicle<select value={form.data.vehicle_id} disabled={form.processing || unconfirmed} onChange={(event) => form.setData('vehicle_id', event.target.value)} className={input}><option value="">Reviewed personal vehicle</option>{workspace.vehicles?.filter((vehicle) => vehicle.hub_id === form.data.hub_id).map((vehicle) => <option key={vehicle.id} value={vehicle.id}>{vehicle.plate}</option>)}</select></label>
                    {!!hub?.barangays.length && <label className="block text-sm font-semibold">Barangay (optional)<select value={form.data.barangay} disabled={form.processing || unconfirmed} onChange={(event) => form.setData('barangay', event.target.value)} className={input}><option value="">Not assigned</option>{hub.barangays.map((place) => <option key={place} value={place}>{place}</option>)}</select></label>}
                    <label className="block text-sm font-semibold">Working arrangement<textarea required rows={5} maxLength={4000} value={form.data.terms} disabled={form.processing || unconfirmed} onChange={(event) => form.setData('terms', event.target.value)} aria-invalid={Boolean(termsError)} className={input} /><span className="mt-2 block text-xs font-normal text-slate-500">Explain the work and schedule in 5–2,000 characters. {Array.from(form.data.terms).length}/2,000</span>{form.data.terms && termsError && <span role="alert" className="mt-2 block text-xs font-normal text-rose-700">{termsError}</span>}</label>
                    <p className="text-xs leading-relaxed text-slate-600">The offer expires seven days after issue and reserves no vehicle. Placement waits for the rider’s acceptance. Replacing it keeps the earlier terms.</p>
                </> : <label className="block text-sm font-semibold">Reason<textarea required rows={3} maxLength={1000} value={form.data.reason} disabled={form.processing || unconfirmed} onChange={(event) => form.setData('reason', event.target.value)} aria-invalid={Boolean(reasonError)} className={input} /><span className="mt-2 block text-xs font-normal text-slate-500">Explain the decision in 5–500 characters.</span>{form.data.reason && reasonError && <span role="alert" className="mt-2 block text-xs font-normal text-rose-700">{reasonError}</span>}</label>}
                {Object.entries(form.errors).map(([key, message]) => <p key={key} role="alert" className="text-sm text-rose-700">{message}</p>)}
                {unconfirmed && <p role="status" className="text-sm text-slate-600">The result is unconfirmed. Retry this same submission or refresh the requests.</p>}
                <div className="flex flex-wrap gap-2"><button type="submit" disabled={form.processing || invalid} className={primary}>{form.processing ? 'Saving…' : unconfirmed ? 'Retry same submission' : action === 'offers' ? 'Send offer' : 'Save decision'}</button><Link href={route('hub.network')} className={button}>Refresh requests</Link></div>
            </form>
        </div>}
    </div>;
}

export function CompanyRiderRoster({ workspace }: { workspace: Workspace }) {
    if (!workspace.roster) return null;
    return <section className="rounded-lg border border-slate-300 bg-white p-5 shadow-xs">
        <h2 className="text-base font-bold text-slate-900">Company riders ({workspace.roster.total})</h2>
        <p className="mt-1 text-sm leading-relaxed text-slate-600">Saved assignments and duty are shown separately. New riders join through an accepted offer above.</p>
        {workspace.roster.data.length ? <div className="mt-4 grid gap-4 xl:grid-cols-2">{workspace.roster.data.map((rider) => <RiderAssignment key={`${rider.id}-${rider.hub_id}-${rider.vehicle_id}-${rider.barangay}`} rider={rider} workspace={workspace} />)}</div> : <p className="py-8 text-center text-sm text-slate-500">No riders assigned to your company yet.</p>}
        <CompanyRequestPagination page={workspace.roster} label="Company riders" />
    </section>;
}

function RiderAssignment({ rider, workspace }: { rider: CompanyRiderAssignment; workspace: Workspace }) {
    const [editing, setEditing] = useState(false);
    const pending = useRef(false);
    const [unconfirmed, setUnconfirmed] = useState(false);
    const form = useForm({ kind: 'courier', email: rider.email ?? '', hub_id: rider.hub_id ?? '',
        current_hub_id: rider.hub_id ?? '', vehicle_id: rider.vehicle_id ?? '', barangay: rider.barangay ?? '' });
    useCourierRequestError(pending, (message) => { setUnconfirmed(true); form.setError('hub_id', message); });
    const hub = workspace.hubs?.find((item) => item.id === form.data.hub_id);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (pending.current) return;
        pending.current = true;
        form.post(route('hub.placements'), { preserveScroll: true,
            onSuccess: () => { setEditing(false); setUnconfirmed(false); }, onError: () => setUnconfirmed(false),
            onFinish: () => { pending.current = false; } });
    };
    return <article className="min-w-0 rounded-lg border border-slate-300 bg-slate-50 p-4">
        <h3 className="break-words font-semibold text-slate-900">{rider.name || 'Rider'}</h3><p className="mt-1 break-all text-xs text-slate-500">{rider.email}</p>
        <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-2">{[['Facility', rider.hub || 'Needs review'], ['Vehicle', rider.vehicle], ['Barangay', rider.barangay || 'Not assigned'], ['Duty', rider.on_duty ? 'On duty' : 'Off duty'], ['Eligibility', rider.eligible ? 'Eligible' : 'Needs review'], ['Source', rider.provenance]].map(([label, value]) => <div key={label}><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 break-words font-medium">{value}</dd></div>)}</dl>
        {!editing && <button type="button" onClick={() => setEditing(true)} className={`${button} mt-4`}>Edit assignment</button>}
        {editing && <form onSubmit={submit} className="mt-4 space-y-3 border-t border-slate-300 pt-4">
            <p className="text-xs text-slate-600">Current facility: {rider.hub || 'Not recorded'}. Finish active parcel or manifest custody before moving.</p>
            <label className="block text-sm font-semibold">Facility<select required value={form.data.hub_id} disabled={form.processing || unconfirmed} onChange={(event) => form.setData({ ...form.data, hub_id: event.target.value, vehicle_id: '', barangay: '' })} className={input}><option value="">Choose a facility</option>{workspace.hubs?.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
            <label className="block text-sm font-semibold">Vehicle<select value={form.data.vehicle_id} disabled={form.processing || unconfirmed} onChange={(event) => form.setData('vehicle_id', event.target.value)} className={input}><option value="">Reviewed personal vehicle</option>{rider.vehicle_id && form.data.hub_id === rider.hub_id && <option value={rider.vehicle_id}>{rider.vehicle} (current)</option>}{workspace.vehicles?.filter((vehicle) => vehicle.hub_id === form.data.hub_id).map((vehicle) => <option key={vehicle.id} value={vehicle.id}>{vehicle.plate}</option>)}</select></label>
            {!!hub?.barangays.length && <label className="block text-sm font-semibold">Barangay<select value={form.data.barangay} disabled={form.processing || unconfirmed} onChange={(event) => form.setData('barangay', event.target.value)} className={input}><option value="">Not assigned</option>{hub.barangays.map((place) => <option key={place} value={place}>{place}</option>)}</select></label>}
            <p className="text-xs text-slate-500">A successful move leaves the rider off duty. Existing fleet assignments require a current or available replacement vehicle.</p>
            {Object.entries(form.errors).map(([key, message]) => <p key={key} role="alert" className="text-sm text-rose-700">{message}</p>)}
            {unconfirmed && <p role="status" className="text-sm text-slate-600">The result is unconfirmed. Refresh the roster to confirm its saved assignment.</p>}
            <div className="flex flex-wrap gap-2"><button type="submit" disabled={form.processing || unconfirmed} className={primary}>{form.processing ? 'Saving…' : 'Save assignment'}</button>{!unconfirmed && <button type="button" disabled={form.processing} onClick={() => setEditing(false)} className={button}>Cancel</button>}<Link href={route('hub.network')} className={button}>Refresh roster</Link></div>
        </form>}
    </article>;
}
