import { FormEvent, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { DialogTitle } from '@headlessui/react';
import { CalendarDays, SlidersHorizontal, Sparkles, Ticket, X } from 'lucide-react';
import Modal from '@/Components/Modal';
import { Campaign, campaignMoney, manilaInput, previewPromotion } from './campaignTypes';
import VoucherProductSelection, { VoucherProduct } from './VoucherProductSelection';

const cleanAmount = (value: number): string => {
    if (!Number.isFinite(value) || value <= 0) return '0';
    const rounded = Math.round(value * 100) / 100;
    return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(2);
};

export default function CampaignForm({ campaign, platform, onClose }: { campaign: Campaign | null; platform: boolean; onClose: () => void }) {
    const terms = campaign?.draft_terms ?? campaign?.published_terms;
    const form = useForm({
        code: campaign?.code ?? '', name: terms?.name ?? '', description: terms?.description ?? '',
        discount_type: terms?.discount_type ?? 'fixed',
        discount_value: terms ? String(terms.discount_type === 'percent' ? (terms.percentage_bps ?? 0) / 100 : terms.value_cents / 100) : '',
        min_spend: terms ? String(terms.minimum_cents / 100) : '', max_discount: terms ? String(terms.maximum_cents / 100) : '',
        budget: terms ? String(terms.budget_cents / 100) : '',
        usage_limit: terms ? String(campaign?.draft_terms ? terms.global_limit : campaign?.remaining_uses ?? terms.global_limit) : '',
        per_buyer_limit: terms ? String(terms.per_buyer_limit) : '',
        starts_at: terms ? manilaInput(terms.starts_at) : '',
        ends_at: terms ? manilaInput(terms.ends_at) : '',
        reason: '', request_key: crypto.randomUUID(), publish_request_key: crypto.randomUUID(), source_version: campaign?.version ?? 0,
        product_scope: terms?.product_scope ?? 'all' as 'all' | 'selected', product_ids: terms?.product_ids ?? [] as number[],
    });
    const [submittingAction, setSubmittingAction] = useState<'draft' | 'publish' | null>(null);
    const [selectedProducts, setSelectedProducts] = useState<VoucherProduct[]>(campaign?.selected_products ?? []);
    const nowInput = manilaInput(new Date().toISOString());
    const originalStart = terms ? manilaInput(terms.starts_at) : '';
    const originalEnd = terms ? manilaInput(terms.ends_at) : '';
    const keepsPastStart = !!campaign && form.data.starts_at === originalStart && originalStart < nowInput;
    const keepsPastEnd = !!campaign && form.data.ends_at === originalEnd && originalEnd < nowInput;
    const example = previewPromotion(form.data, form.data.min_spend);
    const previewValue = form.data.discount_value.replace(/%$/, '');
    const previewReady = form.data.min_spend !== '' && form.data.max_discount !== '' && form.data.discount_value !== ''
        && Number.isFinite(Number(form.data.min_spend)) && Number(form.data.min_spend) >= 0
        && Number.isFinite(Number(form.data.max_discount)) && Number(form.data.max_discount) > 0
        && Number.isFinite(Number(previewValue))
        && (form.data.discount_type === 'free_shipping' ? Number(previewValue) >= 0 : Number(previewValue) > 0);
    const prefix = platform ? 'admin' : 'seller';
    const wholeNumberFields = ['usage_limit', 'per_buyer_limit'];

    const selectedPrices = form.data.product_scope === 'selected'
        ? selectedProducts.map(product => Number(product.price)).filter(price => Number.isFinite(price) && price > 0)
        : [];
    const minProductPrice = selectedPrices.length > 0 ? Math.min(...selectedPrices) : 0;
    const maxProductPrice = selectedPrices.length > 0 ? Math.max(...selectedPrices) : 0;
    const numericDiscount = Number(previewValue);
    const hasValidDiscount = Number.isFinite(numericDiscount) && numericDiscount > 0;
    const numericMinSpend = Number(form.data.min_spend);
    const referencePrice = minProductPrice > 0
        ? minProductPrice
        : (form.data.min_spend !== '' && Number.isFinite(numericMinSpend) && numericMinSpend > 0 ? numericMinSpend : 0);
    const suggestedMinSpend = minProductPrice > 0
        ? minProductPrice
        : (form.data.discount_type === 'fixed' && hasValidDiscount && !platform ? Math.ceil((numericDiscount / 0.9) * 100) / 100 : 0);
    const suggestedMaxDiscount = hasValidDiscount
        ? (form.data.discount_type === 'percent'
            ? (referencePrice > 0 ? Math.max(0.01, Math.round(referencePrice * (numericDiscount / 100) * 100) / 100) : 0)
            : numericDiscount)
        : 0;
    const effectiveMaxDiscount = form.data.max_discount !== '' && Number.isFinite(Number(form.data.max_discount)) && Number(form.data.max_discount) > 0
        ? Number(form.data.max_discount)
        : suggestedMaxDiscount;
    const effectiveUses = form.data.usage_limit !== '' && Number.isFinite(Number(form.data.usage_limit)) && Number(form.data.usage_limit) >= 1
        ? Math.floor(Number(form.data.usage_limit))
        : 10;
    const suggestedBudget = effectiveMaxDiscount > 0
        ? Math.round(effectiveMaxDiscount * effectiveUses * 100) / 100
        : 0;
    const budgetBelowMax = form.data.budget !== '' && form.data.max_discount !== ''
        && Number.isFinite(Number(form.data.budget)) && Number.isFinite(Number(form.data.max_discount))
        && Number(form.data.budget) < Number(form.data.max_discount);
    const minSpendTooLowForFixed = !platform && form.data.discount_type === 'fixed'
        && form.data.min_spend !== '' && hasValidDiscount
        && Math.min(numericDiscount, effectiveMaxDiscount || numericDiscount) > Math.floor(numericMinSpend * 100 * 0.9) / 100;

    const applySuggestedValues = (overrideProducts?: VoucherProduct[]) => {
        const list = overrideProducts ?? selectedProducts;
        const prices = list.map(product => Number(product.price)).filter(price => Number.isFinite(price) && price > 0);
        const lowestPrice = prices.length > 0 ? Math.min(...prices) : (numericMinSpend > 0 ? numericMinSpend : 0);
        const discountNum = hasValidDiscount ? numericDiscount : (form.data.discount_type === 'percent' ? 10 : Math.max(10, Math.round(lowestPrice * 0.1)));
        const nextMinSpend = lowestPrice > 0
            ? lowestPrice
            : (form.data.discount_type === 'fixed' && !platform ? Math.ceil((discountNum / 0.9) * 100) / 100 : 100);
        const nextMaxDiscount = form.data.discount_type === 'percent'
            ? Math.max(0.01, Math.round(nextMinSpend * (discountNum / 100) * 100) / 100)
            : discountNum;
        const uses = form.data.usage_limit !== '' && Number(form.data.usage_limit) >= 1 ? Math.floor(Number(form.data.usage_limit)) : 10;
        const buyerUses = form.data.per_buyer_limit !== '' && Number(form.data.per_buyer_limit) >= 1
            ? Math.min(uses, Math.floor(Number(form.data.per_buyer_limit)))
            : Math.min(uses, 1);
        const nextBudget = Math.max(nextMaxDiscount, Math.round(nextMaxDiscount * uses * 100) / 100);
        form.setData(current => ({
            ...current,
            discount_value: current.discount_value !== '' ? current.discount_value : cleanAmount(discountNum),
            min_spend: cleanAmount(nextMinSpend),
            max_discount: cleanAmount(nextMaxDiscount),
            budget: cleanAmount(nextBudget),
            usage_limit: String(uses),
            per_buyer_limit: String(buyerUses),
        }));
        form.clearErrors('discount_value', 'min_spend', 'max_discount', 'budget', 'usage_limit', 'per_buyer_limit');
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const submitter = (event.nativeEvent as SubmitEvent).submitter as HTMLButtonElement | null;
        const publish = submitter?.value === 'publish';
        setSubmittingAction(publish ? 'publish' : 'draft');
        form.transform(data => ({ ...data, publish_after_save: publish }));
        const options = { preserveScroll: true, onSuccess: onClose, onFinish: () => setSubmittingAction(null) };
        if (campaign) form.patch(route(`${prefix}.vouchers.update`, campaign.id), options);
        else form.post(route(`${prefix}.vouchers.store`), options);
    };
    const control = (name: Exclude<keyof typeof form.data, 'product_ids'>, label: string, type = 'text', extra: Record<string, unknown> = {}, helper?: React.ReactNode) => (
        <div key={name} className="space-y-1">
            <label className="block text-xs font-semibold text-slate-700">
                {label}
                <input type={type === 'number' ? 'text' : type} inputMode={type === 'number' ? wholeNumberFields.includes(name) ? 'numeric' : 'decimal' : undefined}
                    maxLength={type === 'number' ? wholeNumberFields.includes(name) ? 7 : 12 : undefined}
                    value={form.data[name]} onChange={event => {
                        const value = event.target.value;
                        if (type === 'number' && value !== '' && !(wholeNumberFields.includes(name)
                            ? /^(?:0|[1-9][0-9]*)$/.test(value)
                            : /^(?:0|[1-9][0-9]*)(?:\.[0-9]{0,2})?$/.test(value))) return;
                        form.setData(name, (name === 'code' ? value.toUpperCase() : value) as never);
                    }}
                    required={name !== 'code'} {...extra} aria-invalid={!!form.errors[name]}
                    className="mt-1.5 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:border-[#E00D42] focus:ring-[#E00D42] disabled:bg-slate-100" />
                {form.errors[name] && <span role="alert" className="mt-1 block text-rose-700">{form.errors[name]}</span>}
            </label>
            {helper}
        </div>
    );
    return <Modal show onClose={onClose} closeable={!form.processing} maxWidth="2xl">
        <form onSubmit={submit} className="flex max-h-[85dvh] flex-col">
            <div className="flex items-start justify-between gap-4 border-b border-slate-300 bg-rose-50/50 p-5">
                <div><DialogTitle className="text-lg font-bold text-slate-900">{campaign ? 'Edit draft' : 'New voucher draft'}</DialogTitle>
                    <p className="mt-1 text-xs text-slate-600">{platform ? 'Bagoo funds this promotion.' : 'Your shop funds this product discount.'} Times use Asia/Manila.</p></div>
                <button type="button" onClick={onClose} disabled={form.processing} aria-label="Close voucher draft" className="rounded-lg p-2 hover:bg-slate-100"><X className="h-5 w-5" /></button>
            </div>
            <div className="space-y-5 overflow-y-auto p-5">
                {campaign?.current_revision_id && <p className="rounded-xl bg-amber-50 p-3 text-xs text-amber-900">Saving keeps the published offer unchanged. Publishing this draft starts a new revision with its own dated budget and use limits. Existing orders keep their savings.</p>}
                <fieldset className="rounded-xl border border-slate-300 bg-white p-4 shadow-sm"><legend className="flex items-center gap-2 px-2 text-sm font-bold text-slate-900"><Ticket className="h-4 w-4 text-[#E00D42]" />Voucher identity</legend><div className="grid gap-4 sm:grid-cols-2">
                    {control('name', 'Offer title', 'text', { maxLength: 100 })}
                    <div>{control('code', 'Code (leave blank to generate)', 'text', { minLength: campaign ? undefined : 4, maxLength: campaign ? undefined : 12, pattern: campaign ? undefined : '[A-Za-z0-9]{4,12}', readOnly: !!campaign })}<p className="mt-1 text-xs text-slate-500">4–12 letters and numbers, without spaces or symbols. Existing codes stay the same.</p></div>
                </div></fieldset>
                <fieldset className="rounded-xl border border-slate-300 bg-slate-50/60 p-4"><legend className="flex items-center gap-2 px-2 text-sm font-bold text-slate-900"><SlidersHorizontal className="h-4 w-4 text-[#E00D42]" />Savings and limits</legend><p className="mb-3 text-xs leading-relaxed text-slate-500">Set the discount buyers receive and the limits that protect your spending.</p>
                    {!platform && form.data.product_scope === 'selected' && minProductPrice > 0 && (
                        <div className="mb-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded-xl border border-rose-200 bg-rose-50/70 p-3 text-xs text-slate-700">
                            <div className="space-y-0.5">
                                <p className="font-bold text-slate-900 flex items-center gap-1.5">
                                    <Sparkles className="h-3.5 w-3.5 text-[#E00D42]" />
                                    <span>Selected product price guide: {minProductPrice === maxProductPrice ? campaignMoney(Math.round(minProductPrice * 100)) : `${campaignMoney(Math.round(minProductPrice * 100))} – ${campaignMoney(Math.round(maxProductPrice * 100))}`}</span>
                                </p>
                                <p className="text-slate-600">
                                    Suggested minimum spend: <strong>{campaignMoney(Math.round(minProductPrice * 100))}</strong>
                                    {suggestedMaxDiscount > 0 && <> · Max discount/use: <strong>{campaignMoney(Math.round(suggestedMaxDiscount * 100))}</strong></>}
                                    {suggestedBudget > 0 && <> · Total ceiling ({effectiveUses} uses): <strong>{campaignMoney(Math.round((suggestedMaxDiscount || effectiveMaxDiscount) * effectiveUses * 100))}</strong></>}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => applySuggestedValues()}
                                className="shrink-0 rounded-lg bg-[#E00D42] px-3 py-2 text-xs font-bold text-white hover:bg-[#C20836] transition cursor-pointer"
                            >
                                Apply suggested prices
                            </button>
                        </div>
                    )}
                    <div className="grid gap-4 sm:grid-cols-2">
                    <label className="block text-xs font-semibold text-slate-700">Discount type
                        <select value={form.data.discount_type} onChange={e => form.setData('discount_type', e.target.value as typeof form.data.discount_type)} className="mt-1.5 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]">
                            <option value="fixed">Fixed product discount</option><option value="percent">Percentage product discount</option>
                            {platform && <option value="free_shipping">Shipping discount</option>}
                        </select>{form.errors.discount_type && <span role="alert" className="mt-1 block text-rose-700">{form.errors.discount_type}</span>}
                    </label>
                    {form.data.discount_type === 'percent'
                        ? <label className="block text-xs font-semibold text-slate-700">Percentage (shop maximum 90%)
                            <input type="text" inputMode="decimal" maxLength={7} value={form.data.discount_value} onChange={e => { if (/^[0-9]*(?:\.[0-9]{0,2})?%?$/.test(e.target.value)) form.setData('discount_value', e.target.value); }} placeholder="10 or 10%" required aria-invalid={!!form.errors.discount_value} className="mt-1.5 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]" />
                            <span className="mt-1 block font-normal text-slate-500">Enter a number such as 10, or add a trailing % sign.</span>
                            {form.errors.discount_value && <span role="alert" className="mt-1 block text-rose-700">{form.errors.discount_value}</span>}
                        </label>
                        : control('discount_value', form.data.discount_type === 'free_shipping' ? 'Shipping amount (₱; 0 uses the cap)' : 'Discount amount (₱)', 'number', { min: form.data.discount_type === 'free_shipping' ? 0 : 0.01, step: '0.01', max: 999999999.99 },
                            <span className="block text-[11px] text-slate-500">Peso amount deducted from qualifying items per order.</span>
                        )}
                    {control('min_spend', 'Minimum products purchase (₱)', 'number', { min: 0, max: 999999999.99, step: '0.01' },
                        <div className="space-y-1 text-[11px] text-slate-500">
                            <span className="block">Minimum item subtotal required to use this voucher.</span>
                            {minSpendTooLowForFixed && suggestedMinSpend > 0 && (
                                <span className="block text-amber-800 font-medium">
                                    Must be at least {campaignMoney(Math.round(suggestedMinSpend * 100))} so your 90% seller share covers the discount.{' '}
                                    <button type="button" onClick={() => { form.setData('min_spend', cleanAmount(suggestedMinSpend)); form.clearErrors('min_spend'); }} className="font-bold text-[#E00D42] underline cursor-pointer">
                                        Use ₱{cleanAmount(suggestedMinSpend)}
                                    </button>
                                </span>
                            )}
                            {!minSpendTooLowForFixed && minProductPrice > 0 && Number(form.data.min_spend) !== minProductPrice && (
                                <button type="button" onClick={() => { form.setData('min_spend', cleanAmount(minProductPrice)); form.clearErrors('min_spend'); }} className="font-semibold text-[#E00D42] hover:underline cursor-pointer">
                                    Suggested from selected product: Use ₱{cleanAmount(minProductPrice)}
                                </button>
                            )}
                        </div>
                    )}
                    {control('max_discount', 'Maximum discount per use (₱)', 'number', { min: 0.01, max: 999999999.99, step: '0.01' },
                        <div className="space-y-1 text-[11px] text-slate-500">
                            <span className="block">
                                {form.data.discount_type === 'percent'
                                    ? 'Highest peso discount allowed on a single order.'
                                    : 'Cap per order (usually the same as your fixed discount amount).'}
                            </span>
                            {suggestedMaxDiscount > 0 && Number(form.data.max_discount) !== suggestedMaxDiscount && (
                                <button type="button" onClick={() => {
                                    const nextMax = cleanAmount(suggestedMaxDiscount);
                                    const uses = form.data.usage_limit !== '' && Number(form.data.usage_limit) >= 1 ? Math.floor(Number(form.data.usage_limit)) : 1;
                                    const nextBudget = cleanAmount(Math.max(suggestedMaxDiscount, suggestedMaxDiscount * uses));
                                    form.setData(current => ({
                                        ...current,
                                        max_discount: nextMax,
                                        budget: current.budget === '' || Number(current.budget) < suggestedMaxDiscount ? nextBudget : current.budget,
                                    }));
                                    form.clearErrors('max_discount', 'budget');
                                }} className="font-semibold text-[#E00D42] hover:underline cursor-pointer">
                                    {form.data.discount_type === 'percent'
                                        ? `At ${previewValue}% of ₱${cleanAmount(referencePrice)}, discount is ₱${cleanAmount(suggestedMaxDiscount)} — Use ₱${cleanAmount(suggestedMaxDiscount)}`
                                        : `Match discount amount: Use ₱${cleanAmount(suggestedMaxDiscount)}`}
                                </button>
                            )}
                        </div>
                    )}
                    {control('budget', 'Spending ceiling (₱)', 'number', { min: 0.01, max: 999999999.99, step: '0.01' },
                        <div className="space-y-1 text-[11px] text-slate-500">
                            <span className="block">Total campaign budget across all uses (must be ≥ Maximum discount per use).</span>
                            {budgetBelowMax && (
                                <div className="rounded-lg border border-amber-300 bg-amber-50 p-2 text-amber-900 space-y-1">
                                    <p className="font-semibold">Spending ceiling (₱{form.data.budget}) is lower than Maximum discount per use (₱{form.data.max_discount}).</p>
                                    <div className="flex flex-wrap gap-2">
                                        <button type="button" onClick={() => {
                                            const uses = form.data.usage_limit !== '' && Number(form.data.usage_limit) >= 1 ? Math.floor(Number(form.data.usage_limit)) : 1;
                                            form.setData('budget', cleanAmount(Number(form.data.max_discount) * uses));
                                            form.clearErrors('budget');
                                        }} className="rounded bg-[#E00D42] px-2 py-0.5 font-bold text-white hover:bg-[#C20836] cursor-pointer">
                                            Set ceiling to ₱{cleanAmount(Number(form.data.max_discount) * (form.data.usage_limit !== '' && Number(form.data.usage_limit) >= 1 ? Math.floor(Number(form.data.usage_limit)) : 1))}
                                        </button>
                                        {suggestedMaxDiscount > 0 && suggestedMaxDiscount < Number(form.data.max_discount) && (
                                            <button type="button" onClick={() => {
                                                const uses = form.data.usage_limit !== '' && Number(form.data.usage_limit) >= 1 ? Math.floor(Number(form.data.usage_limit)) : 1;
                                                form.setData(current => ({
                                                    ...current,
                                                    max_discount: cleanAmount(suggestedMaxDiscount),
                                                    budget: cleanAmount(Math.max(suggestedMaxDiscount, suggestedMaxDiscount * uses)),
                                                }));
                                                form.clearErrors('max_discount', 'budget');
                                            }} className="rounded border border-amber-400 bg-white px-2 py-0.5 font-semibold text-amber-900 hover:bg-amber-100 cursor-pointer">
                                                Or set Max discount to ₱{cleanAmount(suggestedMaxDiscount)} &amp; Ceiling to ₱{cleanAmount(suggestedMaxDiscount * (form.data.usage_limit !== '' && Number(form.data.usage_limit) >= 1 ? Math.floor(Number(form.data.usage_limit)) : 1))}
                                            </button>
                                        )}
                                    </div>
                                </div>
                            )}
                            {!budgetBelowMax && suggestedBudget > 0 && Number(form.data.budget) !== suggestedBudget && (
                                <button type="button" onClick={() => { form.setData('budget', cleanAmount(suggestedBudget)); form.clearErrors('budget'); }} className="font-semibold text-[#E00D42] hover:underline cursor-pointer">
                                    Suggested for {effectiveUses} {effectiveUses === 1 ? 'use' : 'uses'} (₱{cleanAmount(effectiveMaxDiscount)} × {effectiveUses}): Use ₱{cleanAmount(suggestedBudget)}
                                </button>
                            )}
                        </div>
                    )}
                    {control('usage_limit', 'Remaining successful uses', 'number', { min: 1, max: 1000000, step: 1 },
                        <span className="block text-[11px] text-slate-500">Total number of orders that can redeem this voucher.</span>
                    )}
                    {control('per_buyer_limit', 'Uses per buyer', 'number', { min: 1, max: 1000000, step: 1 },
                        <span className="block text-[11px] text-slate-500">How many times a single buyer can use this voucher (≤ Remaining uses).</span>
                    )}
                </div></fieldset>
                <fieldset className="rounded-xl border border-slate-300 bg-white p-4 shadow-sm"><legend className="flex items-center gap-2 px-2 text-sm font-bold text-slate-900"><CalendarDays className="h-4 w-4 text-[#E00D42]" />Availability</legend><p className="mb-4 text-xs leading-relaxed text-slate-500">Choose when buyers can use this offer. Both dates use Philippine time.</p><div className="grid gap-4 sm:grid-cols-2">
                    <div>{control('starts_at', 'Start (Philippine time)', 'datetime-local', { min: keepsPastStart ? undefined : nowInput })}
                        {keepsPastStart && <p className="mt-1 text-xs font-normal text-slate-500">This existing start date stays visible. Choose today or later if you want to change it.</p>}</div>
                    <div>{control('ends_at', 'End (Philippine time)', 'datetime-local', { min: keepsPastEnd ? undefined : nowInput })}
                        {keepsPastEnd && <p className="mt-1 text-xs font-normal text-slate-500">This historical end date stays visible. Choose a future date to publish a new revision.</p>}</div>
                </div></fieldset>
                {!platform && <VoucherProductSelection scope={form.data.product_scope} ids={form.data.product_ids} initialProducts={campaign?.selected_products}
                    disabled={form.processing} errors={Object.entries(form.errors).filter(([key]) => key === 'product_scope' || key === 'product_ids' || key.startsWith('product_ids.')).map(([, message]) => message)}
                    onScope={scope => {
                        form.setData(data => ({ ...data, product_scope: scope, product_ids: scope === 'all' ? [] : data.product_ids }));
                        if (scope === 'all') setSelectedProducts([]);
                        form.clearErrors('product_scope', 'product_ids');
                    }}
                    onChange={ids => { form.setData('product_ids', ids); form.clearErrors('product_ids'); }}
                    onProductsChange={items => {
                        setSelectedProducts(items);
                        const prices = items.map(product => Number(product.price)).filter(price => Number.isFinite(price) && price > 0);
                        if (prices.length > 0 && form.data.min_spend === '') {
                            const lowest = Math.min(...prices);
                            const discountNum = Number(form.data.discount_value.replace(/%$/, ''));
                            const validDisc = Number.isFinite(discountNum) && discountNum > 0;
                            const calcMax = validDisc
                                ? (form.data.discount_type === 'percent' ? Math.max(0.01, Math.round(lowest * (discountNum / 100) * 100) / 100) : discountNum)
                                : 0;
                            const uses = form.data.usage_limit !== '' && Number(form.data.usage_limit) >= 1 ? Math.floor(Number(form.data.usage_limit)) : 10;
                            form.setData(current => ({
                                ...current,
                                min_spend: current.min_spend === '' ? cleanAmount(lowest) : current.min_spend,
                                max_discount: current.max_discount === '' && calcMax > 0 ? cleanAmount(calcMax) : current.max_discount,
                                budget: current.budget === '' && calcMax > 0 ? cleanAmount(calcMax * uses) : current.budget,
                            }));
                        }
                    }} />}
                <label className="block text-xs font-semibold text-slate-700">Description (optional)
                    <textarea value={form.data.description} maxLength={1000} onChange={e => form.setData('description', e.target.value)} className="mt-1.5 w-full rounded-xl border border-slate-300 p-3 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]" />
                    {form.errors.description && <span role="alert" className="text-rose-700">{form.errors.description}</span>}
                </label>
                {campaign && <label className="block text-xs font-semibold text-slate-700">Reason for this change
                    <textarea required minLength={5} maxLength={1000} value={form.data.reason} onChange={e => form.setData('reason', e.target.value)} aria-invalid={!!form.errors.reason} className="mt-1.5 w-full rounded-xl border border-slate-300 p-3 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]" />
                    <span className="mt-1 block font-normal text-slate-500">Recorded with this draft so the campaign history explains the edit.</span>
                    {form.errors.reason && <span role="alert" className="mt-1 block text-rose-700">{form.errors.reason}</span>}
                </label>}
                <div className="space-y-2 rounded-xl border border-slate-300 bg-slate-50 p-4 text-xs text-slate-700">
                    <h3 className="font-bold text-slate-900">Buyer preview</h3>
                    {!platform && <p>{form.data.product_scope === 'selected' ? `Selected products only (${form.data.product_ids.length}). Only qualifying products count toward the minimum spend and discount.` : 'Applies to all eligible products from your shop.'}</p>}
                    <p>{form.data.name || 'Offer title not entered'} · Minimum {form.data.min_spend === '' ? 'not set' : campaignMoney(Math.round(Number(form.data.min_spend) * 100))} · Up to {form.data.max_discount === '' ? 'not set' : campaignMoney(Math.round(Number(form.data.max_discount) * 100))}</p>
                    {previewReady ? <>
                        <p>Example at the minimum qualifying purchase: <strong>{campaignMoney(example.discount)}</strong>{form.data.discount_type === 'free_shipping' && ' against an example ₱50 shipping fee; actual fees may differ.'}</p>
                        {!platform && <p>Seller gross share: {campaignMoney(example.sellerShare)} · Promotion cost: {campaignMoney(example.discount)} · Net payout: <strong>{campaignMoney(example.netSeller)}</strong></p>}
                    </> : <p>Enter the purchase minimum, discount and maximum to preview an example. No savings are estimated yet.</p>}
                    <p className="text-slate-500">This preview is read-only, excludes other vouchers, and uses your current field values. Checkout checks actual products, fees, dates and remaining limits.</p>
                </div>
                {Object.entries(form.errors).filter(([key]) => !['name', 'code', 'description', 'reason', 'discount_type', 'discount_value', 'min_spend', 'max_discount', 'budget', 'usage_limit', 'per_buyer_limit', 'starts_at', 'ends_at', 'product_scope', 'product_ids'].includes(key) && !key.startsWith('product_ids.')).map(([key, message]) => <p key={key} role="alert" className="text-sm text-rose-700">{message}</p>)}
            </div>
            <div className="flex flex-wrap justify-end gap-3 border-t border-slate-300 bg-white p-4 shadow-[0_-4px_16px_rgba(15,23,42,0.04)]">
                <button type="button" disabled={form.processing} onClick={onClose} className="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold">Cancel</button>
                <button type="submit" name="save_action" value="draft" disabled={form.processing || (form.data.product_scope === 'selected' && form.data.product_ids.length === 0)} className="rounded-xl border border-[#E00D42] bg-white px-4 py-2.5 text-sm font-bold text-[#E00D42] disabled:opacity-50">{submittingAction === 'draft' && form.processing ? 'Saving…' : 'Save draft'}</button>
                <button type="submit" name="save_action" value="publish" disabled={form.processing || (form.data.product_scope === 'selected' && form.data.product_ids.length === 0)} className="rounded-xl bg-[#E00D42] px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50">{submittingAction === 'publish' && form.processing ? 'Publishing…' : 'Publish voucher'}</button>
            </div>
        </form>
    </Modal>;
}
