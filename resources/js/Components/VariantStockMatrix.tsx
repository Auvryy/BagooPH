import React, { useEffect, useState } from 'react';

export interface StockCombination { color: string | null; size: string | null; stock: number | string; extra_price?: number | string; price?: number | string; sku?: string }
interface Props {
    colors: { name: string }[];
    sizes: { name: string; extra_price: number }[];
    value?: StockCombination[];
    onChange: (rows: StockCombination[] | undefined) => void;
    pricingMode?: 'surcharge' | 'independent';
    onPricingModeChange?: (mode: 'surcharge' | 'independent') => void;
    basePrice?: string | number;
    listingSku?: string;
    errors?: Record<string, string>;
    error?: string;
}

export default function VariantStockMatrix({ colors, sizes, value, onChange, error, errors = {}, pricingMode = 'surcharge', onPricingModeChange, basePrice = '', listingSku = '' }: Props) {
    const [touched, setTouched] = useState<Record<string, boolean>>({});
    const independent = pricingMode === 'independent';
    const options = (colors.length ? colors : [{ name: null }]).flatMap(color =>
        (sizes.length ? sizes : [{ name: null, extra_price: 0 }]).map(size => ({ color: color.name, size: size.name, extra_price: size.extra_price })));
    const signature = JSON.stringify(options.map(row => [row.color, row.size]));
    const enabled = value !== undefined;
    const rows = options.map(option => value?.find(row => row.color === option.color && row.size === option.size) ?? {
        ...option, stock: 0, ...(independent ? { price: Number(basePrice) > 0 ? (Number(basePrice) + option.extra_price).toFixed(2) : '', sku: '' } : {}),
    });
    useEffect(() => {
        if (enabled && JSON.stringify(value?.map(row => [row.color, row.size])) !== signature) onChange(rows);
    }, [signature, enabled]);
    if (!colors.length && !sizes.length) return null;
    const update = (index: number, field: 'stock' | 'extra_price' | 'price' | 'sku', input: string) => {
        setTouched(previous => ({ ...previous, [`${index}.${field}`]: true }));
        onChange(rows.map((row, i) => i === index ? { ...row, [field]: field === 'sku' ? input.toUpperCase() : input } : row));
    };
    const fieldError = (index: number, field: 'stock' | 'extra_price' | 'price' | 'sku') => {
        const server = errors[`combinations.${index}.${field}`];
        if (server) return server;
        if (!touched[`${index}.${field}`]) return '';
        const input = String(rows[index][field] ?? '');
        if (field === 'sku') {
            if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,49}$/.test(input)) return 'Use 1–50 letters, numbers, periods, hyphens or underscores.';
            if (input.toUpperCase() === listingSku.trim().toUpperCase() || rows.some((row, i) => i !== index && row.sku?.toUpperCase() === input.toUpperCase())) return 'Use a different SKU for each listing and option.';
        } else if (field === 'stock') {
            if (!/^\d+$/.test(input) || Number(input) > 99999) return 'Enter a whole number from 0 to 99,999.';
        } else if (!/^\d+(\.\d{1,2})?$/.test(input) || Number(input) < (field === 'price' ? 1 : 0) || Number(input) > (field === 'price' ? 1000000 : 99999999.99)) return field === 'price' ? 'Enter ₱1.00–₱1,000,000.00 with up to two decimals.' : 'Enter a valid extra price with up to two decimals.';
        return '';
    };
    const inputCell = (row: StockCombination, index: number, field: 'stock' | 'extra_price' | 'price' | 'sku', label: string) => {
        const message = fieldError(index, field);
        const id = `variant-${index}-${field}`;
        return <td className="p-2 align-top"><input type={field === 'sku' ? 'text' : 'number'} min={field === 'price' ? '1' : '0'} max={field === 'stock' ? '99999' : field === 'price' ? '1000000' : '99999999.99'} step={field === 'stock' ? '1' : '0.01'} maxLength={field === 'sku' ? 50 : undefined} value={row[field] ?? ''} onChange={event => update(index, field, event.target.value)} aria-label={`${label} for ${[row.color, row.size].filter(Boolean).join(' / ')}`} aria-invalid={!!message} aria-describedby={message ? id : undefined} className={`w-full min-w-24 rounded-lg border px-2 py-2 text-sm focus:border-[#E00D42] focus:ring-[#E00D42] ${message ? 'border-rose-500 bg-rose-50' : 'border-slate-300 bg-white'}`} />{message && <p id={id} role="alert" className="mt-1 max-w-56 text-xs leading-relaxed text-rose-700">{message}</p>}</td>;
    };

    return <section className="rounded-lg border border-slate-300 p-4">
        <label className="flex items-start gap-3 text-sm">
            <input type="checkbox" checked={enabled} disabled={options.length > 400} onChange={event => onChange(event.target.checked ? rows : undefined)} className="mt-1 rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" />
            <span><span className="font-bold text-slate-900">Stock and prices for each combination</span><span className="mt-1 block text-slate-600">Set each option’s stock, full selling price and unique SKU. Combined stock is calculated from these rows.</span></span>
        </label>
        {options.length > 400 && <p className="mt-2 text-sm text-rose-700">Reduce the option groups to 400 combinations or fewer.</p>}
        {enabled && <>
            {onPricingModeChange && <label className="mt-4 block text-sm font-semibold">Option pricing<select value={pricingMode} onChange={event => {
                const mode = event.target.value as 'independent' | 'surcharge';
                onPricingModeChange(mode);
                onChange(rows.map(row => ({ ...row, ...(mode === 'independent' ? { price: row.price ?? (Number(basePrice) > 0 ? (Number(basePrice) + Number(row.extra_price || 0)).toFixed(2) : ''), sku: row.sku ?? '' } : { extra_price: row.extra_price ?? Math.max(0, Number(row.price || 0) - Number(basePrice || 0)) }) })));
            }} className="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="independent">Full selling prices and SKUs</option><option value="surcharge">Base price plus extra price</option></select></label>}
            {!independent && <p className="mt-2 text-xs text-slate-600">Extra prices are added to the listing price. Choose full selling prices to assign independent prices and SKUs.</p>}
            <div className="mt-4 overflow-x-auto"><table className="w-full min-w-[400px] text-left text-sm">
                <thead><tr className="border-b border-slate-300 text-slate-600"><th className="pb-2 font-semibold">Options</th><th className="pb-2 font-semibold">Stock</th><th className="pb-2 font-semibold">{independent ? 'Selling price (₱)' : 'Extra price (₱)'}</th>{independent && <th className="pb-2 font-semibold">SKU</th>}</tr></thead>
                <tbody>{rows.map((row, index) => <tr key={JSON.stringify([row.color, row.size])} className="border-b border-slate-200 last:border-0">
                    <td className="py-3 pr-3 align-top font-semibold">{[row.color, row.size].filter(Boolean).join(' / ')}{(errors[`combinations.${index}.color`] || errors[`combinations.${index}.size`]) && <p role="alert" className="mt-1 text-xs text-rose-700">{errors[`combinations.${index}.color`] || errors[`combinations.${index}.size`]}</p>}</td>
                    {inputCell(row, index, 'stock', 'Stock')}{inputCell(row, index, independent ? 'price' : 'extra_price', independent ? 'Selling price' : 'Extra price')}{independent && inputCell(row, index, 'sku', 'SKU')}
                </tr>)}</tbody>
            </table><p className="mt-3 font-semibold text-slate-900">Combined stock: {rows.reduce((total, row) => total + Number(row.stock || 0), 0)}</p></div>
        </>}
        {error && <p role="alert" className="mt-2 text-sm text-rose-700">{error}</p>}
        <p className="mt-2 text-xs text-slate-500">Two option groups maximum, with up to 20 values each. Combined stock must be 0–99,999 units.</p>
    </section>;
}
