import { useState } from 'react';
import { Sparkles, X } from 'lucide-react';
import type { StoreTag } from '@/types';

export type ListingDetails = {
    brand: string;
    condition: string;
    weight_kg: string;
    length_cm: string;
    width_cm: string;
    height_cm: string;
    tag_names: string[];
    tag_draft: string;
};
export const emptyListingDetails: ListingDetails = { brand: '', condition: '', weight_kg: '', length_cm: '', width_cm: '', height_cm: '', tag_names: [], tag_draft: '' };

export default function SellerListingDetails({ data, errors, tags, departmentName, productName, onChange }: {
    data: ListingDetails;
    errors: Partial<Record<string, string>>;
    tags: StoreTag[];
    departmentName: string;
    productName: string;
    onChange: <K extends keyof ListingDetails>(key: K, value: ListingDetails[K]) => void;
}) {
    const [suggestions, setSuggestions] = useState<string[]>([]);
    const [suggestionError, setSuggestionError] = useState('');
    const [suggesting, setSuggesting] = useState(false);
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
    const membershipError = Object.entries(errors).find(([key]) => key === 'tag_names' || key.startsWith('tag_names.'))?.[1];
    const addTag = (raw: string) => {
        const name = raw.trim().replace(/\s+/g, ' ');
        if (!name || Array.from(name).length > 50 || /[<>\u0000-\u001f\u007f]/u.test(name)) return;
        if (data.tag_names.some(tag => tag.toLocaleLowerCase() === name.toLocaleLowerCase()) || data.tag_names.length >= 20) return;
        onChange('tag_names', [...data.tag_names, name]);
        onChange('tag_draft', '');
        setSuggestions(current => current.filter(tag => tag.toLocaleLowerCase() !== name.toLocaleLowerCase()));
    };
    const removeTag = (name: string) => onChange('tag_names', data.tag_names.filter(tag => tag.toLocaleLowerCase() !== name.toLocaleLowerCase()));
    const suggestTags = async () => {
        if (!productName.trim() || suggesting) return;
        setSuggesting(true);
        setSuggestionError('');
        try {
            const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
            const response = await fetch(route('seller.products.assist-tags'), {
                method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ product_name: productName.trim(), tags: data.tag_names }),
            });
            const payload = await response.json();
            if (!response.ok || !Array.isArray(payload.tags)) throw new Error(payload.message || 'Could not suggest tags right now.');
            const selected = new Set(data.tag_names.map(tag => tag.toLocaleLowerCase()));
            const valid = (payload.tags as unknown[]).filter((tag): tag is string => typeof tag === 'string' && tag.length <= 50 && !selected.has(tag.toLocaleLowerCase()));
            setSuggestions(Array.from(new Set<string>(valid)));
        } catch (failure) {
            setSuggestionError(failure instanceof Error ? failure.message : 'Could not suggest tags right now.');
        } finally {
            setSuggesting(false);
        }
    };
    const selected = new Set(data.tag_names.map(name => name.toLocaleLowerCase()));
    const available = tags.filter(tag => !selected.has(tag.name.toLocaleLowerCase()));
    return <section className="space-y-4 border-t border-slate-300 pt-5" aria-label="Product details and tags">
        <h3 className="text-sm font-bold text-slate-900">Product details</h3>
        <p className="text-xs leading-relaxed text-slate-500">Buyers can view these saved details after you publish the listing. Drafts stay private.</p>
        <div className="grid gap-4 sm:grid-cols-2">
            <label className="text-xs font-semibold text-slate-700">Brand<input value={data.brand} maxLength={100} onChange={event => onChange('brand', event.target.value)} placeholder="No brand, if unbranded" className={input} />{error('brand')}</label>
            <label className="text-xs font-semibold text-slate-700">Condition<select value={data.condition} onChange={event => onChange('condition', event.target.value)} className={input}><option value="">Not specified</option><option value="new">New</option><option value="used">Used</option></select>{error('condition')}</label>
            <label className="text-xs font-semibold text-slate-700">Declared weight (kg) *<input required type="number" inputMode="decimal" min="0.01" max="50000" step="0.01" value={data.weight_kg} onChange={event => onChange('weight_kg', event.target.value)} className={input} /><span className="mt-1 block font-normal text-slate-500">0.01–50,000 kg, up to 2 decimal places. Required to publish.</span>{error('weight_kg')}</label>
            <fieldset className="min-w-0"><legend className="text-xs font-semibold text-slate-700">Dimensions (cm)</legend><div className="mt-2 grid grid-cols-3 gap-2">{(['length_cm', 'width_cm', 'height_cm'] as const).map((key, index) => <label key={key} className="text-xs text-slate-500">{['Length', 'Width', 'Height'][index]}<input type="number" inputMode="decimal" min="0.01" max="10000" step="0.01" value={data[key]} onChange={event => onChange(key, event.target.value)} className={input} />{error(key)}</label>)}</div><p className="mt-1 text-xs text-slate-500">Optional. If supplied, enter all three: 0.01–10,000 cm.</p></fieldset>
        </div>
        <fieldset className="space-y-3">
            <legend className="text-xs font-semibold text-slate-700">Product tags</legend>
            <p className="text-xs text-slate-500">Products stay in your approved {departmentName || 'shop'} department. Add flat search tags that describe this product (up to 20, 1–50 characters each).</p>
            <div className="rounded-xl border border-slate-300 bg-white p-3">
                <div className="flex flex-wrap gap-2" aria-label="Selected product tags">
                    {data.tag_names.map(name => <span key={name.toLocaleLowerCase()} className="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-800">{name}<button type="button" onClick={() => removeTag(name)} aria-label={`Remove ${name} tag`} className="rounded-full p-0.5 hover:bg-rose-100"><X className="h-3 w-3" /></button></span>)}
                    {!data.tag_names.length && <span className="text-xs text-slate-500">No tags selected.</span>}
                </div>
                <div className="mt-3 flex flex-wrap gap-2">
                    <input value={data.tag_draft} onChange={event => onChange('tag_draft', event.target.value)} onKeyDown={event => { if (event.key === 'Enter') { event.preventDefault(); addTag(data.tag_draft); } }} maxLength={50} placeholder="Add a product tag" className="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-xs" aria-label="Add product tag" />
                    <button type="button" onClick={() => addTag(data.tag_draft)} disabled={!data.tag_draft.trim() || data.tag_names.length >= 20} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold disabled:opacity-50">Add tag</button>
                    <button type="button" onClick={() => void suggestTags()} disabled={!productName.trim() || suggesting || data.tag_names.length >= 20} className="inline-flex items-center gap-1 rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">{suggesting ? <span className="animate-pulse">Checking…</span> : <><Sparkles className="h-3.5 w-3.5" />Suggest tags</>}</button>
                </div>
                {available.length > 0 && <div className="mt-3 flex flex-wrap gap-2">{available.map(tag => <button key={tag.id} type="button" onClick={() => addTag(tag.name)} disabled={data.tag_names.length >= 20} className="rounded-full border border-slate-300 px-2.5 py-1 text-xs text-slate-700 hover:border-rose-300 hover:text-rose-700 disabled:opacity-50">+ {tag.name}</button>)}</div>}
                {suggestions.length > 0 && <div className="mt-3"><p className="text-xs font-semibold text-slate-600">AI suggestions · review before adding</p><div className="mt-2 flex flex-wrap gap-2">{suggestions.map(name => <button key={name} type="button" onClick={() => addTag(name)} disabled={data.tag_names.length >= 20} className="rounded-full border border-violet-300 bg-violet-50 px-2.5 py-1 text-xs text-violet-800 disabled:opacity-50">+ {name}</button>)}</div></div>}
                {suggestionError && <p role="alert" className="mt-2 text-xs text-rose-700">{suggestionError}</p>}
                {membershipError && <p role="alert" className="mt-2 text-xs text-rose-700">{membershipError}</p>}
            </div>
        </fieldset>
    </section>;
}
