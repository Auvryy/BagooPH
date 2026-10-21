import React, { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Product, Shop, PaginatedData } from '@/types';
import { ArrowLeft, CalendarClock, Pencil, X } from 'lucide-react';
import { percentagePrice } from '@/utils/productPricing';

interface Sale {
    id: number; kind: 'store' | 'flash'; name: string; price: string; discount_type?: 'fixed' | 'percentage'; percentage?: number | null;
    starts_at: string; ends_at: string; quantity: number | null; used_quantity: number; ended_at: string | null;
}
interface Props { shop: Shop; product: Product; sales: PaginatedData<Sale> }
const localInput = (iso: string) => {
    const date = new Date(iso);
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(date);
    const value = (type: string) => parts.find(part => part.type === type)?.value;
    return `${value('year')}-${value('month')}-${value('day')}T${value('hour')}:${value('minute')}`;
};
const displayDate = (iso: string) => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(new Date(iso));
const money = (amount: string | number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(amount));

export default function ProductSales({ shop, product, sales }: Props) {
    const [editing, setEditing] = useState<Sale | null>(null);
    const [ending, setEnding] = useState<number | null>(null);
    const [endError, setEndError] = useState('');
    const canSchedule = product.status === 'active' && !product.compliance_restricted;
    const initial = { kind: 'store' as 'store' | 'flash', name: '', price: '', discount_type: 'percentage' as 'fixed' | 'percentage', percentage: '', starts_at: '', ends_at: '', quantity: '' };
    const form = useForm(initial);
    const clear = () => { setEditing(null); form.setData(initial); form.clearErrors(); };
    const edit = (sale: Sale) => {
        setEditing(sale); form.clearErrors();
        form.setData({ kind: sale.kind, name: sale.name, price: sale.price, discount_type: sale.discount_type ?? 'fixed', percentage: sale.percentage == null ? '' : String(sale.percentage), starts_at: localInput(sale.starts_at), ends_at: localInput(sale.ends_at), quantity: sale.quantity === null ? '' : String(sale.quantity) });
    };
    const running = editing ? new Date(editing.starts_at).getTime() <= Date.now() : false;
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        if (form.processing) return;
        const options = { preserveScroll: true, onSuccess: clear };
        if (editing) form.put(route('seller.products.sales.update', { product: product.id, sale: editing.id }), options);
        else form.post(route('seller.products.sales.store', product.id), options);
    };
    const end = (sale: Sale) => {
        if (ending !== null) return;
        setEnding(sale.id); setEndError('');
        router.post(route('seller.products.sales.end', { product: product.id, sale: sale.id }), {}, { preserveScroll: true,
            onSuccess: () => { if (editing?.id === sale.id) clear(); },
            onError: errors => setEndError(String(errors.sale || 'The sale could not be ended. Try again.')),
            onFinish: () => setEnding(null) });
    };
    const percentageError = form.data.percentage !== '' && (!/^[0-9]+$/.test(form.data.percentage) || Number(form.data.percentage) < 1 || Number(form.data.percentage) > 99)
        ? 'Enter a whole discount percentage from 1 to 99.' : '';
    const minimum = Number(product.price_range?.regular_min ?? product.price);
    const maximum = Number(product.price_range?.regular_max ?? product.price);
    const preview = form.data.discount_type === 'percentage'
        ? form.data.percentage !== '' && !percentageError ? { min: percentagePrice(minimum, Number(form.data.percentage)), max: percentagePrice(maximum, Number(form.data.percentage)) } : null
        : Number(form.data.price) > 0 && Number(form.data.price) < Number(product.price) && minimum - Number(product.price) + Number(form.data.price) > 0
            ? { min: Math.round((minimum - Number(product.price) + Number(form.data.price)) * 100) / 100, max: Math.round((maximum - Number(product.price) + Number(form.data.price)) * 100) / 100 } : null;
    const field = 'mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-[#E00D42] focus:ring-[#E00D42]';
    const error = (name: string) => (form.errors as Record<string, string>)[name] ? <p role="alert" className="mt-1 text-sm text-rose-700">{(form.errors as Record<string, string>)[name]}</p> : null;

    return <DashboardLayout title="Product sales" subtitle="Schedule prices and quantities for your product">
        <Head title={`Sales · ${product.name}`} />
        <Link href={route('seller.products.index')} className="mb-5 inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-[#E00D42]"><ArrowLeft className="h-4 w-4" />Products</Link>
        <div className="mb-6 flex items-center gap-4"><img src={product.featured_image || '/storage/products/placeholder.webp'} alt="" className="h-16 w-16 rounded-lg object-cover" /><div><h1 className="text-xl font-bold text-slate-900">{product.name}</h1><p className="mt-1 text-sm text-slate-600">Regular price {money(product.price)} · {product.stock} available</p></div></div>
        <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(300px,0.8fr)]">
            <section className="rounded-lg border border-slate-300 bg-white p-5">
                <h2 className="font-bold text-slate-900">Sale schedules</h2>
                <p className="mt-2 text-sm leading-relaxed text-slate-600">A flash slot uses its own quantity first. Other units use the store sale or regular price. Percentage discounts apply to the full option price. Vouchers apply separately. All times are in Asia/Manila.</p>
                {endError && <p role="alert" className="mt-3 text-sm text-rose-700">{endError}</p>}
                {sales.data.length === 0 ? <div className="flex min-h-48 flex-col items-center justify-center text-center"><CalendarClock className="mb-3 h-7 w-7 text-slate-400" /><p className="font-semibold text-slate-800">No sale scheduled</p><p className="mt-1 text-sm text-slate-500">Create a store sale or a limited flash slot.</p></div> : <ul className="mt-4 divide-y divide-slate-200">{sales.data.map(sale => {
                    const ended = !!sale.ended_at || new Date(sale.ends_at).getTime() <= Date.now();
                    const status = ended ? 'Ended' : new Date(sale.starts_at).getTime() > Date.now() ? 'Scheduled' : 'Running';
                    return <li key={sale.id} className="py-4"><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="font-semibold text-slate-900">{sale.name}</p><p className="mt-1 text-sm text-slate-600">{sale.kind === 'flash' ? 'Flash sale' : 'Store sale'} · {sale.discount_type === 'percentage' ? `${sale.percentage}% off each option` : money(sale.price)} · {status}</p></div>{!ended && <div className="flex gap-2"><button type="button" onClick={() => edit(sale)} className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold hover:border-slate-400"><Pencil className="h-3.5 w-3.5" />Edit</button><button type="button" onClick={() => end(sale)} disabled={ending !== null} className="rounded-lg border border-rose-300 px-3 py-2 text-xs font-semibold text-rose-700 disabled:opacity-50">{ending === sale.id ? 'Ending…' : 'End now'}</button></div>}</div><p className="mt-2 text-xs text-slate-500">{displayDate(sale.starts_at)} to {displayDate(sale.ends_at)}</p>{sale.kind === 'flash' && <p className="mt-2 text-sm text-slate-700">{Math.max(0, (sale.quantity ?? 0) - sale.used_quantity)} of {sale.quantity} promotional units remain</p>}</li>;
                })}</ul>}
                {sales.last_page > 1 && <nav aria-label="Sale pages" className="mt-4 flex flex-wrap gap-2">{(sales.links ?? []).map((link, index) => link.url ? <Link key={index} href={link.url} preserveScroll className={`rounded-lg border border-slate-300 px-3 py-2 text-sm ${link.active ? 'bg-[#E00D42] text-white' : 'bg-white'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="px-3 py-2 text-sm text-slate-400" dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav>}
            </section>
            <form onSubmit={submit} className="space-y-4 rounded-lg border border-slate-300 bg-white p-5">
                <div className="flex items-center justify-between"><h2 className="font-bold text-slate-900">{editing ? 'Edit sale' : 'Create a sale'}</h2>{editing && <button type="button" onClick={clear} aria-label="Cancel sale editing" className="rounded-lg p-2 hover:bg-slate-100"><X className="h-4 w-4" /></button>}</div>
                <label className="block text-sm font-semibold">Sale type<select value={form.data.kind} onChange={event => form.setData('kind', event.target.value as 'store' | 'flash')} disabled={!!editing} className={field}><option value="store">Store sale</option><option value="flash">Flash sale</option></select>{error('kind')}</label>
                <label className="block text-sm font-semibold">Name<input value={form.data.name} maxLength={100} required onChange={event => form.setData('name', event.target.value)} className={field} />{error('name')}</label>
                <label className="block text-sm font-semibold">Discount method<select value={form.data.discount_type} disabled={running && editing?.kind === 'flash'} onChange={event => { form.setData('discount_type', event.target.value as 'fixed' | 'percentage'); form.clearErrors('price', 'percentage'); }} className={field}><option value="percentage">Percentage off each option</option><option value="fixed">Base sale price</option></select>{error('discount_type')}</label>
                {form.data.discount_type === 'percentage' ? <label className="block text-sm font-semibold">Discount (%)<input type="number" inputMode="numeric" step="1" min="1" max="99" value={form.data.percentage} required disabled={running && editing?.kind === 'flash'} onChange={event => form.setData('percentage', event.target.value)} className={field} aria-invalid={!!form.errors.percentage || !!percentageError} />{error('percentage')}{percentageError && <p role="alert" className="mt-1 text-sm text-rose-700">{percentageError}</p>}<span className="mt-1 block text-xs font-normal text-slate-500">Use a whole percentage from 1 to 99. Every complete option price receives this discount.</span></label>
                    : <label className="block text-sm font-semibold">Base sale price (₱)<input type="number" step="0.01" min="1" max="99999999.99" value={form.data.price} required disabled={running && editing?.kind === 'flash'} onChange={event => form.setData('price', event.target.value)} className={field} />{error('price')}<span className="mt-1 block text-xs font-normal text-slate-500">Below the base regular price. The same peso reduction applies to each option.</span></label>}
                {preview && <div className="rounded-lg border border-rose-200 bg-rose-50 p-4" role="status"><p className="text-xs font-semibold text-slate-600">Sale price preview</p><p className="mt-1 text-lg font-bold text-[#E00D42]">{money(preview.min)}{preview.max !== preview.min ? ` – ${money(preview.max)}` : ''}</p><p className="mt-1 text-xs text-slate-600">Regular prices remain until the start time. Prices are rounded to the nearest centavo.</p></div>}
                <div className="grid gap-4 sm:grid-cols-2"><label className="block text-sm font-semibold">Starts<input type="datetime-local" value={form.data.starts_at} required disabled={running} onChange={event => form.setData('starts_at', event.target.value)} className={field} />{error('starts_at')}</label><label className="block text-sm font-semibold">Ends<input type="datetime-local" value={form.data.ends_at} required disabled={running} onChange={event => form.setData('ends_at', event.target.value)} className={field} />{error('ends_at')}</label></div>
                {form.data.kind === 'flash' && <label className="block text-sm font-semibold">Promotional quantity<input type="number" step="1" min="1" max={product.stock + (editing?.used_quantity ?? 0)} value={form.data.quantity} required disabled={running} onChange={event => form.setData('quantity', event.target.value)} className={field} />{error('quantity')}<span className="mt-1 block text-xs font-normal text-slate-500">Remaining quota cannot exceed current stock. Scheduling does not reserve stock; actual option stock is checked at checkout.</span></label>}
                {running && <p className="text-xs leading-relaxed text-slate-600">Running slots keep their schedule. A store sale can change its price for new orders; a flash slot keeps its price and quantity. End now stops new use.</p>}
                <p className="text-xs leading-relaxed text-slate-500">Sale prices must be below the regular price. Each schedule lasts at most 31 days. Reductions are funded by your shop. Existing orders keep their original price.</p>
                {!canSchedule && <p role="status" className="text-sm text-amber-800">Publish an approved, active product before scheduling a sale. You can still end an existing schedule.</p>}
                {error('sale')}
                <button type="submit" disabled={form.processing || !canSchedule} className="w-full rounded-lg bg-[#E00D42] px-4 py-3 text-sm font-bold text-white hover:bg-[#C20836] disabled:opacity-50">{form.processing ? 'Saving…' : editing ? 'Save sale' : 'Schedule sale'}</button>
            </form>
        </div>
    </DashboardLayout>;
}
