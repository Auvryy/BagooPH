import React, { useState } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Category, PaginatedData, Product, Shop } from '@/types';
import { Package, Plus, Trash2, Edit3, X, Check, Search, Star } from 'lucide-react';

interface Props {
    products: PaginatedData<Product>;
    categories: Category[];
    shop: Shop;
}

export default function SellerProducts({ products, categories, shop }: Props) {
    const [isCreateOpen, setIsCreateOpen] = useState(false);
    const [editingProduct, setEditingProduct] = useState<Product | null>(null);

    const createForm = useForm({
        name: '',
        category_id: categories[0]?.id || '',
        price: '',
        compare_at_price: '',
        stock: '10',
        sku: '',
        featured_image: '',
        description: '',
    });

    const editForm = useForm({
        name: '',
        category_id: '',
        price: '',
        compare_at_price: '',
        stock: '0',
        sku: '',
        featured_image: '',
        description: '',
        status: 'active',
    });

    const openEdit = (product: Product) => {
        setEditingProduct(product);
        editForm.setData({
            name: product.name,
            category_id: String(product.category_id || ''),
            price: String(product.price),
            compare_at_price: String(product.compare_at_price || ''),
            stock: String(product.stock),
            sku: product.sku || '',
            featured_image: product.featured_image || '',
            description: product.description,
            status: product.status,
        });
    };

    const handleCreate = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.post(route('seller.products.store'), {
            onSuccess: () => {
                setIsCreateOpen(false);
                createForm.reset();
            },
        });
    };

    const handleUpdate = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editingProduct) return;
        editForm.put(route('seller.products.update', editingProduct.id), {
            onSuccess: () => {
                setEditingProduct(null);
            },
        });
    };

    const handleDelete = (id: number) => {
        if (confirm('Are you sure you want to remove this product?')) {
            router.delete(route('seller.products.destroy', id));
        }
    };

    return (
        <DashboardLayout
            title="Product Inventory Management"
            subtitle={`Catalog control for ${shop.name}`}
            actions={
                <button
                    onClick={() => setIsCreateOpen(true)}
                    className="flex items-center gap-1.5 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl shadow-xs transition"
                >
                    <Plus className="w-3.5 h-3.5" />
                    <span>Add New Listing</span>
                </button>
            }
        >
            <Head title="My Products — Seller Center" />

            <div className="space-y-6">
                {/* Table Box */}
                <div className="bg-white rounded-3xl border border-slate-200 overflow-hidden shadow-xs">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead className="bg-slate-50 border-b border-slate-200 text-slate-400 font-bold uppercase text-[10px] tracking-wider">
                                <tr>
                                    <th className="py-3.5 px-6">Product</th>
                                    <th className="py-3.5 px-4">Category</th>
                                    <th className="py-3.5 px-4">Price</th>
                                    <th className="py-3.5 px-4">Stock</th>
                                    <th className="py-3.5 px-4">Status</th>
                                    <th className="py-3.5 px-4">Sales</th>
                                    <th className="py-3.5 px-6 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {products.data.map((product) => (
                                    <tr key={product.id} className="hover:bg-slate-50/80 transition">
                                        <td className="py-4 px-6">
                                            <div className="flex items-center gap-3">
                                                <img
                                                    src={product.featured_image || ''}
                                                    alt={product.name}
                                                    className="w-12 h-12 rounded-xl object-cover bg-slate-100"
                                                />
                                                <div>
                                                    <p className="font-bold text-slate-900 text-xs">{product.name}</p>
                                                    <p className="text-[11px] text-slate-400 font-mono">SKU: {product.sku || 'N/A'}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="py-4 px-4 text-slate-600 font-medium">
                                            {product.category?.name || 'Uncategorized'}
                                        </td>
                                        <td className="py-4 px-4 font-black text-slate-900">
                                            ${Number(product.price).toFixed(2)}
                                        </td>
                                        <td className="py-4 px-4">
                                            <span className={`font-bold px-2 py-0.5 rounded-md text-[11px] ${
                                                product.stock > 10 ? 'bg-emerald-50 text-emerald-700' : (product.stock > 0 ? 'bg-amber-50 text-amber-700' : 'bg-rose-50 text-rose-700')
                                            }`}>
                                                {product.stock} in stock
                                            </span>
                                        </td>
                                        <td className="py-4 px-4">
                                            <span className="capitalize text-[11px] font-bold text-slate-700">
                                                {product.status}
                                            </span>
                                        </td>
                                        <td className="py-4 px-4 text-slate-500 font-medium">
                                            {product.sales_count} sold
                                        </td>
                                        <td className="py-4 px-6 text-right space-x-2">
                                            <button
                                                onClick={() => openEdit(product)}
                                                className="p-1.5 text-slate-600 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition"
                                                title="Edit"
                                            >
                                                <Edit3 className="w-4 h-4" />
                                            </button>
                                            <button
                                                onClick={() => handleDelete(product.id)}
                                                className="p-1.5 text-slate-600 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition"
                                                title="Delete"
                                            >
                                                <Trash2 className="w-4 h-4" />
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {/* Create Product Modal */}
            {isCreateOpen && (
                <div className="fixed inset-0 bg-slate-900/60 z-50 flex items-center justify-center p-4">
                    <div className="bg-white rounded-3xl max-w-xl w-full p-6 sm:p-8 space-y-6 shadow-2xl max-h-[90vh] overflow-y-auto">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <h3 className="font-bold text-base text-slate-900">List New Product</h3>
                            <button onClick={() => setIsCreateOpen(false)} className="p-1 text-slate-400 hover:text-black">
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <form onSubmit={handleCreate} className="space-y-4 text-xs">
                            <div>
                                <label className="block font-bold text-slate-700 mb-1">Product Title</label>
                                <input
                                    type="text"
                                    value={createForm.data.name}
                                    onChange={(e) => createForm.setData('name', e.target.value)}
                                    placeholder="e.g. Bagoo Urban Nomad Duffel 40L"
                                    required
                                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl"
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block font-bold text-slate-700 mb-1">Category</label>
                                    <select
                                        value={createForm.data.category_id}
                                        onChange={(e) => createForm.setData('category_id', e.target.value)}
                                        className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl"
                                    >
                                        {categories.map((c) => (
                                            <option key={c.id} value={c.id}>{c.name}</option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="block font-bold text-slate-700 mb-1">SKU</label>
                                    <input
                                        type="text"
                                        value={createForm.data.sku}
                                        onChange={(e) => createForm.setData('sku', e.target.value)}
                                        placeholder="e.g. BAG-DUFFEL-40L"
                                        className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-3 gap-4">
                                <div>
                                    <label className="block font-bold text-slate-700 mb-1">Price ($)</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        value={createForm.data.price}
                                        onChange={(e) => createForm.setData('price', e.target.value)}
                                        required
                                        className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl"
                                    />
                                </div>
                                <div>
                                    <label className="block font-bold text-slate-700 mb-1">Compare Price ($)</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        value={createForm.data.compare_at_price}
                                        onChange={(e) => createForm.setData('compare_at_price', e.target.value)}
                                        className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl"
                                    />
                                </div>
                                <div>
                                    <label className="block font-bold text-slate-700 mb-1">Stock Qty</label>
                                    <input
                                        type="number"
                                        value={createForm.data.stock}
                                        onChange={(e) => createForm.setData('stock', e.target.value)}
                                        required
                                        className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl"
                                    />
                                </div>
                            </div>

                            <div>
                                <label className="block font-bold text-slate-700 mb-1">Featured Image URL</label>
                                <input
                                    type="url"
                                    value={createForm.data.featured_image}
                                    onChange={(e) => createForm.setData('featured_image', e.target.value)}
                                    placeholder="https://images.unsplash.com/..."
                                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl"
                                />
                            </div>

                            <div>
                                <label className="block font-bold text-slate-700 mb-1">Product Description</label>
                                <textarea
                                    value={createForm.data.description}
                                    onChange={(e) => createForm.setData('description', e.target.value)}
                                    rows={3}
                                    required
                                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl"
                                />
                            </div>

                            <div className="flex justify-end gap-3 pt-4 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={() => setIsCreateOpen(false)}
                                    className="px-4 py-2 bg-slate-100 text-slate-700 font-bold rounded-xl"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={createForm.processing}
                                    className="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl"
                                >
                                    Save Product
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Edit Product Modal */}
            {editingProduct && (
                <div className="fixed inset-0 bg-slate-900/60 z-50 flex items-center justify-center p-4">
                    <div className="bg-white rounded-3xl max-w-xl w-full p-6 sm:p-8 space-y-6 shadow-2xl max-h-[90vh] overflow-y-auto">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <h3 className="font-bold text-base text-slate-900">Edit Product: {editingProduct.name}</h3>
                            <button onClick={() => setEditingProduct(null)} className="p-1 text-slate-400 hover:text-black">
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <form onSubmit={handleUpdate} className="space-y-4 text-xs">
                            <div>
                                <label className="block font-bold text-slate-700 mb-1">Product Title</label>
                                <input
                                    type="text"
                                    value={editForm.data.name}
                                    onChange={(e) => editForm.setData('name', e.target.value)}
                                    required
                                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl"
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block font-bold text-slate-700 mb-1">Status</label>
                                    <select
                                        value={editForm.data.status}
                                        onChange={(e) => editForm.setData('status', e.target.value as any)}
                                        className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl"
                                    >
                                        <option value="active">Active</option>
                                        <option value="draft">Draft</option>
                                        <option value="archived">Archived</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="block font-bold text-slate-700 mb-1">Stock Qty</label>
                                    <input
                                        type="number"
                                        value={editForm.data.stock}
                                        onChange={(e) => editForm.setData('stock', e.target.value)}
                                        required
                                        className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block font-bold text-slate-700 mb-1">Price ($)</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        value={editForm.data.price}
                                        onChange={(e) => editForm.setData('price', e.target.value)}
                                        required
                                        className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl"
                                    />
                                </div>
                                <div>
                                    <label className="block font-bold text-slate-700 mb-1">Compare Price ($)</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        value={editForm.data.compare_at_price}
                                        onChange={(e) => editForm.setData('compare_at_price', e.target.value)}
                                        className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl"
                                    />
                                </div>
                            </div>

                            <div>
                                <label className="block font-bold text-slate-700 mb-1">Description</label>
                                <textarea
                                    value={editForm.data.description}
                                    onChange={(e) => editForm.setData('description', e.target.value)}
                                    rows={3}
                                    required
                                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl"
                                />
                            </div>

                            <div className="flex justify-end gap-3 pt-4 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={() => setEditingProduct(null)}
                                    className="px-4 py-2 bg-slate-100 text-slate-700 font-bold rounded-xl"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={editForm.processing}
                                    className="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl"
                                >
                                    Update Listing
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </DashboardLayout>
    );
}
