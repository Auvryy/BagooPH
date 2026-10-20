import { FormEvent, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { DialogTitle } from '@headlessui/react';
import { X } from 'lucide-react';
import Modal from '@/Components/Modal';
import { Campaign, campaignMoney, manilaInput, previewPromotion } from './campaignTypes';

export default function CampaignForm({ campaign, platform, onClose }: { campaign: Campaign | null; platform: boolean; onClose: () => void }) {
    const terms = campaign?.draft_terms ?? campaign?.published_terms;
    const form = useForm({
        code: campaign?.code ?? '', name: terms?.name ?? '', description: terms?.description ?? '',
        discount_type: terms?.discount_type ?? 'fixed',
        discount_value: String(terms ? terms.discount_type === 'percent' ? (terms.percentage_bps ?? 0) / 100 : terms.value_cents / 100 : 100),
        min_spend: String(terms ? terms.minimum_cents / 100 : 500), max_discount: String(terms ? terms.maximum_cents / 100 : 100),
        budget: String(terms ? terms.budget_cents / 100 : 5000), usage_limit: String(terms?.global_limit ?? 50), per_buyer_limit: String(terms?.per_buyer_limit ?? 1),
        starts_at: manilaInput(terms?.starts_at ?? new Date().toISOString()),
        ends_at: manilaInput(terms?.ends_at ?? new Date(Date.now() + 7 * 86400000).toISOString()),
        request_key: crypto.randomUUID(), source_version: campaign?.version ?? 0,
    });
    const [sample, setSample] = useState(form.data.min_spend || '500');
    const example = previewPromotion(form.data, sample);
    const prefix = platform ? 'admin' : 'seller';
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (campaign) form.patch(route(`${prefix}.vouchers.update`, campaign.id), options);
        else form.post(route(`${prefix}.vouchers.store`), options);
    };
    const control = (name: keyof typeof form.data, label: string, type = 'text', extra: Record<string, unknown> = {}) => (
        <label key={name} className="block text-xs font-semibold text-slate-700">
            {label}
            <input type={type} value={form.data[name]} onChange={event => form.setData(name, (name === 'code' ? event.target.value.toUpperCase() : event.target.value) as never)}
                required={name !== 'code'} {...extra} aria-invalid={!!form.errors[name]}
                className="mt-1.5 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:border-[#E00D42] focus:ring-[#E00D42] disabled:bg-slate-100" />
            {form.errors[name] && <span role="alert" className="mt-1 block text-rose-700">{form.errors[name]}</span>}
        </label>
    );
    return <Modal show onClose={onClose} closeable={!form.processing} maxWidth="2xl">
        <form onSubmit={submit} className="flex max-h-[85dvh] flex-col">
            <div className="flex items-start justify-between gap-4 border-b border-slate-300 p-5">
                <div><DialogTitle className="text-lg font-bold text-slate-900">{campaign ? 'Edit draft' : 'New voucher draft'}</DialogTitle>
                    <p className="mt-1 text-xs text-slate-600">{platform ? 'Bagoo funds this promotion.' : 'Your shop funds this product discount.'} Times use Asia/Manila.</p></div>
                <button type="button" onClick={onClose} disabled={form.processing} aria-label="Close voucher draft" className="rounded-lg p-2 hover:bg-slate-100"><X className="h-5 w-5" /></button>
            </div>
            <div className="space-y-5 overflow-y-auto p-5">
                {campaign?.current_revision_id && <p className="rounded-xl bg-amber-50 p-3 text-xs text-amber-900">Saving keeps the published offer unchanged. Publishing this draft starts a new revision with its own dated budget and use limits. Existing orders keep their savings.</p>}
                <div className="grid gap-4 sm:grid-cols-2">
                    {control('name', 'Offer title', 'text', { maxLength: 100 })}
                    <div>{control('code', 'Code (leave blank to generate)', 'text', { minLength: campaign ? undefined : 4, maxLength: campaign ? undefined : 12, pattern: campaign ? undefined : '[A-Za-z0-9]{4,12}', readOnly: !!campaign })}<p className="mt-1 text-xs text-slate-500">4–12 letters and numbers, without spaces or symbols. Existing codes stay the same.</p></div>
                    <label className="block text-xs font-semibold text-slate-700">Discount type
                        <select value={form.data.discount_type} onChange={e => form.setData('discount_type', e.target.value as typeof form.data.discount_type)} className="mt-1.5 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]">
                            <option value="fixed">Fixed product discount</option><option value="percent">Percentage product discount</option>
                            {platform && <option value="free_shipping">Shipping discount</option>}
                        </select>{form.errors.discount_type && <span role="alert" className="mt-1 block text-rose-700">{form.errors.discount_type}</span>}
                    </label>
                    {control('discount_value', form.data.discount_type === 'percent' ? 'Percentage (shop maximum 90%)' : form.data.discount_type === 'free_shipping' ? 'Shipping amount (0 uses the cap)' : 'Discount amount (₱)', 'number', { min: form.data.discount_type === 'free_shipping' ? 0 : 0.01, step: '0.01', max: form.data.discount_type === 'percent' ? platform ? 100 : 90 : undefined })}
                    {control('min_spend', 'Minimum products purchase (₱)', 'number', { min: 0, step: '0.01' })}
                    {control('max_discount', 'Maximum discount per use (₱)', 'number', { min: 0.01, step: '0.01' })}
                    {control('budget', 'Spending ceiling (₱)', 'number', { min: 0.01, step: '0.01' })}
                    {control('usage_limit', 'Total successful uses', 'number', { min: 1, step: 1 })}
                    {control('per_buyer_limit', 'Uses per buyer', 'number', { min: 1, step: 1 })}
                    {control('starts_at', 'Start (Philippine time)', 'datetime-local')}
                    {control('ends_at', 'End (Philippine time)', 'datetime-local')}
                </div>
                <label className="block text-xs font-semibold text-slate-700">Description (optional)
                    <textarea value={form.data.description} maxLength={1000} onChange={e => form.setData('description', e.target.value)} className="mt-1.5 w-full rounded-xl border border-slate-300 p-3 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]" />
                    {form.errors.description && <span role="alert" className="text-rose-700">{form.errors.description}</span>}
                </label>
                <div className="space-y-2 rounded-xl border border-slate-300 bg-slate-50 p-4 text-xs text-slate-700">
                    <h3 className="font-bold text-slate-900">Buyer preview</h3>
                    <p>{form.data.name || 'Your offer title'} · Minimum {campaignMoney(Math.round(Number(form.data.min_spend || 0) * 100))} · Up to {campaignMoney(Math.round(Number(form.data.max_discount || 0) * 100))}</p>
                    <label className="flex items-center gap-3">Example products purchase (₱)<input aria-label="Example purchase" type="number" min="0" step="0.01" value={sample} onChange={e => setSample(e.target.value)} className="w-28 rounded-lg border border-slate-300 p-2" /></label>
                    <p>Example saving: <strong>{campaignMoney(example.discount)}</strong>{form.data.discount_type === 'free_shipping' && ' against an example ₱50 shipping fee; actual fees may differ.'}</p>
                    {!platform && <p>Seller gross share: {campaignMoney(example.sellerShare)} · Promotion cost: {campaignMoney(example.discount)} · Net payout: <strong>{campaignMoney(example.netSeller)}</strong></p>}
                    <p className="text-slate-500">This example excludes other vouchers. Checkout checks actual products, fees, dates and remaining limits.</p>
                </div>
                {Object.entries(form.errors).filter(([key]) => !['name', 'code', 'description', 'discount_type', 'discount_value', 'min_spend', 'max_discount', 'budget', 'usage_limit', 'per_buyer_limit', 'starts_at', 'ends_at'].includes(key)).map(([key, message]) => <p key={key} role="alert" className="text-sm text-rose-700">{message}</p>)}
            </div>
            <div className="flex justify-end gap-3 border-t border-slate-300 p-4">
                <button type="button" disabled={form.processing} onClick={onClose} className="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold">Cancel</button>
                <button disabled={form.processing} className="rounded-xl bg-[#E00D42] px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50">{form.processing ? 'Saving…' : 'Save draft'}</button>
            </div>
        </form>
    </Modal>;
}
