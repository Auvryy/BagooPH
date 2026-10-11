import { RefreshCw } from 'lucide-react';
import { OrderTracking } from '@/types';

export function trackingDate(value: string | null | undefined): string {
    const date = value ? new Date(value) : null;
    return date && Number.isFinite(date.getTime()) ? new Intl.DateTimeFormat('en-PH', {
        dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila',
    }).format(date) : 'Not recorded';
}

export function OrderTrackingStatus({ tracking }: { tracking?: OrderTracking }) {
    const style = tracking?.tone === 'success' ? 'border-emerald-300 bg-emerald-50 text-emerald-800'
        : tracking?.tone === 'attention' ? 'border-amber-300 bg-amber-50 text-amber-900'
            : 'border-slate-300 bg-slate-100 text-slate-700';
    return <span className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ${style}`}>{tracking?.status_label ?? 'Status not loaded'}</span>;
}

export default function OrderTrackingPanel({ tracking, onRefresh, refreshing = false, error, compact = false }: {
    tracking?: OrderTracking;
    onRefresh?: () => void;
    refreshing?: boolean;
    error?: string;
    compact?: boolean;
}) {
    return <section aria-label="Recorded parcel tracking" className={`space-y-4 border border-slate-300 bg-white ${compact ? 'rounded-xl p-4' : 'rounded-2xl p-6 sm:p-8 shadow-xs'}`}>
        <header className="flex flex-wrap items-start justify-between gap-3">
            <div><h2 className="text-sm font-bold text-slate-900">Recorded parcel tracking</h2><p className="mt-1 text-xs text-slate-500">Checked {trackingDate(tracking?.observed_at)} · Philippine time</p></div>
            {onRefresh && <button type="button" onClick={onRefresh} disabled={refreshing} className="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"><RefreshCw className="h-4 w-4" aria-hidden="true" />{refreshing ? 'Refreshing…' : 'Refresh tracking'}</button>}
        </header>
        {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
        {!tracking ? <p className="text-sm text-slate-600">Tracking details are not loaded. Refresh to check the recorded journey.</p> : <>
            <OrderTrackingStatus tracking={tracking} />
            <dl className="grid gap-4 text-sm sm:grid-cols-2">
                <div><dt className="text-xs text-slate-500">Parcel stage</dt><dd className="mt-1 font-semibold text-slate-900">{tracking.stage_label}</dd></div>
                <div><dt className="text-xs text-slate-500">Recorded parcel holder</dt><dd className="mt-1 font-semibold text-slate-900">{tracking.custodian}</dd><dd className="mt-1 text-xs text-slate-500">Handover: {trackingDate(tracking.custody_recorded_at)}</dd></div>
            </dl>
            {tracking.notice && <p role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">{tracking.notice}</p>}
            <div className="rounded-lg border border-slate-300 bg-slate-50 p-3"><p className="text-xs font-semibold text-slate-500">Next step · {tracking.responsible}</p><p className="mt-1 text-sm text-slate-800">{tracking.next_step}</p></div>
            {tracking.failure && <div className="space-y-1 text-sm text-slate-700"><p>Recorded attempt {tracking.failure.attempt_number}: {tracking.failure.reason}</p><p className="text-xs">Attempt: {trackingDate(tracking.failure.attempted_at)}</p>{tracking.failure.retry_at && <p className="font-semibold">Approved retry: {trackingDate(tracking.failure.retry_at)} · Philippine time</p>}</div>}
            <div className="border-t border-slate-300 pt-4"><h3 className="text-sm font-semibold text-slate-900">Recorded journey</h3>
                {tracking.history.length === 0 ? <p className="mt-3 text-sm text-slate-500">No journey checkpoints are recorded yet.</p> : <ol className="mt-4 space-y-4" aria-label="Original parcel journey">
                    {tracking.history.map(event => <li key={event.id} className="border-l-2 border-slate-300 pl-4"><p className="text-sm font-semibold text-slate-800">{event.label}</p><p className="mt-1 break-words text-xs text-slate-600">{event.location}</p><p className="mt-1 text-xs text-slate-500">{trackingDate(event.created_at)}</p></li>)}
                </ol>}
            </div>
        </>}
    </section>;
}
