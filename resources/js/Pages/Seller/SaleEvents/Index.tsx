import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { EventStatus, eventDate, type SaleEventSummary } from '@/Components/Promotions/saleEvents';

type Row = SaleEventSummary & { my_products: number; my_vouchers: number };

export default function SellerSaleEvents({ events, base }: { events: Row[]; base: string }) {
    return (
        <DashboardLayout title="Sale events">
            <Head title="Sale events" />
            {events.length === 0 ? (
                <section className="rounded-card border border-line-200 bg-white px-6 py-12">
                    <p className="font-medium text-ink-900">No sale events are open</p>
                    <p className="mt-1 text-sm text-ink-600">When Bagoo schedules an event like 11.11, it appears here so you can add discounts and vouchers.</p>
                </section>
            ) : (
                <ul className="grid gap-4 md:grid-cols-2">
                    {events.map(event => (
                        <li key={event.id}>
                            <Link href={`${base}/${event.id}`} className="group flex h-full flex-col overflow-hidden rounded-card border border-line-200 bg-white hover:border-line-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500">
                                {event.banner_url ? <img src={event.banner_url} alt="" className="aspect-[47/16] w-full object-cover" /> : <span className="aspect-[47/16] w-full bg-brand-50" />}
                                <div className="flex flex-1 items-center gap-3 p-4">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2"><h2 className="truncate text-lg font-semibold text-ink-900">{event.title}</h2><EventStatus status={event.status} /></div>
                                        <p className="mt-0.5 text-sm text-ink-600">{eventDate(event.starts_at)} – {eventDate(event.ends_at)}</p>
                                        <p className="mt-2 text-sm text-ink-900">
                                            {event.my_products + event.my_vouchers === 0
                                                ? <span className="font-medium text-brand-600">Next: add your discounts</span>
                                                : `You joined with ${event.my_products} ${event.my_products === 1 ? 'product' : 'products'} and ${event.my_vouchers} ${event.my_vouchers === 1 ? 'voucher' : 'vouchers'}`}
                                        </p>
                                    </div>
                                    <ChevronRight aria-hidden="true" className="h-5 w-5 shrink-0 text-ink-400" />
                                </div>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </DashboardLayout>
    );
}
