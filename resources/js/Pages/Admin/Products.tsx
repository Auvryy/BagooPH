import React, { useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Badge, inputClasses } from '@/Components/ui';
import { PaginatedData, Product } from '@/types';
import AdminDirectoryPagination from '@/Components/AdminDirectoryPagination';

type Filters = { search?: string; shop?: string | number; category?: string | number; status?: string; compliance?: string; stock?: string };
interface Props {
    products: PaginatedData<Product>;
    moderationBaseUrl: string;
    filters?: Filters;
    shops?: { id: number; name: string }[];
    categories?: { id: number; name: string }[];
}

const money = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

export default function AdminProducts({ products, moderationBaseUrl, filters = {}, shops = [], categories = [] }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const first = useRef(true);
    const apply = (next: Filters) => router.get(moderationBaseUrl, Object.fromEntries(Object.entries({ ...filters, ...next }).filter(([, v]) => v !== '' && v !== undefined && v !== null)), { preserveState: true, preserveScroll: true, replace: true });
    // Search as you type, after a short pause.
    useEffect(() => {
        if (first.current) { first.current = false; return; }
        const timer = setTimeout(() => apply({ search }), 350);
        return () => clearTimeout(timer);
    }, [search]);
    const active = Boolean(filters.search || filters.shop || filters.category || filters.status || filters.compliance || filters.stock);
    const select = (key: keyof Filters, label: string, options: [string, string][]) => <label className="block min-w-0">
        <span className="sr-only">{label}</span>
        <select value={String(filters[key] ?? '')} onChange={event => apply({ [key]: event.target.value })} className={inputClasses}>
            <option value="">{label}</option>
            {options.map(([value, text]) => <option key={value} value={value}>{text}</option>)}
        </select>
    </label>;

    return <DashboardLayout title="Product compliance" subtitle="Review marketplace listings and preserve purchased-item history">
        <Head title="Product compliance — Bagoo Admin" />
        <div className="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.6fr)_repeat(5,minmax(0,1fr))]">
            <label className="relative block">
                <span className="sr-only">Search products</span>
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-500" aria-hidden="true" />
                <input type="search" value={search} onChange={event => setSearch(event.target.value)} placeholder="Search product name" className={`${inputClasses} pl-9`} />
            </label>
            {select('shop', 'All shops', shops.map(item => [String(item.id), item.name]))}
            {select('category', 'All categories', categories.map(item => [String(item.id), item.name]))}
            {select('status', 'Any seller status', [['active', 'Active'], ['draft', 'Draft']])}
            {select('compliance', 'Any compliance', [['allowed', 'Allowed'], ['restricted', 'Restricted']])}
            {select('stock', 'Any stock', [['low', 'Low (1–5)'], ['out', 'Out of stock']])}
        </div>
        <div className="mb-3 flex items-center justify-between text-sm text-ink-600">
            <span>{products.total ?? products.data.length} product{(products.total ?? products.data.length) === 1 ? '' : 's'}{active ? ' match these filters' : ''}</span>
            {active && <button type="button" onClick={() => { setSearch(''); router.get(moderationBaseUrl, {}, { preserveScroll: true, replace: true }); }} className="font-semibold text-ink-700 underline">Clear filters</button>}
        </div>
        <div className="overflow-hidden rounded-xl border border-slate-300 bg-white shadow-xs">
            <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-300 bg-slate-50 text-xs text-slate-600"><tr>
                        <th className="px-5 py-3">Product</th><th className="px-4 py-3">Shop</th><th className="px-4 py-3">Listing price</th>
                        <th className="px-4 py-3">Seller status</th><th className="px-4 py-3">Compliance</th><th className="px-5 py-3 text-right">Review</th>
                    </tr></thead>
                    <tbody className="divide-y divide-slate-200">
                        {products.data.length === 0 ? <tr><td colSpan={6} className="px-5 py-12 text-center text-slate-500">{active ? 'No products match these filters.' : 'No product listings to review.'}</td></tr> : products.data.map(product => <tr key={product.id}>
                            <td className="px-5 py-4"><p className="font-semibold text-slate-900">{product.name}</p><p className="mt-1 text-xs text-slate-500">{product.stock} units in stock · {product.category?.name ?? 'Category unavailable'}</p></td>
                            <td className="px-4 py-4">{product.shop?.name ?? 'Shop unavailable'}</td>
                            <td className="px-4 py-4 font-semibold">{money.format(Number(product.price))}</td>
                            <td className="px-4 py-4 capitalize">{product.status}</td>
                            <td className="px-4 py-4"><Badge tone={product.compliance_restricted ? 'danger' : 'success'}>{product.compliance_restricted ? 'Restricted' : 'Allowed'}</Badge></td>
                            <td className="px-5 py-4 text-right"><Link href={moderationBaseUrl + '/' + product.id + '/moderation'} className="inline-block whitespace-nowrap rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold hover:border-[#E00D42] hover:text-[#E00D42]">Review</Link></td>
                        </tr>)}
                    </tbody>
                </table>
            </div>
            <AdminDirectoryPagination page={products} kind="products" />
        </div>
    </DashboardLayout>;
}
