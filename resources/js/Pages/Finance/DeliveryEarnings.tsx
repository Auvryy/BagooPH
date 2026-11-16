import { Head, Link, usePage } from '@inertiajs/react';
import { Banknote, ReceiptText } from 'lucide-react';
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
                <HubPageHeader title="Delivery earnings" icon={Banknote} roleLabel="Cash & earnings" description="Released earnings are recorded entitlements. Cash payments and virtual credits retain their original payment records." />

                <div className="grid grid-cols-1 divide-y divide-line-200 overflow-hidden rounded-card border border-line-200 bg-white sm:grid-cols-2 sm:divide-x sm:divide-y-0">
                    <div className="p-5 sm:p-6">
                        <p className="text-[13px] text-ink-600">Released earnings</p>
                        <p className="mt-1 text-[28px] font-bold leading-9 tabular-nums text-success">{money(summary.confirmed_cents)}</p>
                    </div>
                    <div className="p-5 sm:p-6">
                        <p className="text-[13px] text-ink-600">Awaiting COD handover</p>
                        <p className={`mt-1 text-[28px] font-bold leading-9 tabular-nums ${summary.pending_cents > 0 ? 'text-warning' : 'text-ink-900'}`}>{money(summary.pending_cents)}</p>
                    </div>
                </div>

                <HubPanel title="Original earnings entries" description="Each entry keeps its original order, amount, funding source and release status." icon={<ReceiptText className="h-4 w-4" />}>
                    {logistics && (
                        <div className="space-y-3 md:hidden" aria-label="Earnings entries">
                            {entries.data.map(entry => (
                                <article key={entry.id} className="rounded-xl border border-zinc-200/90 bg-zinc-50/40 p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <h3 className="break-all text-sm font-semibold text-ink-900">{entry.order_number}</h3>
                                            <p className="mt-0.5 break-all tabular-nums text-xs text-zinc-500">{entry.tracking_number}</p>
                                        </div>
                                        <div className="text-right">
                                            <p className="text-xs text-zinc-500">Net earnings</p>
                                            <p className="mt-0.5 tabular-nums text-lg font-bold tabular-nums text-ink-900">{money(entry.net_amount_cents)}</p>
                                        </div>
                                    </div>
                                    <div className="mt-3.5 flex flex-wrap items-center justify-between gap-2 border-t border-zinc-200/80 pt-3">
                                        <p className="text-xs capitalize text-zinc-600">{entry.role.replaceAll('_', ' ')} · {entry.pool.replaceAll('_', ' ')} · {entry.virtual_only ? 'Virtual credits' : 'COD'}</p>
                                        <HubBadge tone={entry.status === 'Released' ? 'success' : 'warning'}>{entry.status}</HubBadge>
                                    </div>
                                    <p className="mt-2 break-all text-xs text-zinc-500">Receipt: {entry.source_reference}</p>
                                    {entry.amount_cents !== entry.net_amount_cents && <p className="mt-2 text-xs text-zinc-500">{money(entry.amount_cents)} before vouchers this party funded</p>}
                                </article>
                            ))}
                        </div>
                    )}

                    <div className={`${logistics ? 'hidden overflow-x-auto md:block' : 'overflow-x-auto'} ${entries.data.length === 0 ? '!hidden' : ''}`}>
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
                                            <p className="font-semibold text-ink-900">{entry.order_number}</p>
                                            <p className="mt-0.5 tabular-nums text-xs text-zinc-500">{entry.tracking_number}</p>
                                        </td>
                                        <td className="py-3.5 pr-4 text-zinc-700"><span className="capitalize">{entry.role}</span><p className="mt-1 break-all text-xs text-zinc-500">Receipt: {entry.source_reference}</p></td>
                                        <td className="py-3.5 pr-4 text-zinc-600">{entry.pool.replaceAll('_', ' ')}{entry.virtual_only ? ' · Virtual' : ' · COD'}</td>
                                        <td className="py-3.5 pr-4 tabular-nums font-semibold tabular-nums text-ink-900">
                                            {money(entry.net_amount_cents)}
                                            {entry.amount_cents !== entry.net_amount_cents && <p className="mt-0.5 font-sans text-xs font-normal text-zinc-500">{money(entry.amount_cents)} before funded vouchers</p>}
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
                        <div className="flex min-h-36 flex-col items-center justify-center rounded-control bg-surface-50 py-8 text-center">
                            <span className="flex h-10 w-10 items-center justify-center rounded-full bg-surface-100 text-ink-500">
                                <ReceiptText className="h-4 w-4" aria-hidden="true" />
                            </span>
                            <p className="mt-3 text-sm font-semibold text-ink-900">No delivery earnings yet</p>
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
