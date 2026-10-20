import React, { useEffect } from 'react';

export interface StockCombination { color: string | null; size: string | null; stock: number; extra_price: number | string }
interface Props {
    colors: { name: string }[];
    sizes: { name: string; extra_price: number }[];
    value?: StockCombination[];
    onChange: (rows: StockCombination[] | undefined) => void;
    error?: string;
}

export default function VariantStockMatrix({ colors, sizes, value, onChange, error }: Props) {
    const options = (colors.length ? colors : [{ name: null }]).flatMap(color =>
        (sizes.length ? sizes : [{ name: null, extra_price: 0 }]).map(size => ({ color: color.name, size: size.name, extra_price: size.extra_price })));
    const signature = JSON.stringify(options.map(row => [row.color, row.size]));
    const enabled = value !== undefined;
    const rows = options.map(option => value?.find(row => row.color === option.color && row.size === option.size) ?? { ...option, stock: 0 });
    useEffect(() => {
        if (enabled && JSON.stringify(value?.map(row => [row.color, row.size])) !== signature) onChange(rows);
    }, [signature, enabled]);
    if (!colors.length && !sizes.length) return null;
    const update = (index: number, field: 'stock' | 'extra_price', input: string) => onChange(rows.map((row, i) => i === index ? { ...row, [field]: input === '' ? 0 : Number(input) } : row));

    return <section className="rounded-lg border border-slate-300 p-4">
        <label className="flex items-start gap-3 text-sm">
            <input type="checkbox" checked={enabled} disabled={options.length > 400} onChange={event => onChange(event.target.checked ? rows : undefined)} className="mt-1 rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" />
            <span><span className="font-bold text-slate-900">Stock for each combination</span><span className="mt-1 block text-slate-600">Track each option separately. Combined stock is calculated below; option surcharges replace the shared size surcharge.</span></span>
        </label>
        {options.length > 400 && <p className="mt-2 text-sm text-rose-700">Reduce the option groups to 400 combinations or fewer.</p>}
        {enabled && <div className="mt-4 overflow-x-auto"><table className="w-full min-w-[360px] text-left text-sm">
            <thead><tr className="border-b border-slate-300 text-slate-600"><th className="pb-2 font-semibold">Options</th><th className="pb-2 font-semibold">Stock</th><th className="pb-2 font-semibold">Extra price (₱)</th></tr></thead>
            <tbody>{rows.map((row, index) => <tr key={JSON.stringify([row.color, row.size])} className="border-b border-slate-200 last:border-0">
                <td className="py-2 pr-3">{[row.color, row.size].filter(Boolean).join(' / ')}</td>
                <td className="p-2"><input type="number" min="0" max="99999" step="1" value={row.stock} onChange={event => update(index, 'stock', event.target.value)} aria-label={`Stock for ${[row.color, row.size].filter(Boolean).join(' / ')}`} className="w-24 rounded-lg border border-slate-300 px-2 py-2" /></td>
                <td className="py-2 pl-2"><input type="number" min="0" max="99999999.99" step="0.01" value={row.extra_price} onChange={event => update(index, 'extra_price', event.target.value)} aria-label={`Extra price for ${[row.color, row.size].filter(Boolean).join(' / ')}`} className="w-28 rounded-lg border border-slate-300 px-2 py-2" /></td>
            </tr>)}</tbody>
        </table><p className="mt-3 font-semibold text-slate-900">Combined stock: {rows.reduce((total, row) => total + Number(row.stock || 0), 0)}</p></div>}
        {error && <p role="alert" className="mt-2 text-sm text-rose-700">{error}</p>}
        <p className="mt-2 text-xs text-slate-500">Up to 20 values per group. Combined stock must be 0–99,999 units.</p>
    </section>;
}
