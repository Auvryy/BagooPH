import { useState, type FormEvent } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { HubParcelAction, type ParcelGuidance } from '@/Components/HubParcelGuidance';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { CalendarClock, CheckCircle2, MapPin, RotateCcw, UserRound } from 'lucide-react';
import { CourierDialog } from '@/Components/CourierUI';
import { HubBadge, HubEmpty, HubNextStep, HubPageHeader, hubButton, hubInput, hubPrimary } from '@/Components/Logistics/Workspace';
import type { PageProps } from '@/types';

type Event = { reference: string; type: string; notes: string | null; retryAt: string | null; recordedAt: string };
type Attempt = { id: number; reference: string; number: number; riderName: string; reason: string; notes: string; locationName: string; attemptedAt: string; events: Event[] };
type Parcel = { id: number; trackingNumber: string; status: string; hubName: string; atHub: boolean; canReview: boolean; guidance?: ParcelGuidance; attempts: Attempt[] };
type Props = { deliveries: { data: Parcel[]; links: { url: string | null; label: string; active: boolean }[] }; requestToken: string; basePath: string };
const when = (value: string) => new Date(value).toLocaleString('en-PH', { timeZone: 'Asia/Manila' });

export default function DeliveryRecovery({ deliveries, requestToken, basePath }: Props) {
    const path = (suffix: string) => `${basePath}${suffix}`;
    const { flash } = usePage<PageProps>().props;
    const [review, setReview] = useState<Parcel | null>(null);
    const [retryAt, setRetryAt] = useState('');
    const [notes, setNotes] = useState('');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!review || busy) return;
        setBusy(true);
        router.post(path(`/delivery-recovery/${review.id}/retry`), { retry_at: retryAt, notes, request_token: requestToken, attempt_reference: review.attempts.at(-1)?.reference }, {
            preserveScroll: true, onError: setErrors,
            onSuccess: (page) => { const error = (page.props as PageProps).flash?.error;if (error) setErrors({ review: error });else { setReview(null); setNotes(''); setRetryAt(''); setErrors({}); } },
            onFinish: () => setBusy(false),
        });
    };
    return <DashboardLayout title="Delivery recovery"><Head title="Delivery recovery — BagooPH" /><div className="space-y-6">
        <HubPageHeader title="Delivery recovery" icon={RotateCcw} description="Review failed delivery attempts and the next permitted retry or return. Actual return scans end rider custody." />
        {flash?.error && <p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">{flash.error}</p>}
        {!deliveries.data.length && <div className="rounded-xl border border-slate-200 bg-white"><HubEmpty icon={CheckCircle2} title="No failed deliveries in this view" description="Failed attempts within your facility scope will appear here with their retained evidence and next action." /></div>}
        <div className="grid items-start gap-5 xl:grid-cols-2">{deliveries.data.map(parcel => {
            const retry = parcel.attempts.at(-1)?.events.find(event => event.type === 'retry_approved');
            const canRetry = parcel.canReview && parcel.atHub && parcel.status === 'delivery_failed' && !retry;
            const next = retry ? 'Handler: scan at the approved retry time' : parcel.guidance?.next_step ?? (parcel.status === 'return_to_sender' ? 'Continue the saved return route' : parcel.atHub ? 'Company review is next' : 'Receiving handler: scan the returning parcel');
            return <article key={parcel.id} className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <header className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 p-5"><div><h2 className="text-sm font-bold text-slate-900">{parcel.trackingNumber}</h2><p className="mt-2 flex items-center gap-1.5 text-xs text-slate-500"><MapPin className="h-3.5 w-3.5" />{parcel.guidance?.custodian ?? (parcel.atHub ? parcel.hubName : 'Not at the destination hub')}</p></div><HubBadge tone="warning">{parcel.status.replaceAll('_', ' ')}</HubBadge></header>
                <div className="space-y-5 p-5"><HubNextStep title={next} tone={parcel.guidance?.blocker ? 'warning' : 'brand'} action={canRetry && <button type="button" className={hubButton} onClick={() => { setReview(parcel);setErrors({});setRetryAt('');setNotes(''); }}><CalendarClock className="h-4 w-4" />Review retry date</button>}><p>{retry?.retryAt ? `Reviewed retry: ${when(retry.retryAt)}` : parcel.atHub ? 'Refused parcels and third failures follow the original reverse route. Retry decisions need retained attempt evidence.' : 'The rider returns the actual parcel before another delivery can be arranged.'}</p>{parcel.guidance && <p className="mt-1 text-xs">Next person: {retry ? 'Assigned destination handler at the approved time' : parcel.guidance.responsible}</p>}</HubNextStep>
                    {parcel.guidance && parcel.guidance.action !== 'review' && <HubParcelAction guidance={parcel.guidance} />}
                    <h3 className="text-xs font-semibold text-slate-500">Recorded attempts</h3><ol className="space-y-5">{parcel.attempts.map(attempt => <li key={attempt.id} className="relative border-l border-slate-200 pl-5"><span className="absolute -left-2 top-0 flex h-4 w-4 items-center justify-center rounded-full border-4 border-white bg-[#E00D42]" /><p className="text-sm font-semibold text-slate-900">Attempt {attempt.number} · {attempt.reason.replaceAll('_', ' ')}</p><p className="mt-1 flex items-center gap-1.5 text-xs text-slate-500"><UserRound className="h-3 w-3" />{attempt.riderName} · {when(attempt.attemptedAt)}</p><p className="mt-2 text-xs text-slate-500">{attempt.locationName}</p><p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-700">{attempt.notes}</p><a href={path(`/delivery-attempts/${attempt.id}/proof`)} className="mt-2 inline-block text-xs font-semibold text-[#C20836] underline" target="_blank" rel="noreferrer">View retained attempt proof</a>
                        {attempt.events.length > 0 && <ul className="mt-3 space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3">{attempt.events.map(event => <li key={event.reference} className="text-xs leading-5 text-slate-600"><p className="font-semibold text-slate-800">{event.type.replaceAll('_', ' ')}</p><p>{when(event.recordedAt)}{event.retryAt ? ` · Retry: ${when(event.retryAt)}` : ''}</p>{event.notes && <p className="mt-1">{event.notes}</p>}</li>)}</ul>}
                    </li>)}</ol>
                </div>
            </article>;
        })}</div>
        {deliveries.links.filter(link => link.url).length > 1 && <nav aria-label="Recovery pages" className="flex flex-wrap gap-2">{deliveries.links.map((link, index) => link.url && <Link key={index} href={link.url} className={`${hubButton} ${link.active ? 'border-rose-200 bg-rose-50 text-[#C20836]' : ''}`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav>}
        <CourierDialog open={Boolean(review)} title="Review retry date" busy={busy} onClose={() => setReview(null)}>{review && <form onSubmit={submit} className="space-y-4"><p className="text-sm text-slate-600">{review.trackingNumber} · {review.hubName}</p><label className="block text-xs font-semibold text-slate-700">Retry date · Asia/Manila<input type="datetime-local" required value={retryAt} disabled={busy} onChange={event => setRetryAt(event.target.value)} className={`${hubInput} mt-2`} /></label><label className="block text-xs font-semibold text-slate-700">Why is another attempt appropriate?<textarea required minLength={5} maxLength={1000} rows={3} value={notes} disabled={busy} onChange={event => setNotes(event.target.value)} className={`${hubInput} mt-2`} /></label>{Object.entries(errors).map(([key, value]) => <p key={key} role="alert" className="text-sm text-red-700">{value}</p>)}<div className="flex justify-end gap-2 border-t border-slate-200 pt-4"><button type="button" disabled={busy} onClick={() => setReview(null)} className={hubButton}>Cancel</button><button disabled={busy} className={hubPrimary}>{busy ? 'Saving…' : 'Approve retry date'}</button></div></form>}</CourierDialog>
    </div></DashboardLayout>;
}
