import type { StoreCategory } from '@/types';

export type ListingDetails = { brand: string; condition: string; weight_kg: string; length_cm: string; width_cm: string; height_cm: string; store_category_ids: number[] };
export const emptyListingDetails: ListingDetails = { brand: '', condition: '', weight_kg: '', length_cm: '', width_cm: '', height_cm: '', store_category_ids: [] };

export default function SellerListingDetails({ data, errors, categories, onChange }: {
    data: ListingDetails;
    errors: Partial<Record<string, string>>;
    categories: StoreCategory[];
    onChange: <K extends keyof ListingDetails>(key: K, value: ListingDetails[K]) => void;
}) {
    const input = 'mt-2 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]';
    const error = (key: string) => {
        let message = errors[key];
        if (!message && ['weight_kg', 'length_cm', 'width_cm', 'height_cm'].includes(key)) {
            const value = data[key as 'weight_kg' | 'length_cm' | 'width_cm' | 'height_cm'];
            const maximum = key === 'weight_kg' ? 50000 : 10000;
            if (value && (!/^[0-9]+(?:\.[0-9]{1,2})?$/.test(value) || Number(value) < 0.01 || Number(value) > maximum)) message = `Enter 0.01–${maximum.toLocaleString('en-PH')} with up to 2 decimal places.`;
        }
        return message ? <span role="alert" className="mt-1 block text-xs font-normal text-rose-700">{message}</span> : null;
    };
    const membershipError = Object.entries(errors).find(([key]) => key.startsWith('store_category_ids'))?.[1];
    const ordered = categories.filter(category => !category.parent_id).flatMap(root => [root, ...categories.filter(category => category.parent_id === root.id)]);
    return <section className="space-y-4 border-t border-slate-300 pt-5" aria-label="Product details and store categories">
        <h3 className="text-sm font-bold text-slate-900">Product details</h3>
        <p className="text-xs leading-relaxed text-slate-500">Buyers can view these saved details after you publish the listing. Drafts stay private.</p>
        <div className="grid gap-4 sm:grid-cols-2">
            <label className="text-xs font-semibold text-slate-700">Brand<input value={data.brand} maxLength={100} onChange={event => onChange('brand', event.target.value)} placeholder="No brand, if unbranded" className={input} />{error('brand')}</label>
            <label className="text-xs font-semibold text-slate-700">Condition<select value={data.condition} onChange={event => onChange('condition', event.target.value)} className={input}><option value="">Not specified</option><option value="new">New</option><option value="used">Used</option></select>{error('condition')}</label>
            <label className="text-xs font-semibold text-slate-700">Declared weight (kg) *<input required type="number" inputMode="decimal" min="0.01" max="50000" step="0.01" value={data.weight_kg} onChange={event => onChange('weight_kg', event.target.value)} className={input} /><span className="mt-1 block font-normal text-slate-500">0.01–50,000 kg, up to 2 decimal places. Required to publish.</span>{error('weight_kg')}</label>
            <fieldset className="min-w-0"><legend className="text-xs font-semibold text-slate-700">Dimensions (cm)</legend><div className="mt-2 grid grid-cols-3 gap-2">{(['length_cm', 'width_cm', 'height_cm'] as const).map((key, index) => <label key={key} className="text-xs text-slate-500">{['Length', 'Width', 'Height'][index]}<input type="number" inputMode="decimal" min="0.01" max="10000" step="0.01" value={data[key]} onChange={event => onChange(key, event.target.value)} className={input} />{error(key)}</label>)}</div><p className="mt-1 text-xs text-slate-500">Optional. If supplied, enter all three: 0.01–10,000 cm.</p></fieldset>
        </div>
        <fieldset className="space-y-3"><legend className="text-xs font-semibold text-slate-700">Store categories</legend><p className="text-xs text-slate-500">Choose up to 3. With none selected, this product appears under Uncategorized.</p>
            {ordered.length ? <div className="grid max-h-48 gap-2 overflow-y-auto rounded-xl border border-slate-300 p-3 sm:grid-cols-2">{ordered.map(category => <label key={category.id} className={`flex items-start gap-2 text-xs text-slate-700 ${category.parent_id ? 'pl-4' : ''}`}><input type="checkbox" checked={data.store_category_ids.includes(category.id)} disabled={!data.store_category_ids.includes(category.id) && data.store_category_ids.length >= 3} onChange={event => onChange('store_category_ids', event.target.checked ? [...data.store_category_ids, category.id] : data.store_category_ids.filter(id => id !== category.id))} className="rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" /><span>{category.name}{!category.is_visible ? ' (hidden)' : ''}</span></label>)}</div> : <p className="text-xs text-slate-500">Add categories from Store categories in the menu whenever you need them.</p>}
            {membershipError && <p role="alert" className="text-xs text-rose-700">{membershipError}</p>}
        </fieldset>
    </section>;
}
