import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ShieldAlert, SlidersHorizontal } from 'lucide-react';
import { HubEmpty, hubButton } from '@/Components/Logistics/Workspace';
import type { PageProps } from '@/types';

type Entry = { kind: string; id: number; reference: string; reason: string; companyName: string | null; orderNumber: string | null; trackingNumber: string | null; status: string; responsibleName: string | null; blocker: string | null; canResolve: boolean };

export default function Exceptions({
    exceptions,
    filters,
    sourceGaps,
}: {
    exceptions: { data: Entry[]; total: number; links: { url: string | null; label: string; active: boolean }[] };
    filters: { kind?: string; status?: string };
    sourceGaps: { total: number; data: { orderNumber: string; trackingNumber: string; reason: string }[] };
}) {
    const logistics = usePage<PageProps>().props.auth.user?.role === 'logistics';
    const filter = (key: string, value: string) => router.get('/exceptions', { ...filters, [key]: value }, { preserveState: true, preserveScroll: true });

    return (
        <DashboardLayout title="Delivery exceptions" subtitle="Review actual problems and recovery evidence">
            <Head title="Delivery exceptions" />
            <div className="space-y-5">
                <p className="text-xs sm:text-sm leading-relaxed text-zinc-600">
                    Follow up on restrictions, failed attempts, manifest discrepancies and counter holding. Physical handovers stay with the responsible rider, hub handler or seller.
                </p>

                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200/90 bg-white p-4 shadow-xs">
                    <div className="flex flex-wrap items-center gap-3">
                        {logistics && <SlidersHorizontal className="h-4 w-4 text-zinc-400" aria-hidden="true" />}
                        <label className="flex min-w-0 flex-wrap items-center gap-2 text-xs font-semibold text-[#121316]">
                            Case type
                            <select
                                className="max-w-full rounded-lg border border-zinc-200/90 bg-white px-3 py-1.5 text-xs font-medium text-[#121316] shadow-2xs focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15"
                                value={filters.kind ?? ''}
                                onChange={e => filter('kind', e.target.value)}
                            >
                                <option value="">All types</option>
                                <option value="restriction">Restrictions</option>
                                <option value="attempt">Failed attempts</option>
                                <option value="manifest">Manifest discrepancies</option>
                                <option value="pickup">Counter holding</option>
                            </select>
                        </label>
                        <label className="flex min-w-0 flex-wrap items-center gap-2 text-xs font-semibold text-[#121316]">
                            Review status
                            <select
                                className="max-w-full rounded-lg border border-zinc-200/90 bg-white px-3 py-1.5 text-xs font-medium text-[#121316] shadow-2xs focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15"
                                value={filters.status ?? 'open'}
                                onChange={e => filter('status', e.target.value)}
                            >
                                <option value="open">Open</option>
                                <option value="resolved">Resolved</option>
                                <option value="all">All</option>
                            </select>
                        </label>
                    </div>
                    <span aria-live="polite" className="rounded-md border border-zinc-200/90 bg-zinc-50 px-2.5 py-1 text-xs font-semibold text-zinc-700">
                        {exceptions.total} {exceptions.total === 1 ? 'case' : 'cases'}
                    </span>
                </div>

                {!exceptions.data.length && (
                    logistics ? (
                        <div className="rounded-xl border border-zinc-200/90 bg-white p-4 shadow-xs">
                            <HubEmpty
                                icon={ShieldAlert}
                                title="No exceptions in this view"
                                description="Change the case type or review status to see other recorded problems. Parcel handovers continue in the scanner and transfer pages."
                                action={(filters.kind || (filters.status && filters.status !== 'open')) ? <Link href="/exceptions" className={hubButton}>Show open cases</Link> : undefined}
                            />
                        </div>
                    ) : (
                        <p className="rounded-xl border border-dashed border-zinc-200 bg-white p-6 text-xs text-zinc-500">No recorded cases match this view.</p>
                    )
                )}

                {sourceGaps.total > 0 && (
                    <section className="rounded-xl border border-amber-200/90 bg-amber-50/80 p-4 shadow-2xs">
                        <h2 className="text-sm font-semibold text-amber-900">Missing source records ({sourceGaps.total})</h2>
                        <ul className="mt-2 space-y-2.5">
                            {sourceGaps.data.map(parcel => (
                                <li key={parcel.trackingNumber} className="text-xs text-amber-900">
                                    <p className="font-semibold">{parcel.orderNumber} · <span className="tabular-nums">{parcel.trackingNumber}</span></p>
                                    <p className="mt-0.5">{parcel.reason.replaceAll('_', ' ')}</p>
                                </li>
                            ))}
                        </ul>
                        {sourceGaps.total > sourceGaps.data.length && (
                            <p className="mt-2 text-xs text-amber-900">
                                Showing the first {sourceGaps.data.length} affected parcels. The remaining records also require source recovery.
                            </p>
                        )}
                    </section>
                )}

                <div className="grid gap-3">
                    {exceptions.data.map(item => (
                        <Link
                            key={`${item.kind}-${item.id}`}
                            href={`/exceptions/${item.kind}/${item.id}`}
                            className="rounded-xl border border-zinc-200/90 bg-white p-4 shadow-xs transition-colors hover:border-[#E00D42]"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 className="text-sm font-bold text-[#121316]">{item.orderNumber ?? item.reference}</h2>
                                <span className="rounded-md border border-zinc-200/90 bg-zinc-50 px-2 py-0.5 text-xs font-semibold capitalize text-zinc-700">{item.status}</span>
                            </div>
                            <p className="mt-1 text-xs text-zinc-500">
                                <span className="tabular-nums">{item.trackingNumber ?? item.reference}</span> · {item.companyName ?? 'Platform review'}
                            </p>
                            <p className="mt-2 text-xs sm:text-sm font-medium text-[#121316]">{item.reason.replaceAll('_', ' ')}</p>
                            <p className="mt-1.5 text-xs text-zinc-500">Responsible: {item.responsibleName ?? 'Awaiting assignment'}</p>
                            {item.status === 'open' && (
                                <p className={`mt-2 text-xs font-medium ${item.canResolve ? 'text-emerald-700' : 'text-amber-800'}`}>
                                    {item.canResolve ? 'Source evidence is available for review.' : item.blocker}
                                </p>
                            )}
                        </Link>
                    ))}
                </div>

                {exceptions.links.filter(link => link.url).length > 1 && (
                    <nav aria-label="Exception pages" className="flex flex-wrap gap-2">
                        {exceptions.links.map((link, index) => (
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
                                <span key={index} className="px-3 py-1.5 text-xs text-zinc-400" dangerouslySetInnerHTML={{ __html: link.label }} />
                            )
                        ))}
                    </nav>
                )}
            </div>
        </DashboardLayout>
    );
}
