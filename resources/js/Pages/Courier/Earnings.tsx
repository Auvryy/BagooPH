import { useMemo, useState } from 'react';
import { Head } from '@inertiajs/react';
import { CheckCircle2, Copy, Search } from 'lucide-react';
import CourierLayout from '@/Layouts/CourierLayout';
import { CourierBadge, CourierEmpty, CourierPanel, courierButton, courierInput } from '@/Components/CourierUI';
import { courierDate, type CourierScope } from '@/utils/courier';

interface Props {
    scope: CourierScope;
    summary: { completedDeliveries: number; completedToday: number };
    isOnline: boolean;
    trips: Array<{ id: number; trackingNumber: string; orderNumber: string | null; recipientName: string; deliveryAddress: string; paymentMethod: string; destinationHub: string | null; deliveredAt: string | null }>;
}

export default function CourierCompletedTrips({ scope, summary, trips, isOnline }: Props) {
    const [search, setSearch] = useState('');
    const [payment, setPayment] = useState('all');
    const [page, setPage] = useState(1);
    const [copyResult, setCopyResult] = useState('');
    const filtered = useMemo(() => {
        const query = search.trim().toLowerCase();
        return trips.filter((trip) => {
            const matchingText = [trip.trackingNumber, trip.orderNumber, trip.recipientName, trip.deliveryAddress].some((value) => value?.toLowerCase().includes(query));
            const method = trip.paymentMethod.toUpperCase();
            return matchingText && (payment === 'all' || (payment === 'cod' && method === 'COD') || (payment === 'other' && Boolean(method) && method !== 'COD'));
        });
    }, [trips, search, payment]);
    const pages = Math.max(1, Math.ceil(filtered.length / 10));
    const currentPage = Math.min(page, pages);
    const visible = filtered.slice((currentPage - 1) * 10, currentPage * 10);
    const copy = async (tracking: string) => {
        try {
            if (!navigator.clipboard) throw new Error('Clipboard unavailable');
            await navigator.clipboard.writeText(tracking);
            setCopyResult(`Tracking number ${tracking} copied.`);
        } catch { setCopyResult('Could not copy. Select the tracking number to copy it.'); }
    };

    return (
        <CourierLayout title="Completed trips" subtitle="Recorded final-mile deliveries at your current company and destination hub." scope={scope} isOnline={isOnline}>
            <Head title="Completed trips — BagooPH" />
            <div className="space-y-5">
                <p className="text-base leading-relaxed text-slate-700"><span className="font-bold tabular-nums">{summary.completedDeliveries}</span> recorded in this scope · <span className="font-bold tabular-nums">{summary.completedToday}</span> today. Pickup history and previous hub assignments are not included.</p>
                <CourierPanel className="p-4 sm:p-5">
                    <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_14rem]">
                        <label className="block text-sm font-semibold">Search deliveries<span className="relative block"><Search className="pointer-events-none absolute left-3 top-6 h-5 w-5 text-slate-600" aria-hidden="true" /><input type="search" value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} placeholder="Tracking, recipient, or address" className={`${courierInput} pl-10`} /></span></label>
                        <label className="block text-sm font-semibold">Payment method<select value={payment} onChange={(event) => { setPayment(event.target.value); setPage(1); }} className={courierInput}><option value="all">All methods</option><option value="cod">Cash on delivery</option><option value="other">Other recorded methods</option></select></label>
                    </div>
                    <p role="status" className="mt-3 text-sm text-slate-600">{filtered.length} matching {filtered.length === 1 ? 'delivery' : 'deliveries'}</p>
                </CourierPanel>
                <p role="status" className="break-words text-sm text-slate-600">{copyResult}</p>
                <div className="grid items-start gap-4 xl:grid-cols-2">
                    {visible.length ? visible.map((trip) => <CourierPanel key={trip.id} className="min-w-0 p-4 sm:p-5">
                        <div className="flex flex-wrap items-center justify-between gap-2"><CourierBadge tone="success"><CheckCircle2 className="h-4 w-4" aria-hidden="true" />Delivery recorded</CourierBadge><span className="text-sm text-slate-600">{courierDate(trip.deliveredAt)}</span></div>
                        <h2 className="mt-4 break-words text-xl font-bold">{trip.recipientName || 'Recipient not provided'}</h2>
                        <p className="mt-2 whitespace-pre-line break-words text-base leading-relaxed text-slate-700">{trip.deliveryAddress || 'Address not provided'}</p>
                        <p className="mt-3 break-all text-sm font-semibold">{trip.trackingNumber}</p>
                        <button type="button" onClick={() => copy(trip.trackingNumber)} className={`${courierButton} mt-3`} aria-label={`Copy tracking number ${trip.trackingNumber}`}><Copy className="h-4 w-4" aria-hidden="true" />Copy tracking number</button>
                        <details className="mt-3"><summary className="min-h-12 cursor-pointer py-3 text-sm font-semibold">Recorded details</summary><div className="space-y-2 pb-3 text-base text-slate-700"><p className="break-words">Order: {trip.orderNumber || 'Not provided'}</p><p className="break-words">Destination hub: {trip.destinationHub || 'Not provided'}</p><p>Payment method: {trip.paymentMethod || 'Not provided'}</p><p className="text-sm leading-relaxed text-slate-600">This delivery record does not confirm buyer receipt, cash remittance, or a rider payout.</p></div></details>
                    </CourierPanel>) : <CourierEmpty title={trips.length ? 'No matching trips' : 'No completed trips yet'}>{trips.length ? 'Try a different search or payment filter.' : 'Your recorded final-mile deliveries in this assignment will appear here.'}</CourierEmpty>}
                </div>
                {pages > 1 && <nav aria-label="Trip pages" className="flex flex-wrap items-center justify-between gap-3"><button type="button" disabled={currentPage === 1} onClick={() => setPage(currentPage - 1)} className={courierButton}>Previous</button><span className="text-sm text-slate-600">Page {currentPage} of {pages}</span><button type="button" disabled={currentPage === pages} onClick={() => setPage(currentPage + 1)} className={courierButton}>Next</button></nav>}
            </div>
        </CourierLayout>
    );
}
