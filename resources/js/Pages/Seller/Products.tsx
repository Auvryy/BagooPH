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
    AlertCircle,
    GripVertical,
    ChevronLeft,
    ChevronRight
} from 'lucide-react';

const MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024; // 5MB
const ALLOWED_MIME_TYPES = [
    'image/jpeg',
    'image/png',
    'image/jpg',
    'image/webp',
    'image/gif'
];

interface GalleryItem {
    id: string;
    type: 'file' | 'existing' | 'url';
    file?: File;
    url: string;
    name?: string;
    size?: number;
}

interface Props {
    products: PaginatedData<Product>;
    categories: Category[];
    shop: Shop;
}

export default function SellerProducts({ products, categories, shop }: Props) {
    const [isCreateOpen, setIsCreateOpen] = useState(false);
    const [editingProduct, setEditingProduct] = useState<Product | null>(null);
    const [searchQuery, setSearchQuery] = useState('');

    // Create Modal Gallery & Mode States
    const [createImageMode, setCreateImageMode] = useState<'file' | 'url'>('file');
    const [createGallery, setCreateGallery] = useState<GalleryItem[]>([]);
    const [createUrlInput, setCreateUrlInput] = useState('');
    const [createFileError, setCreateFileError] = useState<string | null>(null);
    const createFileInputRef = useRef<HTMLInputElement>(null);
    const [createDragIndex, setCreateDragIndex] = useState<number | null>(null);
    const [createDragOverIndex, setCreateDragOverIndex] = useState<number | null>(null);

    // Edit Modal Gallery & Mode States
    const [editImageMode, setEditImageMode] = useState<'file' | 'url'>('file');
    const [editGallery, setEditGallery] = useState<GalleryItem[]>([]);
    const [editUrlInput, setEditUrlInput] = useState('');
    const [editFileError, setEditFileError] = useState<string | null>(null);
    const editFileInputRef = useRef<HTMLInputElement>(null);
    const [editDragIndex, setEditDragIndex] = useState<number | null>(null);
    const [editDragOverIndex, setEditDragOverIndex] = useState<number | null>(null);

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
        image_files: File[];
        gallery_manifest: string;
        description: string;
    }>({
        name: '',
        category_id: categories[0]?.id || '',
        price: '',
        compare_at_price: '',
        stock: '25',
        sku: '',
        featured_image: '',
        image_files: [],
        gallery_manifest: '',
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
        image_files: File[];
        gallery_manifest: string;
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
        image_files: [],
        gallery_manifest: '',
        description: '',
        status: 'active',
        _method: 'PUT',
    });

    // Real-time slashed price validation: compare_at_price must be strictly greater than price
    const isSlashedPriceInvalid = (priceVal: string | number, compareVal: string | number | undefined | null) => {
        if (!compareVal || String(compareVal).trim() === '') return false;
        const p = parseFloat(String(priceVal));
        const c = parseFloat(String(compareVal));
        if (isNaN(c) || isNaN(p)) return false;
        return c <= p;
    };

    const createSlashedPriceError = isSlashedPriceInvalid(createForm.data.price, createForm.data.compare_at_price);
    const editSlashedPriceError = isSlashedPriceInvalid(editForm.data.price, editForm.data.compare_at_price);

    const reorderList = (list: GalleryItem[], start: number, end: number): GalleryItem[] => {
        const result = Array.from(list);
        const [removed] = result.splice(start, 1);
        result.splice(end, 0, removed);
        return result;
    };

    const handleFilesSelected = (files: FileList | null, isCreate: boolean) => {
        if (!files || files.length === 0) return;
        const newItems: GalleryItem[] = [];
        let errorMsg: string | null = null;

        Array.from(files).forEach((file) => {
            if (file.size > MAX_FILE_SIZE_BYTES) {
                const sizeMb = (file.size / (1024 * 1024)).toFixed(2);
                errorMsg = `File "${file.name}" (${sizeMb} MB) exceeds 5MB limit and was rejected.`;
                return;
            }
            if (!ALLOWED_MIME_TYPES.includes(file.type.toLowerCase())) {
                errorMsg = `File "${file.name}" has an unsupported format. Please use PNG, JPG, JPEG, WEBP, or GIF.`;
                return;
            }
            newItems.push({
                id: `file_${Date.now()}_${Math.random().toString(36).substring(2, 9)}`,
                type: 'file',
                file,
                url: URL.createObjectURL(file),
                name: file.name,
                size: file.size,
            });
        });

        if (errorMsg) {
            if (isCreate) setCreateFileError(errorMsg);
            else setEditFileError(errorMsg);
        } else {
            if (isCreate) setCreateFileError(null);
            else setEditFileError(null);
        }

        if (newItems.length > 0) {
            if (isCreate) {
                setCreateGallery((prev) => [...prev, ...newItems]);
            } else {
                setEditGallery((prev) => [...prev, ...newItems]);
            }
        }
    };

    const handleAddUrl = (url: string, isCreate: boolean) => {
        const trimmed = url.trim();
        if (!trimmed) return;
        const item: GalleryItem = {
            id: `url_${Date.now()}_${Math.random().toString(36).substring(2, 9)}`,
            type: 'url',
            url: trimmed,
        };
        if (isCreate) {
            setCreateGallery((prev) => [...prev, item]);
            setCreateUrlInput('');
        } else {
            setEditGallery((prev) => [...prev, item]);
            setEditUrlInput('');
        }
    };

    const moveGalleryItem = (index: number, direction: 'left' | 'right' | 'top', isCreate: boolean) => {
        const setGallery = isCreate ? setCreateGallery : setEditGallery;
        setGallery((prev) => {
            if (direction === 'top') {
                return reorderList(prev, index, 0);
            }
            if (direction === 'left' && index > 0) {
                return reorderList(prev, index, index - 1);
            }
            if (direction === 'right' && index < prev.length - 1) {
                return reorderList(prev, index, index + 1);
            }
            return prev;
        });
    };

    const removeGalleryItem = (index: number, isCreate: boolean) => {
        const setGallery = isCreate ? setCreateGallery : setEditGallery;
        setGallery((prev) => prev.filter((_, i) => i !== index));
    };

    const handleDragStart = (index: number, isCreate: boolean) => {
        if (isCreate) setCreateDragIndex(index);
        else setEditDragIndex(index);
    };

    const handleDragOver = (e: React.DragEvent, index: number, isCreate: boolean) => {
        e.preventDefault();
        if (isCreate) setCreateDragOverIndex(index);
        else setEditDragOverIndex(index);
    };

    const handleDrop = (index: number, isCreate: boolean) => {
        if (isCreate) {
            if (createDragIndex !== null && createDragIndex !== index) {
                setCreateGallery((prev) => reorderList(prev, createDragIndex, index));
            }
            setCreateDragIndex(null);
            setCreateDragOverIndex(null);
        } else {
            if (editDragIndex !== null && editDragIndex !== index) {
                setEditGallery((prev) => reorderList(prev, editDragIndex, index));
            }
            setEditDragIndex(null);
            setEditDragOverIndex(null);
        }
    };

    const handleDragEnd = (isCreate: boolean) => {
        if (isCreate) {
            setCreateDragIndex(null);
            setCreateDragOverIndex(null);
        } else {
            setEditDragIndex(null);
            setEditDragOverIndex(null);
        }
    };

    const openCreate = () => {
        setCreateFileError(null);
        setCreateImageMode('file');
        setCreateGallery([]);
        setCreateUrlInput('');
        if (createFileInputRef.current) {
            createFileInputRef.current.value = '';
        }
        createForm.reset();
        setIsCreateOpen(true);
    };

    const openEdit = (product: Product) => {
        setEditingProduct(product);
        setEditImageMode('file');
        setEditFileError(null);
        setEditUrlInput('');

        const initialGallery: GalleryItem[] = [];
        if (product.images && product.images.length > 0) {
            product.images.forEach((img, idx) => {
                initialGallery.push({
                    id: `existing_${img.id || idx}_${Math.random().toString(36).substring(2, 7)}`,
                    type: 'existing',
                    url: img.image_url,
                });
            });
        } else if (product.featured_image) {
            initialGallery.push({
                id: `existing_primary_${Math.random().toString(36).substring(2, 7)}`,
                type: 'existing',
                url: product.featured_image,
            });
        }

        setEditGallery(initialGallery);

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
            image_files: [],
            gallery_manifest: '',
            description: product.description,
            status: product.status as any,
            _method: 'PUT',
        });
    };

    const handleCreate = (e: React.FormEvent) => {
        e.preventDefault();
        if (createSlashedPriceError) {
            return;
        }

        const filesToUpload: File[] = [];
        const manifest = createGallery.map((item) => {
            if (item.type === 'file' && item.file) {
                const fileIndex = filesToUpload.length;
                filesToUpload.push(item.file);
                return { type: 'file', file_index: fileIndex };
            }
            return { type: item.type, url: item.url };
        });

        createForm.transform((data) => ({
            ...data,
            gallery_manifest: JSON.stringify(manifest),
            image_files: filesToUpload,
            featured_image: createGallery[0]?.url || '',
        }));

        createForm.post(route('seller.products.store'), {
            forceFormData: true,
            onSuccess: () => {
                setIsCreateOpen(false);
                createForm.reset();
                setCreateGallery([]);
                setCreateImageMode('file');
                setCreateFileError(null);
                setCreateUrlInput('');
                if (createFileInputRef.current) {
                    createFileInputRef.current.value = '';
                }
            },
        });
    };

    const handleUpdate = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editingProduct) return;
        if (editSlashedPriceError) {
            return;
        }

        const filesToUpload: File[] = [];
        const manifest = editGallery.map((item) => {
            if (item.type === 'file' && item.file) {
                const fileIndex = filesToUpload.length;
                filesToUpload.push(item.file);
                return { type: 'file', file_index: fileIndex };
            }
            return { type: item.type, url: item.url };
        });

        editForm.transform((data) => ({
            ...data,
            gallery_manifest: JSON.stringify(manifest),
            image_files: filesToUpload,
            featured_image: editGallery[0]?.url || '',
            _method: 'PUT',
        }));

        editForm.post(route('seller.products.update', editingProduct.id), {
            forceFormData: true,
            onSuccess: () => {
                setEditingProduct(null);
                setEditGallery([]);
                setEditImageMode('file');
                setEditFileError(null);
                setEditUrlInput('');
                if (editFileInputRef.current) {
                    editFileInputRef.current.value = '';
                }
            },
        });
    };

    const renderGalleryManager = (isCreate: boolean) => {
        const imageMode = isCreate ? createImageMode : editImageMode;
        const setImageMode = isCreate ? setCreateImageMode : setEditImageMode;
        const gallery = isCreate ? createGallery : editGallery;
        const fileError = isCreate ? createFileError : editFileError;
        const fileInputRef = isCreate ? createFileInputRef : editFileInputRef;
        const urlInput = isCreate ? createUrlInput : editUrlInput;
        const setUrlInput = isCreate ? setCreateUrlInput : setEditUrlInput;
        const dragIndex = isCreate ? createDragIndex : editDragIndex;
        const dragOverIndex = isCreate ? createDragOverIndex : editDragOverIndex;

        return (
            <div className="space-y-3 font-sans">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <label className="block font-bold text-slate-700 uppercase text-xs font-mono">
                            Product Images ({gallery.length})
                        </label>
                        {gallery.length > 0 && (
                            <span className="text-[10px] text-slate-500 font-mono bg-slate-100 px-2 py-0.5 rounded-full">
                                Drag to reorder
                            </span>
                        )}
                    </div>
                    <div className="flex items-center gap-2">
                        <span className="text-[11px] text-slate-500 font-medium">Source:</span>
                        <select
                            value={imageMode}
                            onChange={(e) => setImageMode(e.target.value as 'file' | 'url')}
                            className="px-2.5 py-1 text-xs bg-slate-100 border border-slate-300 rounded-lg text-slate-800 font-sans focus:ring-1 focus:ring-[#E00D42]"
                        >
                            <option value="file">Image File (Upload)</option>
                            <option value="url">Web Image URL</option>
                        </select>
                    </div>
                </div>

                {imageMode === 'file' ? (
                    <div className="space-y-2">
                        <div
                            onClick={() => fileInputRef.current?.click()}
                            className={`border-2 border-dashed ${
                                fileError 
                                    ? 'border-rose-400 bg-rose-50/40' 
                                    : 'border-slate-300 hover:border-[#E00D42] bg-slate-50/70 hover:bg-slate-50'
                            } rounded-2xl p-4 text-center cursor-pointer transition`}
                        >
                            <input
                                ref={fileInputRef}
                                type="file"
                                multiple
                                accept="image/jpeg,image/png,image/jpg,image/webp,image/gif"
                                onChange={(e) => {
                                    handleFilesSelected(e.target.files, isCreate);
                                    if (fileInputRef.current) fileInputRef.current.value = '';
                                }}
                                className="hidden"
                            />
                            <div className="flex flex-col items-center justify-center gap-1.5 py-1">
                                <div className={`w-9 h-9 rounded-full ${fileError ? 'bg-rose-100 text-rose-600' : 'bg-rose-50 text-[#E00D42]'} flex items-center justify-center`}>
                                    <Upload className="w-4 h-4" />
                                </div>
                                <div>
                                    <p className="font-bold text-slate-800 text-xs">
                                        Click to upload image files (Multiple allowed)
                                    </p>
                                    <p className="text-[11px] text-slate-400 mt-0.5">
                                        Accepted formats: PNG, JPG, JPEG, WEBP, GIF (Max 5MB each)
                                    </p>
                                </div>
                            </div>
                        </div>

                        {fileError && (
                            <div className="flex items-start gap-2 p-3 bg-rose-50 border border-rose-200 rounded-xl text-rose-700 text-xs font-sans">
                                <AlertCircle className="w-4 h-4 shrink-0 text-rose-600 mt-0.5" />
                                <div>
                                    <p className="font-bold">Upload Notice</p>
                                    <p className="text-[11px]">{fileError}</p>
                                </div>
                            </div>
                        )}
                    </div>
                ) : (
                    <div className="space-y-2">
                        <div className="flex gap-2">
                            <div className="relative flex-1">
                                <input
                                    type="url"
                                    value={urlInput}
                                    onChange={(e) => setUrlInput(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            e.preventDefault();
                                            handleAddUrl(urlInput, isCreate);
                                        }
                                    }}
                                    placeholder="Paste direct image URL (https://...)"
                                    className="w-full pl-9 pr-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 text-xs focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                />
                                <Link className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" />
                            </div>
                            <button
                                type="button"
                                onClick={() => handleAddUrl(urlInput, isCreate)}
                                className="px-3.5 py-2 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-xs font-mono font-bold uppercase transition"
                            >
                                Add Image
                            </button>
                        </div>
                        <p className="text-[11px] text-slate-400">Direct image link (JPEG, PNG, WEBP, GIF)</p>
                    </div>
                )}

                {/* Gallery Items Grid with Drag & Drop */}
                {gallery.length === 0 ? (
                    <div className="p-4 bg-slate-50 border border-dashed border-slate-200 rounded-xl text-center text-slate-400 text-xs font-mono">
                        No images attached. Upload one or more images above.
                    </div>
                ) : (
                    <div className="space-y-1.5">
                        <div className="flex items-center justify-between text-[11px] text-slate-500 font-mono">
                            <span>Image #1 is the primary cover displayed in catalogs</span>
                            <span>{gallery.length} image{gallery.length > 1 ? 's' : ''}</span>
                        </div>
                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                            {gallery.map((item, index) => {
                                const isPrimary = index === 0;
                                const isBeingDragged = dragIndex === index;
                                const isOver = dragOverIndex === index;

                                return (
                                    <div
                                        key={item.id}
                                        draggable
                                        onDragStart={() => handleDragStart(index, isCreate)}
                                        onDragOver={(e) => handleDragOver(e, index, isCreate)}
                                        onDrop={() => handleDrop(index, isCreate)}
                                        onDragEnd={() => handleDragEnd(isCreate)}
                                        className={`group relative rounded-xl border overflow-hidden transition-all select-none ${
                                            isBeingDragged
                                                ? 'opacity-40 scale-95 border-dashed border-[#E00D42]'
                                                : isOver
                                                ? 'border-2 border-[#E00D42] ring-2 ring-[#E00D42]/20 scale-102'
                                                : isPrimary
                                                ? 'border-2 border-emerald-500 bg-emerald-50/20 shadow-xs'
                                                : 'border-slate-200 hover:border-slate-300 bg-white'
                                        }`}
                                    >
                                        <div className="relative w-full h-24 bg-slate-100 cursor-grab active:cursor-grabbing">
                                            <img
                                                src={item.url}
                                                alt={item.name || `Image ${index + 1}`}
                                                className="w-full h-full object-cover"
                                            />
                                            <div className="absolute top-1.5 left-1.5 flex items-center gap-1">
                                                {isPrimary ? (
                                                    <span className="bg-emerald-600 text-white font-bold text-[9px] font-mono uppercase px-1.5 py-0.5 rounded-md shadow-xs flex items-center gap-0.5">
                                                        <Star className="w-2.5 h-2.5 fill-current" />
                                                        Cover
                                                    </span>
                                                ) : (
                                                    <span className="bg-black/60 text-white font-mono font-bold text-[9px] px-1.5 py-0.5 rounded-md backdrop-blur-xs">
                                                        #{index + 1}
                                                    </span>
                                                )}
                                            </div>
                                            <div className="absolute top-1.5 right-1.5 flex items-center gap-1">
                                                <button
                                                    type="button"
                                                    onClick={() => removeGalleryItem(index, isCreate)}
                                                    className="p-1 rounded-md bg-black/60 hover:bg-rose-600 text-white transition backdrop-blur-xs"
                                                    title="Remove image"
                                                >
                                                    <X className="w-3 h-3" />
                                                </button>
                                            </div>
                                            <div className="absolute bottom-1 right-1.5 p-0.5 text-white/80 drop-shadow-sm pointer-events-none">
                                                <GripVertical className="w-3.5 h-3.5" />
                                            </div>
                                        </div>

                                        {/* Quick ordering controls */}
                                        <div className="p-1.5 bg-slate-50 border-t border-slate-100 flex items-center justify-between text-[10px] font-mono">
                                            <div className="flex items-center gap-0.5">
                                                <button
                                                    type="button"
                                                    disabled={index === 0}
                                                    onClick={() => moveGalleryItem(index, 'left', isCreate)}
                                                    className="p-1 rounded text-slate-600 hover:bg-slate-200 disabled:opacity-30 disabled:hover:bg-transparent"
                                                    title="Move earlier"
                                                >
                                                    <ChevronLeft className="w-3 h-3" />
                                                </button>
                                                <button
                                                    type="button"
                                                    disabled={index === gallery.length - 1}
                                                    onClick={() => moveGalleryItem(index, 'right', isCreate)}
                                                    className="p-1 rounded text-slate-600 hover:bg-slate-200 disabled:opacity-30 disabled:hover:bg-transparent"
                                                    title="Move later"
                                                >
                                                    <ChevronRight className="w-3 h-3" />
                                                </button>
                                            </div>
                                            {!isPrimary && (
                                                <button
                                                    type="button"
                                                    onClick={() => moveGalleryItem(index, 'top', isCreate)}
                                                    className="px-1.5 py-0.5 text-[9px] font-bold text-slate-600 hover:text-emerald-700 hover:bg-emerald-50 rounded"
                                                    title="Make primary cover"
                                                >
                                                    Set Cover
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}
            </div>
        );
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
                                    <label className={`block font-bold uppercase mb-1 ${createSlashedPriceError ? 'text-rose-600' : 'text-slate-700'}`}>
                                        Slashed Price (₱)
                                    </label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        value={createForm.data.compare_at_price}
                                        onChange={(e) => createForm.setData('compare_at_price', e.target.value)}
                                        placeholder="1799.00"
                                        className={`w-full px-3.5 py-2.5 bg-slate-50 border rounded-xl text-slate-900 focus:bg-white focus:ring-1 ${
                                            createSlashedPriceError
                                                ? 'border-rose-500 ring-1 ring-rose-500 focus:border-rose-500 focus:ring-rose-500 bg-rose-50/30'
                                                : 'border-slate-200 focus:ring-[#E00D42]'
                                        }`}
                                    />
                                    {createSlashedPriceError && (
                                        <p className="mt-1 text-[11px] text-rose-600 font-sans flex items-center gap-1">
                                            <AlertCircle className="w-3.5 h-3.5 shrink-0" />
                                            <span>Must be higher than listing price ({formatPrice(createForm.data.price)})</span>
                                        </p>
                                    )}
                                    {createForm.errors.compare_at_price && (
                                        <p className="mt-1 text-[11px] text-rose-600 font-sans">{createForm.errors.compare_at_price}</p>
                                    )}
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

                            {renderGalleryManager(true)}

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
                                    disabled={createForm.processing || createSlashedPriceError}
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
                                    <label className={`block font-bold uppercase mb-1 ${editSlashedPriceError ? 'text-rose-600' : 'text-slate-700'}`}>
                                        Slashed Price (₱)
                                    </label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        value={editForm.data.compare_at_price}
                                        onChange={(e) => editForm.setData('compare_at_price', e.target.value)}
                                        placeholder="1799.00"
                                        className={`w-full px-3.5 py-2.5 bg-slate-50 border rounded-xl text-slate-900 focus:bg-white focus:ring-1 ${
                                            editSlashedPriceError
                                                ? 'border-rose-500 ring-1 ring-rose-500 focus:border-rose-500 focus:ring-rose-500 bg-rose-50/30'
                                                : 'border-slate-200 focus:ring-[#E00D42]'
                                        }`}
                                    />
                                    {editSlashedPriceError && (
                                        <p className="mt-1 text-[11px] text-rose-600 font-sans flex items-center gap-1">
                                            <AlertCircle className="w-3.5 h-3.5 shrink-0" />
                                            <span>Must be higher than listing price ({formatPrice(editForm.data.price)})</span>
                                        </p>
                                    )}
                                    {editForm.errors.compare_at_price && (
                                        <p className="mt-1 text-[11px] text-rose-600 font-sans">{editForm.errors.compare_at_price}</p>
                                    )}
                                </div>
                            </div>

                            {renderGalleryManager(false)}

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
                                    disabled={editForm.processing || editSlashedPriceError}
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
