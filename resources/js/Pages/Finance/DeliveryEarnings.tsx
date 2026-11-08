import { Head, Link, usePage } from '@inertiajs/react';
import { Banknote, Clock3, ReceiptText } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { HubBadge, HubPanel, HubPageHeader, hubButton } from '@/Components/Logistics/Workspace';
import type { PageProps } from '@/types';

type Entry = { id: string; order_number: string; tracking_number: string; role: string; pool: string; amount_cents: number; net_amount_cents: number; status: string; virtual_only: boolean; source_reference: string; earned_at: string };
type Props = { summary: { confirmed_cents: string; pending_cents: string }; entries: { data: Entry[]; links: { url: string | null; active: boolean; label: string }[] } };
const money = (value: number | string) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value) / 100);

export default function DeliveryEarnings({ summary, entries }: Props) {
    const logistics = usePage<PageProps>().props.auth.user?.role === 'logistics';
    return (
        <DashboardLayout title="Delivery earnings">
            <Head title="Delivery earnings" />
            <div className="space-y-6">
                <HubPageHeader title="Delivery earnings" icon={Banknote} description="Released earnings are recorded entitlements. Cash payments and virtual credits retain their original payment records." />

                <div className="grid grid-cols-1 divide-y divide-zinc-200/80 overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs sm:grid-cols-2 sm:divide-x sm:divide-y-0">
                    {[
                        { label: 'Released earnings', value: summary.confirmed_cents, icon: Banknote },
                        { label: 'Awaiting COD handover', value: summary.pending_cents, icon: Clock3 },
                    ].map(({ label, value, icon: Icon }) => (
                        <div key={label} className="p-5 sm:p-6">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-semibold text-zinc-500">{label}</span>
                                <span className="flex h-7 w-7 items-center justify-center rounded-md border border-zinc-200/80 bg-zinc-50 text-zinc-600">
                                    <Icon className="h-3.5 w-3.5" />
                                </span>
                            </div>
                            <p className="mt-2.5 font-mono text-2xl font-bold tracking-tight tabular-nums text-[#121316] sm:text-3xl">{money(value)}</p>
                        </div>
                    ))}
                </div>

                <HubPanel title="Original earnings entries" description="Each entry keeps its original order, amount, funding source and release status." icon={<ReceiptText className="h-4 w-4" />}>
                    {logistics && (
                        <div className="space-y-3 md:hidden" aria-label="Earnings entries">
                            {entries.data.map(entry => (
                                <article key={entry.id} className="rounded-xl border border-zinc-200/90 bg-zinc-50/40 p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <h3 className="break-all text-sm font-semibold text-[#121316]">{entry.order_number}</h3>
                                            <p className="mt-0.5 break-all font-mono text-xs text-zinc-500">{entry.tracking_number}</p>
                                        </div>
                                        <div className="text-right">
                                            <p className="text-xs text-zinc-500">Net earnings</p>
                                            <p className="mt-0.5 font-mono text-lg font-bold tabular-nums text-[#121316]">{money(entry.net_amount_cents)}</p>
                                        </div>
                                    </div>
                                    <div className="mt-3.5 flex flex-wrap items-center justify-between gap-2 border-t border-zinc-200/80 pt-3">
                                        <p className="text-xs capitalize text-zinc-600">{entry.role.replaceAll('_', ' ')} · {entry.pool.replaceAll('_', ' ')} · {entry.virtual_only ? 'Virtual credits' : 'COD'}</p>
                                        <HubBadge tone={entry.status === 'Released' ? 'success' : 'warning'}>{entry.status}</HubBadge>
                                    </div>
                                    {entry.amount_cents !== entry.net_amount_cents && <p className="mt-2 text-xs text-zinc-500">Before adjustments: {money(entry.amount_cents)}</p>}
                                </article>
                            ))}
                        </div>
                    )}

                    <div className={logistics ? 'hidden overflow-x-auto md:block' : 'overflow-x-auto'}>
                        <table className="w-full min-w-[680px] text-left text-sm">
                            <thead className="border-b border-zinc-200/80 text-xs text-zinc-500">
                                <tr>
                                    {['Order / Waybill', 'Share', 'Funding source', 'Net earnings', 'Release'].map(label => (
                                        <th key={label} className="pb-3 pr-4 font-semibold">{label}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-zinc-100">
                                {entries.data.map(entry => (
                                    <tr key={entry.id} className="transition-colors hover:bg-zinc-50/50">
                                        <td className="py-3.5 pr-4">
                                            <p className="font-semibold text-[#121316]">{entry.order_number}</p>
                                            <p className="mt-0.5 font-mono text-xs text-zinc-500">{entry.tracking_number}</p>
                                        </td>
                                        <td className="py-3.5 pr-4 capitalize text-zinc-700">{entry.role}</td>
                                        <td className="py-3.5 pr-4 text-zinc-600">{entry.pool.replaceAll('_', ' ')}{entry.virtual_only ? ' · Virtual' : ' · COD'}</td>
                                        <td className="py-3.5 pr-4 font-mono font-semibold tabular-nums text-[#121316]">
                                            {money(entry.net_amount_cents)}
                                            {entry.amount_cents !== entry.net_amount_cents && <p className="mt-0.5 font-sans text-xs font-normal text-zinc-500">Gross {money(entry.amount_cents)}</p>}
                                        </td>
                                        <td className="py-3.5">
                                            <HubBadge tone={entry.status === 'Released' ? 'success' : 'warning'}>{entry.status}</HubBadge>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {entries.data.length === 0 && (
                        <div className="flex min-h-44 flex-col items-center justify-center rounded-xl border border-dashed border-zinc-200 bg-zinc-50/40 py-10 text-center">
                            <span className="flex h-10 w-10 items-center justify-center rounded-lg border border-zinc-200/90 bg-white text-zinc-500 shadow-2xs">
                                <ReceiptText className="h-4 w-4" />
                            </span>
                            <p className="mt-3 text-sm font-semibold text-[#121316]">No delivery earnings recorded yet</p>
                            <p className="mt-1 text-xs text-zinc-500">Actual origin intake and verified delivery create these entries.</p>
                        </div>
                    )}
                </HubPanel>

                {(!logistics || entries.links.filter(link => link.url).length > 1) && (
                    <nav aria-label="Earnings pages" className="flex flex-wrap gap-2">
                        {entries.links.filter(link => link.url).map((link, index) => (
                            <Link key={index} href={link.url!} aria-current={link.active ? 'page' : undefined} className={hubButton} dangerouslySetInnerHTML={{ __html: link.label }} />
                        ))}
                    </nav>
                )}
            </div>
        </DashboardLayout>
    );
}
