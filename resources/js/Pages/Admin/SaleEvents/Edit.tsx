import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { ArrowLeft, ExternalLink, ImagePlus } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Notice, Panel, buttonClasses, fieldMessage, inputClasses, labelClasses } from '@/Components/ui';
import { EventStatus, eventDate, manilaInput, peso, voucherLabel, type EventProductRow, type EventVoucherRow, type SaleEventSummary } from '@/Components/Promotions/saleEvents';
import type { PageProps } from '@/types';

interface Props {
    event: SaleEventSummary | null; vouchers: EventVoucherRow[]; products: EventProductRow[];
    voucherOptions: { id: number; code: string; name: string }[]; base: string;
}

function DetailsForm({ event, base }: { event: SaleEventSummary | null; base: string }) {
    const form = useForm<{ title: string; badge: string; starts_at: string; ends_at: string; banner: File | null }>({
        title: event?.title ?? '', badge: event?.badge ?? '', starts_at: manilaInput(event?.starts_at), ends_at: manilaInput(event?.ends_at), banner: null,
    });
    const [preview, setPreview] = useState<string | null>(event?.banner_url ?? null);
    const locked = event?.status === 'live';
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(event ? `${base}/${event.id}` : base, { forceFormData: true, preserveScroll: true, onSuccess: () => form.setData('banner', null) });
    };
    const pick = (file: File | null) => { form.setData('banner', file); if (file) setPreview(URL.createObjectURL(file)); };

    return (
        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
            <label className="sm:col-span-2">
                <span className={labelClasses}>Event name</span>
                <input className={inputClasses} value={form.data.title} maxLength={80} placeholder="11.11 Mega Sale" onChange={e => form.setData('title', e.target.value)} aria-invalid={Boolean(form.errors.title)} />
                {fieldMessage({ error: form.errors.title })}
            </label>
            <label>
                <span className={labelClasses}>Label on deals</span>
                <input className={inputClasses} value={form.data.badge} maxLength={24} placeholder="11.11 DEAL" onChange={e => form.setData('badge', e.target.value)} aria-invalid={Boolean(form.errors.badge)} />
                {fieldMessage({ error: form.errors.badge })}
            </label>
            <div className="hidden sm:block" />
            <label>
                <span className={labelClasses}>Starts (Philippine time)</span>
                <input type="datetime-local" className={inputClasses} value={form.data.starts_at} disabled={locked} onChange={e => form.setData('starts_at', e.target.value)} aria-invalid={Boolean(form.errors.starts_at)} />
                {fieldMessage({ error: form.errors.starts_at, hint: locked ? 'The event is live, so its start is fixed.' : undefined })}
            </label>
            <label>
                <span className={labelClasses}>Ends (Philippine time)</span>
                <input type="datetime-local" className={inputClasses} value={form.data.ends_at} onChange={e => form.setData('ends_at', e.target.value)} aria-invalid={Boolean(form.errors.ends_at)} />
                {fieldMessage({ error: form.errors.ends_at })}
            </label>
            <div className="sm:col-span-2">
                <span className={labelClasses}>Banner</span>
                <label className="flex cursor-pointer flex-col items-center justify-center gap-2 overflow-hidden rounded-card border border-dashed border-line-300 bg-surface-100 text-sm text-ink-700 hover:border-brand-500 focus-within:outline focus-within:outline-2 focus-within:outline-brand-500">
                    {preview ? <img src={preview} alt="Banner preview" className="aspect-[47/16] w-full object-cover" /> : (
                        <span className="flex aspect-[47/16] w-full flex-col items-center justify-center gap-2"><ImagePlus className="h-6 w-6 text-ink-500" aria-hidden="true" />Choose a banner image</span>
                    )}
                    <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={e => pick(e.target.files?.[0] ?? null)} />
                </label>
                {fieldMessage({ error: form.errors.banner, hint: 'Wide JPG, PNG or WebP, ideally 1880 × 880. It leads the marketplace while the event is live.' })}
            </div>
            <div className="flex flex-wrap items-center gap-3 sm:col-span-2">
                <button type="submit" disabled={form.processing} className={buttonClasses('primary')}>{form.processing ? 'Saving…' : event ? 'Save changes' : 'Create event'}</button>
                {form.recentlySuccessful && <span role="status" className="text-sm text-success">Saved</span>}
            </div>
        </form>
    );
}

function VoucherSection({ event, vouchers, options, base }: { event: SaleEventSummary; vouchers: EventVoucherRow[]; options: Props['voucherOptions']; base: string }) {
    const form = useForm({ voucher_id: '', claim_limit: '' });
    const ended = event.status === 'ended';
    const add = (e: FormEvent) => { e.preventDefault(); form.post(`${base}/${event.id}/vouchers`, { preserveScroll: true, onSuccess: () => form.reset() }); };
    const vouchersUrl = base.replace('sale-events', 'vouchers');
    return (
        <Panel title="Vouchers" action={<Link href={vouchersUrl} className="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 underline underline-offset-4">Create a voucher<ExternalLink className="h-3.5 w-3.5" aria-hidden="true" /></Link>}>
            {vouchers.length > 0 ? (
                <ul className="divide-y divide-line-200 rounded-control border border-line-200">
                    {vouchers.map(voucher => (
                        <li key={voucher.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-semibold text-ink-900">{voucherLabel(voucher)} <span className="font-normal text-ink-600">· {voucher.issuer === 'platform' ? 'Bagoo' : voucher.shop_name}</span></p>
                                <p className="font-mono text-xs text-ink-500">{voucher.code}{!voucher.usable && <span className="ml-2 font-sans text-warning">Paused or ended</span>}</p>
                            </div>
                            <p className="text-sm tabular-nums text-ink-700">{voucher.claim_limit !== null ? `${voucher.claimed_count} of ${voucher.claim_limit} claimed` : `${voucher.claimed_count} claimed`}</p>
                            {voucher.claimed_count === 0 && (
                                <button type="button" onClick={() => router.delete(`${base}/${event.id}/vouchers/${voucher.id}`, { preserveScroll: true })} className={buttonClasses('ghost', 'sm')}>Remove</button>
                            )}
                        </li>
                    ))}
                </ul>
            ) : <p className="text-sm text-ink-600">No vouchers yet. Add a Bagoo voucher buyers can claim during the event.</p>}

            {!ended && (
                <form onSubmit={add} className="mt-4 flex flex-wrap items-end gap-3">
                    <label className="min-w-0 flex-1 basis-60">
                        <span className={labelClasses}>Bagoo voucher</span>
                        <select className={inputClasses} value={form.data.voucher_id} onChange={e => form.setData('voucher_id', e.target.value)} aria-invalid={Boolean(form.errors.voucher_id)}>
                            <option value="">{options.length ? 'Choose a voucher' : 'No running Bagoo vouchers'}</option>
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
            )}
        </Panel>
    );
}

function ProductSection({ event, products, base }: { event: SaleEventSummary; products: EventProductRow[]; base: string }) {
    return (
        <Panel title={`Products on discount · ${products.length}`}>
            {products.length > 0 ? (
                <ul className="divide-y divide-line-200 rounded-control border border-line-200">
                    {products.map(product => (
                        <li key={product.sale_id} className="flex items-center gap-3 px-4 py-2">
                            {product.image ? <img src={product.image} alt="" className="h-10 w-10 shrink-0 rounded object-cover" /> : <span className="h-10 w-10 shrink-0 rounded bg-surface-100" />}
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm text-ink-900">{product.name}</p>
                                <p className="truncate text-xs text-ink-600">{product.shop?.name}</p>
                            </div>
                            <p className="text-right text-sm tabular-nums"><span className="font-semibold text-ink-900">{peso(product.price)}</span> <span className="text-xs text-ink-500 line-through">{peso(product.regular_price)}</span></p>
                            <span className="w-12 text-right text-sm font-semibold text-ink-700">-{product.discount_pct}%</span>
                            {event.status !== 'ended' && (
                                <button type="button" className={buttonClasses('ghost', 'sm')}
                                    onClick={() => { if (window.confirm(`Remove “${product.name}” from the event? Its discount stops now.`)) router.delete(`${base}/${event.id}/products/${product.sale_id}`, { preserveScroll: true }); }}>Remove</button>
                            )}
                        </li>
                    ))}
                </ul>
            ) : <p className="text-sm text-ink-600">No products yet. Sellers add their discounts from Sale events in the Seller Centre.</p>}
        </Panel>
    );
}

export default function SaleEventEdit({ event, vouchers, products, voucherOptions, base }: Props) {
    const { flash } = usePage<PageProps & { flash?: { success?: string; error?: string } }>().props;
    const [ending, setEnding] = useState(false);
    const end = () => {
        if (!event || !window.confirm(`End “${event.title}” now? Buyers stop seeing it and every product discount in it stops.`)) return;
        setEnding(true);
        router.post(`${base}/${event.id}/end`, {}, { preserveScroll: true, onFinish: () => setEnding(false) });
    };

    return (
        <DashboardLayout title={event ? event.title : 'New sale event'}
            actions={event && event.status !== 'ended' ? <a href={`/campaign/${event.slug}`} target="_blank" rel="noreferrer" aria-label="View as buyer" className={buttonClasses('secondary')}><span className="hidden sm:inline">View as buyer</span><ExternalLink className="h-4 w-4" aria-hidden="true" /></a> : undefined}>
            <Head title={event ? event.title : 'New sale event'} />
            <div className="max-w-4xl space-y-6">
                <Link href={base} className="inline-flex items-center gap-1 text-sm text-ink-700 hover:text-ink-900"><ArrowLeft className="h-4 w-4" aria-hidden="true" />All sale events</Link>
                {flash?.success && <Notice tone="success" role="status">{flash.success}</Notice>}
                {flash?.error && <Notice tone="danger" role="alert">{flash.error}</Notice>}
                {event && (
                    <div className="flex flex-wrap items-center gap-3 text-sm text-ink-700">
                        <EventStatus status={event.status} />
                        <span>{eventDate(event.starts_at)} – {eventDate(event.ends_at)}</span>
                    </div>
                )}
                <Panel title="Event details"><DetailsForm event={event} base={base} /></Panel>
                {event && <VoucherSection event={event} vouchers={vouchers} options={voucherOptions} base={base} />}
                {event && <ProductSection event={event} products={products} base={base} />}
                {event && event.status !== 'ended' && (
                    <section className="flex flex-wrap items-center justify-between gap-3 rounded-card border border-line-200 bg-white p-5">
                        <p className="text-sm text-ink-700">End the event early. Its discounts stop immediately.</p>
                        <button type="button" onClick={end} disabled={ending} className={buttonClasses('danger')}>{ending ? 'Ending…' : 'End event now'}</button>
                    </section>
                )}
            </div>
        </DashboardLayout>
    );
}
