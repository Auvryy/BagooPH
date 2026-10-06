import React, { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { GovernanceEvent, governanceTime, recordLabel } from '@/Components/GovernanceRecord';
import { PageProps, PaginatedData } from '@/types';

type Filters = Record<'source' | 'subject_type' | 'subject_id' | 'actor_id' | 'from' | 'to', string>;
interface Props {
    events: PaginatedData<GovernanceEvent>;
    filters: Partial<Filters>;
    sources: { value: string; label: string }[];
    subjectTypes: string[];
    scope: 'platform' | 'own_company_resources';
}
const empty: Filters = { source: '', subject_type: '', subject_id: '', actor_id: '', from: '', to: '' };

export default function History({ events, filters, sources, subjectTypes, scope }: Props) {
    const [input, setInput] = useState<Filters>({ ...empty, ...filters });
    const { errors } = usePage<PageProps>().props;
    const change = (field: keyof Filters, value: string) => setInput((current) => ({ ...current, [field]: value }));
    const control = 'mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]';

    return <DashboardLayout title="Governance history" subtitle={scope === 'platform' ? 'Recorded application, shop, account and product decisions' : 'Recorded activity decisions for your company’s hubs, handlers and fleet'}>
        <Head title="Governance history — Bagoo Admin" />
        <div className="space-y-6">
            <form onSubmit={(event) => { event.preventDefault(); router.get('/governance-history', input, { preserveState: true }); }} className="rounded-xl border border-slate-300 bg-white p-5">
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <label className="text-sm font-semibold text-slate-700">Decision source<select className={control} value={input.source} onChange={(event) => change('source', event.target.value)}><option value="">All available sources</option>{sources.map((source) => <option key={source.value} value={source.value}>{source.label}</option>)}</select></label>
                    <label className="text-sm font-semibold text-slate-700">Subject type<select className={control} value={input.subject_type} onChange={(event) => change('subject_type', event.target.value)}><option value="">All subjects</option>{subjectTypes.map((type) => <option key={type} value={type}>{recordLabel(type)}</option>)}</select></label>
                    <label className="text-sm font-semibold text-slate-700">Subject ID<input type="number" min="1" className={control} value={input.subject_id} onChange={(event) => change('subject_id', event.target.value)} /></label>
                    <label className="text-sm font-semibold text-slate-700">Reviewer ID<input type="number" min="1" className={control} value={input.actor_id} onChange={(event) => change('actor_id', event.target.value)} /></label>
                    <label className="text-sm font-semibold text-slate-700">From (Philippine date)<input type="date" className={control} value={input.from} onChange={(event) => change('from', event.target.value)} /></label>
                    <label className="text-sm font-semibold text-slate-700">Through (Philippine date)<input type="date" className={control} value={input.to} onChange={(event) => change('to', event.target.value)} /></label>
                </div>
                {Object.keys(errors).length > 0 && <div role="alert" className="mt-4 space-y-1 text-sm text-rose-700">{Object.entries(errors).map(([field, message]) => <p key={field}>{message}</p>)}</div>}
                <div className="mt-4 flex gap-3"><button className="rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white">Apply filters</button><button type="button" onClick={() => { setInput(empty); router.get('/governance-history'); }} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Clear</button></div>
            </form>
            <div className="overflow-hidden rounded-xl border border-slate-300 bg-white">
                <div className="border-b border-slate-300 p-5"><p className="text-sm text-slate-600">{events.total} recorded decisions. History is read only. Current account and resource details may differ from a saved decision.</p></div>
                <div className="overflow-x-auto"><table className="w-full text-left text-sm">
                    <thead className="bg-slate-50 text-xs text-slate-600"><tr>{['Decision', 'Subject', 'Recorded reviewer', 'Philippine time', 'Record'].map((label) => <th key={label} className="px-5 py-3">{label}</th>)}</tr></thead>
                    <tbody className="divide-y divide-slate-200">{events.data.map((event) => <tr key={`${event.source}-${event.id}`}>
                        <td className="max-w-sm px-5 py-4"><p className="font-semibold text-slate-900">{event.source_label}</p><p className="text-slate-600">{recordLabel(event.action)}</p><p className="mt-1 line-clamp-2 text-xs text-slate-500">{event.reason ?? 'No reason recorded'}</p></td>
                        <td className="px-5 py-4"><p>{event.subject.name ?? 'Name not recorded'}</p><p className="text-xs text-slate-500">{recordLabel(event.subject.type)} #{event.subject.id}</p></td>
                        <td className="px-5 py-4"><p>{event.actor.name}</p><p className="text-xs text-slate-500">Reviewer #{event.actor.id}</p></td>
                        <td className="whitespace-nowrap px-5 py-4 text-slate-600">{governanceTime(event.occurred_at)}</td>
                        <td className="px-5 py-4"><Link href={event.detail_url} className="font-semibold text-[#E00D42] hover:underline">View record</Link></td>
                    </tr>)}</tbody>
                </table></div>
                {events.data.length === 0 && <p className="p-8 text-center text-sm text-slate-500">No recorded decisions match this view.</p>}
                <div className="flex items-center justify-between gap-4 border-t border-slate-300 p-5 text-sm"><span className="text-slate-600">Page {events.current_page} of {events.last_page}</span><div className="flex gap-3">{events.prev_page_url && <Link href={events.prev_page_url} className="rounded-lg border border-slate-300 px-3 py-2">Previous</Link>}{events.next_page_url && <Link href={events.next_page_url} className="rounded-lg border border-slate-300 px-3 py-2">Next</Link>}</div></div>
            </div>
        </div>
    </DashboardLayout>;
}
