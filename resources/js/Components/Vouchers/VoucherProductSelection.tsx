import { useEffect, useState } from 'react';
import axios from 'axios';
import { PaginatedData } from '@/types';

export interface VoucherProduct {
    id: number; name: string; sku: string | null; featured_image: string | null; eligible?: boolean;
}

export default function VoucherProductSelection({ scope, ids, initialProducts = [], errors = [], disabled = false, onScope, onChange }: {
    scope: 'all' | 'selected'; ids: number[]; initialProducts?: VoucherProduct[]; errors?: string[]; disabled?: boolean;
    onScope: (scope: 'all' | 'selected') => void; onChange: (ids: number[]) => void;
}) {
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [retry, setRetry] = useState(0);
    const [products, setProducts] = useState<PaginatedData<VoucherProduct> | null>(null);
    const [known, setKnown] = useState<Record<number, VoucherProduct>>(() => Object.fromEntries(initialProducts.map(product => [product.id, product])));
    const [pending, setPending] = useState(scope === 'selected');
    const [failure, setFailure] = useState('');
    useEffect(() => {
        if (scope !== 'selected') return;
        const controller = new AbortController();
        setPending(true); setFailure('');
        const timer = window.setTimeout(() => {
            axios.get(route('seller.vouchers.products'), { params: { search, page }, signal: controller.signal }).then(response => {
                if (controller.signal.aborted) return;
                const rows = response.data?.products;
                if (!rows || !Array.isArray(rows.data) || !Number.isInteger(rows.last_page)) throw new Error('Invalid product response');
                setProducts(rows);
                setKnown(current => ({ ...current, ...Object.fromEntries(rows.data.map((product: VoucherProduct) => [product.id, product])) }));
            }).catch(error => {
                if (!controller.signal.aborted) setFailure(axios.isAxiosError(error) && [401, 419].includes(error.response?.status ?? 0)
                    ? 'Reload to renew your session, then try again.' : 'Could not load your products. Try again.');
            }).finally(() => { if (!controller.signal.aborted) setPending(false); });
        }, 200);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [scope, search, page, retry]);
    const toggle = (id: number) => onChange(ids.includes(id) ? ids.filter(current => current !== id) : [...ids, id].sort((a, b) => a - b));
    return <fieldset className="space-y-3 border-t border-slate-300 pt-5" disabled={disabled}>
        <legend className="text-sm font-bold text-slate-900">Eligible products</legend>
        <div className="flex flex-wrap gap-x-6 gap-y-3 text-sm text-slate-700">
            {([['all', 'All shop products'], ['selected', 'Selected products']] as const).map(([value, label]) => <label key={value} className="flex min-h-11 cursor-pointer items-center gap-2">
                <input type="radio" name="voucher-product-scope" value={value} checked={scope === value} onChange={() => onScope(value)} className="text-[#E00D42] focus:ring-[#E00D42]" />{label}
            </label>)}
        </div>
        <p className="text-xs leading-5 text-slate-600">{scope === 'selected' ? 'Only these products count toward the minimum spend and discount. All their available variants qualify. Choose up to 100 products.' : 'All eligible products from your shop count toward the minimum spend and discount.'}</p>
        {scope === 'selected' && <>
            <div className="flex flex-wrap items-center justify-between gap-2 text-xs"><span className="font-semibold text-slate-700">{ids.length} / 100 selected</span>
                {ids.length > 0 && <button type="button" onClick={() => onChange([])} className="min-h-11 font-semibold text-[#C20836] hover:underline">Clear selection</button>}
            </div>
            {ids.length > 0 && <ul aria-label="Selected voucher products" className="max-h-48 divide-y divide-slate-200 overflow-y-auto rounded-xl border border-slate-300">
                {ids.map(id => <li key={id} className="flex items-center justify-between gap-3 px-3 py-2 text-xs">
                    <span className="min-w-0"><span className="block break-words font-semibold text-slate-800">{known[id]?.name ?? 'Product no longer available'}</span>
                        {(!known[id] || known[id].eligible === false) && <span className="block text-amber-800">Remove this product or choose another before publishing.</span>}</span>
                    <button type="button" aria-label={`Remove ${known[id]?.name ?? 'unavailable product'}`} onClick={() => toggle(id)} className="min-h-11 shrink-0 px-2 font-semibold text-[#C20836] hover:underline">Remove</button>
                </li>)}
            </ul>}
            <label className="block text-xs font-semibold text-slate-700">Search published products
                <input type="search" value={search} maxLength={100} onChange={event => { setSearch(event.target.value); setPage(1); }}
                    onKeyDown={event => { if (event.key === 'Enter') event.preventDefault(); }} placeholder="Product name or SKU"
                    className="mt-1.5 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]" />
            </label>
            {pending ? <p role="status" className="flex min-h-24 items-center justify-center text-xs text-slate-600">Loading your products…</p>
                : failure ? <div role="alert" className="flex min-h-24 flex-col items-center justify-center gap-2 text-center text-xs"><p className="text-rose-700">{failure}</p><button type="button" onClick={() => setRetry(retry + 1)} className="min-h-11 font-semibold text-[#C20836] hover:underline">Try again</button></div>
                    : products?.data.length ? <>
                        <div className="divide-y divide-slate-200 rounded-xl border border-slate-300">
                            {products.data.map(product => <label key={product.id} className="flex min-h-14 cursor-pointer items-center gap-3 px-3 py-2 text-xs hover:bg-slate-50">
                                <input type="checkbox" checked={ids.includes(product.id)} disabled={disabled || (ids.length >= 100 && !ids.includes(product.id))}
                                    onChange={() => toggle(product.id)} className="rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" />
                                <span className="min-w-0"><span className="block break-words font-semibold text-slate-900">{product.name}</span>{product.sku && <span className="mt-1 block break-all text-slate-500">{product.sku}</span>}</span>
                            </label>)}
                        </div>
                        {products.last_page > 1 && <nav aria-label="Voucher product pages" className="flex items-center justify-between gap-3 text-xs">
                            <button type="button" disabled={disabled || page <= 1} onClick={() => setPage(page - 1)} className="min-h-11 rounded-lg border border-slate-300 px-3 disabled:opacity-50">Previous</button>
                            <span>{page} of {products.last_page}</span><button type="button" disabled={disabled || page >= products.last_page} onClick={() => setPage(page + 1)} className="min-h-11 rounded-lg border border-slate-300 px-3 disabled:opacity-50">Next</button>
                        </nav>}
                    </> : <p className="flex min-h-24 items-center justify-center text-center text-xs text-slate-600">No matching published products. Try another search or publish a product first.</p>}
            {ids.length === 0 && <p role="alert" className="text-xs text-rose-700">Choose at least one product, or use All shop products.</p>}
        </>}
        {errors.map((error, index) => <p key={index} role="alert" className="text-xs text-rose-700">{error}</p>)}
    </fieldset>;
}
