import React, { useRef, useState } from 'react';
import { Sparkles, X } from 'lucide-react';
import type { StoreTag } from '@/types';
import { inputClasses, labelClasses, buttonClasses } from '@/Components/ui';
import { acceptPriceDraft } from '@/utils/sellerListingValidation';

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
    const checkingTag = useRef(false);
    const [tagPending, setTagPending] = useState(false);
    const latest = useRef({ data, productName });
    latest.current = { data, productName };
    const [suggestions, setSuggestions] = useState<string[]>([]);
    const [suggestionError, setSuggestionError] = useState('');
    const [suggesting, setSuggesting] = useState(false);
    const input = inputClasses;
    // Weight and size fields take numbers only: letters, signs and a third decimal are refused as they are typed.
    const decimal = (key: 'weight_kg' | 'length_cm' | 'width_cm' | 'height_cm', maximum: number) => (event: React.ChangeEvent<HTMLInputElement>) => {
        const value = acceptPriceDraft(event.target.value.trim(), maximum);
        if (value !== null) onChange(key, value);
    };
    const error = (key: string) => {
        let message = errors[key];
        if (!message && ['weight_kg', 'length_cm', 'width_cm', 'height_cm'].includes(key)) {
            const value = data[key as 'weight_kg' | 'length_cm' | 'width_cm' | 'height_cm'];
            const maximum = key === 'weight_kg' ? 50000 : 10000;
            if (value && (!/^[0-9]+(?:\.[0-9]{1,2})?$/.test(value) || Number(value) < 0.01 || Number(value) > maximum)) message = `Enter 0.01–${maximum.toLocaleString('en-PH')} with up to 2 decimal places.`;
        }
        return message ? <span role="alert" className="mt-1.5 block text-[13px] font-normal text-brand-700">{message}</span> : null;
    };
    const membershipError = Object.entries(errors).find(([key]) => key === 'tag_names' || key.startsWith('tag_names.'))?.[1];
    const addTag = async (raw: string) => {
        const name = raw.trim().replace(/\s+/g, ' ');
        if (!name || Array.from(name).length > 50 || /[<>\u0000-\u001f\u007f]/u.test(name)) return;
        if (data.tag_names.some(tag => tag.toLocaleLowerCase() === name.toLocaleLowerCase()) || data.tag_names.length >= 20) return;
        if (checkingTag.current) return;
        if (!productName.trim()) { setSuggestionError('Enter the product name before adding a tag.'); return; }
        checkingTag.current = true;
        setTagPending(true);
        setSuggestionError('');
        const checkedProduct = productName;
        try {
            const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
            const response = await fetch(route('seller.products.assist-tags'), {
                method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ product_name: checkedProduct.trim(), tag: name }),
            });
            const payload = await response.json();
            if (!response.ok || payload.tag !== name) throw new Error(payload.errors?.tag_names?.[0] || payload.message || 'This tag could not be verified. Try again.');
            if (latest.current.productName !== checkedProduct) throw new Error('The product name changed. Check the tag again.');
            const current = latest.current.data;
            if (current.tag_names.length < 20 && !current.tag_names.some(tag => tag.toLocaleLowerCase() === name.toLocaleLowerCase())) {
                onChange('tag_names', [...current.tag_names, name]);
                if (current.tag_draft.trim() === raw.trim()) onChange('tag_draft', '');
                setSuggestions(tags => tags.filter(tag => tag.toLocaleLowerCase() !== name.toLocaleLowerCase()));
            }
        } catch (failure) {
            setSuggestionError(failure instanceof Error ? failure.message : 'Could not check this tag. Try again.');
        } finally {
            checkingTag.current = false;
            setTagPending(false);
        }
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
    return <section className="space-y-4" aria-label="Product details and tags">
        <h3 className="text-base font-semibold text-ink-900">Shipping and details</h3>
        <div className="grid gap-4 sm:grid-cols-2">
            <div><label htmlFor="listing-weight" className={labelClasses}>Declared weight (kg) <span className="text-brand-700">*</span></label><input id="listing-weight" required type="text" inputMode="decimal" maxLength={8} placeholder="0.50" value={data.weight_kg} onChange={decimal('weight_kg', 50000)} className={input} />{error('weight_kg') ?? <span className="mt-1.5 block text-xs text-ink-600">Required to publish. Used for the shipping fee.</span>}</div>
            <fieldset className="min-w-0"><legend className={labelClasses}>Package size (cm) <span className="font-normal text-ink-500">optional</span></legend><div className="grid grid-cols-3 gap-2">{(['length_cm', 'width_cm', 'height_cm'] as const).map((key, index) => <div key={key}><input type="text" inputMode="decimal" maxLength={8} aria-label={`${['Length', 'Width', 'Height'][index]} in cm`} placeholder={['Length', 'Width', 'Height'][index]} value={data[key]} onChange={decimal(key, 10000)} className={input} />{error(key)}</div>)}</div><span className="mt-1.5 block text-xs text-ink-600">Fill all three or leave all blank.</span></fieldset>
            <div><label htmlFor="listing-brand" className={labelClasses}>Brand <span className="font-normal text-ink-500">optional</span></label><input id="listing-brand" value={data.brand} maxLength={100} onChange={event => onChange('brand', event.target.value)} placeholder="Leave blank if unbranded" className={input} />{error('brand')}</div>
            <div><label htmlFor="listing-condition" className={labelClasses}>Condition</label><select id="listing-condition" value={data.condition} onChange={event => onChange('condition', event.target.value)} className={input}><option value="">Not specified</option><option value="new">New</option><option value="used">Used</option></select>{error('condition')}</div>
        </div>
        <fieldset className="space-y-3">
            <legend className={labelClasses}>Search tags <span className="font-normal text-ink-500">optional, up to 20</span></legend>
            <p className="text-xs text-ink-600">Stays in your approved {departmentName || 'shop'} department. Tags help buyers find it.</p>
            <div className="rounded-card border border-line-200 bg-white p-3">
                <div className="flex flex-wrap gap-2" aria-label="Selected product tags">
                    {data.tag_names.map(name => <span key={name.toLocaleLowerCase()} className="inline-flex items-center gap-1 rounded-full bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-700">{name}<button type="button" onClick={() => removeTag(name)} aria-label={`Remove ${name} tag`} className="rounded-full p-0.5 hover:bg-brand-100"><X className="h-3 w-3" /></button></span>)}
                    {!data.tag_names.length && <span className="text-xs text-ink-500">No tags yet.</span>}
                </div>
                <div className="mt-3 flex flex-wrap gap-2">
                    <input value={data.tag_draft} onChange={event => onChange('tag_draft', event.target.value)} onKeyDown={event => { if (event.key === 'Enter') { event.preventDefault(); addTag(data.tag_draft); } }} maxLength={50} placeholder="Add a product tag" className={`${inputClasses} min-w-0 flex-1 basis-48`} aria-label="Add product tag" />
                    <button type="button" onClick={() => addTag(data.tag_draft)} disabled={tagPending || !data.tag_draft.trim() || data.tag_names.length >= 20} className={buttonClasses('secondary')}>{tagPending ? 'Checking tag…' : 'Add tag'}</button>
                    <button type="button" onClick={() => void suggestTags()} disabled={!productName.trim() || suggesting || data.tag_names.length >= 20} className={buttonClasses('secondary')}>{suggesting ? <span className="animate-pulse">Checking…</span> : <><Sparkles className="h-3.5 w-3.5" />Suggest tags</>}</button>
                </div>
                {available.length > 0 && <div className="mt-3 flex flex-wrap gap-2">{available.map(tag => <button key={tag.id} type="button" onClick={() => addTag(tag.name)} disabled={tagPending || data.tag_names.length >= 20} className="rounded-full border border-line-300 px-2.5 py-1 text-xs text-ink-700 hover:border-brand-500 hover:text-brand-700 disabled:opacity-50">+ {tag.name}</button>)}</div>}
                {suggestions.length > 0 && <div className="mt-3"><p className="text-xs font-semibold text-ink-600">AI suggestions · review before adding</p><div className="mt-2 flex flex-wrap gap-2">{suggestions.map(name => <button key={name} type="button" onClick={() => addTag(name)} disabled={tagPending || data.tag_names.length >= 20} className="rounded-full border border-line-300 bg-white px-2.5 py-1 text-xs text-ink-700 hover:border-brand-500 disabled:opacity-50">+ {name}</button>)}</div></div>}
                {suggestionError && <p role="alert" className="mt-2 text-[13px] text-brand-700">{suggestionError}</p>}
                {membershipError && <p role="alert" className="mt-2 text-[13px] text-brand-700">{membershipError}</p>}
            </div>
        </fieldset>
    </section>;
}
