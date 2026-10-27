import { Check, ChevronDown, ClipboardCheck, Package, PackageCheck, Star, Truck } from 'lucide-react';
import { OrderTracking } from '@/types';
import { buyerJourneyEntries, buyerJourneyException, buyerMilestones, originalBuyerJourney,
    type BuyerJourneyEntry, type BuyerOrderNotice, type BuyerProgressOrder } from '@/utils/buyerParcelProgress';
import { trackingDate } from '@/utils/orderTracking';
import useNotificationAction from '@/hooks/useNotificationAction';

const icons = { placed: Package, packing: ClipboardCheck, shipped: Truck, received: PackageCheck, review: Star };

export default function BuyerParcelProgress({ tracking, order, notices = [], onReview, reviewDisabled = false }: {
    tracking: OrderTracking;
    order?: BuyerProgressOrder;
    notices?: BuyerOrderNotice[];
    onReview?: () => void;
    reviewDisabled?: boolean;
}) {
    const milestones = buyerMilestones(tracking, order);
    const history = originalBuyerJourney(tracking);
    const entries = buyerJourneyEntries(tracking, notices);
    const last = history.at(-1);
    const unread = new Set(notices.filter(notice => !notice.read_at).map(notice => notice.id)).size;
    const exception = buyerJourneyException(tracking);
    const { reading, error, markRead } = useNotificationAction();
    const earlierCount = Math.max(0, entries.length - 5);
    const renderEntry = (event: BuyerJourneyEntry, index: number) => <li key={event.key} data-journey-entry={event.key} className="relative flex gap-3 pb-5 last:pb-0">
        {index < entries.length - 1 && <span aria-hidden="true" className="absolute bottom-0 left-[5px] top-3 w-px bg-slate-300" />}
        <span aria-hidden="true" className={`relative mt-1.5 h-3 w-3 shrink-0 rounded-full border-2 ${event.checkpoint && event.checkpoint.id === last?.id
            ? 'border-amber-500 bg-amber-300' : 'border-[#E00D42] bg-[#E00D42]'}`} />
        <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1"><p className="break-words text-sm font-semibold text-slate-800">{event.label}</p><time dateTime={event.created_at ?? undefined} className="text-xs text-slate-500">{trackingDate(event.created_at)}</time></div>
            {event.location && <p className="mt-1 break-words text-xs text-slate-600">{event.location}</p>}
            {!event.checkpoint && event.notices[0]?.data.body && <p className="mt-1 break-words text-xs leading-relaxed text-slate-600">{event.notices[0].data.body}</p>}
            {event.notices.filter(notice => !notice.read_at).map(notice => <button key={notice.id} type="button" disabled={reading !== null} onClick={() => markRead(notice.id)}
                aria-label={`Mark "${notice.data.title}" as read`} className="mt-1 min-h-10 rounded-lg px-1 text-xs font-semibold text-[#C20836] underline underline-offset-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42] disabled:opacity-50">{reading === notice.id ? 'Saving…' : 'Mark read'}</button>)}
        </div>
    </li>;

    return <div className="space-y-5">
        {tracking.notice && <p role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">{tracking.notice}</p>}
        {exception && <p role="status" className="text-sm font-semibold text-amber-900">{exception}. {tracking.next_step}</p>}
        <ol aria-label="Order milestones" className="grid grid-cols-5 gap-0 py-2 sm:py-4">
            {milestones.map((step, index) => {
                const Icon = icons[step.key];
                const content = <>
                    <span aria-hidden="true" className={`relative z-10 mx-auto flex h-9 w-9 shrink-0 items-center justify-center rounded-full border-2 sm:h-11 sm:w-11 ${step.current
                        ? 'border-amber-500 bg-amber-300 text-amber-950 ring-4 ring-amber-100'
                        : step.complete ? 'border-[#E00D42] bg-[#E00D42] text-white'
                            : 'border-slate-300 bg-white text-slate-400'}`}>
                        {step.complete ? <Check className="h-4 w-4 sm:h-5 sm:w-5" /> : <Icon className="h-4 w-4 sm:h-5 sm:w-5" />}
                    </span>
                    <span className={`mt-3 block break-words text-xs font-bold leading-relaxed sm:text-sm ${step.current ? 'text-amber-900' : 'text-slate-800'}`}>{step.label}</span>
                    {step.current ? <span className="mt-1 block text-xs font-semibold text-amber-900">{step.key === 'review' ? onReview ? 'Your turn' : 'Awaiting review' : 'Current'}</span>
                        : step.missing ? <span className="mt-1 block text-xs text-slate-500">Not recorded</span>
                            : <span className="sr-only">{step.complete ? 'Completed' : 'Upcoming'}</span>}
                </>;
                return <li key={step.key} aria-current={step.current ? 'step' : undefined} data-milestone={step.key} data-recorded={step.recorded}
                    className="relative min-w-0 px-0.5 text-center sm:px-2">
                    {index < milestones.length - 1 && <span aria-hidden="true" className={`absolute left-[calc(50%_+_1.125rem)] top-[17px] h-0.5 w-[calc(100%_-_2.25rem)] sm:left-[calc(50%_+_1.375rem)] sm:top-[21px] sm:w-[calc(100%_-_2.75rem)] ${step.complete && milestones[index + 1].recorded ? 'bg-[#E00D42]' : 'bg-slate-300'}`} />}
                    {step.key === 'review' && onReview && step.current
                        ? <button type="button" onClick={onReview} disabled={reviewDisabled} className="w-full rounded-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#E00D42] disabled:opacity-50"><span className="sr-only">Write a product review. </span>{content}</button>
                        : <div>{content}</div>}
                </li>;
            })}
        </ol>
        <details open className="group/journey border-t border-slate-300 pt-1">
            <summary className="flex min-h-16 cursor-pointer list-none items-center justify-between gap-3 rounded-lg py-3 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42] [&::-webkit-details-marker]:hidden">
                <div className="min-w-0">
                    <p className="text-xs font-semibold text-slate-500">Journey checkpoints ({entries.length}){unread > 0 && <span className="ml-2 text-[#C20836]">{unread} unread</span>}</p>
                    <p className="mt-1 break-words text-sm font-bold text-slate-900">{tracking.stage_label || last?.label || tracking.status_label}</p>
                    {last && <p className="mt-1 break-words text-xs leading-relaxed text-slate-600">{last.location || 'Location not recorded'} · {trackingDate(last.created_at)}</p>}
                </div>
                <ChevronDown aria-hidden="true" className="h-5 w-5 shrink-0 text-slate-500 transition-transform group-open/journey:rotate-180 motion-reduce:transition-none" />
            </summary>
            <div className="space-y-5 pb-2 pt-3">
                <dl className="grid gap-3 text-xs text-slate-600 sm:grid-cols-2">
                    <div><dt>Recorded parcel holder</dt><dd className="mt-1 break-words font-semibold text-slate-800">{tracking.custodian}</dd>{tracking.custody_recorded_at && <dd className="mt-1">Holder record: {trackingDate(tracking.custody_recorded_at)}</dd>}</div>
                    <div><dt>Last checked</dt><dd className="mt-1">{trackingDate(tracking.observed_at)} · Philippine time</dd></div>
                </dl>
                {tracking.failure && <div className="space-y-1 text-sm text-amber-900"><p>Recorded attempt {tracking.failure.attempt_number}: {tracking.failure.reason}</p><p className="text-xs">Attempt: {trackingDate(tracking.failure.attempted_at)}</p>{tracking.failure.retry_at && <p className="font-semibold">Approved retry: {trackingDate(tracking.failure.retry_at)} · Philippine time</p>}</div>}
                {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
                {earlierCount > 0 && <details className="group/earlier">
                    <summary className="mb-4 flex min-h-10 cursor-pointer list-none items-center gap-2 rounded-lg text-xs font-semibold text-[#C20836] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42] [&::-webkit-details-marker]:hidden">
                        <ChevronDown aria-hidden="true" className="h-4 w-4 transition-transform group-open/earlier:rotate-180 motion-reduce:transition-none" />
                        <span className="group-open/earlier:hidden">See more</span><span className="hidden group-open/earlier:inline">See less</span>
                        <span className="font-normal text-slate-500">({earlierCount} earlier {earlierCount === 1 ? 'update' : 'updates'})</span>
                    </summary>
                    <ol aria-label="Earlier parcel journey and order updates" className="pb-5">{entries.slice(0, earlierCount).map(renderEntry)}</ol>
                </details>}
                {entries.length === 0 ? <p className="py-5 text-center text-sm text-slate-500">No journey checkpoints or order updates are recorded yet.</p> : <ol aria-label="Original parcel journey and order updates" start={earlierCount + 1}>
                    {entries.slice(earlierCount).map((event, index) => renderEntry(event, earlierCount + index))}
                </ol>}
            </div>
        </details>
    </div>;
}
