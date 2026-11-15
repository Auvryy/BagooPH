import { RefreshCw } from 'lucide-react';
import { buttonClasses } from '@/Components/ui';
import { OrderTracking } from '@/types';
import BuyerParcelProgress from '@/Components/BuyerParcelProgress';
import { type BuyerOrderNotice, type BuyerProgressOrder } from '@/utils/buyerParcelProgress';

import { trackingDate } from '@/utils/orderTracking';
export { trackingDate } from '@/utils/orderTracking';

export function OrderTrackingStatus({ tracking }: { tracking?: OrderTracking }) {
    const style = tracking?.tone === 'success' ? 'bg-success-soft text-success'
        : tracking?.tone === 'attention' ? 'bg-warning-soft text-warning'
            : 'bg-info-soft text-info';
    return <span className={`inline-flex rounded-chip px-2 py-0.5 text-xs font-medium ${style}`}>{tracking?.status_label ?? 'Status not loaded'}</span>;
}

export default function OrderTrackingPanel({ tracking, onRefresh, refreshing = false, error, compact = false, variant = 'default', buyerOrder, notices, onReview }: {
    tracking?: OrderTracking;
    onRefresh?: () => void;
    refreshing?: boolean;
    error?: string;
    compact?: boolean;
    variant?: 'default' | 'buyer';
    buyerOrder?: BuyerProgressOrder;
    notices?: BuyerOrderNotice[];
    onReview?: () => void;
}) {
    const buyer = variant === 'buyer';
    return <section aria-label={buyer ? 'Order progress' : 'Recorded parcel tracking'} className={`space-y-4 bg-white ${buyer ? 'space-y-5 rounded-card border border-line-200 p-5 sm:p-6' : compact ? 'rounded-xl border border-slate-300 p-4' : 'rounded-2xl border border-slate-300 p-6 sm:p-8 shadow-xs'}`}>
        <header className="flex flex-wrap items-start justify-between gap-3">
            <div><h2 className={buyer ? 'text-lg font-semibold text-ink-900' : 'text-sm font-bold text-slate-900'}>{buyer ? 'Order progress' : 'Recorded parcel tracking'}</h2>{!buyer && <p className="mt-1 text-xs text-slate-500">Checked {trackingDate(tracking?.observed_at)} · Philippine time</p>}</div>
            {onRefresh && <button type="button" onClick={onRefresh} disabled={refreshing} className={buyer ? buttonClasses('secondary', 'sm') : 'inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50'}><RefreshCw className={`h-4 w-4 ${refreshing ? 'animate-spin motion-reduce:animate-none' : ''}`} aria-hidden="true" />{refreshing ? 'Refreshing…' : 'Refresh tracking'}</button>}
        </header>
        {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
        {!tracking ? <p className="text-sm text-slate-600">Tracking details are not loaded. Refresh to check the recorded journey.</p> : buyer ? <BuyerParcelProgress tracking={tracking} order={buyerOrder} notices={notices} onReview={onReview} reviewDisabled={refreshing} /> : <>
            <OrderTrackingStatus tracking={tracking} />
            {tracking.dispatch_delay_label && (
                <p role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm font-medium text-amber-900">
                    {tracking.dispatch_delay_label}
                </p>
            )}
            {tracking.eta && (
                <div className="rounded-lg border border-slate-200 bg-slate-50/70 p-3 text-sm space-y-1">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="text-xs font-semibold text-slate-600">Estimated delivery ({tracking.eta.status_label})</span>
                        <span className="font-bold text-slate-900">{tracking.eta.label}</span>
                    </div>
                    {tracking.eta.pause_notice && <p className="text-xs font-medium text-amber-900">{tracking.eta.pause_notice}</p>}
                    {tracking.eta.delay_notice && <p className="text-xs text-slate-600">{tracking.eta.delay_notice}</p>}
                </div>
            )}
            <dl className="grid gap-4 text-sm sm:grid-cols-2">
                <div><dt className="text-xs text-slate-500">Parcel stage</dt><dd className="mt-1 font-semibold text-slate-900">{tracking.stage_label}</dd></div>
                <div><dt className="text-xs text-slate-500">Recorded parcel holder</dt><dd className="mt-1 font-semibold text-slate-900">{tracking.custodian}</dd>{tracking.custody_recorded_at && <dd className="mt-1 text-xs text-slate-500">Holder record: {trackingDate(tracking.custody_recorded_at)}</dd>}</div>
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
