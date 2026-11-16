import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useMemo, useState } from 'react';
import { ArrowLeft, ExternalLink, Search } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Notice, Panel, buttonClasses, fieldMessage, inputClasses, labelClasses } from '@/Components/ui';
import { EventStatus, eventDate, peso, voucherLabel, type EventProductRow, type EventVoucherRow, type SaleEventSummary } from '@/Components/Promotions/saleEvents';
import type { PageProps } from '@/types';

interface Available { id: number; name: string; image: string | null; price: number; stock: number }
interface Props {
    event: SaleEventSummary; products: EventProductRow[]; vouchers: EventVoucherRow[]; availableProducts: Available[];
    voucherOptions: { id: number; code: string; name: string }[]; base: string; vouchersUrl: string;
}

const PRESETS = [10, 20, 30, 50];
const discounted = (price: number, pct: number) => Math.max(0.01, Math.round(price * (100 - pct)) / 100);

function AddProducts({ event, available, base }: { event: SaleEventSummary; available: Available[]; base: string }) {
    const form = useForm<{ product_ids: number[]; percentage: string }>({ product_ids: [], percentage: '20' });
    const [query, setQuery] = useState('');
    const pct = Number(form.data.percentage) || 0;
    const visible = useMemo(() => available.filter(product => product.name.toLowerCase().includes(query.trim().toLowerCase())), [available, query]);
    const selected = new Set(form.data.product_ids);
    const allVisible = visible.length > 0 && visible.every(product => selected.has(product.id));
    const toggle = (id: number) => form.setData('product_ids', selected.has(id) ? form.data.product_ids.filter(value => value !== id) : [...form.data.product_ids, id]);
    const toggleAll = () => form.setData('product_ids', allVisible
        ? form.data.product_ids.filter(id => !visible.some(product => product.id === id))
        : Array.from(new Set([...form.data.product_ids, ...visible.map(product => product.id)])));
    const submit = (e: FormEvent) => { e.preventDefault(); form.post(`${base}/${event.id}/products`, { preserveScroll: true, onSuccess: () => form.reset('product_ids') }); };

    if (available.length === 0) return <p className="text-sm text-ink-600">All your live products are already in this event.</p>;
    return (
        <form onSubmit={submit} className="space-y-4">
            <div>
                <span className={labelClasses}>Discount</span>
                <div className="flex flex-wrap items-center gap-2">
                    {PRESETS.map(value => (
                        <button key={value} type="button" onClick={() => form.setData('percentage', String(value))} aria-pressed={pct === value}
                            className={`h-10 rounded-control border px-4 text-sm font-semibold ${pct === value ? 'border-brand-500 bg-brand-50 text-brand-600' : 'border-line-300 bg-white text-ink-900 hover:bg-surface-100'}`}>{value}% off</button>
                    ))}
                    <label className="flex items-center gap-2 text-sm text-ink-700">Other
                        <input inputMode="numeric" aria-label="Custom discount percent" className={`${inputClasses} w-20`} value={form.data.percentage} onChange={e => form.setData('percentage', e.target.value.replace(/\D/g, '').slice(0, 2))} />%
                    </label>
                </div>
                {fieldMessage({ error: form.errors.percentage })}
            </div>
            <div className="rounded-control border border-line-200">
                <div className="flex flex-wrap items-center gap-3 border-b border-line-200 p-3">
                    <label className="flex min-h-10 items-center gap-2 text-sm font-medium text-ink-900">
                        <input type="checkbox" checked={allVisible} onChange={toggleAll} className="h-4 w-4 rounded border-line-300 text-brand-500 focus:ring-brand-500" />
                        Select all{query ? ' shown' : ''} ({visible.length})
                    </label>
                    <label className="relative ml-auto w-full sm:w-64">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" aria-hidden="true" />
                        <input type="search" aria-label="Search your products" placeholder="Search your products" className={`${inputClasses} pl-9`} value={query} onChange={e => setQuery(e.target.value)} />
                    </label>
                </div>
                <ul className="max-h-96 divide-y divide-line-200 overflow-y-auto">
                    {visible.map(product => (
                        <li key={product.id}>
                            <label className="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-surface-100">
                                <input type="checkbox" checked={selected.has(product.id)} onChange={() => toggle(product.id)} className="h-4 w-4 rounded border-line-300 text-brand-500 focus:ring-brand-500" />
                                {product.image ? <img src={product.image} alt="" className="h-10 w-10 shrink-0 rounded object-cover" /> : <span className="h-10 w-10 shrink-0 rounded bg-surface-100" />}
                                <span className="min-w-0 flex-1 truncate text-sm text-ink-900">{product.name}</span>
                                <span className="text-right text-sm tabular-nums">
                                    {pct >= 1 && pct <= 90 ? <><span className="font-semibold text-ink-900">{peso(discounted(product.price, pct))}</span> <span className="text-xs text-ink-500 line-through">{peso(product.price)}</span></> : <span className="text-ink-900">{peso(product.price)}</span>}
                                </span>
                            </label>
                        </li>
                    ))}
                    {visible.length === 0 && <li className="px-3 py-6 text-sm text-ink-600">No products match “{query}”.</li>}
                </ul>
            </div>
            {fieldMessage({ error: form.errors.product_ids })}
            <button type="submit" disabled={form.processing || form.data.product_ids.length === 0 || pct < 1 || pct > 90} className={buttonClasses('primary')}>
                {form.processing ? 'Adding…' : form.data.product_ids.length === 0 ? 'Choose products to add' : `Add ${form.data.product_ids.length} ${form.data.product_ids.length === 1 ? 'product' : 'products'} at ${pct}% off`}
            </button>
        </form>
    );
}

function Vouchers({ event, vouchers, options, base, vouchersUrl }: { event: SaleEventSummary; vouchers: EventVoucherRow[]; options: Props['voucherOptions']; base: string; vouchersUrl: string }) {
    const form = useForm({ voucher_id: '', claim_limit: '' });
    const add = (e: FormEvent) => { e.preventDefault(); form.post(`${base}/${event.id}/vouchers`, { preserveScroll: true, onSuccess: () => form.reset() }); };
    return (
        <Panel title="Your vouchers in this event" action={<Link href={vouchersUrl} className="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 underline underline-offset-4">Create a voucher<ExternalLink className="h-3.5 w-3.5" aria-hidden="true" /></Link>}>
            {vouchers.length > 0 && (
                <ul className="mb-4 divide-y divide-line-200 rounded-control border border-line-200">
                    {vouchers.map(voucher => (
                        <li key={voucher.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                            <div className="min-w-0 flex-1"><p className="text-sm font-semibold text-ink-900">{voucherLabel(voucher)}</p><p className="font-mono text-xs text-ink-500">{voucher.code}</p></div>
                            <p className="text-sm tabular-nums text-ink-700">{voucher.claim_limit !== null ? `${voucher.claimed_count} of ${voucher.claim_limit} claimed` : `${voucher.claimed_count} claimed`}</p>
                            {voucher.claimed_count === 0 && <button type="button" onClick={() => router.delete(`${base}/${event.id}/vouchers/${voucher.id}`, { preserveScroll: true })} className={buttonClasses('ghost', 'sm')}>Remove</button>}
                        </li>
                    ))}
                </ul>
            )}
            {event.status !== 'ended' && (options.length > 0 ? (
                <form onSubmit={add} className="flex flex-wrap items-end gap-3">
                    <label className="min-w-0 flex-1 basis-60">
                        <span className={labelClasses}>Shop voucher</span>
                        <select className={inputClasses} value={form.data.voucher_id} onChange={e => form.setData('voucher_id', e.target.value)}>
                            <option value="">Choose a voucher</option>
                            {options.map(option => <option key={option.id} value={option.id}>{option.code} · {option.name}</option>)}
                        </select>
                    </label>
                    <label className="w-40">
                        <span className={labelClasses}>Claim limit</span>
                        <input inputMode="numeric" className={inputClasses} value={form.data.claim_limit} placeholder="Everyone" onChange={e => form.setData('claim_limit', e.target.value.replace(/\D/g, ''))} />
                    </label>
                    <button type="submit" disabled={form.processing || !form.data.voucher_id} className={buttonClasses('secondary')}>{form.processing ? 'Adding…' : 'Add voucher'}</button>
                    <div className="basis-full">{fieldMessage({ error: form.errors.voucher_id ?? form.errors.claim_limit, hint: 'With a limit, only the first buyers to claim can use it.' })}</div>
                </form>
            ) : <p className="text-sm text-ink-600">You have no running vouchers to add. Create one first.</p>)}
        </Panel>
    );
}

export default function SellerSaleEvent({ event, products, vouchers, availableProducts, voucherOptions, base, vouchersUrl }: Props) {
    const { flash } = usePage<PageProps & { flash?: { success?: string; error?: string } }>().props;
    const ended = event.status === 'ended';
    return (
        <DashboardLayout title={event.title}
            actions={!ended ? <a href={`/campaign/${event.slug}`} target="_blank" rel="noreferrer" aria-label="View event page" className={buttonClasses('secondary')}><span className="hidden sm:inline">View event page</span><ExternalLink className="h-4 w-4" aria-hidden="true" /></a> : undefined}>
            <Head title={event.title} />
            <div className="max-w-4xl space-y-6">
                <Link href={base} className="inline-flex items-center gap-1 text-sm text-ink-700 hover:text-ink-900"><ArrowLeft className="h-4 w-4" aria-hidden="true" />All sale events</Link>
                <div className="flex flex-wrap items-center gap-3 text-sm text-ink-700"><EventStatus status={event.status} /><span>{eventDate(event.starts_at)} – {eventDate(event.ends_at)}</span></div>
                {flash?.success && <Notice tone="success" role="status">{flash.success}</Notice>}
                {flash?.error && <Notice tone="danger" role="alert">{flash.error}</Notice>}

                <Panel title={`Your products on discount · ${products.length}`}>
                    {products.length > 0 ? (
                        <ul className="divide-y divide-line-200 rounded-control border border-line-200">
                            {products.map(product => (
                                <li key={product.sale_id} className="flex items-center gap-3 px-4 py-2">
                                    {product.image ? <img src={product.image} alt="" className="h-10 w-10 shrink-0 rounded object-cover" /> : <span className="h-10 w-10 shrink-0 rounded bg-surface-100" />}
                                    <span className="min-w-0 flex-1 truncate text-sm text-ink-900">{product.name}</span>
                                    <span className="text-right text-sm tabular-nums"><span className="font-semibold text-ink-900">{peso(product.price)}</span> <span className="text-xs text-ink-500 line-through">{peso(product.regular_price)}</span></span>
                                    <span className="w-12 text-right text-sm font-semibold text-ink-700">-{product.discount_pct}%</span>
                                    {!ended && <button type="button" className={buttonClasses('ghost', 'sm')} onClick={() => { if (window.confirm(`Remove “${product.name}”? Its regular price comes back now.`)) router.delete(`${base}/${event.id}/products/${product.sale_id}`, { preserveScroll: true }); }}>Remove</button>}
                                </li>
                            ))}
                        </ul>
                    ) : <p className="text-sm text-ink-600">None yet. Choose products below and set a discount.</p>}
                </Panel>

                {!ended && <Panel title="Add products"><AddProducts event={event} available={availableProducts} base={base} /></Panel>}
                <Vouchers event={event} vouchers={vouchers} options={voucherOptions} base={base} vouchersUrl={vouchersUrl} />
            </div>
        </DashboardLayout>
    );
}
