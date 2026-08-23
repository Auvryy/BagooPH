import React, { useState } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Category, PaginatedData, Product, Shop } from '@/types';
import { 
    Package, 
    Plus, 
    Trash2, 
    Edit3, 
    X, 
    Check, 
    Search, 
    Star, 
    Layers, 
    Image as ImageIcon,
    Tag,
    DollarSign,
    Box,
    Sparkles
} from 'lucide-react';

interface Props {
    products: PaginatedData<Product>;
    categories: Category[];
    shop: Shop;
}

export default function SellerProducts({ products, categories, shop }: Props) {
    const [isCreateOpen, setIsCreateOpen] = useState(false);
    const [editingProduct, setEditingProduct] = useState<Product | null>(null);
    const [searchQuery, setSearchQuery] = useState('');

    const formatPrice = (val: string | number | undefined | null) => {
        const num = Number(val || 0);
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(num);
    };

    const createForm = useForm({
        name: '',
        category_id: categories[0]?.id || '',
        price: '',
        compare_at_price: '',
        stock: '25',
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
        if (confirm('Are you sure you want to remove this product from your storefront?')) {
            router.delete(route('seller.products.destroy', id));
        }
    };

    const filteredProducts = products.data.filter(p => 
        !searchQuery.trim() || 
        p.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
        (p.sku && p.sku.toLowerCase().includes(searchQuery.toLowerCase()))
    );

    return (
        <DashboardLayout
            title="Product Inventory & Catalog Engine"
            subtitle={`Catalog control & inventory balances for ${shop.name}`}
            actions={
                <button
                    onClick={() => setIsCreateOpen(true)}
                    className="flex items-center gap-1.5 px-4 py-2 bg-[#E00D42] hover:bg-[#C20836] text-white text-xs font-bold font-mono rounded-xl shadow-xs transition uppercase"
                >
                    <Plus className="w-3.5 h-3.5" />
                    <span>List New Product</span>
                </button>
            }
        >
            <Head title="Catalog Inventory — BagooPH Seller" />

            <div className="space-y-6 font-sans">
                
                {/* Search and Stats Filter */}
                <div className="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/90 shadow-2xs flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div className="flex items-center gap-2 font-mono text-xs text-slate-600">
                        <Package className="w-4 h-4 text-[#E00D42]" />
                        <span>Showing <strong>{filteredProducts.length}</strong> of <strong>{products.total ?? products.data.length}</strong> catalog listings</span>
                    </div>

                    <div className="w-full sm:w-80 relative font-mono text-xs">
                        <input
                            type="text"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            placeholder="Search by title or SKU..."
                            className="w-full pl-9 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 text-xs focus:ring-1 focus:ring-[#E00D42]"
                        />
                        <Search className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" />
                    </div>
                </div>

                {/* Products Table Box */}
                <div className="bg-white rounded-2xl border border-slate-200/90 overflow-hidden shadow-2xs">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs font-mono">
                            <thead className="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold uppercase text-[10px] tracking-wider">
                                <tr>
                                    <th className="py-4 px-6">Product Details</th>
                                    <th className="py-4 px-4">Master Department</th>
                                    <th className="py-4 px-4">Listing Price</th>
                                    <th className="py-4 px-4">Stock Level</th>
                                    <th className="py-4 px-4">Status</th>
                                    <th className="py-4 px-4">Units Sold</th>
                                    <th className="py-4 px-6 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {filteredProducts.length === 0 ? (
                                    <tr>
                                        <td colSpan={7} className="py-12 text-center text-slate-400">
                                            No products match your search query.
                                        </td>
                                    </tr>
                                ) : (
                                    filteredProducts.map((product) => (
                                        <tr key={product.id} className="hover:bg-slate-50 transition">
                                            <td className="py-4 px-6">
                                                <div className="flex items-center gap-3 min-w-0">
                                                    <img
                                                        src={product.featured_image || ''}
                                                        alt={product.name}
                                                        className="w-14 h-14 rounded-xl object-cover bg-slate-100 border border-slate-200 shrink-0"
                                                    />
                                                    <div className="space-y-0.5 min-w-0">
                                                        <p className="font-bold text-slate-900 text-xs font-sans truncate max-w-xs">{product.name}</p>
                                                        <p className="text-[11px] text-slate-400 font-mono">SKU: {product.sku || 'BGO-7721-PH'}</p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="py-4 px-4 text-slate-600 font-medium">
                                                {product.category?.name || 'Curated Department'}
                                            </td>
                                            <td className="py-4 px-4 font-black text-slate-900 font-sans text-sm">
                                                {formatPrice(product.price)}
                                            </td>
                                            <td className="py-4 px-4">
                                                <span className={`font-bold px-2.5 py-1 rounded-lg text-[11px] ${
                                                    product.stock > 10 ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : (product.stock > 0 ? 'bg-amber-50 text-amber-700 border border-amber-200' : 'bg-rose-50 text-rose-700 border border-rose-200')
                                                }`}>
                                                    {product.stock} units
                                                </span>
                                            </td>
                                            <td className="py-4 px-4">
                                                <span className="capitalize text-[11px] font-bold text-slate-700">
                                                    {product.status}
                                                </span>
                                            </td>
                                            <td className="py-4 px-4 text-slate-500 font-medium">
                                                {product.sales_count ?? 0} sold
                                            </td>
                                            <td className="py-4 px-6 text-right space-x-1">
                                                <button
                                                    onClick={() => openEdit(product)}
                                                    className="p-2 text-slate-500 hover:text-emerald-700 hover:bg-emerald-50 rounded-xl transition"
                                                    title="Edit Product"
                                                >
                                                    <Edit3 className="w-4 h-4" />
                                                </button>
                                                <button
                                                    onClick={() => handleDelete(product.id)}
                                                    className="p-2 text-slate-500 hover:text-rose-700 hover:bg-rose-50 rounded-xl transition"
                                                    title="Remove Product"
                                                >
                                                    <Trash2 className="w-4 h-4" />
                                                </button>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {/* Create Product Modal */}
            {isCreateOpen && (
                <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4 backdrop-blur-xs animate-fade-in">
                    <div className="bg-white text-slate-900 rounded-3xl max-w-xl w-full p-6 sm:p-8 space-y-6 shadow-2xl max-h-[90vh] overflow-y-auto border border-slate-200 font-sans">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <div className="flex items-center gap-2">
                                <Plus className="w-5 h-5 text-[#E00D42]" />
                                <h3 className="font-bold text-base text-slate-900">Publish New Product Listing</h3>
                            </div>
                            <button onClick={() => setIsCreateOpen(false)} className="p-1 text-slate-400 hover:text-slate-700">
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <form onSubmit={handleCreate} className="space-y-4 text-xs font-mono">
                            <div>
                                <label className="block font-bold text-slate-700 uppercase mb-1">Product Title</label>
                                <input
                                    type="text"
                                    value={createForm.data.name}
                                    onChange={(e) => createForm.setData('name', e.target.value)}
                                    placeholder="e.g. Bagoo Urban Nomad Tactical Duffel 45L"
                                    required
                                    className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block font-bold text-slate-700 uppercase mb-1">Department Category</label>
                                    <select
                                        value={createForm.data.category_id}
                                        onChange={(e) => createForm.setData('category_id', e.target.value)}
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                    >
                                        {categories.map((c) => (
                                            <option key={c.id} value={c.id}>{c.name}</option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="block font-bold text-slate-700 uppercase mb-1">SKU / Model Code</label>
                                    <input
                                        type="text"
                                        value={createForm.data.sku}
                                        onChange={(e) => createForm.setData('sku', e.target.value)}
                                        placeholder="e.g. BGO-DUFFEL-45L"
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-3 gap-4">
                                <div>
                                    <label className="block font-bold text-slate-700 uppercase mb-1">Price (₱)</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        value={createForm.data.price}
                                        onChange={(e) => createForm.setData('price', e.target.value)}
                                        required
                                        placeholder="1299.00"
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                    />
                                </div>
                                <div>
                                    <label className="block font-bold text-slate-700 uppercase mb-1">Slashed Price (₱)</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        value={createForm.data.compare_at_price}
                                        onChange={(e) => createForm.setData('compare_at_price', e.target.value)}
                                        placeholder="1799.00"
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                    />
                                </div>
                                <div>
                                    <label className="block font-bold text-slate-700 uppercase mb-1">Warehouse Stock</label>
                                    <input
                                        type="number"
                                        value={createForm.data.stock}
                                        onChange={(e) => createForm.setData('stock', e.target.value)}
                                        required
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                    />
                                </div>
                            </div>

                            <div>
                                <label className="block font-bold text-slate-700 uppercase mb-1">Featured Image URL</label>
                                <input
                                    type="url"
                                    value={createForm.data.featured_image}
                                    onChange={(e) => createForm.setData('featured_image', e.target.value)}
                                    placeholder="https://images.unsplash.com/..."
                                    className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                />
                            </div>

                            <div>
                                <label className="block font-bold text-slate-700 uppercase mb-1">Product Description & Specs</label>
                                <textarea
                                    value={createForm.data.description}
                                    onChange={(e) => createForm.setData('description', e.target.value)}
                                    rows={3}
                                    required
                                    placeholder="Provide detailed material specifications, size measurements, and warranty details..."
                                    className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                />
                            </div>

                            <div className="flex justify-end gap-3 pt-4 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={() => setIsCreateOpen(false)}
                                    className="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl uppercase transition"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={createForm.processing}
                                    className="px-6 py-2.5 bg-[#E00D42] hover:bg-[#C20836] text-white font-bold rounded-xl uppercase shadow-sm transition disabled:opacity-50"
                                >
                                    Publish Listing
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Edit Product Modal */}
            {editingProduct && (
                <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4 backdrop-blur-xs animate-fade-in">
                    <div className="bg-white text-slate-900 rounded-3xl max-w-xl w-full p-6 sm:p-8 space-y-6 shadow-2xl max-h-[90vh] overflow-y-auto border border-slate-200 font-sans">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <div className="flex items-center gap-2">
                                <Edit3 className="w-5 h-5 text-[#E00D42]" />
                                <h3 className="font-bold text-base text-slate-900 truncate max-w-sm">Edit Product: {editingProduct.name}</h3>
                            </div>
                            <button onClick={() => setEditingProduct(null)} className="p-1 text-slate-400 hover:text-slate-700">
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <form onSubmit={handleUpdate} className="space-y-4 text-xs font-mono">
                            <div>
                                <label className="block font-bold text-slate-700 uppercase mb-1">Product Title</label>
                                <input
                                    type="text"
                                    value={editForm.data.name}
                                    onChange={(e) => editForm.setData('name', e.target.value)}
                                    required
                                    className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block font-bold text-slate-700 uppercase mb-1">Status</label>
                                    <select
                                        value={editForm.data.status}
                                        onChange={(e) => editForm.setData('status', e.target.value as any)}
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                    >
                                        <option value="active">Active Listing</option>
                                        <option value="draft">Draft Mode</option>
                                        <option value="archived">Archived</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="block font-bold text-slate-700 uppercase mb-1">Warehouse Stock</label>
                                    <input
                                        type="number"
                                        value={editForm.data.stock}
                                        onChange={(e) => editForm.setData('stock', e.target.value)}
                                        required
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block font-bold text-slate-700 uppercase mb-1">Price (₱)</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        value={editForm.data.price}
                                        onChange={(e) => editForm.setData('price', e.target.value)}
                                        required
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                    />
                                </div>
                                <div>
                                    <label className="block font-bold text-slate-700 uppercase mb-1">Slashed Price (₱)</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        value={editForm.data.compare_at_price}
                                        onChange={(e) => editForm.setData('compare_at_price', e.target.value)}
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                    />
                                </div>
                            </div>

                            <div>
                                <label className="block font-bold text-slate-700 uppercase mb-1">Description</label>
                                <textarea
                                    value={editForm.data.description}
                                    onChange={(e) => editForm.setData('description', e.target.value)}
                                    rows={3}
                                    required
                                    className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                />
                            </div>

                            <div className="flex justify-end gap-3 pt-4 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={() => setEditingProduct(null)}
                                    className="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl uppercase transition"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={editForm.processing}
                                    className="px-6 py-2.5 bg-[#E00D42] hover:bg-[#C20836] text-white font-bold rounded-xl uppercase shadow-sm transition disabled:opacity-50"
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
