import { FormEvent } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { BarChart3 } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Pagination, dateLabel, slotLabel, stateLabel } from '@/types/advertisements';

interface Counts { impressions: number; clicks: number; click_through_rate: number | null }
interface Placement extends Counts {
    id: number; advertisement_id: number; revision_id: number; revision_number: number; title: string;
    owner: string; creator: string; purpose: string; slot: string; starts_at: string; ends_at: string; effective_end: string; state: string;
}
interface Choice { id: number; title: string; advertisement_id?: number; revision_id?: number }
interface Props {
    available: boolean; collecting: boolean; seller: boolean; baseUrl: string; totals: Counts | null;
    filters: { from: string; to: string; advertisement_id: number | null; revision_id: number | null; publication_id: number | null };
    dateLimits: { minimum: string; maximum: string }; placements: Pagination<Placement> | null;
    options: { advertisements: Choice[]; revisions: Choice[]; placements: Choice[] };
    failureReference?: string | null;
}
const field = 'mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]';
const rate = (value: number | null) => value === null ? '—' : `${value.toFixed(2)}%`;

export default function AdvertisementPerformance({ available, collecting, seller, baseUrl, totals, filters, dateLimits, placements, options, failureReference }: Props) {
    const form = useForm({ from: filters.from, to: filters.to, advertisement_id: filters.advertisement_id?.toString() ?? '',
        revision_id: filters.revision_id?.toString() ?? '', publication_id: filters.publication_id?.toString() ?? '' });
    const apply = (event: FormEvent) => { event.preventDefault(); form.get(`${baseUrl}/performance`, { preserveState: true, preserveScroll: true }); };
    const title = seller ? 'Promotion performance' : 'Advertisement performance';
    const revisions = options.revisions.filter((choice) => !form.data.advertisement_id || choice.advertisement_id === Number(form.data.advertisement_id));
    const positions = options.placements.filter((choice) => (!form.data.advertisement_id || choice.advertisement_id === Number(form.data.advertisement_id)) && (!form.data.revision_id || choice.revision_id === Number(form.data.revision_id)));
    return <DashboardLayout title={title} subtitle={seller ? 'Recorded activity for your own published promotions.' : 'Compare recorded activity across marketplace placements.'}
        actions={<Link href={baseUrl} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-bold">{seller ? 'Manage promotions' : 'Manage advertisements'}</Link>}>
        <Head title={title} />
        <div className="space-y-6">
            {!available ? <div role="status" className="flex min-h-64 flex-col items-center justify-center rounded-lg border border-slate-300 bg-white p-6 text-center"><BarChart3 className="mb-3 h-8 w-8 text-slate-400" /><h2 className="font-bold">Reporting unavailable</h2><p className="mt-2 max-w-md text-sm text-slate-500">Recorded totals cannot be loaded right now. You can still manage promotions and buyers can keep shopping.</p>{failureReference && <p className="mt-2 text-xs text-slate-500">Reference: {failureReference}</p>}</div> : <>
                {!collecting && <p role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm">New activity is not being recorded. Retained totals are still shown for the selected dates.</p>}
                <form onSubmit={apply} className="space-y-4 rounded-lg border border-slate-300 bg-white p-4">
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                        <label className="text-sm font-semibold">From (Manila)<input type="date" required min={dateLimits.minimum} max={dateLimits.maximum} className={field} value={form.data.from} onChange={(e) => form.setData('from', e.target.value)} /></label>
                        <label className="text-sm font-semibold">To (Manila)<input type="date" required min={form.data.from || dateLimits.minimum} max={dateLimits.maximum} className={field} value={form.data.to} onChange={(e) => form.setData('to', e.target.value)} /></label>
                        <label className="text-sm font-semibold">{seller ? 'Promotion' : 'Advertisement'}<select className={field} value={form.data.advertisement_id} onChange={(e) => form.setData({ ...form.data, advertisement_id: e.target.value, revision_id: '', publication_id: '' })}><option value="">All {seller ? 'your promotions' : 'advertisements'}</option>{options.advertisements.map((choice) => <option key={choice.id} value={choice.id}>{choice.title}</option>)}</select></label>
                        <label className="text-sm font-semibold">Published revision<select className={field} value={form.data.revision_id} onChange={(e) => form.setData({ ...form.data, revision_id: e.target.value, publication_id: '' })}><option value="">All revisions</option>{revisions.map((choice) => <option key={choice.id} value={choice.id}>{choice.title}</option>)}</select></label>
                        <label className="text-sm font-semibold">Placement<select className={field} value={form.data.publication_id} onChange={(e) => form.setData('publication_id', e.target.value)}><option value="">All placements</option>{positions.map((choice) => <option key={choice.id} value={choice.id}>{choice.title}</option>)}</select></label>
                    </div>
                    <div className="flex flex-wrap items-center gap-4"><button disabled={form.processing} className="rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-bold text-white disabled:opacity-50">Apply filters</button><Link href={`${baseUrl}/performance`} className="text-sm font-semibold text-[#E00D42] underline">Reset filters</Link><p className="text-xs text-slate-500">Choose up to 90 days within the last year.</p></div>
                    {Object.values(form.errors).length > 0 && <ul role="alert" className="list-inside list-disc text-sm text-red-700">{Object.values(form.errors).map((message) => <li key={message}>{message}</li>)}</ul>}
                </form>
                {totals && <dl className="grid gap-4 sm:grid-cols-3">{[['Recorded views', totals.impressions.toLocaleString('en-PH')], ['Image clicks', totals.clicks.toLocaleString('en-PH')], ['Click-through rate', rate(totals.click_through_rate)]].map(([label, value]) => <div key={label} className="rounded-lg border border-slate-300 bg-white p-5"><dt className="text-sm text-slate-500">{label}</dt><dd className="mt-2 text-2xl font-bold">{value}</dd></div>)}</dl>}
                <p className="text-xs leading-relaxed text-slate-500">A view requires loaded artwork to stay at least half visible for one second in an active tab. Clicks are deliberate image activations; carousel controls and previews do not count. These are recorded events, not unique people, sales or revenue. A rate is unavailable without recorded views; a click may happen before a qualifying view. Activity before collection started, while disabled or when reporting failed is not reconstructed.</p>
                {placements?.data.length ? <div className="overflow-x-auto rounded-lg border border-slate-300 bg-white"><table className="w-full text-left text-sm"><caption className="px-4 py-3 text-left font-bold">Results by saved revision and placement · {filters.from} to {filters.to} (Manila)</caption><thead className="border-y border-slate-300 bg-slate-50 text-slate-600"><tr><th scope="col" className="p-4">Promotion and period</th><th scope="col" className="p-4">Placement</th><th scope="col" className="p-4 text-right">Views</th><th scope="col" className="p-4 text-right">Clicks</th><th scope="col" className="p-4 text-right">Click-through rate</th></tr></thead><tbody>{placements.data.map((placement) => <tr key={placement.id} className="border-b border-slate-300 last:border-b-0"><td className="min-w-64 p-4"><Link href={`${baseUrl}/${placement.advertisement_id}`} className="font-bold text-[#E00D42] underline">{placement.title}</Link><p className="mt-1 text-xs text-slate-500">Revision {placement.revision_number} · {stateLabel(placement.purpose)}{!seller ? ` · ${placement.owner} · ${placement.creator}` : ''}</p><p className="mt-1 text-xs text-slate-500">{dateLabel(placement.starts_at)} – {dateLabel(placement.ends_at)} (Manila)</p>{placement.effective_end && placement.effective_end !== placement.ends_at && <p className="mt-1 text-xs text-slate-500">{new Date(placement.effective_end) <= new Date(placement.starts_at) ? 'Stopped before start' : `Stopped or replaced ${dateLabel(placement.effective_end)} (Manila)`}</p>}</td><td className="whitespace-nowrap p-4"><p className="font-semibold">{slotLabel(placement.slot)}</p><p className="mt-1 text-xs text-slate-500">#{placement.id} · {stateLabel(placement.state)}</p></td><td className="p-4 text-right">{placement.impressions.toLocaleString('en-PH')}</td><td className="p-4 text-right">{placement.clicks.toLocaleString('en-PH')}</td><td className="p-4 text-right">{rate(placement.click_through_rate)}</td></tr>)}</tbody></table></div> : <div className="flex min-h-56 flex-col items-center justify-center rounded-lg border border-slate-300 bg-white p-6 text-center"><BarChart3 className="mb-3 h-8 w-8 text-slate-400" /><h2 className="font-bold">No placements found</h2><p className="mt-2 text-sm text-slate-500">Choose another promotion or revise your filters. Recorded activity will appear after a published promotion receives views or clicks.</p></div>}
                {placements && <nav aria-label="Performance pages" className="flex flex-wrap justify-between gap-3 text-sm"><span>{placements.total} placements · Page {placements.current_page} of {placements.last_page}</span><div className="flex gap-4">{placements.prev_page_url && <Link href={placements.prev_page_url} preserveScroll>Previous</Link>}{placements.next_page_url && <Link href={placements.next_page_url} preserveScroll>Next</Link>}</div></nav>}
            </>}
        </div>
    </DashboardLayout>;
}
