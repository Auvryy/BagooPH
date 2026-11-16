import { Head, Link } from '@inertiajs/react';
import { ChevronRight, Plus } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { buttonClasses } from '@/Components/ui';
import { EventStatus, eventDate, type SaleEventSummary } from '@/Components/Promotions/saleEvents';

type Row = SaleEventSummary & { vouchers_count: number; products_count: number; claims_count: number };

export default function SaleEventsIndex({ events, base }: { events: { data: Row[]; total: number; prev_page_url: string | null; next_page_url: string | null }; base: string }) {
    return (
        <DashboardLayout title="Sale events" actions={<Link href={`${base}/create`} className={buttonClasses('primary')}><Plus className="h-4 w-4" aria-hidden="true" />New event</Link>}>
            <Head title="Sale events" />
            {events.data.length === 0 ? (
                <section className="rounded-card border border-line-200 bg-white px-6 py-12">
                    <p className="font-medium text-ink-900">No sale events yet</p>
                    <p className="mt-1 text-sm text-ink-600">Create an event like an 11.11 Mega Sale. Sellers then add their discounts and vouchers.</p>
                    <Link href={`${base}/create`} className={`${buttonClasses('secondary')} mt-4`}>Create the first event</Link>
                </section>
            ) : (
                <section aria-label="Sale events" className="rounded-card border border-line-200 bg-white">
                    <div className="hidden grid-cols-[2fr_1.6fr_1fr_1fr_1fr_24px] gap-4 border-b border-line-200 px-6 py-2 text-xs font-medium uppercase tracking-wide text-ink-600 md:grid" aria-hidden="true">
                        <span>Event</span><span>When</span><span>Status</span><span className="text-right">Products</span><span className="text-right">Claims</span><span />
                    </div>
                    <ul className="divide-y divide-line-200">
                        {events.data.map(event => (
                            <li key={event.id}>
                                <Link href={`${base}/${event.id}`} className="grid grid-cols-[1fr_auto_24px] items-center gap-x-4 gap-y-1 px-4 py-3 hover:bg-surface-100 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-500 sm:px-6 md:grid-cols-[2fr_1.6fr_1fr_1fr_1fr_24px]">
                                    <span className="flex min-w-0 items-center gap-3">
                                        {event.banner_url ? <img src={event.banner_url} alt="" className="h-10 w-20 shrink-0 rounded object-cover" /> : <span className="h-10 w-20 shrink-0 rounded bg-surface-100" />}
                                        <span className="min-w-0"><span className="block truncate text-sm font-semibold text-ink-900">{event.title}</span><span className="block text-xs text-ink-600">{event.badge}</span></span>
                                    </span>
                                    <span className="col-start-1 text-xs text-ink-600 md:col-start-auto md:text-sm">{eventDate(event.starts_at)} – {eventDate(event.ends_at)}</span>
                                    <span className="col-start-2 row-start-1 md:col-start-auto md:row-start-auto"><EventStatus status={event.status} /></span>
                                    <span className="hidden text-right text-sm tabular-nums text-ink-900 md:block">{event.products_count}</span>
                                    <span className="hidden text-right text-sm tabular-nums text-ink-900 md:block">{event.claims_count}</span>
                                    <ChevronRight aria-hidden="true" className="col-start-3 row-span-2 row-start-1 h-4 w-4 text-ink-400 md:col-start-auto md:row-span-1 md:row-start-auto" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                    {(events.prev_page_url || events.next_page_url) && (
                        <nav aria-label="Event pages" className="flex justify-end gap-2 border-t border-line-200 px-6 py-3">
                            {events.prev_page_url && <Link href={events.prev_page_url} className={buttonClasses('secondary', 'sm')}>Previous</Link>}
                            {events.next_page_url && <Link href={events.next_page_url} className={buttonClasses('secondary', 'sm')}>Next</Link>}
                        </nav>
                    )}
                </section>
            )}
        </DashboardLayout>
    );
}
