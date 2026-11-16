import { useMemo, useState } from 'react';
import { Search, X } from 'lucide-react';
import { inputClasses } from '@/Components/ui';

export interface PickerProduct { id: number; name: string; shop_id: number; category_id?: number | null }
interface Named { id: number; name: string }

interface Props {
    products: PickerProduct[];
    shops: Named[];
    categories: Named[];
    /** Selected product ids. Single mode keeps at most one. */
    value: number[];
    onChange: (ids: number[]) => void;
    multiple?: boolean;
    max?: number;
    disabled?: boolean;
}

const LIST_LIMIT = 60;

/**
 * Pick products for a promotion without scrolling a list of every product:
 * narrow by shop, category and name, then tick (or "Add all shown").
 */
export default function ProductPicker({ products, shops, categories, value, onChange, multiple = false, max = 24, disabled = false }: Props) {
    const [query, setQuery] = useState('');
    const [shop, setShop] = useState('');
    const [category, setCategory] = useState('');
    const shopName = useMemo(() => new Map(shops.map(item => [item.id, item.name])), [shops]);
    const byId = useMemo(() => new Map(products.map(item => [item.id, item])), [products]);
    const usedCategories = useMemo(() => {
        const ids = new Set(products.map(item => item.category_id).filter(Boolean));
        return categories.filter(item => ids.has(item.id));
    }, [products, categories]);
    const usedShops = useMemo(() => {
        const ids = new Set(products.map(item => item.shop_id));
        return shops.filter(item => ids.has(item.id));
    }, [products, shops]);

    const matches = useMemo(() => {
        const words = query.trim().toLowerCase();
        return products.filter(item => (!shop || item.shop_id === Number(shop))
            && (!category || item.category_id === Number(category))
            && (!words || item.name.toLowerCase().includes(words)));
    }, [products, query, shop, category]);
    const shown = matches.slice(0, LIST_LIMIT);
    const full = multiple && value.length >= max;
    const filtered = Boolean(query || shop || category);

    const toggle = (id: number) => {
        if (!multiple) { onChange(value[0] === id ? [] : [id]); return; }
        onChange(value.includes(id) ? value.filter(item => item !== id) : full ? value : [...value, id]);
    };
    const addAllShown = () => {
        const next = [...value];
        for (const item of matches) {
            if (next.length >= max) break;
            if (!next.includes(item.id)) next.push(item.id);
        }
        onChange(next);
    };

    return <div className="space-y-3 rounded-card border border-line-200 p-3">
        <div className="grid gap-2 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)]">
            <label className="relative block">
                <span className="sr-only">Search products</span>
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-500" aria-hidden="true" />
                <input type="search" value={query} disabled={disabled} onChange={event => setQuery(event.target.value)} placeholder="Search product name" className={`${inputClasses} pl-9`} />
            </label>
            <label className="block"><span className="sr-only">Shop</span>
                <select value={shop} disabled={disabled} onChange={event => setShop(event.target.value)} className={inputClasses}>
                    <option value="">All shops</option>
                    {usedShops.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
                </select>
            </label>
            <label className="block"><span className="sr-only">Category</span>
                <select value={category} disabled={disabled} onChange={event => setCategory(event.target.value)} className={inputClasses}>
                    <option value="">All categories</option>
                    {usedCategories.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
                </select>
            </label>
        </div>

        <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-ink-600">
            <span>{matches.length} product{matches.length === 1 ? '' : 's'} match{matches.length > LIST_LIMIT ? ` · showing first ${LIST_LIMIT}, narrow the search to see more` : ''}</span>
            <span className="flex gap-3">
                {filtered && <button type="button" onClick={() => { setQuery(''); setShop(''); setCategory(''); }} className="font-semibold text-ink-700 underline">Clear filters</button>}
                {multiple && filtered && matches.length > 0 && <button type="button" disabled={disabled || full} onClick={addAllShown} className="font-semibold text-brand-600 underline disabled:text-ink-500 disabled:no-underline">Add all matching (up to {max})</button>}
            </span>
        </div>

        <ul className="max-h-64 divide-y divide-line-200 overflow-y-auto rounded-control border border-line-200" aria-label="Matching products">
            {shown.length ? shown.map(item => {
                const checked = value.includes(item.id);
                return <li key={item.id}>
                    <label className={`flex cursor-pointer items-start gap-3 px-3 py-2 text-sm hover:bg-surface-100 ${checked ? 'bg-brand-50' : ''}`}>
                        <input type={multiple ? 'checkbox' : 'radio'} name="picker-product" checked={checked} disabled={disabled || (!checked && full)} onChange={() => toggle(item.id)} className="mt-0.5 border-line-300 text-brand-500 focus:ring-brand-500" />
                        <span className="min-w-0"><span className="block text-ink-900">{item.name}</span><span className="block text-xs text-ink-600">{shopName.get(item.shop_id) ?? 'Shop'}</span></span>
                    </label>
                </li>;
            }) : <li className="px-3 py-6 text-center text-sm text-ink-600">No products match. Change the shop, category or search.</li>}
        </ul>

        {value.length > 0 && <div>
            <div className="flex items-center justify-between text-xs font-medium text-ink-700">
                <span>Selected {multiple ? `${value.length}/${max}` : ''}</span>
                {multiple && <button type="button" disabled={disabled} onClick={() => onChange([])} className="font-semibold underline">Remove all</button>}
            </div>
            <ul className="mt-2 flex flex-wrap gap-2">
                {value.map(id => <li key={id} className="inline-flex max-w-full items-center gap-1 rounded-full border border-line-300 bg-white py-1 pl-3 pr-1 text-xs text-ink-900">
                    <span className="truncate">{byId.get(id)?.name ?? `Product #${id}`}</span>
                    <button type="button" disabled={disabled} onClick={() => onChange(value.filter(item => item !== id))} className="rounded-full p-1 hover:bg-surface-100" aria-label={`Remove ${byId.get(id)?.name ?? 'product'}`}><X className="h-3 w-3" aria-hidden="true" /></button>
                </li>)}
            </ul>
        </div>}
    </div>;
}
