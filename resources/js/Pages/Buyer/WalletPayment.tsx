import { FormEvent, useEffect, useRef, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Wallet } from 'lucide-react';
import BuyerOrderAccessLayout from '@/Layouts/BuyerOrderAccessLayout';
import { trackingDate } from '@/utils/orderTracking';
import { walletMoney } from '@/utils/wallet';

export interface WalletPaymentData {
    id: number; reference: string; status: string; amount_cents: number; order_id: number; order_number: string;
    balance_cents: number | null; expires_at: string; paid_at: string | null; can_pay: boolean; payment_reference: string | null; request_token: string;
    items: { id: number; name: string; quantity: number; unit_price: string }[];
    last_attempt: { outcome: string; message: string } | null;
    refunded_cents?: number; refund_reference?: string | null;
}

export default function WalletPayment({ payment, canUsePortal = false }: { payment: WalletPaymentData; canUsePortal?: boolean }) {
    const form = useForm<{ payment_confirmed: boolean; request_token: string }>({ payment_confirmed: false, request_token: payment.request_token });
    const busy = useRef(false);
    const [now, setNow] = useState(Date.now);
    useEffect(() => {
        const delay = Date.parse(payment.expires_at) - Date.now();
        if (!Number.isFinite(delay) || delay <= 0) return;
        const timeout = window.setTimeout(() => setNow(Date.now()), Math.min(delay + 1, 2147483647));
        return () => window.clearTimeout(timeout);
    }, [payment.expires_at]);
    const canPay = payment.can_pay && now < Date.parse(payment.expires_at);
    const send = (action: 'pay' | 'cancel') => {
        if (busy.current || form.processing || !canPay) return;
        if (action === 'pay' && !form.data.payment_confirmed) { form.setError('payment_confirmed', 'Confirm the exact wallet amount before paying.'); return; }
        busy.current = true;
        form.post(`/wallet/payments/${payment.id}/${action}`, { preserveScroll: true,
            onError: () => form.setData('request_token', crypto.randomUUID()),
            onFinish: () => { busy.current = false; },
        });
    };
    const submit = (event: FormEvent) => { event.preventDefault(); send('pay'); };
    const errors = form.errors as Record<string, string>;
    const paid = payment.status === 'paid' && !!payment.payment_reference;
    return <BuyerOrderAccessLayout canUsePortal={canUsePortal}>
        <Head title="Wallet payment — BagooPH" />
        <main className="mx-auto max-w-3xl space-y-5 px-4 py-8 sm:px-6">
            <Link href={route('buyer.orders.show', payment.order_id)} className="inline-flex items-center gap-2 text-xs font-semibold text-slate-600 hover:text-[#C20836]"><ArrowLeft className="h-4 w-4" />Back to order</Link>
            <section className="space-y-5 rounded-2xl border border-slate-300 bg-white p-5 sm:p-7">
                <header className="flex items-start gap-3"><Wallet className="mt-0.5 h-5 w-5 shrink-0 text-[#E00D42]" /><div><h1 className="text-xl font-bold text-slate-900">{paid ? 'Wallet payment recorded' : 'Pay with Bagoo Wallet'}</h1><p className="mt-1 break-all text-xs text-slate-600">Order {payment.order_number}</p></div></header>
                <p className="text-xs leading-6 text-slate-600">Bagoo Wallet uses admin-approved virtual credits. This payment does not move real cash. Paid wallet parcels require no cash collection at delivery.</p>
                <ul className="divide-y divide-slate-200">{payment.items.map(item => <li key={item.id} className="flex justify-between gap-3 py-3 text-sm"><span className="min-w-0 break-words font-semibold text-slate-800">{item.name}</span><span className="shrink-0 text-xs text-slate-500">Qty {item.quantity}</span></li>)}</ul>
                <dl className="space-y-3 border-y border-slate-300 py-4 text-sm"><div className="flex justify-between gap-3"><dt className="text-slate-600">Available credits</dt><dd className="font-semibold tabular-nums">{payment.balance_cents === null ? 'Unavailable' : walletMoney(payment.balance_cents)}</dd></div><div className="flex justify-between gap-3"><dt className="font-bold text-slate-900">{paid ? 'Amount paid' : 'Exact amount to pay'}</dt><dd className="text-xl font-bold tabular-nums text-[#E00D42]">{walletMoney(payment.amount_cents)}</dd></div></dl>
                {(payment.refunded_cents ?? 0) > 0 && <div role="status" className="space-y-2 text-xs leading-6 text-slate-700"><p className="font-semibold">Refunded to your original wallet: {walletMoney(payment.refunded_cents ?? 0)}</p><p className="break-all">Refund reference: {payment.refund_reference}</p></div>}
                {paid ? <div role="status" className="space-y-2 text-sm text-slate-700"><p className="flex items-center gap-2 font-semibold"><CheckCircle2 className="h-4 w-4 text-emerald-700" />Your saved payment is confirmed.</p><p className="break-all text-xs">Reference: {payment.payment_reference}</p><p className="text-xs">Paid {trackingDate(payment.paid_at)}. Reopening this page does not deduct more credits.</p></div> : <>
                    <p className="text-xs text-slate-600">Payment deadline: {trackingDate(payment.expires_at)} · Philippine time</p>
                    {!canPay && <p role="status" className="text-sm font-semibold text-amber-900">{payment.status === 'pending' ? (now >= Date.parse(payment.expires_at) ? 'The payment deadline has passed. This order cannot accept payment.' : 'Payment is unavailable for this account or saved balance. Review your account or contact support.') : `This payment is ${payment.status}. No further credits will be deducted.`}</p>}
                    {payment.last_attempt && !['paid', 'cancelled_attempt'].includes(payment.last_attempt.outcome) && <p role="status" className="text-sm text-amber-900">{payment.last_attempt.message}</p>}
                    {Object.values(errors).map((error, index) => <p key={index} role="alert" className="text-xs text-rose-700">{error}</p>)}
                    {canPay && <form onSubmit={submit} className="space-y-4"><label className="flex items-start gap-2 text-xs leading-6 text-slate-700"><input type="checkbox" checked={form.data.payment_confirmed} disabled={form.processing} onChange={event => { form.setData('payment_confirmed', event.target.checked); form.clearErrors('payment_confirmed'); }} className="mt-1 rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" />I confirm paying {walletMoney(payment.amount_cents)} from my Bagoo Wallet for this order.</label><div className="flex flex-wrap gap-3"><button type="submit" disabled={form.processing} className="rounded-lg bg-[#E00D42] px-5 py-3 text-xs font-bold text-white hover:bg-[#C20836] disabled:opacity-50">{form.processing ? 'Saving payment…' : payment.last_attempt?.outcome === 'failed' ? 'Pay again' : 'Confirm payment'}</button><button type="button" disabled={form.processing} onClick={() => send('cancel')} className="rounded-lg border border-slate-300 px-4 py-3 text-xs font-semibold text-slate-700 disabled:opacity-50">Cancel payment</button></div></form>}
                    <Link href={route('buyer.profile', { tab: 'wallet' })} className="inline-block text-xs font-semibold text-[#C20836] underline underline-offset-2">View wallet or request credits</Link>
                </>}
            </section>
        </main>
    </BuyerOrderAccessLayout>;
}
