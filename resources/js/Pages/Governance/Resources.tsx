import React, { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { HubEmpty } from '@/Components/Logistics/Workspace';
import type { PageProps, PaginatedData } from '@/types';

type Resource = {
    id: number; type: string; name: string; status: string; eligible: boolean;
    parents: { label: string; name: string; status: string; eligible: boolean }[];
};
type Props = { resources: PaginatedData<Resource>; types: string[]; filters: { type: string; search: string }; baseUrl: string };

export default function Resources({ resources, types, filters, baseUrl }: Props) {
    const logistics = usePage<PageProps>().props.auth.user?.role === 'logistics';
    const [search, setSearch] = useState(filters.search);
    const filter = (type: string) => router.get(baseUrl, { type, search }, { preserveState: true });

    return (
        <DashboardLayout title="Resource activity" subtitle="Keep each resource’s activity separate from its parent approval">
            <Head title="Resource activity — BagooPH" />
            <div className="space-y-5">
                <form
                    onSubmit={(event: React.FormEvent) => {
                        event.preventDefault();
                        filter(filters.type);
                    }}
                    className="flex flex-col gap-3 rounded-xl border border-zinc-200/90 bg-white p-4 shadow-xs sm:flex-row"
                >
                    <div>
                        <label htmlFor="resource-type" className="block text-xs font-semibold text-[#121316]">Resource type</label>
                        <select
                            id="resource-type"
                            value={filters.type}
                            onChange={event => filter(event.target.value)}
                            className="mt-1.5 w-full rounded-lg border border-zinc-200/90 bg-white px-3 py-2 text-xs font-medium capitalize text-[#121316] shadow-2xs focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15"
                        >
                            {types.map(type => <option key={type} value={type}>{type}</option>)}
                        </select>
                    </div>
                    <div className="flex-1">
                        <label htmlFor="resource-search" className="block text-xs font-semibold text-[#121316]">Name or vehicle plate</label>
                        <input
                            id="resource-search"
                            value={search}
                            onChange={event => setSearch(event.target.value)}
                            maxLength={100}
                            className="mt-1.5 w-full rounded-lg border border-zinc-200/90 bg-white px-3 py-2 text-xs font-medium text-[#121316] shadow-2xs focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15"
                        />
                    </div>
                    <button type="submit" className="self-end rounded-lg bg-[#E00D42] px-4 py-2 text-xs font-semibold text-white shadow-2xs transition hover:bg-[#C20836]">
                        Search
                    </button>
                </form>

                <p className="text-xs sm:text-sm leading-relaxed text-zinc-600">
                    Open a review to see current work and record a reasoned decision. Reinstating a parent preserves separate restrictions on its children.
                </p>

                {resources.data.length === 0 ? (
                    <div className="rounded-xl border border-zinc-200/90 bg-white p-4 shadow-xs">
                        {logistics ? (
                            <HubEmpty
                                icon={ShieldCheck}
                                title="No resources match this review"
                                description="Try another resource type or search for a shorter name or vehicle plate. Each resource keeps its own activity and approval records."
                            />
                        ) : (
                            <p className="p-6 text-xs text-zinc-500">No resources match this review.</p>
                        )}
                    </div>
                ) : (
                    <div className="space-y-3">
                        {resources.data.map(resource => (
                            <article
                                key={resource.id}
                                className="flex flex-col gap-4 rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs sm:flex-row sm:items-start sm:justify-between"
                            >
                                <div>
                                    <h2 className="text-sm font-bold text-[#121316]">{resource.name}</h2>
                                    <p className="mt-1 text-xs capitalize text-zinc-600">
                                        Local activity: <strong className="text-[#121316]">{resource.status.replaceAll('_', ' ')}</strong> · {resource.eligible ? 'Eligible for new work' : 'Unavailable for new work'}
                                    </p>
                                    <div className="mt-3 space-y-1 text-xs text-zinc-500">
                                        {resource.parents.map(parent => (
                                            <p key={parent.label}>{parent.label}: {parent.name} · {parent.eligible ? 'Eligible' : 'Unavailable'}</p>
                                        ))}
                                    </div>
                                </div>
                                <Link
                                    href={`${baseUrl}/${resource.type}/${resource.id}`}
                                    className="shrink-0 self-start rounded-lg border border-zinc-200/90 bg-white px-3.5 py-2 text-xs font-semibold text-zinc-700 shadow-2xs transition hover:bg-zinc-50 hover:text-[#121316]"
                                >
                                    Review activity
                                </Link>
                            </article>
                        ))}
                    </div>
                )}

                {resources.last_page > 1 && (
                    <nav aria-label="Resource pages" className="flex flex-wrap gap-2">
                        {resources.links?.map((link, index) => (
                            link.url ? (
                                <Link
                                    key={index}
                                    href={link.url}
                                    className={`rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors ${
                                        link.active ? 'border-[#E00D42] bg-[#E00D42] text-white' : 'border-zinc-200/90 bg-white text-zinc-700 hover:bg-zinc-50'
                                    }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ) : (
                                <span key={index} className="rounded-lg border border-zinc-200/90 px-3 py-1.5 text-xs text-zinc-400" dangerouslySetInnerHTML={{ __html: link.label }} />
                            )
                        ))}
                    </nav>
                )}
            </div>
        </DashboardLayout>
    );
}
