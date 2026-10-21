import React from 'react';
import type { Product } from '@/types';

const measure = (value?: string | number | null) => value != null && Number.isFinite(Number(value)) && Number(value) > 0
    ? new Intl.NumberFormat('en-PH', { maximumFractionDigits: 2 }).format(Number(value)) : null;

export function productFacts(product: Product) {
    const dimensions = [product.length_cm, product.width_cm, product.height_cm].map(measure);
    return {
        condition: product.condition === 'new' ? 'New' : product.condition === 'used' ? 'Used' : null,
        weight: measure(product.weight_kg) ? `${measure(product.weight_kg)} kg` : null,
        dimensions: dimensions.every(Boolean) ? `${dimensions.join(' × ')} cm` : null,
    };
}

export function ProductFactsSummary({ product }: { product: Product }) {
    const facts = productFacts(product);
    const values = [facts.condition && `Condition: ${facts.condition}`, facts.weight && `Weight: ${facts.weight}`, facts.dimensions && `L × W × H: ${facts.dimensions}`].filter(Boolean);
    if (!values.length) return null;
    return <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-600" aria-label="Product details summary">{values.map(value => <span key={value as string}>{value}</span>)}<a href="#product-details" className="font-semibold text-[#E00D42] underline underline-offset-4">View details</a></div>;
}

export default function ProductSpecifications({ product }: { product: Product }) {
    const facts = productFacts(product);
    const rows = [
        ['Department', product.category?.name || 'Not provided'],
        ['Store categories', product.store_categories?.map(category => category.name).join(', ') || 'Not listed'],
        ['Brand', product.brand || 'Not provided'], ['Condition', facts.condition || 'Not provided'],
        ['Declared weight', facts.weight || 'Not provided'], ['Dimensions (L × W × H)', facts.dimensions || 'Not provided'],
        ['Available stock', product.stock > 0 ? `${product.stock} units` : 'Out of stock'],
        ['Seller location', product.shop?.city || 'Not provided'],
    ];
    return <section id="product-details" aria-label="Product specifications" className="scroll-mt-24">
        <h3 className="text-lg font-bold text-slate-900">Product specifications</h3>
        <dl className="mt-5 grid grid-cols-1 gap-x-8 sm:grid-cols-2">{rows.map(([label, value]) => <div key={label} className="min-w-0 border-b border-slate-200 py-3 last:border-0">
            <dt className="text-xs font-semibold text-slate-500">{label}</dt><dd className="mt-1 break-words text-sm font-semibold text-slate-800">{value}</dd>
        </div>)}</dl>
        <p className="mt-3 text-xs leading-relaxed text-slate-500">Weight and dimensions are declared by the seller. Delivery fees are confirmed at checkout.</p>
    </section>;
}
