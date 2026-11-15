import { FormEvent, ReactNode, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { DialogTitle } from '@headlessui/react';
import { Percent, Tag, Truck, X } from 'lucide-react';
import Modal from '@/Components/Modal';
import { Campaign, campaignMoney, manilaInput, previewPromotion } from './campaignTypes';
import VoucherProductSelection, { VoucherProduct } from './VoucherProductSelection';

type DiscountType = 'fixed' | 'percent' | 'free_shipping';

const toCents = (value: string) => {
    const number = Number(String(value).replace(/%$/, ''));
    return Number.isFinite(number) && number > 0 ? Math.round(number * 100) : 0;
};
const plainAmount = (cents: number) => (cents % 100 === 0 ? String(cents / 100) : (cents / 100).toFixed(2));
const field = 'mt-1.5 block h-10 w-full rounded-control border border-line-300 bg-white px-3 text-sm text-ink-900 placeholder:text-ink-500 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/40 disabled:bg-surface-100 aria-[invalid=true]:border-brand-700';
const label = 'block text-[13px] font-medium text-ink-700';

function Section({ step, title, children }: { step: number; title: string; children: ReactNode }) {
    return (
        <section className="space-y-3">
            <h3 className="flex items-center gap-2 text-sm font-semibold text-ink-900">
                <span aria-hidden="true" className="flex h-5 w-5 items-center justify-center rounded-full bg-brand-500 text-xs font-semibold text-white">{step}</span>
                {title}
            </h3>
            {children}
        </section>
    );
}

export default function CampaignForm({ campaign, platform, onClose }: { campaign: Campaign | null; platform: boolean; onClose: () => void }) {
    const terms = campaign?.draft_terms ?? campaign?.published_terms;
    const form = useForm({
        code: campaign?.code ?? '', name: terms?.name ?? '', description: terms?.description ?? '',
        discount_type: (terms?.discount_type ?? 'fixed') as DiscountType,
        discount_value: terms ? String(terms.discount_type === 'percent' ? (terms.percentage_bps ?? 0) / 100 : terms.value_cents / 100) : '',
        min_spend: terms && terms.minimum_cents > 0 ? String(terms.minimum_cents / 100) : '',
        max_discount: terms ? String(terms.maximum_cents / 100) : '',
        budget: terms ? String(terms.budget_cents / 100) : '',
        usage_limit: terms ? String(campaign?.draft_terms ? terms.global_limit : campaign?.remaining_uses ?? terms.global_limit) : '',
        per_buyer_limit: terms ? String(terms.per_buyer_limit) : '1',
        starts_at: terms ? manilaInput(terms.starts_at) : '',
        ends_at: terms ? manilaInput(terms.ends_at) : '',
        reason: '', request_key: crypto.randomUUID(), publish_request_key: crypto.randomUUID(), source_version: campaign?.version ?? 0,
        product_scope: terms?.product_scope ?? 'all' as 'all' | 'selected', product_ids: terms?.product_ids ?? [] as number[],
    });
    const [submitting, setSubmitting] = useState<'draft' | 'publish' | null>(null);
    const [, setSelectedProducts] = useState<VoucherProduct[]>(campaign?.selected_products ?? []);
    const prefix = platform ? 'admin' : 'seller';
    const type = form.data.discount_type;
    const nowInput = manilaInput(new Date().toISOString());

    // What one use can cost and what the whole campaign can cost, from the current inputs.
    const valueCents = toCents(form.data.discount_value);
    const perUseCap = type === 'percent' ? toCents(form.data.max_discount) : toCents(form.data.max_discount) || valueCents;
    const uses = /^[1-9][0-9]*$/.test(form.data.usage_limit) ? Number(form.data.usage_limit) : 0;
    const ceiling = toCents(form.data.budget) || perUseCap * uses;
    const minimumCents = toCents(form.data.min_spend);
    // A shop's peso-off voucher must fit inside the seller's 90% share of the qualifying items. Same rounding as the server:
    // a blank minimum is filled with this value automatically; percent and Bagoo vouchers need no minimum.
    const requiredMinimum = (() => {
        if (platform || type !== 'fixed' || valueCents <= 0) return 0;
        let cents = Math.ceil(valueCents / 0.9);
        while (cents - Math.floor((cents + 5) / 10) < valueCents) cents++;
        return cents;
    })();
    const minimumTooLow = requiredMinimum > 0 && minimumCents > 0 && minimumCents < requiredMinimum;
    const effectiveMinimum = minimumCents || requiredMinimum;
    const example = previewPromotion({ discount_type: type, discount_value: form.data.discount_value, max_discount: plainAmount(perUseCap || valueCents) }, plainAmount(effectiveMinimum));

    const setNumber = (name: 'discount_value' | 'min_spend' | 'max_discount' | 'budget', value: string, allowPercent = false) => {
        const pattern = allowPercent ? /^(?:0|[1-9][0-9]*)?(?:\.[0-9]{0,2})?%?$/ : /^(?:0|[1-9][0-9]*)?(?:\.[0-9]{0,2})?$/;
        if (value === '' || pattern.test(value)) form.setData(name, value);
    };
    const setWhole = (name: 'usage_limit' | 'per_buyer_limit', value: string) => {
        if (value === '' || /^[1-9][0-9]*$/.test(value)) form.setData(name, value);
    };
    const quickDates = (days: number) => {
        const start = new Date(Date.now() + 5 * 60 * 1000);
        const end = new Date(start.getTime() + days * 24 * 60 * 60 * 1000);
        form.setData(current => ({ ...current, starts_at: manilaInput(start.toISOString()), ends_at: manilaInput(end.toISOString()) }));
        form.clearErrors('starts_at', 'ends_at');
    };
    const error = (name: keyof typeof form.errors) => form.errors[name] ? <p role="alert" className="mt-1.5 text-[13px] text-brand-700">{form.errors[name]}</p> : null;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const publish = ((event.nativeEvent as SubmitEvent).submitter as HTMLButtonElement | null)?.value === 'publish';
        setSubmitting(publish ? 'publish' : 'draft');
        form.transform(data => ({ ...data, publish_after_save: publish }));
        const options = { preserveScroll: true, onSuccess: onClose, onFinish: () => setSubmitting(null) };
        if (campaign) form.patch(route(`${prefix}.vouchers.update`, campaign.id), options);
        else form.post(route(`${prefix}.vouchers.store`), options);
    };

    const discountChoices: { value: DiscountType; title: string; icon: typeof Tag }[] = [
        { value: 'fixed', title: '₱ off', icon: Tag },
        { value: 'percent', title: '% off', icon: Percent },
        ...(platform ? [{ value: 'free_shipping' as DiscountType, title: 'Shipping off', icon: Truck }] : []),
    ];
    const blockedBySelection = form.data.product_scope === 'selected' && form.data.product_ids.length === 0;
    const knownErrors = ['name', 'code', 'description', 'reason', 'discount_type', 'discount_value', 'min_spend', 'max_discount', 'budget', 'usage_limit', 'per_buyer_limit', 'starts_at', 'ends_at', 'product_scope', 'product_ids'];

    return <Modal show onClose={onClose} closeable={!form.processing} maxWidth="2xl">
        <form onSubmit={submit} className="flex max-h-[88dvh] flex-col bg-white">
            <div className="flex items-start justify-between gap-4 border-b border-line-200 px-6 py-4">
                <div>
                    <DialogTitle className="text-lg font-semibold text-ink-900">{campaign ? 'Edit voucher' : 'New voucher'}</DialogTitle>
                    <p className={`mt-1 inline-flex items-center rounded-chip px-2 py-0.5 text-xs font-medium ${platform ? 'bg-info-soft text-info' : 'bg-brand-50 text-brand-600'}`}>
                        {platform ? 'Paid by Bagoo' : 'Paid by your shop'}
                    </p>
                </div>
                <button type="button" onClick={onClose} disabled={form.processing} aria-label="Close voucher form" className="rounded-full p-2 text-ink-500 hover:bg-surface-100 hover:text-ink-900"><X className="h-5 w-5" /></button>
            </div>

            <div className="space-y-7 overflow-y-auto px-6 py-5">
                {campaign?.current_revision_id && <p className="rounded-control bg-warning-soft px-3 py-2 text-[13px] text-warning">The live voucher keeps working until you publish these changes. Orders already placed keep their savings.</p>}

                <Section step={1} title="What do buyers get?">
                    <div role="radiogroup" aria-label="Discount type" className={`grid gap-2 ${platform ? 'grid-cols-3' : 'grid-cols-2'}`}>
                        {discountChoices.map(choice => {
                            const selected = type === choice.value;
                            return (
                                <label key={choice.value} className={`flex cursor-pointer items-center justify-center gap-2 rounded-control border px-3 py-2.5 text-sm font-medium transition-colors focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-brand-500 ${selected ? 'border-brand-500 bg-brand-50 text-brand-600' : 'border-line-300 text-ink-700 hover:border-ink-400'}`}>
                                    <input type="radio" name="discount_type" value={choice.value} checked={selected} className="sr-only"
                                        onChange={() => { form.setData(current => ({ ...current, discount_type: choice.value, max_discount: '', budget: '' })); form.clearErrors('discount_type', 'discount_value', 'max_discount'); }} />
                                    <choice.icon className="h-4 w-4" aria-hidden="true" />{choice.title}
                                </label>
                            );
                        })}
                    </div>
                    {error('discount_type')}
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className={label}>
                            {type === 'percent' ? 'Percent off' : type === 'free_shipping' ? 'Shipping discount (₱)' : 'Amount off (₱)'}
                            {type === 'percent'
                                ? <input type="text" inputMode="decimal" maxLength={7} required value={form.data.discount_value} placeholder="10 or 10%"
                                    onChange={event => setNumber('discount_value', event.target.value, true)} aria-invalid={!!form.errors.discount_value} className={field} />
                                : <input type="text" inputMode="decimal" maxLength={12} required value={form.data.discount_value} placeholder="e.g. 50"
                                    onChange={event => setNumber('discount_value', event.target.value)} aria-invalid={!!form.errors.discount_value} className={field} />}
                            {error('discount_value')}
                        </label>
                        {type === 'percent' && (
                            <label className={label}>
                                Up to (₱)
                                <input type="text" inputMode="decimal" maxLength={12} required value={form.data.max_discount} placeholder="Most saved per order"
                                    onChange={event => setNumber('max_discount', event.target.value)} aria-invalid={!!form.errors.max_discount} className={field} />
                                {error('max_discount')}
                            </label>
                        )}
                    </div>
                </Section>

                <Section step={2} title="Who can use it?">
                    <label className={label}>
                        Minimum order (₱) <span className="font-normal text-ink-500">(optional)</span>
                        <input type="text" inputMode="decimal" maxLength={12} value={form.data.min_spend} placeholder={requiredMinimum ? `Auto: ${plainAmount(requiredMinimum)}` : 'No minimum'}
                            onChange={event => setNumber('min_spend', event.target.value)} aria-invalid={!!form.errors.min_spend || minimumTooLow} className={`${field} sm:max-w-xs`} />
                        {!minimumTooLow && !form.errors.min_spend && requiredMinimum > 0 && minimumCents === 0 && (
                            <p className="mt-1.5 text-xs text-ink-600">Left blank, we use {campaignMoney(requiredMinimum)} so the discount always fits your share{form.data.product_scope === 'selected' ? ' (counting only the selected products)' : ''}.</p>
                        )}
                        {form.data.product_scope === 'selected' && (minimumCents > 0 || requiredMinimum === 0) && <p className="mt-1.5 text-xs text-ink-600">Only the selected products count toward the minimum.</p>}
                        {minimumTooLow && !form.errors.min_spend && (
                            <p className="mt-1.5 text-[13px] text-warning">
                                Needs to be at least {campaignMoney(requiredMinimum)} so your share covers the discount.{' '}
                                <button type="button" onClick={() => { form.setData('min_spend', plainAmount(requiredMinimum)); form.clearErrors('min_spend'); }} className="font-medium text-ink-900 underline decoration-line-300 underline-offset-4">Use {campaignMoney(requiredMinimum)}</button>
                            </p>
                        )}
                        {error('min_spend')}
                    </label>
                    {!platform && <VoucherProductSelection scope={form.data.product_scope} ids={form.data.product_ids} initialProducts={campaign?.selected_products}
                        disabled={form.processing} errors={Object.entries(form.errors).filter(([key]) => key === 'product_scope' || key === 'product_ids' || key.startsWith('product_ids.')).map(([, message]) => message)}
                        onScope={scope => {
                            form.setData(data => ({ ...data, product_scope: scope, product_ids: scope === 'all' ? [] : data.product_ids }));
                            if (scope === 'all') setSelectedProducts([]);
                            form.clearErrors('product_scope', 'product_ids');
                        }}
                        onChange={ids => { form.setData('product_ids', ids); form.clearErrors('product_ids'); }}
                        onProductsChange={setSelectedProducts} />}
                </Section>

                <Section step={3} title="How many, and when?">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className={label}>
                            Number of vouchers
                            <input type="text" inputMode="numeric" maxLength={7} required value={form.data.usage_limit} placeholder="e.g. 100"
                                onChange={event => setWhole('usage_limit', event.target.value)} aria-invalid={!!form.errors.usage_limit} className={field} />
                            {error('usage_limit')}
                        </label>
                        <label className={label}>
                            Uses per buyer
                            <input type="text" inputMode="numeric" maxLength={7} required value={form.data.per_buyer_limit}
                                onChange={event => setWhole('per_buyer_limit', event.target.value)} aria-invalid={!!form.errors.per_buyer_limit} className={field} />
                            {error('per_buyer_limit')}
                        </label>
                    </div>
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-[13px] text-ink-600">Run for</span>
                            {[7, 14, 30].map(days => (
                                <button key={days} type="button" onClick={() => quickDates(days)} className="min-h-8 rounded-full border border-line-300 bg-white px-3 text-[13px] font-medium text-ink-700 hover:border-ink-400">{days} days</button>
                            ))}
                        </div>
                        <div className="mt-3 grid gap-3 sm:grid-cols-2">
                            <label className={label}>
                                Starts (Philippine time)
                                <input type="datetime-local" required value={form.data.starts_at} min={campaign ? undefined : nowInput} onChange={event => form.setData('starts_at', event.target.value)} aria-invalid={!!form.errors.starts_at} className={field} />
                                {error('starts_at')}
                            </label>
                            <label className={label}>
                                Ends (Philippine time)
                                <input type="datetime-local" required value={form.data.ends_at} min={campaign ? undefined : nowInput} onChange={event => form.setData('ends_at', event.target.value)} aria-invalid={!!form.errors.ends_at} className={field} />
                                {error('ends_at')}
                            </label>
                        </div>
                    </div>
                </Section>

                <Section step={4} title="Name it">
                    <label className={label}>
                        Voucher title buyers see
                        <input type="text" required maxLength={100} value={form.data.name} placeholder="e.g. Weekend treat" onChange={event => form.setData('name', event.target.value)} aria-invalid={!!form.errors.name} className={field} />
                        {error('name')}
                    </label>
                    <details className="rounded-control border border-line-200">
                        <summary className="cursor-pointer px-3 py-2.5 text-[13px] font-medium text-ink-700">More options</summary>
                        <div className="space-y-3 border-t border-line-200 p-3">
                            <label className={label}>
                                Code {campaign ? '' : <span className="font-normal text-ink-500">(blank = generated)</span>}
                                <input type="text" value={form.data.code} readOnly={!!campaign} minLength={campaign ? undefined : 4} maxLength={campaign ? undefined : 12} pattern={campaign ? undefined : '[A-Za-z0-9]{4,12}'}
                                    onChange={event => form.setData('code', event.target.value.toUpperCase())} aria-invalid={!!form.errors.code} className={`${field} uppercase`} />
                                {error('code')}
                            </label>
                            <label className={label}>
                                Spending limit (₱) <span className="font-normal text-ink-500">(blank = max per order × vouchers)</span>
                                <input type="text" inputMode="decimal" maxLength={12} value={form.data.budget} placeholder={ceiling ? plainAmount(ceiling) : ''}
                                    onChange={event => setNumber('budget', event.target.value)} aria-invalid={!!form.errors.budget} className={field} />
                                {error('budget')}
                            </label>
                            <label className={label}>
                                Description
                                <textarea maxLength={1000} rows={2} value={form.data.description} onChange={event => form.setData('description', event.target.value)} className={`${field} h-auto py-2`} />
                                {error('description')}
                            </label>
                        </div>
                    </details>
                    {campaign && (
                        <label className={label}>
                            Why are you changing it?
                            <textarea required minLength={5} maxLength={1000} rows={2} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} aria-invalid={!!form.errors.reason} className={`${field} h-auto py-2`} />
                            {error('reason')}
                        </label>
                    )}
                </Section>

                <section aria-label="Buyer preview and cost" className={`rounded-card border p-4 ${platform ? 'border-info/20 bg-info-soft' : 'border-brand-100 bg-brand-50/60'}`}>
                    <h3 className="text-sm font-semibold text-ink-900">Buyer preview</h3>
                    <p className="mt-1 text-sm text-ink-700">
                        {valueCents > 0
                            ? <>{type === 'percent' ? `${form.data.discount_value.replace(/%$/, '')}% off` : type === 'free_shipping' ? `${campaignMoney(valueCents)} off shipping` : `${campaignMoney(valueCents)} off`}
                                {type === 'percent' && perUseCap > 0 && `, up to ${campaignMoney(perUseCap)}`}
                                {effectiveMinimum > 0 ? ` on ${form.data.product_scope === 'selected' ? 'selected items' : 'orders'} of ${campaignMoney(effectiveMinimum)} or more` : ''}</>
                            : 'Enter a discount to see what buyers get.'}
                    </p>
                    <dl className="mt-3 space-y-1 border-t border-black/5 pt-3 text-[13px]">
                        <div className="flex justify-between gap-3"><dt className="text-ink-600">Who pays</dt><dd className="font-medium text-ink-900">{platform ? 'Bagoo (from platform commission)' : 'Your shop (from your payout)'}</dd></div>
                        <div className="flex justify-between gap-3"><dt className="text-ink-600">Most per order</dt><dd className="font-medium tabular-nums text-ink-900">{perUseCap ? campaignMoney(perUseCap) : '—'}</dd></div>
                        <div className="flex justify-between gap-3"><dt className="text-ink-600">Most in total</dt><dd className="font-semibold tabular-nums text-ink-900">{ceiling ? campaignMoney(ceiling) : '—'}</dd></div>
                        {!platform && valueCents > 0 && example.basis > 0 && (
                            <div className="flex justify-between gap-3"><dt className="text-ink-600">Your payout at the minimum order ({campaignMoney(example.basis)})</dt><dd className="font-medium tabular-nums text-ink-900">{campaignMoney(example.sellerShare)} − {campaignMoney(example.discount)} = {campaignMoney(example.netSeller)}</dd></div>
                        )}
                    </dl>
                    <p className="mt-2 text-xs text-ink-600">{platform ? 'Sellers still receive their full share.' : 'The discount is taken from your share only when an order with this voucher completes.'}</p>
                </section>

                {Object.entries(form.errors).filter(([key]) => !knownErrors.includes(key) && !key.startsWith('product_ids.')).map(([key, message]) => <p key={key} role="alert" className="text-sm text-brand-700">{message}</p>)}
            </div>

            <div className="flex flex-wrap justify-end gap-2 border-t border-line-200 px-6 py-4">
                <button type="button" disabled={form.processing} onClick={onClose} className="min-h-10 rounded-control px-4 text-sm font-medium text-ink-700 hover:bg-surface-100">Cancel</button>
                <button type="submit" name="save_action" value="draft" disabled={form.processing || blockedBySelection} className="min-h-10 rounded-control border border-line-300 bg-white px-4 text-sm font-semibold text-ink-900 hover:bg-surface-100 disabled:opacity-50">{submitting === 'draft' && form.processing ? 'Saving…' : 'Save draft'}</button>
                <button type="submit" name="save_action" value="publish" disabled={form.processing || blockedBySelection} className="min-h-10 rounded-control bg-brand-500 px-4 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">{submitting === 'publish' && form.processing ? 'Publishing…' : 'Publish voucher'}</button>
            </div>
        </form>
    </Modal>;
}
