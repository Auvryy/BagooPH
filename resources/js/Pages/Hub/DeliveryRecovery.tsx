import { useState, type FormEvent } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import type { PageProps } from '@/types';

type Event = { reference: string; type: string; notes: string | null; retryAt: string | null; recordedAt: string };
type Attempt = { id: number; reference: string; number: number; riderName: string; reason: string; notes: string; locationName: string; attemptedAt: string; events: Event[] };
type Parcel = { id: number; trackingNumber: string; status: string; hubName: string; atHub: boolean; canReview: boolean; attempts: Attempt[] };
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
            onSuccess: (page) => { if (!(page.props as PageProps).flash?.error) { setReview(null); setNotes(''); setRetryAt(''); setErrors({}); } },
            onFinish: () => setBusy(false),
        });
    };
    return <DashboardLayout title="Delivery recovery"><Head title="Delivery recovery — BagooPH" /><div className="space-y-5 p-6">
        <h1 className="text-xl font-semibold">Delivery recovery</h1>
        <p className="text-sm text-slate-600">Return scans end rider custody. Reviewed retries return to sorting; refused parcels and third failures await the reverse route.</p>
        {flash?.error && <p role="alert" className="text-red-700">{flash.error}</p>}{flash?.success && <p role="status" className="text-green-700">{flash.success}</p>}
        {!deliveries.data.length && <p>No failed deliveries in your facility scope.</p>}
        {deliveries.data.map(parcel => <article key={parcel.id} className="space-y-3 rounded-[8px] border border-slate-300 bg-white p-5">
            <h2 className="font-semibold">{parcel.trackingNumber}</h2><p className="text-sm">{parcel.atHub ? `At ${parcel.hubName}` : 'With the returning rider'} · {parcel.status.replaceAll('_', ' ')}</p>
            {parcel.attempts.map(attempt => <div key={attempt.id} className="space-y-2 border-t border-slate-300 pt-3 text-sm">
                <p className="font-semibold">Attempt {attempt.number}: {attempt.reason}</p><p>{attempt.riderName} · {when(attempt.attemptedAt)}</p><p>{attempt.locationName}</p><p>{attempt.notes}</p>
                <a href={path(`/delivery-attempts/${attempt.id}/proof`)} className="text-[#E00D42] underline" target="_blank" rel="noreferrer">View retained attempt proof</a>
                {attempt.events.map(event => <p key={event.reference}>{event.type.replaceAll('_', ' ')} · {when(event.recordedAt)}{event.retryAt ? ` · Retry: ${when(event.retryAt)}` : ''}{event.notes ? ` · ${event.notes}` : ''}</p>)}
            </div>)}
            {parcel.canReview && parcel.atHub && parcel.status === 'delivery_failed' && !parcel.attempts.at(-1)?.events.some(event => event.type === 'retry_approved') && <button type="button" className="rounded-[8px] border border-slate-300 px-4 py-2" onClick={() => { setReview(parcel); setErrors({}); }}>Review retry date</button>}
        </article>)}
        {review && <form onSubmit={submit} className="space-y-3 rounded-[8px] border border-slate-300 bg-white p-5">
            <h2 className="font-semibold">Review {review.trackingNumber}</h2><label className="block">Retry date, Asia/Manila<input type="datetime-local" required value={retryAt} disabled={busy} onChange={event => setRetryAt(event.target.value)} className="block rounded-[8px] border border-slate-300 p-2" /></label>
            <label className="block">Review notes<textarea required maxLength={1000} value={notes} disabled={busy} onChange={event => setNotes(event.target.value)} className="block w-full rounded-[8px] border border-slate-300 p-2" /></label>
            {Object.entries(errors).map(([key, value]) => <p key={key} role="alert" className="text-red-700">{value}</p>)}
            <button disabled={busy} className="rounded-[8px] bg-[#E00D42] px-4 py-2 text-white">{busy ? 'Saving…' : 'Approve retry date'}</button><button type="button" disabled={busy} onClick={() => setReview(null)} className="ml-3 rounded-[8px] border border-slate-300 px-4 py-2">Cancel</button>
        </form>}
        <nav aria-label="Pages" className="flex gap-3">{deliveries.links.map((link, index) => link.url && <Link key={index} href={link.url} className={link.active ? 'font-semibold' : ''} dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav>
    </div></DashboardLayout>;
}
