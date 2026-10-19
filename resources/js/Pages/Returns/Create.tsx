import { FormEvent, useCallback, useRef, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Camera } from 'lucide-react';
import ReturnLayout from '@/Components/Returns/ReturnLayout';
import { returnDate } from '@/Components/Returns/ReturnStatus';
import useRequestError from '@/hooks/useCourierRequestError';

interface PurchasedItem { id: number; name: string; color: string | null; size: string | null; unit_price: string; quantity: number; available_quantity: number }
interface Props { order: { id: number; order_number: string }; items: PurchasedItem[]; reasons: Record<string, string>; requestToken: string; requestUntil: string }
const control = 'mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-[#E00D42] focus:outline-none focus:ring-1 focus:ring-[#E00D42] disabled:bg-slate-50';

export default function CreateReturn({ order, items, reasons, requestToken, requestUntil }: Props) {
    const form = useForm({ request_token: requestToken, reason: '', description: '', items: [] as Array<{ order_item_id: number; quantity: number }>, photos: [] as File[] });
    const pending = useRef(false);
    const [failure, setFailure] = useState('');
    useRequestError(pending, useCallback(message => setFailure(message), []));
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (pending.current || form.processing) return;
        if (!form.data.items.length) { form.setError('items', 'Select at least one purchased item.'); return; }
        if (!form.data.photos.length || form.data.photos.length > 5 || form.data.photos.some(file => file.size > 5242880 || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type))) {
            form.setError('photos', 'Choose one to five JPG, PNG or WebP photos, up to 5 MB each.'); return;
        }
        pending.current = true; setFailure('');
        form.post(`/buyer-returns/orders/${order.id}`, { forceFormData: true, preserveScroll: true, onFinish: () => { pending.current = false; } });
    };
    return <ReturnLayout>
        <Head title="Request a return — BagooPH" />
        <form onSubmit={submit} className="mx-auto max-w-3xl space-y-6 py-4" aria-busy={form.processing || undefined}>
            <Link href={route('buyer.orders.show', order.id)} className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600"><ArrowLeft className="h-4 w-4" />Back to purchase</Link>
            <div className="border-b border-slate-300 pb-5"><h1 className="text-2xl font-bold text-slate-900">Request a return</h1><p className="mt-2 text-sm text-slate-600">Purchase {order.order_number}</p><p className="mt-2 text-sm leading-6 text-slate-600">Request by <strong>{returnDate(requestUntil)}</strong>. Confirming receipt does not end this window. Repayment covers the original paid item amount; original shipping stays separate.</p></div>
            {failure && <p role="alert" className="rounded-xl border border-rose-300 bg-rose-50 p-4 text-sm text-rose-800">{failure} <Link href={`/buyer-returns/orders/${order.id}/create`} className="font-semibold underline">Reload return form</Link></p>}
            {Object.keys(form.errors).length > 0 && <div role="alert" className="rounded-xl border border-rose-300 bg-rose-50 p-4 text-sm text-rose-800"><p className="font-bold">Check your return details</p><ul className="mt-2 list-disc space-y-1 pl-5">{Object.entries(form.errors).map(([key, message]) => <li key={key}>{String(message)}</li>)}</ul></div>}
            <fieldset disabled={form.processing} className="space-y-4 rounded-2xl border border-slate-300 bg-white p-5 sm:p-6">
                <legend className="px-1 text-base font-bold text-slate-900">Which items have a problem?</legend>
                {items.map(item => {
                    const selected = form.data.items.find(row => row.order_item_id === item.id);
                    return <div key={item.id} className="flex flex-wrap items-start gap-3 border-b border-slate-200 pb-4 last:border-0 last:pb-0">
                        <input id={`return-item-${item.id}`} type="checkbox" checked={!!selected} disabled={item.available_quantity < 1 || form.processing} className="mt-1 rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" onChange={event => {
                            form.clearErrors('items');
                            form.setData('items', event.target.checked ? [...form.data.items, { order_item_id: item.id, quantity: 1 }] : form.data.items.filter(row => row.order_item_id !== item.id));
                        }} />
                        <div className="min-w-0 flex-1"><label htmlFor={`return-item-${item.id}`} className="break-words text-sm font-bold text-slate-900">{item.name}</label><p className="mt-1 text-xs text-slate-500">{[item.color, item.size].filter(Boolean).join(' · ')} · Purchased {item.quantity}</p><p className="mt-1 text-xs text-slate-600">{item.available_quantity > 0 ? `${item.available_quantity} available to request` : 'All purchased units already have a return request.'}</p></div>
                        {selected && <label className="w-24 text-xs font-semibold text-slate-600">Quantity<input type="number" min="1" max={item.available_quantity} step="1" value={selected.quantity} className={control} onChange={event => form.setData('items', form.data.items.map(row => row.order_item_id === item.id ? { ...row, quantity: Number(event.target.value) } : row))} /></label>}
                    </div>;
                })}
            </fieldset>
            <fieldset disabled={form.processing} className="space-y-5 rounded-2xl border border-slate-300 bg-white p-5 sm:p-6">
                <legend className="px-1 text-base font-bold text-slate-900">Describe the problem</legend>
                <label className="block text-sm font-semibold text-slate-800">Reason<select required value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} className={control}><option value="">Choose a reason</option>{Object.entries(reasons).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
                <label className="block text-sm font-semibold text-slate-800">What happened?<textarea required minLength={10} maxLength={1000} rows={5} value={form.data.description} onChange={event => form.setData('description', event.target.value)} className={control} /><span className="mt-1 block text-xs font-normal text-slate-500">Use 10–1,000 characters. Describe the affected item and what you received. {form.data.description.length}/1,000</span></label>
                <label className="block text-sm font-semibold text-slate-800"><span className="flex items-center gap-2"><Camera className="h-4 w-4" aria-hidden="true" />Photos of the problem</span><input required multiple type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" className={control} onChange={event => { form.clearErrors('photos'); form.setData('photos', Array.from(event.target.files ?? [])); }} /><span className="mt-2 block text-xs font-normal leading-5 text-slate-500">One to five photos. JPG, PNG or WebP, up to 5 MB each. Photos are private to the involved account owners and authorized reviewers.</span></label>
                {form.data.photos.length > 0 && <ul className="space-y-1 text-xs text-slate-600">{form.data.photos.map((file, index) => <li key={`${file.name}-${index}`} className="break-all">{file.name} · {(file.size / 1048576).toFixed(2)} MB</li>)}</ul>}
            </fieldset>
            <div className="flex flex-wrap items-center justify-between gap-4 border-t border-slate-300 pt-5"><p className="max-w-md text-xs leading-5 text-slate-500">The seller reviews your request first. Approval starts the return instructions; repayment follows actual return and payment checks.</p><button type="submit" disabled={form.processing || !items.some(item => item.available_quantity > 0)} className="rounded-xl bg-[#E00D42] px-5 py-3 text-sm font-bold text-white hover:bg-[#C20836] disabled:opacity-50">{form.processing ? 'Saving request…' : 'Request a return'}</button></div>
        </form>
    </ReturnLayout>;
}
