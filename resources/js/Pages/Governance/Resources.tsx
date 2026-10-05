import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { PaginatedData } from '@/types';

type Resource = {
    id: number; type: string; name: string; status: string; eligible: boolean;
    parents: { label: string; name: string; status: string; eligible: boolean }[];
};
type Props = { resources: PaginatedData<Resource>; types: string[]; filters: { type: string; search: string }; baseUrl: string };

export default function Resources({ resources, types, filters, baseUrl }: Props) {
    const [search, setSearch] = useState(filters.search);
    const filter = (type: string) => router.get(baseUrl, { type, search }, { preserveState: true });

    return <DashboardLayout title="Resource activity" subtitle="Keep each resource’s activity separate from its parent approval">
        <Head title="Resource activity — BagooPH" />
        <div className="space-y-5">
            <form onSubmit={(event: React.FormEvent) => { event.preventDefault(); filter(filters.type); }} className="flex flex-col gap-3 rounded-xl border border-slate-300 bg-white p-4 sm:flex-row">
                <div><label htmlFor="resource-type" className="block text-xs font-semibold">Resource type</label><select id="resource-type" value={filters.type} onChange={event => filter(event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 text-sm capitalize focus:border-[#E00D42] focus:ring-[#E00D42]">{types.map(type => <option key={type} value={type}>{type}</option>)}</select></div>
                <div className="flex-1"><label htmlFor="resource-search" className="block text-xs font-semibold">Name or vehicle plate</label><input id="resource-search" value={search} onChange={event => setSearch(event.target.value)} maxLength={100} className="mt-1 w-full rounded-lg border border-slate-300 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]" /></div>
                <button type="submit" className="self-end rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white">Search</button>
            </form>
            <p className="text-sm text-slate-600">Open a review to see current work and record a reasoned decision. Reinstating a parent preserves separate restrictions on its children.</p>
            {resources.data.length === 0 ? <div className="rounded-xl border border-slate-300 bg-white p-6 text-sm text-slate-500">No resources match this review.</div> : <div className="space-y-3">{resources.data.map(resource => <article key={resource.id} className="flex flex-col gap-4 rounded-xl border border-slate-300 bg-white p-5 sm:flex-row sm:items-start sm:justify-between">
                <div><h2 className="font-semibold">{resource.name}</h2><p className="mt-1 text-sm capitalize">Local activity: {resource.status.replaceAll('_', ' ')} · {resource.eligible ? 'Eligible for new work' : 'Unavailable for new work'}</p><div className="mt-3 space-y-1 text-xs text-slate-600">{resource.parents.map(parent => <p key={parent.label}>{parent.label}: {parent.name} · {parent.eligible ? 'Eligible' : 'Unavailable'}</p>)}</div></div>
                <Link href={`${baseUrl}/${resource.type}/${resource.id}`} className="shrink-0 self-start rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold hover:bg-slate-50">Review activity</Link>
            </article>)}</div>}
            {resources.last_page > 1 && <nav aria-label="Resource pages" className="flex flex-wrap gap-2">{resources.links?.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded-lg border border-slate-300 px-3 py-2 text-sm ${link.active ? 'bg-[#E00D42] text-white' : 'bg-white'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-400" dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav>}
        </div>
    </DashboardLayout>;
}
