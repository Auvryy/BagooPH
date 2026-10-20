import { FormEvent, useRef } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowDownLeft, ArrowUpRight, Wallet } from 'lucide-react';
import VoucherPagination from '@/Components/Vouchers/VoucherPagination';
import { creditAmountError, walletKind, walletMoney } from '@/utils/wallet';
import { trackingDate } from '@/utils/orderTracking';

export interface WalletData {
    available: boolean; balance?: number | null; balance_cents?: number | null; can_request?: boolean; request_token?: string;
    transactions?: { data: { id: number; reference: string; kind: string; delta_cents: number; balance_after_cents: number; reason: string; order_id: number | null; created_at: string }[];
        current_page: number; last_page: number; links?: { url: string | null; label: string; active: boolean }[] };
    credit_requests?: { id: number; reference: string; amount_cents: number; reason: string; status: string; review_reason: string | null; created_at: string }[];
}

export default function BuyerWalletPanel({ wallet }: { wallet: WalletData }) {
    const form = useForm({ amount: '', reason: '', request_token: wallet.request_token ?? '' });
    const busy = useRef(false);
    const pending = wallet.credit_requests?.some(request => request.status === 'pending') ?? false;
    const canRequest = wallet.available && wallet.can_request && !pending;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (busy.current || !canRequest) return;
        const error = creditAmountError(form.data.amount);
        if (error) { form.setError('amount', error); return; }
        if (form.data.reason.trim().length < 5 || form.data.reason.length > 500) { form.setError('reason', 'Explain your request in 5–500 characters.'); return; }
        busy.current = true;
        form.post('/wallet/credits', { preserveScroll: true,
            onSuccess: () => { form.reset('amount', 'reason'); form.setData('request_token', crypto.randomUUID()); },
            onFinish: () => { busy.current = false; },
        });
    };
    return <div className="space-y-6">
        <header className="flex flex-wrap items-start justify-between gap-4 border-b border-slate-300 pb-5">
            <div><h3 className="flex items-center gap-2 text-lg font-bold text-slate-900"><Wallet className="h-5 w-5 text-[#E00D42]" />Bagoo Wallet</h3><p className="mt-2 max-w-lg text-xs leading-6 text-slate-600">Virtual credits for purchases inside Bagoo. Admin-approved demo credits have no cash value and cannot be withdrawn or transferred.</p></div>
            <div><p className="text-xs text-slate-500">Available demo credits</p><p className="mt-1 text-2xl font-bold tabular-nums text-[#E00D42]">{wallet.available && wallet.balance_cents !== null ? walletMoney(wallet.balance_cents ?? 0) : 'Unavailable'}</p></div>
        </header>
        {!wallet.available && <p role="alert" className="text-sm text-slate-600">Your wallet balance is unavailable. Contact support to review its saved transactions.</p>}
        {wallet.can_request ? <form onSubmit={submit} className="space-y-4 rounded-xl border border-slate-300 p-4 sm:p-5">
            <div><h4 className="text-sm font-bold text-slate-900">Request demo credits</h4><p className="mt-1 text-xs leading-5 text-slate-600">An admin reviews the amount and reason. Requesting credits does not increase your balance.</p></div>
            {pending && <p role="status" className="text-xs font-semibold text-amber-900">Your current request is awaiting admin review.</p>}
            <div className="grid gap-4 sm:grid-cols-[11rem_1fr]">
                <div><label htmlFor="wallet-credit-amount" className="mb-1.5 block text-xs font-semibold text-slate-700">Amount (PHP)</label><input id="wallet-credit-amount" type="text" inputMode="decimal" maxLength={9} value={form.data.amount} disabled={!canRequest || form.processing} onChange={event => { form.setData('amount', event.target.value); if (event.target.value) { const error = creditAmountError(event.target.value); if (error) form.setError('amount', error); else form.clearErrors('amount'); } }} aria-invalid={!!form.errors.amount} aria-describedby="wallet-credit-amount-help" className="w-full rounded-lg border-slate-300 text-sm focus:border-[#E00D42] focus:ring-[#E00D42] disabled:bg-slate-50" /><p id="wallet-credit-amount-help" className="mt-1 text-xs leading-5 text-slate-500">₱1.00–₱100,000.00 per request.</p>{form.errors.amount && <p role="alert" className="mt-1 text-xs text-rose-700">{form.errors.amount}</p>}</div>
                <div><label htmlFor="wallet-credit-reason" className="mb-1.5 block text-xs font-semibold text-slate-700">Reason</label><textarea id="wallet-credit-reason" rows={2} maxLength={500} value={form.data.reason} disabled={!canRequest || form.processing} onChange={event => form.setData('reason', event.target.value)} aria-invalid={!!form.errors.reason} className="w-full rounded-lg border-slate-300 text-sm focus:border-[#E00D42] focus:ring-[#E00D42] disabled:bg-slate-50" /><p className="mt-1 text-xs text-slate-500">5–500 characters.</p>{form.errors.reason && <p role="alert" className="mt-1 text-xs text-rose-700">{form.errors.reason}</p>}</div>
            </div>
            <button type="submit" disabled={!canRequest || form.processing} className="rounded-lg bg-[#E00D42] px-4 py-2.5 text-xs font-bold text-white hover:bg-[#C20836] disabled:opacity-50">{form.processing ? 'Sending…' : 'Request credits'}</button>
        </form> : <p className="text-xs text-slate-600">An active approved buyer account with a verified email is required to request credits or pay for a new order.</p>}
        {(wallet.credit_requests?.length ?? 0) > 0 && <section aria-label="Credit requests"><h4 className="text-sm font-bold text-slate-900">Recent credit requests</h4><ul className="mt-3 divide-y divide-slate-200">{wallet.credit_requests?.map(request => <li key={request.id} className="py-3"><div className="flex flex-wrap justify-between gap-2 text-xs"><span className="font-semibold text-slate-800">{walletMoney(request.amount_cents)}</span><span className="capitalize text-slate-600">{request.status}</span></div><p className="mt-1 text-xs text-slate-600">{trackingDate(request.created_at)}</p>{request.review_reason && <p className="mt-1 break-words text-xs leading-5 text-slate-600">Admin feedback: {request.review_reason}</p>}</li>)}</ul></section>}
        <section aria-label="Wallet transactions"><h4 className="text-sm font-bold text-slate-900">Transactions</h4>{wallet.transactions?.data.length ? <ul className="mt-3 divide-y divide-slate-200">{wallet.transactions.data.map(entry => {
            const Icon = entry.delta_cents > 0 ? ArrowDownLeft : ArrowUpRight;
            return <li key={entry.id} className="flex items-start gap-3 py-4"><Icon aria-hidden="true" className="mt-0.5 h-4 w-4 shrink-0 text-slate-500" /><div className="min-w-0 flex-1"><div className="flex flex-wrap justify-between gap-2 text-xs"><p className="font-semibold text-slate-900">{walletKind(entry.kind)}</p><p className="font-bold tabular-nums text-slate-800">{entry.delta_cents > 0 ? '+' : '−'}{walletMoney(Math.abs(entry.delta_cents))}</p></div><p className="mt-1 break-words text-xs text-slate-500">{trackingDate(entry.created_at)} · Balance {walletMoney(entry.balance_after_cents)}</p><p className="mt-1 break-all text-xs text-slate-500">{entry.reference}</p>{entry.order_id && <Link href={route('buyer.orders.show', entry.order_id)} className="mt-1 inline-block text-xs font-semibold text-[#C20836] underline underline-offset-2">View order</Link>}</div></li>;
        })}</ul> : <div className="flex min-h-40 flex-col items-center justify-center gap-2 text-center"><p className="text-sm font-semibold text-slate-800">No wallet transactions yet</p><p className="max-w-sm text-xs leading-5 text-slate-500">Approved credits, purchases and refunds will appear here.</p></div>}{wallet.transactions && <VoucherPagination page={wallet.transactions} label="Wallet transaction pages" />}</section>
    </div>;
}
