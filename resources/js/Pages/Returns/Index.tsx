import { Head, Link, usePage } from '@inertiajs/react';
import { PackageCheck, ChevronRight } from 'lucide-react';
import { HubPageHeader } from '@/Components/Logistics/Workspace';
import ReturnLayout from '@/Components/Returns/ReturnLayout';
import ReturnStatus, { returnDate } from '@/Components/Returns/ReturnStatus';
import { PageProps, PaginatedData } from '@/types';

interface Summary { id: number; reference: string; status: string; reason: string; created_at: string; order_number: string }

export default function ReturnsIndex({ cases }: { cases: PaginatedData<Summary> }) {
    const { auth } = usePage<PageProps>().props;
    const buyer = auth.user?.role === 'buyer';

    return (
        <ReturnLayout>
            <Head title={auth.user?.role === 'logistics' ? 'Buyer returns — BagooPH' : 'Returns and refunds — BagooPH'} />
            <div className={`${auth.user?.role === 'logistics' ? 'max-w-5xl' : 'mx-auto max-w-5xl py-4'} space-y-6`}>
                <div className="flex flex-wrap items-start justify-between gap-4 border-b border-zinc-200 pb-5">
                    <div>
                        {auth.user?.role === 'logistics' ? (
                            <HubPageHeader
                                title="Buyer returns"
                                icon={PackageCheck}
                                description="Follow accepted returns through their actual hub handovers. Decisions, physical receipt and repayment keep separate evidence."
                            />
                        ) : (
                            <h1 className="text-2xl font-bold text-[#121316]">Returns and refunds</h1>
                        )}
                        <p className={`mt-2 max-w-2xl text-sm leading-6 text-zinc-600 ${auth.user?.role === 'logistics' ? 'hidden' : ''}`}>
                            {buyer
                                ? 'Request a return from your purchase within seven days of delivery, including after confirming receipt. Follow each decision, parcel handover and repayment here.'
                                : 'Check the buyer’s original items and evidence. A return decision, physical receipt and repayment are separate steps.'}
                        </p>
                    </div>
                    {buyer && (
                        <Link
                            href={route('buyer.orders.index')}
                            className="rounded-lg border border-zinc-200/90 bg-white px-4 py-2 text-xs font-semibold text-zinc-700 shadow-2xs hover:bg-zinc-50 hover:border-zinc-300 transition-colors"
                        >
                            My purchases
                        </Link>
                    )}
                </div>

                {cases.data.length === 0 ? (
                    <section className="flex min-h-72 flex-col items-center justify-center rounded-xl border border-zinc-200/90 bg-white px-6 py-12 text-center shadow-xs">
                        <PackageCheck className="mb-4 h-9 w-9 text-zinc-400" aria-hidden="true" />
                        <h2 className="text-lg font-bold text-[#121316]">No return requests</h2>
                        <p className="mt-2 max-w-md text-sm leading-6 text-zinc-500">
                            {buyer ? 'If a delivered item has a problem, open its purchase and choose Request a return.' : 'Requests linked to your account will appear here for review.'}
                        </p>
                    </section>
                ) : (
                    <div className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs">
                        <div className="border-b border-zinc-100 bg-zinc-50/40 px-5 py-3 text-xs font-bold text-[#121316]">
                            Return cases ({cases.data.length})
                        </div>
                        <div className="divide-y divide-zinc-100">
                            {cases.data.map(item => (
                                <Link
                                    key={item.id}
                                    href={`/buyer-returns/${item.id}`}
                                    className="flex flex-wrap items-center justify-between gap-4 px-5 py-4 hover:bg-zinc-50/60 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]"
                                >
                                    <div className="min-w-0">
                                        <p className="inline-block break-all rounded-md border border-rose-200 bg-rose-50 px-2 py-0.5 tabular-nums text-xs font-bold text-[#E00D42]">
                                            {item.reference}
                                        </p>
                                        <p className="mt-2 text-sm font-semibold text-[#121316]">Purchase {item.order_number}</p>
                                        <p className="mt-1 text-xs text-zinc-500">Requested {returnDate(item.created_at)}</p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <ReturnStatus status={item.status} />
                                        <ChevronRight className="h-4 w-4 text-zinc-400" aria-hidden="true" />
                                    </div>
                                </Link>
                            ))}
                        </div>
                    </div>
                )}

                {cases.last_page > 1 && (
                    <nav aria-label="Return pages" className="flex items-center justify-between gap-3 text-xs font-medium text-zinc-600">
                        {cases.prev_page_url ? (
                            <Link href={cases.prev_page_url} className="rounded-lg border border-zinc-200/90 bg-white px-4 py-2 font-semibold text-zinc-700 shadow-2xs hover:bg-zinc-50">
                                Previous
                            </Link>
                        ) : (
                            <span />
                        )}
                        <span>Page {cases.current_page} of {cases.last_page}</span>
                        {cases.next_page_url ? (
                            <Link href={cases.next_page_url} className="rounded-lg border border-zinc-200/90 bg-white px-4 py-2 font-semibold text-zinc-700 shadow-2xs hover:bg-zinc-50">
                                Next
                            </Link>
                        ) : (
                            <span />
                        )}
                    </nav>
                )}
            </div>
        </ReturnLayout>
    );
}
