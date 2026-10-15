import { useRef, useState, type FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { CourierDialog, CourierFieldError, courierButton, courierInput, courierPrimary } from '@/Components/CourierUI';
import useCourierRequestError from '@/hooks/useCourierRequestError';
import { courierDate, courierPath } from '@/utils/courier';
import { companyTextError } from '@/utils/riderCompany';
import type { CompanyAssignmentOffer, CompanyJoinRequest } from '@/types/riderCompany';

export function companyRequestStatus(request: CompanyJoinRequest): string {
    const labels = { pending: 'Request submitted', offered: 'Offer ready', accepted: 'Assignment accepted',
        rejected: 'Request declined', withdrawn: 'Withdrawn', declined: 'Offer declined', cancelled: 'Offer withdrawn', expired: 'Offer expired' };
    return labels[request.effective_status ?? request.status];
}

export function AssignmentOfferDetails({ offer }: { offer: CompanyAssignmentOffer }) {
    return <div className="min-w-0 space-y-3 text-sm">
        <div className="flex flex-wrap justify-between gap-2"><h4 className="font-semibold">Assignment offer · Version {offer.version}</h4><span className="break-all text-xs text-slate-500">{offer.reference}</span></div>
        <dl className="grid gap-3 sm:grid-cols-2">{[
            ['Company', offer.assignment.company], ['Facility', `${offer.assignment.hub} (${offer.assignment.hub_code})`],
            ['Vehicle', offer.assignment.vehicle], ['Barangay', offer.barangay || 'Not assigned'],
            ['Issued', courierDate(offer.issued_at)], ['Expires', courierDate(offer.expires_at)],
        ].map(([label, value]) => <div key={label}><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 break-words font-medium text-slate-800">{value}</dd></div>)}</dl>
        <div><h5 className="font-semibold">Working arrangement</h5><p className="mt-2 whitespace-pre-wrap break-words leading-relaxed text-slate-700">{offer.terms}</p></div>
    </div>;
}

export function CompanyDecisionHistory({ request }: { request: CompanyJoinRequest }) {
    return <>
        {(request.offers?.length ?? 0) > 1 && <details className="mt-3 text-sm"><summary className="cursor-pointer py-2 font-medium text-slate-700">Earlier offers ({request.offers!.length - 1})</summary><div className="space-y-4 pt-2">{request.offers!.filter((offer) => offer.id !== request.offer?.id).map((offer) => <div key={offer.id} className="rounded-[8px] border border-slate-300 bg-white p-4"><p className="mb-3 text-xs font-semibold text-slate-500">Replaced offer</p><AssignmentOfferDetails offer={offer} /></div>)}</div></details>}
        {!!request.decisions?.length && <details className="mt-3 text-sm"><summary className="cursor-pointer py-2 font-medium text-slate-700">Decision history</summary><ol className="space-y-3 border-l border-slate-300 pl-4 pt-2">{request.decisions.map((decision, index) => <li key={index}><p className="font-medium">{decision.action === 'submitted' ? 'Request submitted' : companyRequestStatus({ ...request, status: decision.action as CompanyJoinRequest['status'], effective_status: undefined })}</p><p className="mt-1 text-xs text-slate-500">{courierDate(decision.at)}</p>{decision.reason && <p className="mt-2 whitespace-pre-wrap break-words leading-relaxed text-slate-700">{decision.reason}</p>}</li>)}</ol></details>}
    </>;
}

export function RiderAssignmentDecision({ request }: { request: CompanyJoinRequest }) {
    const [action, setAction] = useState<'accept' | 'decline' | null>(null);
    const [unconfirmed, setUnconfirmed] = useState(false);
    const pending = useRef(false);
    const form = useForm({ expected_version: request.version, request_token: request.decision_token ?? '',
        offer_id: request.offer?.id ?? '', terms_hash: request.offer?.terms_hash ?? '', acknowledged: false, reason: '' });
    useCourierRequestError(pending, (message) => { setUnconfirmed(true); form.setError('expected_version', message); });
    if (!request.offer) return <CompanyDecisionHistory request={request} />;
    const historyUrl = `${courierPath('/profile')}?tab=assignment`;
    const isOpen = (request.effective_status ?? request.status) === 'offered';
    const reasonError = companyTextError(form.data.reason, 5, 500);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!action || pending.current || (action === 'accept' && !form.data.acknowledged) || (action === 'decline' && reasonError)) return;
        pending.current = true;
        form.transform((data) => action === 'accept'
            ? { expected_version: data.expected_version, request_token: data.request_token, offer_id: data.offer_id, terms_hash: data.terms_hash, acknowledged: data.acknowledged }
            : { expected_version: data.expected_version, request_token: data.request_token, reason: data.reason });
        form.post(courierPath(`/company-requests/${request.id}/${action}`), {
            preserveScroll: true,
            onSuccess: () => { setAction(null); setUnconfirmed(false); },
            onError: () => setUnconfirmed(false),
            onFinish: () => { pending.current = false; },
        });
    };
    return <div className="mt-4">
        <div className="rounded-[8px] bg-white p-4"><AssignmentOfferDetails offer={request.offer} /></div>
        {request.accepted_at && <p className="mt-3 text-sm font-medium text-emerald-700">Accepted {courierDate(request.accepted_at)}. Go on duty separately when ready.</p>}
        {isOpen && <><p className="mt-3 text-sm leading-relaxed text-slate-600">This offer reserves neither you nor a vehicle. Current eligibility and availability are checked when you accept.</p><div className="mt-3 flex flex-wrap gap-2"><button type="button" disabled={!request.can_accept} onClick={() => { form.clearErrors(); setAction('accept'); }} className={courierPrimary}>Accept assignment</button><button type="button" onClick={() => { form.clearErrors(); setAction('decline'); }} className={courierButton}>Decline offer</button></div>{!request.can_accept && <p role="status" className="mt-3 text-sm text-amber-800">Assignment details or eligibility changed. Ask the company for a new offer, or withdraw this request.</p>}</>}
        <CompanyDecisionHistory request={request} />
        <CourierDialog open={action !== null} title={action === 'accept' ? 'Accept assignment?' : 'Decline offer?'} busy={form.processing} onClose={() => { if (unconfirmed) router.get(historyUrl); else setAction(null); }}>
            <form onSubmit={submit} className="space-y-4">
                <AssignmentOfferDetails offer={request.offer} />
                {action === 'accept' ? <label className="flex cursor-pointer items-start gap-3 rounded-[8px] bg-slate-50 p-3 text-sm leading-relaxed"><input type="checkbox" required checked={form.data.acknowledged} disabled={form.processing || unconfirmed} onChange={(event) => form.setData('acknowledged', event.target.checked)} className="mt-1 h-5 w-5 shrink-0 rounded-[8px] border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" /><span>I have read version {request.offer.version} and accept this company, facility, vehicle and working arrangement. I will choose when to go on duty.</span></label>
                    : <div><label htmlFor={`offer-reason-${request.id}`} className="text-sm font-semibold">Reason (optional)</label><textarea id={`offer-reason-${request.id}`} value={form.data.reason} rows={3} maxLength={1000} disabled={form.processing || unconfirmed} onChange={(event) => form.setData('reason', event.target.value)} className={courierInput} aria-invalid={Boolean(reasonError)} /><p className="mt-2 text-xs text-slate-500">5–500 characters if supplied.</p><CourierFieldError message={reasonError} /></div>}
                {Object.entries(form.errors).map(([key, message]) => <CourierFieldError key={key} message={message} />)}
                {unconfirmed && <p role="status" className="text-sm text-slate-600">The result is unconfirmed. Retry this same decision or check your request history.</p>}
                <button type="submit" disabled={form.processing || (action === 'accept' ? !form.data.acknowledged : Boolean(reasonError))} className={`${courierPrimary} w-full`}>{form.processing ? 'Saving…' : unconfirmed ? 'Retry same decision' : action === 'accept' ? 'Accept assignment' : 'Decline offer'}</button>
                <Link href={historyUrl} className={`${courierButton} w-full`}>Check request history</Link>
            </form>
        </CourierDialog>
    </div>;
}
