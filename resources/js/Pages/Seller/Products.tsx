import React, { useState, useRef } from 'react';
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
    Sparkles,
    Upload,
    Link,
    AlertCircle
} from 'lucide-react';

const MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024; // 5MB
const ALLOWED_MIME_TYPES = [
    'image/jpeg',
    'image/png',
    'image/jpg',
    'image/webp',
    'image/gif'
];

interface Props {
    products: PaginatedData<Product>;
    categories: Category[];
    shop: Shop;
}

export default function SellerProducts({ products, categories, shop }: Props) {
    const [isCreateOpen, setIsCreateOpen] = useState(false);
    const [editingProduct, setEditingProduct] = useState<Product | null>(null);
    const [searchQuery, setSearchQuery] = useState('');

    const [createImageMode, setCreateImageMode] = useState<'url' | 'file'>('url');
    const [createImagePreview, setCreateImagePreview] = useState<string | null>(null);
    const [createFileError, setCreateFileError] = useState<string | null>(null);
    const createFileInputRef = useRef<HTMLInputElement>(null);

    const [editImageMode, setEditImageMode] = useState<'url' | 'file'>('url');
    const [editImagePreview, setEditImagePreview] = useState<string | null>(null);
    const [editFileError, setEditFileError] = useState<string | null>(null);
    const editFileInputRef = useRef<HTMLInputElement>(null);

    const formatPrice = (val: string | number | undefined | null) => {
        const num = Number(val || 0);
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(num);
    };

    const createForm = useForm<{
        name: string;
        category_id: string | number;
        price: string;
        compare_at_price: string;
        stock: string;
        sku: string;
        featured_image: string;
        image_file: File | null;
        description: string;
    }>({
        name: '',
        category_id: categories[0]?.id || '',
        price: '',
        compare_at_price: '',
        stock: '25',
        sku: '',
        featured_image: '',
        image_file: null,
        description: '',
    });

    const editForm = useForm<{
        name: string;
        category_id: string | number;
        price: string;
        compare_at_price: string;
        stock: string;
        sku: string;
        featured_image: string;
        image_file: File | null;
        description: string;
        status: 'active' | 'draft' | 'archived';
        _method: string;
    }>({
        name: '',
        category_id: '',
        price: '',
        compare_at_price: '',
        stock: '0',
        sku: '',
        featured_image: '',
        image_file: null,
        description: '',
        status: 'active',
        _method: 'PUT',
    });

    const openCreate = () => {
        setCreateFileError(null);
        setCreateImagePreview(null);
        setCreateImageMode('url');
        if (createFileInputRef.current) {
            createFileInputRef.current.value = '';
        }
        setIsCreateOpen(true);
    };

    const openEdit = (product: Product) => {
        setEditingProduct(product);
        setEditImageMode('url');
        setEditImagePreview(product.featured_image || null);
        setEditFileError(null);
        if (editFileInputRef.current) {
            editFileInputRef.current.value = '';
        }
        editForm.setData({
            name: product.name,
            category_id: String(product.category_id || ''),
            price: String(product.price),
            compare_at_price: String(product.compare_at_price || ''),
            stock: String(product.stock),
            sku: product.sku || '',
            featured_image: product.featured_image || '',
            image_file: null,
            description: product.description,
            status: product.status as any,
            _method: 'PUT',
        });
    };

    const handleCreateFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        setCreateFileError(null);

        if (!file) {
            return;
        }

        // Instant validation before keeping file in state
        if (file.size > MAX_FILE_SIZE_BYTES) {
            const sizeMb = (file.size / (1024 * 1024)).toFixed(2);
            setCreateFileError(`File too large (${sizeMb} MB). Maximum allowed size is 5MB. The file was rejected immediately to prevent upload errors.`);
            createForm.setData('image_file', null);
            setCreateImagePreview(null);
            if (createFileInputRef.current) {
                createFileInputRef.current.value = '';
            }
            return;
        }

        if (!ALLOWED_MIME_TYPES.includes(file.type.toLowerCase())) {
            setCreateFileError(`Unsupported format (${file.type || 'unknown'}). Please select a PNG, JPG, JPEG, WEBP, or GIF image.`);
            createForm.setData('image_file', null);
            setCreateImagePreview(null);
            if (createFileInputRef.current) {
                createFileInputRef.current.value = '';
            }
            return;
        }

        createForm.setData('image_file', file);
        setCreateImagePreview(URL.createObjectURL(file));
    };

    const clearCreateFile = () => {
        setCreateFileError(null);
        createForm.setData('image_file', null);
        setCreateImagePreview(null);
        if (createFileInputRef.current) {
            createFileInputRef.current.value = '';
        }
    };

    const handleEditFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        setEditFileError(null);

        if (!file) {
            return;
        }

        // Instant validation before keeping file in state
        if (file.size > MAX_FILE_SIZE_BYTES) {
            const sizeMb = (file.size / (1024 * 1024)).toFixed(2);
            setEditFileError(`File too large (${sizeMb} MB). Maximum allowed size is 5MB. The file was rejected immediately to prevent upload errors.`);
            editForm.setData('image_file', null);
            setEditImagePreview(editingProduct?.featured_image || null);
            if (editFileInputRef.current) {
                editFileInputRef.current.value = '';
            }
            return;
        }

        if (!ALLOWED_MIME_TYPES.includes(file.type.toLowerCase())) {
            setEditFileError(`Unsupported format (${file.type || 'unknown'}). Please select a PNG, JPG, JPEG, WEBP, or GIF image.`);
            editForm.setData('image_file', null);
            setEditImagePreview(editingProduct?.featured_image || null);
            if (editFileInputRef.current) {
                editFileInputRef.current.value = '';
            }
            return;
        }

        editForm.setData('image_file', file);
        setEditImagePreview(URL.createObjectURL(file));
    };

    const clearEditFile = () => {
        setEditFileError(null);
        editForm.setData('image_file', null);
        setEditImagePreview(editingProduct?.featured_image || null);
        if (editFileInputRef.current) {
            editFileInputRef.current.value = '';
        }
    };

    const handleCreate = (e: React.FormEvent) => {
        e.preventDefault();
        if (createImageMode === 'file' && createForm.data.image_file && createForm.data.image_file.size > MAX_FILE_SIZE_BYTES) {
            setCreateFileError('Selected image file exceeds 5MB limit. Please choose a smaller file.');
            return;
        }
        createForm.post(route('seller.products.store'), {
            forceFormData: true,
            onSuccess: () => {
                setIsCreateOpen(false);
                createForm.reset();
                setCreateImagePreview(null);
                setCreateImageMode('url');
                setCreateFileError(null);
                if (createFileInputRef.current) {
                    createFileInputRef.current.value = '';
                }
            },
        });
    };

    const handleUpdate = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editingProduct) return;
        if (editImageMode === 'file' && editForm.data.image_file && editForm.data.image_file.size > MAX_FILE_SIZE_BYTES) {
            setEditFileError('Selected image file exceeds 5MB limit. Please choose a smaller file.');
            return;
        }
        editForm.post(route('seller.products.update', editingProduct.id), {
            forceFormData: true,
            onSuccess: () => {
                setEditingProduct(null);
                setEditImagePreview(null);
                setEditImageMode('url');
                setEditFileError(null);
                if (editFileInputRef.current) {
                    editFileInputRef.current.value = '';
                }
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
                    onClick={openCreate}
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

                            <div className="space-y-2">
                                <div className="flex items-center justify-between">
                                    <label className="block font-bold text-slate-700 uppercase">Product Image</label>
                                    <div className="flex items-center gap-2">
                                        <span className="text-[11px] text-slate-500 font-medium">Source:</span>
                                        <select
                                            value={createImageMode}
                                            onChange={(e) => {
                                                const mode = e.target.value as 'url' | 'file';
                                                setCreateImageMode(mode);
                                                if (mode === 'url') {
                                                    setCreateImagePreview(createForm.data.featured_image || null);
                                                } else {
                                                    setCreateImagePreview(createForm.data.image_file ? URL.createObjectURL(createForm.data.image_file) : null);
                                                }
                                            }}
                                            className="px-2.5 py-1 text-xs bg-slate-100 border border-slate-300 rounded-lg text-slate-800 font-sans focus:ring-1 focus:ring-[#E00D42]"
                                        >
                                            <option value="url">Web Image URL</option>
                                            <option value="file">Image File (Upload)</option>
                                        </select>
                                    </div>
                                </div>

                                {createImageMode === 'url' ? (
                                    <div className="space-y-1.5">
                                        <div className="relative">
                                            <input
                                                type="url"
                                                value={createForm.data.featured_image}
                                                onChange={(e) => {
                                                    createForm.setData('featured_image', e.target.value);
                                                    setCreateImagePreview(e.target.value || null);
                                                }}
                                                placeholder="https://images.unsplash.com/photo-..."
                                                className="w-full pl-9 pr-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                            />
                                            <Link className="w-4 h-4 text-slate-400 absolute left-3 top-3" />
                                        </div>
                                        <p className="text-[11px] text-slate-400">Direct image link (JPEG, PNG, WEBP, GIF)</p>
                                    </div>
                                ) : (
                                    <div className="space-y-2">
                                        <div 
                                            onClick={() => createFileInputRef.current?.click()}
                                            className={`border-2 border-dashed ${createFileError ? 'border-rose-400 bg-rose-50/40' : 'border-slate-200 hover:border-[#E00D42] bg-slate-50/50 hover:bg-slate-50'} rounded-2xl p-4 text-center cursor-pointer transition`}
                                        >
                                            <input
                                                ref={createFileInputRef}
                                                type="file"
                                                accept="image/jpeg,image/png,image/jpg,image/webp,image/gif"
                                                onChange={handleCreateFileChange}
                                                className="hidden"
                                            />
                                            <div className="flex flex-col items-center justify-center gap-1.5 py-1">
                                                <div className={`w-8 h-8 rounded-full ${createFileError ? 'bg-rose-100 text-rose-600' : 'bg-rose-50 text-[#E00D42]'} flex items-center justify-center`}>
                                                    <Upload className="w-4 h-4" />
                                                </div>
                                                <div>
                                                    <p className="font-bold text-slate-800 text-xs">
                                                        {createForm.data.image_file ? createForm.data.image_file.name : 'Click to select image file'}
                                                    </p>
                                                    <p className="text-[11px] text-slate-400 mt-0.5">
                                                        Accepted formats: PNG, JPG, JPEG, WEBP, GIF (Max 5MB)
                                                    </p>
                                                </div>
                                            </div>
                                        </div>

                                        {createFileError && (
                                            <div className="flex items-start gap-2 p-3 bg-rose-50 border border-rose-200 rounded-xl text-rose-700 text-xs font-sans">
                                                <AlertCircle className="w-4 h-4 shrink-0 text-rose-600 mt-0.5" />
                                                <div>
                                                    <p className="font-bold">File Rejected Immediately</p>
                                                    <p className="text-[11px]">{createFileError}</p>
                                                </div>
                                            </div>
                                        )}

                                        {createForm.errors.image_file && (
                                            <div className="flex items-center gap-1.5 p-2 bg-rose-50 border border-rose-200 rounded-xl text-rose-600 text-xs font-sans">
                                                <AlertCircle className="w-3.5 h-3.5 shrink-0" />
                                                <span>{createForm.errors.image_file}</span>
                                            </div>
                                        )}

                                        {createForm.data.image_file && (
                                            <div className="flex items-center justify-between text-[11px] bg-slate-100 px-3 py-1.5 rounded-lg text-slate-600">
                                                <span className="truncate max-w-xs">{createForm.data.image_file.name} ({(createForm.data.image_file.size / 1024 / 1024).toFixed(2)} MB)</span>
                                                <button
                                                    type="button"
                                                    onClick={clearCreateFile}
                                                    className="text-rose-600 hover:text-rose-800 font-bold uppercase"
                                                >
                                                    Remove
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                )}

                                {createImagePreview && (
                                    <div className="relative inline-block mt-2">
                                        <p className="text-[10px] uppercase font-bold text-slate-400 mb-1">Image Preview</p>
                                        <div className="relative w-20 h-20 rounded-xl overflow-hidden border border-slate-200 bg-slate-100">
                                            <img
                                                src={createImagePreview}
                                                alt="Preview"
                                                className="w-full h-full object-cover"
                                                onError={() => setCreateImagePreview(null)}
                                            />
                                        </div>
                                    </div>
                                )}
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

                            <div className="space-y-2">
                                <div className="flex items-center justify-between">
                                    <label className="block font-bold text-slate-700 uppercase">Product Image</label>
                                    <div className="flex items-center gap-2">
                                        <span className="text-[11px] text-slate-500 font-medium">Source:</span>
                                        <select
                                            value={editImageMode}
                                            onChange={(e) => {
                                                const mode = e.target.value as 'url' | 'file';
                                                setEditImageMode(mode);
                                                if (mode === 'url') {
                                                    setEditImagePreview(editForm.data.featured_image || editingProduct?.featured_image || null);
                                                } else {
                                                    setEditImagePreview(editForm.data.image_file ? URL.createObjectURL(editForm.data.image_file) : (editingProduct?.featured_image || null));
                                                }
                                            }}
                                            className="px-2.5 py-1 text-xs bg-slate-100 border border-slate-300 rounded-lg text-slate-800 font-sans focus:ring-1 focus:ring-[#E00D42]"
                                        >
                                            <option value="url">Web Image URL</option>
                                            <option value="file">Image File (Upload)</option>
                                        </select>
                                    </div>
                                </div>

                                {editImageMode === 'url' ? (
                                    <div className="space-y-1.5">
                                        <div className="relative">
                                            <input
                                                type="url"
                                                value={editForm.data.featured_image}
                                                onChange={(e) => {
                                                    editForm.setData('featured_image', e.target.value);
                                                    setEditImagePreview(e.target.value || null);
                                                }}
                                                placeholder="https://images.unsplash.com/photo-..."
                                                className="w-full pl-9 pr-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                            />
                                            <Link className="w-4 h-4 text-slate-400 absolute left-3 top-3" />
                                        </div>
                                        <p className="text-[11px] text-slate-400">Direct image link (JPEG, PNG, WEBP, GIF)</p>
                                    </div>
                                ) : (
                                    <div className="space-y-2">
                                        <div 
                                            onClick={() => editFileInputRef.current?.click()}
                                            className={`border-2 border-dashed ${editFileError ? 'border-rose-400 bg-rose-50/40' : 'border-slate-200 hover:border-[#E00D42] bg-slate-50/50 hover:bg-slate-50'} rounded-2xl p-4 text-center cursor-pointer transition`}
                                        >
                                            <input
                                                ref={editFileInputRef}
                                                type="file"
                                                accept="image/jpeg,image/png,image/jpg,image/webp,image/gif"
                                                onChange={handleEditFileChange}
                                                className="hidden"
                                            />
                                            <div className="flex flex-col items-center justify-center gap-1.5 py-1">
                                                <div className={`w-8 h-8 rounded-full ${editFileError ? 'bg-rose-100 text-rose-600' : 'bg-rose-50 text-[#E00D42]'} flex items-center justify-center`}>
                                                    <Upload className="w-4 h-4" />
                                                </div>
                                                <div>
                                                    <p className="font-bold text-slate-800 text-xs">
                                                        {editForm.data.image_file ? editForm.data.image_file.name : 'Click to select new image file'}
                                                    </p>
                                                    <p className="text-[11px] text-slate-400 mt-0.5">
                                                        Accepted formats: PNG, JPG, JPEG, WEBP, GIF (Max 5MB)
                                                    </p>
                                                </div>
                                            </div>
                                        </div>

                                        {editFileError && (
                                            <div className="flex items-start gap-2 p-3 bg-rose-50 border border-rose-200 rounded-xl text-rose-700 text-xs font-sans">
                                                <AlertCircle className="w-4 h-4 shrink-0 text-rose-600 mt-0.5" />
                                                <div>
                                                    <p className="font-bold">File Rejected Immediately</p>
                                                    <p className="text-[11px]">{editFileError}</p>
                                                </div>
                                            </div>
                                        )}

                                        {editForm.errors.image_file && (
                                            <div className="flex items-center gap-1.5 p-2 bg-rose-50 border border-rose-200 rounded-xl text-rose-600 text-xs font-sans">
                                                <AlertCircle className="w-3.5 h-3.5 shrink-0" />
                                                <span>{editForm.errors.image_file}</span>
                                            </div>
                                        )}

                                        {editForm.data.image_file && (
                                            <div className="flex items-center justify-between text-[11px] bg-slate-100 px-3 py-1.5 rounded-lg text-slate-600">
                                                <span className="truncate max-w-xs">{editForm.data.image_file.name} ({(editForm.data.image_file.size / 1024 / 1024).toFixed(2)} MB)</span>
                                                <button
                                                    type="button"
                                                    onClick={clearEditFile}
                                                    className="text-rose-600 hover:text-rose-800 font-bold uppercase"
                                                >
                                                    Remove
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                )}

                                {editImagePreview && (
                                    <div className="relative inline-block mt-2">
                                        <p className="text-[10px] uppercase font-bold text-slate-400 mb-1">
                                            {editForm.data.image_file ? 'New Image Preview' : 'Current Image Preview'}
                                        </p>
                                        <div className="relative w-20 h-20 rounded-xl overflow-hidden border border-slate-200 bg-slate-100">
                                            <img
                                                src={editImagePreview}
                                                alt="Preview"
                                                className="w-full h-full object-cover"
                                                onError={() => setEditImagePreview(null)}
                                            />
                                        </div>
                                    </div>
                                )}
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
