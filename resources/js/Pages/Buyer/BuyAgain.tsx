import { Head, Link, router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { useRef, useState } from 'react';
import { ArrowLeft, ShoppingBag } from 'lucide-react';
import BuyerLayout from '@/Layouts/BuyerLayout';

interface Item {
    order_item_id: number;
    original_quantity: number;
    previous_unit_price: string;
    current_unit_price: string | null;
    color: string | null;
    size: string | null;
    name: string;
    image: string | null;
    product_url: string | null;
    maximum_quantity: number;
    can_select: boolean;
    unavailable_reason: string | null;
}
interface Selection { order_item_id: number; quantity: number | string; expected_unit_price: string }
interface Props {
    order: { id: number; order_number: string };
    items: Item[];
    requestToken: string;
    submitUrl: string;
    bagUrl: string;
}
const money = (value: string | number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value));

export default function BuyAgain({ order, items, requestToken, submitUrl, bagUrl }: Props) {
    const form = useForm({ request_token: requestToken, items: items.filter(item => item.can_select).map(item => ({
        order_item_id: item.order_item_id, quantity: Math.min(item.original_quantity, item.maximum_quantity),
        expected_unit_price: item.current_unit_price!,
    })) as Selection[] });
    const [busy, setBusy] = useState(false);
    const [committed, setCommitted] = useState(false);
    const [message, setMessage] = useState('');
    const submitting = useRef(false);
    const toggle = (item: Item, selected: boolean) => form.setData('items', selected
        ? [...form.data.items, { order_item_id: item.order_item_id, quantity: Math.min(item.original_quantity, item.maximum_quantity), expected_unit_price: item.current_unit_price! }]
        : form.data.items.filter(row => row.order_item_id !== item.order_item_id));
    const submit = async (event: React.FormEvent) => {
        event.preventDefault();
        if (submitting.current || committed || !form.data.items.length) return;
        submitting.current = true; setBusy(true); form.clearErrors();
        let confirmed = false;
        try {
            const { data } = await axios.post(submitUrl, form.data, { headers: { Accept: 'application/json' } });
            if (!data.result?.cart_id || !Array.isArray(data.result.items)) throw new Error('The result could not be confirmed. Retry this selection or check your Bag.');
            setCommitted(true);
            confirmed = true;
            setMessage(data.replayed ? 'This selection was already added. Open your current Shopping Bag.' : 'Your selected items were added at current prices.');
            router.visit(bagUrl, { onError: () => setMessage('Your items were added. Open your Shopping Bag to continue.') });
        } catch (error) {
            if (confirmed) {
                setMessage('Your items were added. Open your Shopping Bag to continue.');
                return;
            }
            const response = axios.isAxiosError(error) ? error.response : null;
            const errors = response?.data?.errors;
            form.setError('items', errors ? Object.values(errors).flat().join(' ') : response?.data?.message ?? (error instanceof Error && !axios.isAxiosError(error)
                ? error.message : 'The result could not be confirmed. Retry this selection safely or check your Bag.'));
        } finally { submitting.current = false; setBusy(false); }
    };

    return <BuyerLayout>
        <Head title="Buy again — BagooPH" />
        <main className="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6">
            <Link href={route('buyer.orders.show', order.id)} className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600"><ArrowLeft className="h-4 w-4" />Back to purchase</Link>
            <div className="border-b border-slate-300 pb-5"><h1 className="text-2xl font-bold text-slate-900">Buy again</h1><p className="mt-1 text-sm text-slate-500">Purchase {order.order_number}. Review current prices and choose what to add to your Bag.</p></div>
            <p className="rounded-xl border border-slate-300 bg-white p-4 text-sm text-slate-600">Your original purchase stays unchanged. Stock is checked again when you add items; it is reserved only at normal checkout. Choose your delivery address and review shipping and vouchers there.</p>
            {message && <div role="status" className="rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-800"><p>{message}</p><Link href={bagUrl} className="mt-2 inline-block font-bold underline">Review Shopping Bag</Link></div>}
            {form.errors.items && <div role="alert" className="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-700"><p>{form.errors.items}</p><Link href={route('buyer.orders.buy-again', order.id)} className="mt-2 inline-block font-semibold underline">Open a new preview</Link><span className="px-2">·</span><Link href={bagUrl} className="font-semibold underline">Check Shopping Bag</Link></div>}
            <form onSubmit={submit} className="space-y-4">
                {items.map(item => {
                    const selected = form.data.items.find(row => row.order_item_id === item.order_item_id);
                    return <article key={item.order_item_id} className="rounded-2xl border border-slate-300 bg-white p-5 shadow-xs">
                        <div className="flex items-start gap-3">
                            <input id={`buy-again-item-${item.order_item_id}`} type="checkbox" checked={Boolean(selected)} disabled={!item.can_select || busy || committed} onChange={event => toggle(item, event.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" />
                            {item.image && <img src={item.image} alt="" className="h-14 w-14 shrink-0 rounded-xl object-cover" />}
                            <div className="min-w-0 flex-1 space-y-2">
                                <label htmlFor={`buy-again-item-${item.order_item_id}`} className="block font-bold text-slate-900">{item.name}</label>
                                <p className="text-xs text-slate-500">Original quantity: {item.original_quantity}{item.color !== null ? ` · ${item.color}` : ''}{item.size !== null ? ` · ${item.size}` : ''}</p>
                                <div className="text-sm"><span className="text-slate-500">Previous unit price: {money(item.previous_unit_price)}</span>{item.current_unit_price !== null && <p className="font-semibold text-[#E00D42]">Current unit price: {money(item.current_unit_price)}{item.current_unit_price !== item.previous_unit_price ? ' · Price changed' : ''}</p>}</div>
                                {item.unavailable_reason && <p className="text-sm font-medium text-amber-800">{item.unavailable_reason}</p>}
                                {item.can_select && <div className="flex flex-wrap items-center gap-3">
                                    <label htmlFor={`buy-again-quantity-${item.order_item_id}`} className="text-sm text-slate-600">Quantity to add</label>
                                    <input id={`buy-again-quantity-${item.order_item_id}`} type="number" min="1" max={item.maximum_quantity} step="1" required disabled={!selected || busy || committed} value={selected?.quantity ?? ''} onChange={event => form.setData('items', form.data.items.map(row => row.order_item_id === item.order_item_id ? { ...row, quantity: event.target.value } : row))} className="w-20 rounded-lg border-slate-300 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]" />
                                    <span className="text-xs text-slate-500">Up to {item.maximum_quantity} more with your current Bag.</span>
                                </div>}
                                {item.product_url && <Link href={item.product_url} className="inline-block text-xs font-semibold text-[#E00D42] underline">View current product</Link>}
                            </div>
                        </div>
                    </article>;
                })}
                {items.length === 0 && <p className="rounded-xl border border-slate-300 bg-white p-5 text-sm text-slate-500">No items are available from this purchase.</p>}
                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-300 pt-5">
                    <p className="text-sm text-slate-500">{form.data.items.length} selected. If one selection fails, nothing is added.</p>
                    <button type="submit" disabled={busy || committed || !form.data.items.length} className="inline-flex items-center gap-2 rounded-xl bg-[#E00D42] px-5 py-3 text-sm font-bold text-white hover:bg-[#C20836] disabled:cursor-not-allowed disabled:opacity-60"><ShoppingBag className="h-4 w-4" />{committed ? 'Added to Bag' : busy ? 'Adding…' : 'Add selected to Bag'}</button>
                </div>
            </form>
        </main>
    </BuyerLayout>;
}
