import { Head, Link, usePage } from '@inertiajs/react';
import { PackageCheck, ChevronRight } from 'lucide-react';
import ReturnLayout from '@/Components/Returns/ReturnLayout';
import ReturnStatus, { returnDate } from '@/Components/Returns/ReturnStatus';
import { PageProps, PaginatedData } from '@/types';

interface Summary { id: number; reference: string; status: string; reason: string; created_at: string; order_number: string }

export default function ReturnsIndex({ cases }: { cases: PaginatedData<Summary> }) {
    const { auth } = usePage<PageProps>().props;
    const buyer = auth.user?.role === 'buyer';
    return <ReturnLayout>
        <Head title="Returns and refunds — BagooPH" />
        <div className="mx-auto max-w-5xl space-y-6 py-4">
            <div className="flex flex-wrap items-start justify-between gap-4 border-b border-slate-300 pb-5">
                <div><h1 className="text-2xl font-bold text-slate-900">Returns and refunds</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600">{buyer
                    ? 'Request a return from your purchase within seven days of delivery, including after confirming receipt. Follow each decision, parcel handover and repayment here.'
                    : 'Check the buyer’s original items and evidence. A return decision, physical receipt and repayment are separate steps.'}</p></div>
                {buyer && <Link href={route('buyer.orders.index')} className="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">My purchases</Link>}
            </div>
            {cases.data.length === 0 ? <section className="flex min-h-72 flex-col items-center justify-center rounded-2xl border border-slate-300 bg-white px-6 py-12 text-center">
                <PackageCheck className="mb-4 h-9 w-9 text-slate-400" aria-hidden="true" /><h2 className="text-lg font-bold text-slate-900">No return requests</h2>
                <p className="mt-2 max-w-md text-sm leading-6 text-slate-600">{buyer ? 'If a delivered item has a problem, open its purchase and choose Request a return.' : 'Requests linked to your account will appear here for review.'}</p>
            </section> : <div className="divide-y divide-slate-300 overflow-hidden rounded-2xl border border-slate-300 bg-white">
                {cases.data.map(item => <Link key={item.id} href={`/buyer-returns/${item.id}`} className="flex flex-wrap items-center justify-between gap-4 px-5 py-5 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]">
                    <div className="min-w-0"><p className="break-all font-bold text-slate-900">{item.reference}</p><p className="mt-1 text-sm text-slate-600">Purchase {item.order_number}</p><p className="mt-1 text-xs text-slate-500">Requested {returnDate(item.created_at)}</p></div>
                    <div className="flex items-center gap-3"><ReturnStatus status={item.status} /><ChevronRight className="h-4 w-4 text-slate-400" aria-hidden="true" /></div>
                </Link>)}
            </div>}
            {cases.last_page > 1 && <nav aria-label="Return pages" className="flex items-center justify-between gap-3 text-sm">
                {cases.prev_page_url ? <Link href={cases.prev_page_url} className="rounded-lg border border-slate-300 px-4 py-2 font-semibold">Previous</Link> : <span />}
                <span>Page {cases.current_page} of {cases.last_page}</span>
                {cases.next_page_url ? <Link href={cases.next_page_url} className="rounded-lg border border-slate-300 px-4 py-2 font-semibold">Next</Link> : <span />}
            </nav>}
        </div>
    </ReturnLayout>;
}
