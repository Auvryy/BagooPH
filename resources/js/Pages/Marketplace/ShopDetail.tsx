import React, { useState, useRef, useEffect } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import { PaginatedData, Product, Shop } from '@/types';
import { 
    Store, 
    Star, 
    MapPin, 
    Phone, 
    ShoppingBag, 
    ArrowLeft, 
    ShieldCheck, 
    CheckCircle2, 
    MessageSquare, 
    Tag, 
    Clock, 
    Sparkles, 
    Truck, 
    Search, 
    ChevronRight, 
    Heart, 
    Share2, 
    Check,
    Camera,
    PenLine,
    Upload,
    X,
    Image as ImageIcon,
    Edit
} from 'lucide-react';

interface Props {
    shop: Shop & {
        response_rate?: string;
        response_time?: string;
        rating?: number | string;
        products_count?: number;
    };
    products: PaginatedData<Product>;
    isOwner?: boolean;
}

export default function ShopDetail({ shop, products, isOwner = false }: Props) {
    const isPreview = typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('preview') === 'true';
    const canManageStore = isOwner || isPreview;

    const bannerFileRef = useRef<HTMLInputElement | null>(null);
    const logoFileRef = useRef<HTMLInputElement | null>(null);

    const [editModalOpen, setEditModalOpen] = useState(false);
    const [bannerPreview, setBannerPreview] = useState<string>(shop.banner || '');
    const [logoPreview, setLogoPreview] = useState<string>(shop.logo || '');

    const { data: editData, setData: setEditData, post: postEdit, processing: editProcessing, errors: editErrors, reset: resetEdit, recentlySuccessful } = useForm({
        name: shop.name || '',
        description: shop.description || '',
        phone: shop.phone || '',
        address: shop.address || '',
        city: shop.city || '',
        logo: null as File | string | null,
        banner: null as File | string | null,
    });

    useEffect(() => {
        setBannerPreview(shop.banner || '');
        setLogoPreview(shop.logo || '');
        setEditData({
            name: shop.name || '',
            description: shop.description || '',
            phone: shop.phone || '',
            address: shop.address || '',
            city: shop.city || '',
            logo: null,
            banner: null,
        });
    }, [shop]);

    const handleBannerFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = () => {
            setBannerPreview(reader.result as string);
        };
        reader.readAsDataURL(file);
        setEditData('banner', file);
    };

    const handleLogoFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = () => {
            setLogoPreview(reader.result as string);
        };
        reader.readAsDataURL(file);
        setEditData('logo', file);
    };

    const handleSaveBranding = (e: React.FormEvent) => {
        e.preventDefault();
        postEdit(route('shop.updateBranding', shop.slug), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setTimeout(() => setEditModalOpen(false), 800);
            },
        });
    };

    const [searchInShop, setSearchInShop] = useState('');
    const [selectedCategoryTab, setSelectedCategoryTab] = useState('all');
    const [isFollowed, setIsFollowed] = useState(false);
    const [copiedVoucher, setCopiedVoucher] = useState<string | null>(null);

    const formatPrice = (val: string | number | undefined | null) => {
        const num = Number(val || 0);
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(num);
    };

    const copyVoucher = (code: string) => {
        navigator.clipboard.writeText(code);
        setCopiedVoucher(code);
        setTimeout(() => setCopiedVoucher(null), 3000);
    };

    const filteredProducts = products.data.filter(product => {
        const matchesSearch = !searchInShop.trim() || 
            product.name.toLowerCase().includes(searchInShop.toLowerCase()) ||
            product.description.toLowerCase().includes(searchInShop.toLowerCase());
        return matchesSearch;
    });

    return (
        <BuyerLayout hideAuthButtons={canManageStore}>
            <Head title={`${shop.name} — Verified Official Storefront`} />

            <div className="space-y-6 max-w-7xl mx-auto font-sans">
                
                {/* Back Link & Merchant Preview Bar */}
                <div className="space-y-3 pb-2">
                    <div className="flex items-center justify-between">
                        <Link
                            href={route('buyer.index')}
                            className="text-xs font-mono text-slate-500 hover:text-[#E00D42] flex items-center gap-1 uppercase font-bold transition"
                        >
                            <ArrowLeft className="w-3.5 h-3.5" />
                            <span>Return to Marketplace</span>
                        </Link>
                    </div>

                    {canManageStore && (
                        <div className="bg-slate-900 text-white px-4 sm:px-6 py-3 rounded-xl flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 font-mono text-xs shadow-md border border-slate-800 animate-fade-in">
                            <div className="flex items-center gap-2.5">
                                <span className="w-2.5 h-2.5 rounded-full bg-[#E00D42]"></span>
                                <div>
                                    <p className="font-bold uppercase tracking-wider text-slate-100">Merchant Storefront Preview</p>
                                    <p className="text-[11px] text-slate-400 font-sans">Viewing public storefront as seen by buyers. Guest auth controls are hidden.</p>
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => setEditModalOpen(true)}
                                    className="px-3.5 py-2 bg-[#E00D42] hover:bg-[#C20836] text-white rounded-lg font-bold uppercase text-[11px] transition shadow-xs flex items-center gap-1.5 cursor-pointer"
                                >
                                    <Camera className="w-3.5 h-3.5" />
                                    <span>Edit Store Cover, Logo & Bio</span>
                                </button>
                                <a
                                    href={route('seller.dashboard')}
                                    className="px-3.5 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg font-bold text-[11px] transition flex items-center gap-1.5"
                                >
                                    <ArrowLeft className="w-3.5 h-3.5" />
                                    <span>Back to Cockpit</span>
                                </a>
                            </div>
                        </div>
                    )}
                </div>

                {/* 1. MASTER FLAGSHIP STOREFRONT PROFILE CARD */}
                <div className="bg-white rounded-md border border-slate-300 shadow-xs overflow-hidden relative">
                    
                    {/* Top Banner Image with Cinematic Backdrop */}
                    <div className="h-44 sm:h-56 relative bg-slate-950 overflow-hidden group">
                        <img 
                            src={bannerPreview || 'https://images.unsplash.com/photo-1441986300917-64674bd600d8?auto=format&fit=crop&w=1400&q=80'} 
                            alt="" 
                            className="w-full h-full object-cover opacity-60 transition duration-300" 
                        />
                        <div className="absolute inset-0 bg-gradient-to-t from-black/80 via-black/30 to-transparent"></div>
                        <div className="absolute top-4 right-4 flex items-center gap-2 font-mono text-xs text-white">
                            <span className="px-2.5 py-1 rounded-xs bg-black/70 backdrop-blur-md border border-white/20 flex items-center gap-1 font-bold">
                                <ShieldCheck className="w-3.5 h-3.5 text-emerald-400" />
                                <span>100% Authentic Products</span>
                            </span>
                        </div>

                        {canManageStore && (
                            <div className="absolute bottom-4 right-4 z-20">
                                <button
                                    type="button"
                                    onClick={() => setEditModalOpen(true)}
                                    className="px-3 py-1.5 bg-black/75 hover:bg-black text-white rounded-lg border border-white/20 text-xs font-mono font-bold flex items-center gap-1.5 backdrop-blur-md transition shadow-md cursor-pointer"
                                >
                                    <Camera className="w-3.5 h-3.5" />
                                    <span>Change Cover Image</span>
                                </button>
                            </div>
                        )}
                    </div>

                    {/* Storefront Info & Telemetry Bar - PURE WHITE CANVAS FOR 100% GUARANTEED LEGIBILITY */}
                    <div className="p-6 sm:p-8 bg-white relative">
                        <div className="flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
                            
                            {/* Logo (Overlapping Banner) & Store Information (Crystal Clear on White) */}
                            <div className="flex flex-col sm:flex-row items-start sm:items-center gap-4 sm:gap-6">
                                {/* Logo Box with clean high-contrast border and 2px radius */}
                                <div className="relative group w-24 h-24 sm:w-28 sm:h-28 -mt-16 sm:-mt-20 rounded-xs bg-white p-1.5 shadow-xl border-2 border-white overflow-hidden shrink-0 z-10">
                                    <img 
                                        src={logoPreview || 'https://images.unsplash.com/photo-1544816155-12df9643f363?auto=format&fit=crop&w=300&q=80'} 
                                        alt={shop.name} 
                                        className="w-full h-full object-cover rounded-xs" 
                                    />
                                    {canManageStore && (
                                        <button
                                            type="button"
                                            onClick={() => setEditModalOpen(true)}
                                            className="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 flex flex-col items-center justify-center text-white transition-opacity text-[10px] font-mono font-bold cursor-pointer"
                                        >
                                            <Camera className="w-4 h-4 mb-0.5" />
                                            <span>Change Logo</span>
                                        </button>
                                    )}
                                </div>

                                {/* Text Content - Guaranteed Readable on White Canvas */}
                                <div className="space-y-1.5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h1 className="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">
                                            {shop.name}
                                        </h1>
                                        <span className="px-2 py-0.5 rounded-xs bg-emerald-50 text-emerald-700 border border-emerald-300 text-[10px] font-bold font-mono uppercase">
                                            MALL PREFERRED
                                        </span>
                                    </div>
                                    <div className="flex items-start gap-2">
                                        <p className="text-xs text-slate-700 max-w-xl line-clamp-2">
                                            {shop.description || 'Official flagship store for curated lifestyle essentials on BagooPH.'}
                                        </p>
                                        {canManageStore && (
                                            <button
                                                type="button"
                                                onClick={() => setEditModalOpen(true)}
                                                className="text-slate-400 hover:text-[#E00D42] text-[11px] font-mono font-bold shrink-0 flex items-center gap-1 transition cursor-pointer"
                                                title="Edit Store Bio"
                                            >
                                                <PenLine className="w-3 h-3" />
                                                <span>Edit Bio</span>
                                            </button>
                                        )}
                                    </div>
                                    <p className="text-[11px] text-slate-500 font-mono flex items-center gap-1.5 pt-0.5">
                                        <MapPin className="w-3.5 h-3.5 text-[#E00D42]" />
                                        <span>{shop.address || 'Artisan District'}, {shop.city || 'Metro Manila'}</span>
                                    </p>
                                </div>
                            </div>

                            {/* Action Buttons (2px Radius) */}
                            <div className="flex items-center gap-3 w-full md:w-auto font-mono text-xs shrink-0">
                                {canManageStore ? (
                                    <button
                                        type="button"
                                        onClick={() => setEditModalOpen(true)}
                                        className="flex-1 md:flex-initial px-5 py-2.5 rounded-xs bg-[#E00D42] hover:bg-[#C20836] text-white font-bold uppercase transition shadow-xs flex items-center justify-center gap-1.5 cursor-pointer"
                                    >
                                        <Edit className="w-3.5 h-3.5" />
                                        <span>Edit Storefront</span>
                                    </button>
                                ) : (
                                    <>
                                        <button
                                            type="button"
                                            onClick={() => setIsFollowed(!isFollowed)}
                                            className={`flex-1 md:flex-initial px-5 py-2.5 rounded-xs font-bold uppercase transition flex items-center justify-center gap-1.5 ${
                                                isFollowed 
                                                    ? 'bg-slate-100 text-slate-700 border border-slate-300' 
                                                    : 'bg-slate-900 hover:bg-black text-white shadow-xs'
                                            }`}
                                        >
                                            <Heart className={`w-3.5 h-3.5 ${isFollowed ? 'fill-[#E00D42] text-[#E00D42]' : ''}`} />
                                            <span>{isFollowed ? 'Following (14.2k)' : '+ Follow Shop'}</span>
                                        </button>

                                        <button
                                            type="button"
                                            className="flex-1 md:flex-initial px-5 py-2.5 rounded-xs bg-[#E00D42] hover:bg-[#C20836] text-white font-bold uppercase transition shadow-xs flex items-center justify-center gap-1.5"
                                        >
                                            <MessageSquare className="w-3.5 h-3.5" />
                                            <span>Live Chat</span>
                                        </button>
                                    </>
                                )}
                            </div>
                        </div>

                        {/* Store Statistics Grid (2px Radius & High-Visibility Borders) */}
                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-8 pt-6 border-t border-slate-200 font-mono text-xs">
                            <div className="p-3.5 rounded-xs bg-slate-50 border border-slate-300 space-y-1">
                                <span className="text-slate-500 text-[10px] uppercase font-bold block">Rating & Feedback</span>
                                <div className="flex items-center gap-1.5">
                                    <span className="text-base font-black text-[#E00D42]">{Number(shop.rating || 4.95).toFixed(2)}</span>
                                    <div className="flex text-amber-400">
                                        {[...Array(5)].map((_, i) => (
                                            <Star key={i} className="w-3 h-3 fill-amber-400" />
                                        ))}
                                    </div>
                                </div>
                            </div>

                            <div className="p-3.5 rounded-xs bg-slate-50 border border-slate-300 space-y-1">
                                <span className="text-slate-500 text-[10px] uppercase font-bold block">Total Products</span>
                                <p className="text-base font-black text-slate-900">{products.total ?? products.data.length} Listings</p>
                            </div>

                            <div className="p-3.5 rounded-xs bg-slate-50 border border-slate-300 space-y-1">
                                <span className="text-slate-500 text-[10px] uppercase font-bold block">Chat Response</span>
                                <p className="text-base font-black text-emerald-600">99% (Within 5 mins)</p>
                            </div>

                            <div className="p-3.5 rounded-xs bg-slate-50 border border-slate-300 space-y-1">
                                <span className="text-slate-500 text-[10px] uppercase font-bold block">Dispatch Performance</span>
                                <p className="text-base font-black text-indigo-600">98% Fast Dispatch</p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* 2. STORE EXCLUSIVE VOUCHER STRIP */}
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 font-mono text-xs">
                    <div className="p-4 rounded-xs bg-gradient-to-r from-rose-50 to-rose-100/60 border border-rose-300 flex items-center justify-between">
                        <div className="space-y-1">
                            <span className="px-2 py-0.5 rounded-xs bg-[#E00D42] text-white text-[9px] font-bold uppercase">STORE EXCLUSIVE</span>
                            <h4 className="font-black text-sm text-slate-900">₱150 OFF DISCOUNT</h4>
                            <p className="text-[10px] text-slate-500">Min. Spend ₱1,500 • Code: PRIME150</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => copyVoucher('PRIME150')}
                            className="px-3.5 py-1.5 bg-[#E00D42] hover:bg-[#C20836] text-white font-bold rounded-xs text-[11px] uppercase transition shadow-2xs"
                        >
                            {copiedVoucher === 'PRIME150' ? 'Claimed ✓' : 'Claim'}
                        </button>
                    </div>

                    <div className="p-4 rounded-xs bg-gradient-to-r from-emerald-50 to-emerald-100/60 border border-emerald-300 flex items-center justify-between">
                        <div className="space-y-1">
                            <span className="px-2 py-0.5 rounded-xs bg-emerald-600 text-white text-[9px] font-bold uppercase">SHIPPING PASS</span>
                            <h4 className="font-black text-sm text-slate-900">FREE DOORSTEP DELIVERY</h4>
                            <p className="text-[10px] text-slate-500">Min. Spend ₱0 • Code: PRIMESHIP</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => copyVoucher('PRIMESHIP')}
                            className="px-3.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xs text-[11px] uppercase transition shadow-2xs"
                        >
                            {copiedVoucher === 'PRIMESHIP' ? 'Claimed ✓' : 'Claim'}
                        </button>
                    </div>

                    <div className="p-4 rounded-xs bg-gradient-to-r from-amber-50 to-amber-100/60 border border-amber-300 flex items-center justify-between">
                        <div className="space-y-1">
                            <span className="px-2 py-0.5 rounded-xs bg-amber-500 text-white text-[9px] font-bold uppercase">FLASH COUPON</span>
                            <h4 className="font-black text-sm text-slate-900">10% CASHBACK COINS</h4>
                            <p className="text-[10px] text-slate-500">All Categories • Code: COIN10</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => copyVoucher('COIN10')}
                            className="px-3.5 py-1.5 bg-amber-500 hover:bg-amber-600 text-white font-bold rounded-xs text-[11px] uppercase transition shadow-2xs"
                        >
                            {copiedVoucher === 'COIN10' ? 'Claimed ✓' : 'Claim'}
                        </button>
                    </div>
                </div>

                {/* 3. STORE PRODUCTS CATALOG WITH IN-STORE SEARCH */}
                <div className="space-y-4">
                    {/* Catalog Header & Search Filter */}
                    <div className="bg-white rounded-md p-4 sm:p-5 border border-slate-300 shadow-xs flex flex-col sm:flex-row items-center justify-between gap-4">
                        <div className="flex items-center gap-2">
                            <ShoppingBag className="w-5 h-5 text-[#E00D42]" />
                            <h3 className="font-black text-slate-900 text-base">Store Catalog & All Products</h3>
                            <span className="text-xs font-mono text-slate-500">({filteredProducts.length} listings)</span>
                        </div>

                        {/* Search Inside Shop */}
                        <div className="w-full sm:w-80">
                            <div className="relative">
                                <input
                                    type="text"
                                    value={searchInShop}
                                    onChange={(e) => setSearchInShop(e.target.value)}
                                    placeholder="Search in this shop..."
                                    className="w-full pl-9 pr-4 py-2 bg-slate-50 border border-slate-300 rounded-xs text-xs focus:ring-2 focus:ring-[#E00D42] focus:bg-white text-slate-900 placeholder-slate-400"
                                />
                                <Search className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" />
                            </div>
                        </div>
                    </div>

                    {/* Products Grid */}
                    {filteredProducts.length === 0 ? (
                        <div className="bg-white rounded-md p-12 text-center border border-slate-300 space-y-3">
                            <ShoppingBag className="w-12 h-12 text-slate-300 mx-auto" />
                            <h4 className="font-bold text-slate-800 text-base">No products match your search in this store</h4>
                            <p className="text-xs text-slate-500">Try searching for other keywords or clear your search.</p>
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3.5">
                            {filteredProducts.map((product) => {
                                const priceNum = Number(product.price);
                                const compareNum = product.compare_at_price ? Number(product.compare_at_price) : null;
                                const discountPct = compareNum && compareNum > priceNum 
                                    ? Math.round(((compareNum - priceNum) / compareNum) * 100)
                                    : null;

                                return (
                                    <Link
                                        key={product.id}
                                        href={route('buyer.products.show', product.slug)}
                                        className="group bg-white rounded-xs border border-slate-300 overflow-hidden hover:shadow-xl hover:border-[#E00D42] transition duration-300 flex flex-col justify-between"
                                    >
                                        <div className="block relative aspect-square bg-slate-100 overflow-hidden">
                                            <img
                                                src={product.featured_image || ''}
                                                alt={product.name}
                                                className="w-full h-full object-cover group-hover:scale-105 transition duration-500"
                                            />

                                            {/* Mall Badge */}
                                            <div className="absolute top-2 left-2 flex flex-col gap-1">
                                                <span className="px-1.5 py-0.5 rounded-xs bg-[#E00D42] text-white font-mono text-[9px] font-black tracking-wider shadow-2xs">
                                                    MALL
                                                </span>
                                            </div>

                                            {discountPct && (
                                                <span className="absolute top-2 right-2 px-1.5 py-0.5 rounded-xs bg-amber-400 text-slate-950 font-mono text-[9px] font-black shadow-2xs">
                                                    -{discountPct}%
                                                </span>
                                            )}

                                            <div className="absolute bottom-2 left-2">
                                                <span className="px-1.5 py-0.5 rounded-xs bg-emerald-600/90 backdrop-blur-xs text-white font-mono text-[8px] font-bold uppercase flex items-center gap-0.5 shadow-2xs">
                                                    <Truck className="w-2.5 h-2.5" /> FREE DELIVERY
                                                </span>
                                            </div>
                                        </div>

                                        {/* Product Info */}
                                        <div className="p-3 space-y-2 font-sans flex-1 flex flex-col justify-between">
                                            <div className="space-y-1">
                                                <h4 className="font-bold text-xs text-slate-800 group-hover:text-[#E00D42] transition line-clamp-2 leading-tight">
                                                    {product.name}
                                                </h4>

                                                {/* Price */}
                                                <div className="flex items-baseline gap-1.5 pt-0.5">
                                                    <span className="text-sm font-black text-[#E00D42]">
                                                        {formatPrice(product.price)}
                                                    </span>
                                                    {compareNum && compareNum > priceNum && (
                                                        <span className="text-[10px] text-slate-400 line-through">
                                                            {formatPrice(product.compare_at_price)}
                                                        </span>
                                                    )}
                                                </div>

                                                {/* Rating & Sold count */}
                                                <div className="flex items-center justify-between text-[10px] text-slate-500 font-sans pt-1">
                                                    <div className="flex items-center gap-0.5 text-amber-500 font-bold">
                                                        <Star className="w-3 h-3 fill-amber-400" />
                                                        <span>{Number(product.rating || 5.0).toFixed(1)}</span>
                                                    </div>
                                                    <span className="text-slate-400">{product.sales_count ?? 120} sold</span>
                                                </div>
                                            </div>
                                        </div>
                                    </Link>
                                );
                            })}
                        </div>
                    )}
                </div>

            </div>

            {/* Merchant Storefront Branding & Bio Edit Modal */}
            {canManageStore && editModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-black/60 backdrop-blur-xs overflow-y-auto animate-fade-in">
                    <div className="relative w-full max-w-2xl bg-white rounded-xl shadow-2xl border border-slate-200 overflow-hidden my-8">
                        {/* Header */}
                        <div className="px-6 py-4 bg-slate-900 text-white flex items-center justify-between">
                            <div>
                                <h3 className="font-bold text-base font-mono uppercase tracking-wider flex items-center gap-2">
                                    <Edit className="w-4 h-4 text-[#E00D42]" />
                                    <span>Edit Storefront Branding & Bio</span>
                                </h3>
                                <p className="text-xs text-slate-400 font-sans mt-0.5">
                                    Update your store cover, logo avatar, and merchant biography visible to buyers.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setEditModalOpen(false)}
                                className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        {/* Form Body */}
                        <form onSubmit={handleSaveBranding} className="p-6 space-y-5 max-h-[75vh] overflow-y-auto">
                            {/* Hidden File Inputs */}
                            <input
                                ref={bannerFileRef}
                                type="file"
                                accept="image/jpeg,image/png,image/jpg,image/webp"
                                onChange={handleBannerFileSelect}
                                className="hidden"
                            />
                            <input
                                ref={logoFileRef}
                                type="file"
                                accept="image/jpeg,image/png,image/jpg,image/webp"
                                onChange={handleLogoFileSelect}
                                className="hidden"
                            />

                            {/* Cover Banner */}
                            <div className="space-y-2">
                                <label className="block text-xs font-mono font-bold uppercase text-slate-700">
                                    Storefront Cover Banner
                                </label>
                                <div className="relative h-36 rounded-lg overflow-hidden border-2 border-dashed border-slate-300 bg-slate-100 group">
                                    {bannerPreview ? (
                                        <img
                                            src={bannerPreview}
                                            alt="Cover Banner"
                                            className="w-full h-full object-cover"
                                        />
                                    ) : (
                                        <div className="flex flex-col items-center justify-center h-full text-slate-400">
                                            <ImageIcon className="w-8 h-8 mb-1 text-slate-400" />
                                            <span className="text-xs font-mono">No cover banner set</span>
                                        </div>
                                    )}
                                    <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                                        <button
                                            type="button"
                                            onClick={() => bannerFileRef.current?.click()}
                                            className="px-3.5 py-1.5 bg-white text-slate-900 rounded-md font-mono text-xs font-bold uppercase shadow-md flex items-center gap-1.5 hover:bg-slate-100 transition"
                                        >
                                            <Camera className="w-3.5 h-3.5 text-[#E00D42]" />
                                            <span>Upload New Cover</span>
                                        </button>
                                    </div>
                                </div>
                                <div className="flex items-center justify-between text-[11px] text-slate-500 font-mono">
                                    <span>Recommended: 1200 x 400px (JPG, PNG, WEBP up to 4MB)</span>
                                    <button
                                        type="button"
                                        onClick={() => bannerFileRef.current?.click()}
                                        className="text-[#E00D42] font-bold hover:underline"
                                    >
                                        Browse File
                                    </button>
                                </div>
                                {editErrors.banner && (
                                    <p className="text-xs text-rose-600 font-mono">{editErrors.banner}</p>
                                )}
                            </div>

                            {/* Store Logo */}
                            <div className="space-y-2">
                                <label className="block text-xs font-mono font-bold uppercase text-slate-700">
                                    Store Logo / Brand Avatar
                                </label>
                                <div className="flex items-center gap-4">
                                    <div className="relative w-20 h-20 rounded-lg overflow-hidden border-2 border-slate-300 bg-slate-100 shrink-0">
                                        {logoPreview ? (
                                            <img
                                                src={logoPreview}
                                                alt="Store Logo"
                                                className="w-full h-full object-cover"
                                            />
                                        ) : (
                                            <div className="flex flex-col items-center justify-center h-full text-slate-400">
                                                <Store className="w-6 h-6 text-slate-400" />
                                            </div>
                                        )}
                                    </div>
                                    <div className="space-y-1.5">
                                        <button
                                            type="button"
                                            onClick={() => logoFileRef.current?.click()}
                                            className="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-800 rounded-md font-mono text-xs font-bold uppercase transition flex items-center gap-1.5 border border-slate-300"
                                        >
                                            <Camera className="w-3.5 h-3.5 text-[#E00D42]" />
                                            <span>Upload Store Logo</span>
                                        </button>
                                        <p className="text-[11px] text-slate-500 font-mono">
                                            Square image (JPG, PNG, WEBP up to 3MB)
                                        </p>
                                        {editErrors.logo && (
                                            <p className="text-xs text-rose-600 font-mono">{editErrors.logo}</p>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* Store Name */}
                            <div className="space-y-1">
                                <label className="block text-xs font-mono font-bold uppercase text-slate-700">
                                    Store Display Name
                                </label>
                                <input
                                    type="text"
                                    value={editData.name}
                                    onChange={(e) => setEditData('name', e.target.value)}
                                    className="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-1 focus:ring-[#E00D42] focus:border-[#E00D42] font-sans"
                                    required
                                />
                                {editErrors.name && (
                                    <p className="text-xs text-rose-600 font-mono">{editErrors.name}</p>
                                )}
                            </div>

                            {/* Bio / Description */}
                            <div className="space-y-1">
                                <div className="flex items-center justify-between">
                                    <label className="block text-xs font-mono font-bold uppercase text-slate-700">
                                        Store Bio & Description
                                    </label>
                                    <span className="text-[11px] text-slate-400 font-mono">
                                        {editData.description?.length || 0}/500 chars
                                    </span>
                                </div>
                                <textarea
                                    value={editData.description}
                                    onChange={(e) => setEditData('description', e.target.value)}
                                    rows={3}
                                    maxLength={500}
                                    placeholder="Describe your brand, products, authentic heritage, and commitment to buyers..."
                                    className="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-1 focus:ring-[#E00D42] focus:border-[#E00D42] font-sans"
                                />
                                {editErrors.description && (
                                    <p className="text-xs text-rose-600 font-mono">{editErrors.description}</p>
                                )}
                            </div>

                            {/* City and Address */}
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div className="space-y-1">
                                    <label className="block text-xs font-mono font-bold uppercase text-slate-700">
                                        Origin City / Municipality
                                    </label>
                                    <input
                                        type="text"
                                        value={editData.city}
                                        onChange={(e) => setEditData('city', e.target.value)}
                                        placeholder="e.g. Quezon City, Cebu City"
                                        className="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-1 focus:ring-[#E00D42] focus:border-[#E00D42] font-sans"
                                    />
                                    {editErrors.city && (
                                        <p className="text-xs text-rose-600 font-mono">{editErrors.city}</p>
                                    )}
                                </div>

                                <div className="space-y-1">
                                    <label className="block text-xs font-mono font-bold uppercase text-slate-700">
                                        Store Contact Phone
                                    </label>
                                    <input
                                        type="text"
                                        value={editData.phone}
                                        onChange={(e) => setEditData('phone', e.target.value)}
                                        placeholder="09171234567"
                                        className="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-1 focus:ring-[#E00D42] focus:border-[#E00D42] font-sans"
                                    />
                                    {editErrors.phone && (
                                        <p className="text-xs text-rose-600 font-mono">{editErrors.phone}</p>
                                    )}
                                </div>
                            </div>

                            {/* Footer Actions */}
                            <div className="pt-4 border-t border-slate-200 flex items-center justify-between">
                                <div>
                                    {recentlySuccessful && (
                                        <span className="inline-flex items-center gap-1.5 text-xs text-emerald-600 font-mono font-bold">
                                            <CheckCircle2 className="w-4 h-4" />
                                            <span>Storefront updated successfully.</span>
                                        </span>
                                    )}
                                </div>
                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setEditModalOpen(false)}
                                        className="px-4 py-2 text-xs font-mono font-bold uppercase text-slate-600 hover:text-slate-900 transition"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={editProcessing}
                                        className="px-5 py-2 bg-[#E00D42] hover:bg-[#C20836] text-white rounded-lg font-mono text-xs font-bold uppercase tracking-wider transition shadow-sm disabled:opacity-50 flex items-center gap-1.5"
                                    >
                                        {editProcessing ? (
                                            <span>Saving Changes...</span>
                                        ) : (
                                            <>
                                                <Check className="w-3.5 h-3.5" />
                                                <span>Save Storefront</span>
                                            </>
                                        )}
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </BuyerLayout>
    );
}
