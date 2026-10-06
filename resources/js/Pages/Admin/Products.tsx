import React from 'react';
import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { PaginatedData, Product } from '@/types';

interface Props {
    products: PaginatedData<Product>;
    moderationBaseUrl: string;
}

const money = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

export default function AdminProducts({ products, moderationBaseUrl }: Props) {
    return <DashboardLayout title="Product compliance" subtitle="Review marketplace listings and preserve purchased-item history">
        <Head title="Product compliance — Bagoo Admin" />
        <p className="mb-5 text-sm text-slate-600">Seller listing status and platform compliance are separate. Review a listing to record a reasoned removal or reinstatement.</p>
        <div className="overflow-hidden rounded-xl border border-slate-300 bg-white shadow-xs">
            <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-300 bg-slate-50 text-xs text-slate-600"><tr>
                        <th className="px-5 py-3">Product</th><th className="px-4 py-3">Shop</th><th className="px-4 py-3">Listing price</th>
                        <th className="px-4 py-3">Seller status</th><th className="px-4 py-3">Compliance</th><th className="px-5 py-3 text-right">Review</th>
                    </tr></thead>
                    <tbody className="divide-y divide-slate-200">
                        {products.data.length === 0 ? <tr><td colSpan={6} className="px-5 py-12 text-center text-slate-500">No product listings to review.</td></tr> : products.data.map(product => <tr key={product.id}>
                            <td className="px-5 py-4"><p className="font-semibold text-slate-900">{product.name}</p><p className="mt-1 text-xs text-slate-500">{product.stock} units in stock · {product.category?.name ?? 'Category unavailable'}</p></td>
                            <td className="px-4 py-4">{product.shop?.name ?? 'Shop unavailable'}</td>
                            <td className="px-4 py-4 font-semibold">{money.format(Number(product.price))}</td>
                            <td className="px-4 py-4 capitalize">{product.status}</td>
                            <td className="px-4 py-4"><span className={'rounded-full px-2.5 py-1 text-xs font-semibold ' + (product.compliance_restricted ? 'bg-rose-100 text-rose-800' : 'bg-slate-100 text-slate-700')}>{product.compliance_restricted ? 'Restricted' : 'No platform restriction'}</span></td>
                            <td className="px-5 py-4 text-right"><Link href={moderationBaseUrl + '/' + product.id + '/moderation'} className="inline-block rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold hover:border-[#E00D42] hover:text-[#E00D42]">Review compliance</Link></td>
                        </tr>)}
                    </tbody>
                </table>
            </div>
            <div className="flex items-center justify-between border-t border-slate-300 px-5 py-4 text-sm">
                <span className="text-slate-500">Page {products.current_page} of {products.last_page}</span>
                <div className="flex gap-3">{products.prev_page_url && <Link href={products.prev_page_url}>Previous</Link>}{products.next_page_url && <Link href={products.next_page_url}>Next</Link>}</div>
            </div>
        </div>
    </DashboardLayout>;
}
