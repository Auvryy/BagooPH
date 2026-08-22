import React from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { PaginatedData, Product } from '@/types';
import { Package, ToggleLeft, ToggleRight } from 'lucide-react';

interface Props {
    products: PaginatedData<Product>;
}

export default function AdminProducts({ products }: Props) {
    const toggleStatus = (id: number) => {
        router.patch(route('admin.products.toggle', id));
    };

    return (
        <DashboardLayout
            title="Global Product Moderation"
            subtitle="Platform-wide catalog monitoring"
        >
            <Head title="Products Moderation — Bagoo Admin" />

            <div className="bg-white rounded-3xl border border-slate-200 overflow-hidden shadow-xs">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-xs">
                        <thead className="bg-slate-50 border-b border-slate-200 text-slate-400 font-bold uppercase text-[10px] tracking-wider">
                            <tr>
                                <th className="py-3.5 px-6">Product</th>
                                <th className="py-3.5 px-4">Merchant Store</th>
                                <th className="py-3.5 px-4">Price</th>
                                <th className="py-3.5 px-4">Status</th>
                                <th className="py-3.5 px-6 text-right">Moderation Toggle</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {products.data.map((product) => (
                                <tr key={product.id} className="hover:bg-slate-50 transition">
                                    <td className="py-4 px-6">
                                        <div className="flex items-center gap-3">
                                            <img src={product.featured_image || ''} alt="" className="w-10 h-10 rounded-xl object-cover bg-slate-100" />
                                            <div>
                                                <p className="font-bold text-slate-900">{product.name}</p>
                                                <p className="text-[11px] text-slate-400">Stock: {product.stock}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td className="py-4 px-4 font-medium text-slate-700">
                                        {product.shop?.name || 'Unknown Store'}
                                    </td>
                                    <td className="py-4 px-4 font-black text-slate-900">
                                        ${Number(product.price).toFixed(2)}
                                    </td>
                                    <td className="py-4 px-4">
                                        <span className={`px-2.5 py-1 rounded-full text-[10px] font-bold capitalize ${
                                            product.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'
                                        }`}>
                                            {product.status}
                                        </span>
                                    </td>
                                    <td className="py-4 px-6 text-right">
                                        <button
                                            onClick={() => toggleStatus(product.id)}
                                            className={`px-3 py-1.5 rounded-xl font-bold text-xs transition ${
                                                product.status === 'active' 
                                                    ? 'bg-rose-50 text-rose-700 hover:bg-rose-100' 
                                                    : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100'
                                            }`}
                                        >
                                            {product.status === 'active' ? 'Deactivate Listing' : 'Activate Listing'}
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </DashboardLayout>
    );
}
