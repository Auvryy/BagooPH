import { useMemo, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { Banknote, CheckCircle2, Copy, MapPin, PackageCheck, Search } from 'lucide-react';
import CourierLayout from '@/Layouts/CourierLayout';
import { CourierBadge, CourierEmpty, CourierPanel, courierButton, courierInput } from '@/Components/CourierUI';
import { courierDate, courierPath, type CourierScope } from '@/utils/courier';

interface Props {
    scope: CourierScope;
    summary: { completedDeliveries: number; completedToday: number };
    isOnline: boolean;
    earningsSummary?: { confirmed_cents: string; pending_cents: string };
    earningEntries?: { id: string; order_number: string; role: string; amount_cents: number; released: boolean; virtual_only: boolean }[];
    shift?: { held_cents: number; can_close: boolean; pending_handovers: number; unresolved_differences: number };
    trips: Array<{ id: number; trackingNumber: string; orderNumber: string | null; recipientName: string; deliveryAddress: string; paymentMethod: string; destinationHub: string | null; deliveredAt: string | null }>;
}

export default function CourierCompletedTrips({ scope, summary, trips, isOnline, earningsSummary, earningEntries = [], shift }: Props) {
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
                {earningsSummary && <section aria-label="Recorded delivery earnings" className="grid gap-4 sm:grid-cols-2">{[{ label: 'Released earnings', value: earningsSummary.confirmed_cents }, { label: 'Awaiting cash handover', value: earningsSummary.pending_cents }].map(item => <CourierPanel key={item.label} className="p-5"><p className="text-xs font-medium text-slate-500">{item.label}</p><p className="mt-3 text-2xl font-bold">{new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(item.value) / 100)}</p></CourierPanel>)}</section>}
                {shift && !shift.can_close && <div role="status" className="rounded-[8px] border border-amber-300 bg-amber-50 p-4 text-sm leading-6 text-amber-950">Your shift needs a confirmed hub cash handover before you can close duty. Recorded COD still held: ₱{(shift.held_cents / 100).toFixed(2)}. Pending handovers: {shift.pending_handovers}; unresolved differences: {shift.unresolved_differences}.</div>}
                {earningEntries.length > 0 && <CourierPanel className="overflow-hidden p-5"><h2 className="text-sm font-semibold">Pickup and delivery earnings</h2><p className="mt-1 text-xs text-slate-500">Original shipping shares. Virtual wallet proceeds and actual cash payments stay separately recorded.</p><div className="mt-4 overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr className="border-b border-slate-200 text-xs text-slate-500"><th className="pb-3 font-medium">Order</th><th className="pb-3 font-medium">Work</th><th className="pb-3 font-medium">Earnings</th><th className="pb-3 font-medium">Release</th></tr></thead><tbody>{earningEntries.map(entry => <tr key={entry.id} className="border-b border-slate-100 last:border-0"><td className="py-3 font-semibold">{entry.order_number}</td><td className="py-3 capitalize">{entry.role}</td><td className="py-3">₱{(entry.amount_cents / 100).toFixed(2)}{entry.virtual_only ? ' · Virtual' : ''}</td><td className="py-3 text-xs">{entry.released ? 'Released' : 'Awaiting hub cash receipt'}</td></tr>)}</tbody></table></div></CourierPanel>}
                <div className="flex justify-end"><Link href={courierPath('/cod')} className={`${courierButton} gap-2`}><Banknote className="h-4 w-4" aria-hidden="true" />COD cash handovers</Link></div>
                <section aria-label="Trip overview" className="grid grid-cols-2 gap-4 lg:grid-cols-[1fr_1fr_1.5fr]">
                    {[{ label: 'Recorded deliveries', value: summary.completedDeliveries, hint: 'In your current assignment', icon: PackageCheck }, { label: 'Delivered today', value: summary.completedToday, hint: 'Recorded at this hub', icon: CheckCircle2 }].map(({ label, value, hint, icon: Icon }) => <CourierPanel key={label} className="min-w-0 p-4 sm:p-5">
                        <div className="flex items-start justify-between gap-2"><p className="text-xs font-medium text-slate-600">{label}</p><span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-[8px] bg-[#FFF6F8] text-[#C20836]"><Icon className="h-4 w-4" aria-hidden="true" /></span></div>
                        <p className="mt-2 text-[28px] font-semibold leading-none tabular-nums">{value}</p><p className="mt-3 text-xs leading-relaxed text-slate-600">{hint}</p>
                    </CourierPanel>)}
                    <CourierPanel className="col-span-2 min-w-0 p-4 sm:p-5 lg:col-span-1"><p className="flex items-center gap-2 text-xs font-medium text-slate-600"><MapPin className="h-4 w-4 text-[#C20836]" aria-hidden="true" />Current destination hub</p><p className="mt-3 break-words text-lg font-semibold">{scope.hub || 'Hub not assigned'}</p><p className="mt-2 text-xs leading-relaxed text-slate-600">Pickup history and previous hub assignments are not included.</p></CourierPanel>
                </section>
                <section aria-label="Trip filters" className="space-y-4 pt-2">
                    <div className="flex flex-wrap items-end justify-between gap-4">
                        <div><h2 className="text-lg font-semibold">Trip history</h2><p role="status" className="mt-1 text-sm text-slate-600">{filtered.length} matching {filtered.length === 1 ? 'delivery' : 'deliveries'}</p></div>
                        <div className="flex w-full min-w-0 max-w-full flex-col gap-3 sm:w-auto sm:flex-row">
                            <label className="relative block min-w-0 sm:w-72 sm:flex-1"><span className="sr-only">Search deliveries</span><Search className="pointer-events-none absolute left-3 top-4 h-4 w-4 text-slate-500" aria-hidden="true" /><input type="search" value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} placeholder="Tracking, recipient, or address" className={`${courierInput} mt-0 pl-10 text-sm`} /></label>
                            <label className="block min-w-0 sm:w-56 sm:shrink-0"><span className="sr-only">Payment method</span><select value={payment} onChange={(event) => { setPayment(event.target.value); setPage(1); }} className={`${courierInput} mt-0 text-sm`}><option value="all">All payment methods</option><option value="cod">Cash on delivery</option><option value="other">Other recorded methods</option></select></label>
                        </div>
                    </div>
                </section>
                <p role="status" className={copyResult ? 'break-words text-sm text-slate-600' : 'sr-only'}>{copyResult}</p>
                <div className="grid items-start gap-4 xl:grid-cols-2">
                    {visible.length ? visible.map((trip) => <CourierPanel key={trip.id} className="min-w-0 p-4 sm:p-5">
                        <div className="flex flex-wrap items-center justify-between gap-2"><CourierBadge tone="success"><CheckCircle2 className="h-4 w-4" aria-hidden="true" />Delivery recorded</CourierBadge><span className="text-sm text-slate-600">{courierDate(trip.deliveredAt)}</span></div>
                        <div className="mt-5 flex items-center gap-3"><span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-[8px] bg-[#FFF6F8] text-[#C20836]"><PackageCheck className="h-5 w-5" aria-hidden="true" /></span><div className="min-w-0"><p className="break-all text-sm font-semibold">{trip.trackingNumber}</p><p className="mt-1 break-words text-xs text-slate-600">{trip.orderNumber ? `Order ${trip.orderNumber}` : 'Order not provided'}</p></div><button type="button" onClick={() => copy(trip.trackingNumber)} className={`${courierButton} ml-auto h-12 w-12 shrink-0 border-transparent p-0`} aria-label={`Copy tracking number ${trip.trackingNumber}`} title="Copy tracking number"><Copy className="h-4 w-4" aria-hidden="true" /></button></div>
                        <div className="courier-inset mt-4 rounded-[8px] p-4"><h3 className="break-words text-base font-semibold">{trip.recipientName || 'Recipient not provided'}</h3><p className="mt-2 whitespace-pre-line break-words text-sm leading-relaxed text-slate-600">{trip.deliveryAddress || 'Address not provided'}</p></div>
                        <details className="mt-2"><summary className="min-h-12 cursor-pointer rounded-[8px] py-3 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]">Recorded details</summary><div className="space-y-2 pb-3 text-sm text-slate-700"><p className="break-words">Order: {trip.orderNumber || 'Not provided'}</p><p className="break-words">Destination hub: {trip.destinationHub || 'Not provided'}</p><p>Payment method: {trip.paymentMethod || 'Not provided'}</p><p className="leading-relaxed text-slate-600">This delivery record does not confirm buyer receipt, cash remittance, or a rider payout.</p></div></details>
                    </CourierPanel>) : <div className="xl:col-span-2"><CourierEmpty title={trips.length ? 'No matching trips' : 'No completed trips yet'}>{trips.length ? 'Try a different search or payment filter.' : 'Your recorded final-mile deliveries in this assignment will appear here.'}</CourierEmpty></div>}
                </div>
                {pages > 1 && <nav aria-label="Trip pages" className="flex flex-wrap items-center justify-between gap-3"><button type="button" disabled={currentPage === 1} onClick={() => setPage(currentPage - 1)} className={courierButton}>Previous</button><span className="text-sm text-slate-600">Page {currentPage} of {pages}</span><button type="button" disabled={currentPage === pages} onClick={() => setPage(currentPage + 1)} className={courierButton}>Next</button></nav>}
            </div>
        </CourierLayout>
    );
}
