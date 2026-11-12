import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Receipt } from 'lucide-react';
import ReturnLayout from '@/Components/Returns/ReturnLayout';
import ReturnMoneyPanel, { ReturnMoney } from '@/Components/Returns/ReturnMoneyPanel';
import ReturnStatus from '@/Components/Returns/ReturnStatus';

interface FinancialCase { id: number; reference: string; status: string; version: number; order: { id: number; order_number: string }; money: ReturnMoney | null; canViewReturn: boolean }

export default function Liability({ financialCase: item }: { financialCase: FinancialCase }) {
    return <ReturnLayout><Head title={`${item.reference} · Return finances`} /><main className="mx-auto max-w-4xl space-y-6 py-4">
        <Link href="/return-liabilities" className="inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50/85 px-3 py-2 text-sm font-semibold text-[#C20836] hover:bg-[#E00D42] hover:text-white"><ArrowLeft className="h-4 w-4" />Return liabilities</Link>
        <header className="flex flex-wrap items-start justify-between gap-4 rounded-2xl border border-rose-200 bg-white p-5 shadow-sm"><div><h1 className="flex items-center gap-2 text-xl font-bold text-slate-900"><Receipt className="h-5 w-5 text-[#E00D42]" />Return finances</h1><p className="mt-2 break-all text-sm text-slate-600">{item.reference} · Purchase {item.order.order_number}</p></div><ReturnStatus status={item.status} />
            {item.canViewReturn && <Link href={`/buyer-returns/${item.id}`} className="rounded-lg border border-rose-200 bg-rose-50/85 px-3 py-2 text-sm font-semibold text-[#C20836] hover:bg-[#E00D42] hover:text-white">Open return details</Link>}</header>
        {item.money ? <ReturnMoneyPanel value={item.money} caseId={item.id} version={item.version} /> : <p className="rounded-xl border border-slate-300 bg-white p-5 text-sm text-slate-600">Financial details are unavailable for this account.</p>}
    </main></ReturnLayout>;
}
