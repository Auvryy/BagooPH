import { useId, useState } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, Package, PackageCheck, Target, Truck, type LucideIcon } from 'lucide-react';
import { CourierBadge, CourierPanel, courierButton, courierInput } from '@/Components/CourierUI';
import { courierActivitySeries, courierDate, courierPath } from '@/utils/courier';

export interface CourierActivity {
    id: number;
    trackingNumber: string;
    orderNumber: string | null;
    recipientName: string;
    deliveryAddress: string;
    paymentMethod: string;
    destinationHub: string | null;
    deliveredAt: string | null;
}

type WorkTab = 'pickup' | 'final_mile' | 'available';

export function CourierDashboardSummary({ available, pickups, deliveries, completedToday, onSelect }: {
    available: number; pickups: number; deliveries: number; completedToday?: number;
    onSelect: (tab: WorkTab) => void;
}) {
    const cards: Array<{ label: string; value: number | string; hint: string; icon: LucideIcon; tab?: WorkTab }> = [
        { label: 'Available pickups', value: available, hint: 'At your assigned hub', icon: Package, tab: 'available' },
        { label: 'Active pickups', value: pickups, hint: 'Collection and hub intake', icon: PackageCheck, tab: 'pickup' },
        { label: 'Delivery tasks', value: deliveries, hint: 'Assigned final-mile parcels', icon: Truck, tab: 'final_mile' },
        { label: 'Delivered today', value: completedToday ?? '—', hint: completedToday === undefined ? 'Count not provided' : 'Recorded at your current hub', icon: CheckCircle2 },
    ];
    return <section aria-label="Work overview" className="grid grid-cols-2 gap-3 min-[860px]:grid-cols-4 sm:gap-4">
        {cards.map(({ label, value, hint, icon: Icon, tab }) => {
            const content = <>
                <span className="flex items-start justify-between gap-2"><span className="text-xs font-medium leading-relaxed text-slate-600">{label}</span><span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-[10px] bg-[#FFF2F4] text-[#C20836]"><Icon className="h-4 w-4" aria-hidden="true" /></span></span>
                <span className="mt-2 block text-[26px] font-semibold leading-none tracking-tight tabular-nums text-slate-900">{value}</span>
                <span className="mt-2 block text-xs leading-relaxed text-slate-600">{hint}</span>
            </>;
            const classes = 'block h-full w-full rounded-[20px] p-4 text-left transition-colors hover:bg-[#FFFAFB] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42] motion-reduce:transition-none';
            return <CourierPanel key={label} className="min-w-0">{tab
                ? <button type="button" onClick={() => onSelect(tab)} className={classes} aria-label={`View ${label.toLowerCase()}`}>{content}</button>
                : <Link href={courierPath('/earnings')} className={classes} aria-label="View completed trips">{content}</Link>}</CourierPanel>;
        })}
    </section>;
}

export function CourierDeliveryOverview({ activity, activePickups, pickupLimit, awaitingIntake, claimReason, onViewPickups }: {
    activity: CourierActivity[]; activePickups: number; pickupLimit?: number; awaitingIntake: number;
    claimReason: string; onViewPickups: () => void;
}) {
    const [days, setDays] = useState(7);
    const chartId = useId();
    const series = courierActivitySeries(activity, days);
    const maximum = Math.max(4, Math.ceil(Math.max(...series.map((day) => day.count)) / 4) * 4);
    const capacityKnown = typeof pickupLimit === 'number' && Number.isFinite(pickupLimit) && pickupLimit > 0;
    const percent = capacityKnown ? Math.min(100, Math.max(0, Math.round(activePickups / pickupLimit * 100))) : 0;
    const remaining = capacityKnown ? Math.max(0, pickupLimit - activePickups) : null;

    return <div className="grid gap-4 min-[860px]:grid-cols-2">
        <CourierPanel className="min-w-0 p-4 sm:p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div><h2 className="text-lg font-semibold">Delivery activity</h2><p className="mt-2 text-sm text-slate-600">Your latest 10 delivery records at this hub.</p></div>
                <label className="sr-only" htmlFor={chartId}>Activity period</label>
                <select id={chartId} value={days} onChange={(event) => setDays(Number(event.target.value))} className={`${courierInput} mt-0 w-auto border-[#E00D42] py-2 pr-8 text-sm text-[#C20836]`}><option value={7}>Last 7 days</option><option value={14}>Last 14 days</option></select>
            </div>
            <div className="relative mt-6 pl-7" role="img" aria-label={`Recent delivery records: ${series.map((day) => `${day.fullLabel}, ${day.count}`).join('; ')}`}>
                <div className="pointer-events-none absolute inset-x-0 top-0 flex h-36 flex-col justify-between" aria-hidden="true">
                    {[4, 3, 2, 1, 0].map((tick) => <div key={tick} className="flex items-center gap-2"><span className="w-5 text-right text-xs tabular-nums text-slate-600">{maximum * tick / 4}</span><span className="h-px flex-1 bg-slate-200" /></div>)}
                </div>
                <div className="relative grid h-36 items-end gap-1 px-1" style={{ gridTemplateColumns: `repeat(${series.length}, minmax(0, 1fr))` }} aria-hidden="true">
                    {series.map((day, index) => <div key={day.key} className="flex h-full items-end justify-center"><span title={`${day.fullLabel}: ${day.count} recorded deliveries`} className={`w-[65%] max-w-8 rounded-t-[5px] ${index === series.length - 1 ? 'bg-[#E00D42]' : 'bg-[#FCE7EA]'}`} style={{ height: `${day.count / maximum * 100}%` }} /></div>)}
                </div>
                <div className="mt-2 grid gap-1 px-1 text-center text-xs text-slate-600" style={{ gridTemplateColumns: `repeat(${series.length}, minmax(0, 1fr))` }} aria-hidden="true">{series.map((day) => <span key={day.key}>{day.label}</span>)}</div>
            </div>
            {!series.some((day) => day.count) && <p className="mt-4 text-sm text-slate-600">No recent records in this period.</p>}
            <p className="mt-4 text-xs leading-relaxed text-slate-600">Based on your latest 10 delivery records. Open Trips for your full history in this assignment.</p>
        </CourierPanel>

        <CourierPanel className="min-w-0 p-4 sm:p-5">
            <div className="flex items-start justify-between gap-3"><div><h2 className="text-lg font-semibold">Keep your pickups moving</h2><p className="mt-2 text-sm leading-relaxed text-slate-600">A little progress at every handoff.</p></div><span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-[10px] bg-[#FFF2F4] text-[#C20836]"><Target className="h-[18px] w-[18px]" aria-hidden="true" /></span></div>
            <div className="mt-6 flex flex-wrap items-end justify-between gap-2"><p className="text-[28px] font-semibold tracking-tight tabular-nums">{activePickups}<span className="ml-1 text-sm font-normal text-slate-600">{capacityKnown ? `/ ${pickupLimit} pickups` : ' active pickups'}</span></p>{capacityKnown && <CourierBadge tone="brand">{percent}% in use</CourierBadge>}</div>
            {capacityKnown && <div role="progressbar" aria-label="Pickup capacity in use" aria-valuemin={0} aria-valuemax={pickupLimit} aria-valuenow={Math.min(pickupLimit, Math.max(0, activePickups))} aria-valuetext={`${activePickups} active pickups out of ${pickupLimit}`} className="mt-3 h-2 overflow-hidden rounded-full bg-[#FCE7EA]"><div className="h-full rounded-full bg-[#E00D42]" style={{ width: `${percent}%` }} /></div>}
            <div className="mt-3 flex flex-wrap justify-between gap-2 text-xs text-slate-600"><span>Current pickup capacity</span><span>{remaining === null ? 'Limit not provided' : `${remaining} ${remaining === 1 ? 'slot' : 'slots'} free`}</span></div>
            <div className="mt-5 flex items-start gap-3 rounded-[16px] border border-rose-200 bg-[#FFF2F4] p-4"><PackageCheck className="mt-0.5 h-5 w-5 shrink-0 text-[#C20836]" aria-hidden="true" /><div><p className="text-sm font-semibold">{awaitingIntake ? `${awaitingIntake} ${awaitingIntake === 1 ? 'parcel awaits' : 'parcels await'} hub intake` : 'Every parcel has a next step'}</p><p className="mt-1 text-sm leading-relaxed text-slate-600">{claimReason || 'Check the waybill at collection, then bring each pickup to its origin Bayan Hub.'}</p></div></div>
            <button type="button" onClick={onViewPickups} className={`${courierButton} mt-4 w-full`}>View pickup tasks<ArrowRight className="h-4 w-4" aria-hidden="true" /></button>
        </CourierPanel>
    </div>;
}

export function CourierRecentDeliveries({ activity, completedToday }: { activity: CourierActivity[]; completedToday?: number }) {
    const visible = activity.slice(0, 5);
    return <CourierPanel className="min-w-0 p-4 sm:p-5">
        <div className="flex flex-wrap items-center justify-between gap-3"><div className="flex flex-wrap items-center gap-3"><h2 className="text-lg font-semibold">Recent deliveries</h2>{completedToday !== undefined && <CourierBadge tone="brand">{completedToday} today</CourierBadge>}</div><Link href={courierPath('/earnings')} className="inline-flex min-h-12 items-center gap-2 text-sm font-semibold text-[#C20836] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]">View all trips<ArrowRight className="h-4 w-4" aria-hidden="true" /></Link></div>
        {!visible.length ? <p className="py-8 text-base text-slate-600">No recent deliveries. Recorded final-mile deliveries at your current hub will appear here.</p> : <>
            <div className="mt-3 hidden min-[860px]:block">
                <table className="w-full table-fixed border-separate border-spacing-0 text-left text-sm"><caption className="sr-only">Latest recorded final-mile deliveries at your current company and hub</caption><thead><tr className="text-slate-600"><th className="w-[22%] rounded-l-[12px] bg-slate-100 px-3 py-3 font-medium" scope="col">Parcel</th><th className="w-[29%] bg-slate-100 px-3 py-3 font-medium" scope="col">Recipient</th><th className="w-[18%] bg-slate-100 px-3 py-3 font-medium" scope="col">Recorded</th><th className="w-[16%] bg-slate-100 px-3 py-3 font-medium" scope="col">Payment</th><th className="w-[15%] rounded-r-[12px] bg-slate-100 px-3 py-3 font-medium" scope="col">Status</th></tr></thead><tbody>{visible.map((trip) => <tr key={trip.id}><td className="break-all border-b border-slate-200 px-3 py-4 font-medium">{trip.trackingNumber}<span className="mt-1 block text-xs font-normal text-slate-600">{trip.orderNumber}</span></td><td className="border-b border-slate-200 px-3 py-4"><span className="block break-words font-medium">{trip.recipientName || 'Not provided'}</span><span className="mt-1 block break-words text-xs leading-relaxed text-slate-600">{trip.deliveryAddress || 'Address not provided'}</span></td><td className="border-b border-slate-200 px-3 py-4 text-xs leading-relaxed text-slate-600">{courierDate(trip.deliveredAt)}</td><td className="break-words border-b border-slate-200 px-3 py-4">{trip.paymentMethod || 'Not provided'}</td><td className="border-b border-slate-200 px-3 py-4"><CourierBadge tone="success">Delivered</CourierBadge></td></tr>)}</tbody></table>
            </div>
            <ul className="mt-3 divide-y divide-slate-300 min-[860px]:hidden">{visible.map((trip) => <li key={trip.id} className="space-y-2 py-4"><div className="flex flex-wrap items-center justify-between gap-2"><p className="break-all text-sm font-semibold">{trip.trackingNumber}</p><CourierBadge tone="success">Delivered</CourierBadge></div><p className="break-words text-base font-medium">{trip.recipientName || 'Recipient not provided'}</p><p className="break-words text-sm leading-relaxed text-slate-600">{trip.deliveryAddress || 'Address not provided'}</p><p className="text-xs leading-relaxed text-slate-600">{courierDate(trip.deliveredAt)} · {trip.paymentMethod || 'Payment method not provided'}</p></li>)}</ul>
            {activity.length > visible.length && <p className="mt-3 text-xs text-slate-600">Showing {visible.length} of {activity.length} recent records.</p>}
        </>}
        <p className="mt-4 text-xs leading-relaxed text-slate-600">Buyer receipt confirmation and cash remittance are separate steps.</p>
    </CourierPanel>;
}
