import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import { Product, Review } from '@/types';
import { 
    Star, 
    Truck, 
    ShieldCheck, 
    ShoppingBag, 
    Heart, 
    Share2, 
    Store, 
    MessageSquare, 
    Check, 
    Plus, 
    Minus, 
    Tag, 
    Clock, 
    ChevronRight,
    ArrowLeft,
    Sparkles,
    CheckCircle2,
    Camera,
    Image as ImageIcon,
    X,
    ThumbsUp
} from 'lucide-react';

import { useAmbientColor } from '@/Hooks/useAmbientColor';

interface VariationColor {
    id: string;
    name: string;
    hex: string;
    in_stock: boolean;
}

interface VariationSize {
    id: string;
    name: string;
    extra_price: number;
    stock: number;
}

interface Variations {
    colors: VariationColor[];
    sizes: VariationSize[];
}

interface ShopStats {
    rating: number | string;
    products_count: number;
    response_rate: string;
    response_time: string;
    joined: string;
    is_mall: boolean;
}

interface Props {
    product: Product & {
        reviews?: Review[];
    };
    variations: Variations;
    relatedProducts: Product[];
    shopStats: ShopStats;
}

export default function BuyerProductDetail({
    product,
    variations,
    relatedProducts,
    shopStats,
}: Props) {
    const [selectedColor, setSelectedColor] = useState<VariationColor>(variations.colors[0] || null);
    const [selectedSize, setSelectedSize] = useState<VariationSize>(variations.sizes[0] || null);
    const [quantity, setQuantity] = useState(1);
    const [selectedImage, setSelectedImage] = useState(product.featured_image || '');
    const [isAdding, setIsAdding] = useState(false);
    const [addedSuccess, setAddedSuccess] = useState(false);
    const [copiedVoucher, setCopiedVoucher] = useState(false);
    const [lightboxImage, setLightboxImage] = useState<string | null>(null);

    // Dynamic Ambient Color Extraction
    const { ambientGlow, subtleBackground, accentColor } = useAmbientColor(
        selectedImage || product.featured_image,
        selectedColor?.hex,
        0.20
    );

    // Dynamic price calculation based on selected size/variation
    const basePrice = Number(product.price);
    const extraPrice = selectedSize ? selectedSize.extra_price : 0;
    const currentPrice = basePrice + extraPrice;
    
    const baseCompare = product.compare_at_price ? Number(product.compare_at_price) : basePrice * 1.25;
    const currentComparePrice = baseCompare + extraPrice;
    const discountPct = Math.round(((currentComparePrice - currentPrice) / currentComparePrice) * 100);

    const maxAvailableStock = selectedSize ? selectedSize.stock : product.stock;

    // Collect all review customer photos for photo gallery
    const allCustomerPhotos = (product.reviews || []).flatMap(r => r.images || []).filter(Boolean);

    const handleQuantityChange = (delta: number) => {
        setQuantity(prev => {
            const next = prev + delta;
            return Math.max(1, Math.min(next, maxAvailableStock));
        });
    };

    const handleAddToBag = (buyNow: boolean = false) => {
        setIsAdding(true);

        router.post(route('cart.store'), {
            product_id: product.id,
            quantity: quantity,
            color: selectedColor?.name,
            size: selectedSize?.name,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setIsAdding(false);
                setAddedSuccess(true);
                setTimeout(() => setAddedSuccess(false), 2500);

                if (buyNow) {
                    router.get(route('checkout.index'));
                }
            },
            onError: () => {
                setIsAdding(false);
            },
        });
    };

    const formatPrice = (val: string | number | undefined | null) => {
        const num = Number(val || 0);
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(num);
    };

    const copyShopVoucher = () => {
        navigator.clipboard.writeText('BAGOO20');
        setCopiedVoucher(true);
        setTimeout(() => setCopiedVoucher(false), 3000);
    };

    // Gallery images combining primary + multi-angle shots
    const galleryImages = [
        product.featured_image,
        ...(product.images?.map(img => img.image_url) || [
            'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=800&q=80',
            'https://images.unsplash.com/photo-1581605405669-fcdf81165afa?auto=format&fit=crop&w=800&q=80',
            'https://images.unsplash.com/photo-1622560480605-d83c853bc5c3?auto=format&fit=crop&w=800&q=80',
        ])
    ].filter(Boolean) as string[];

    return (
        <BuyerLayout>
            <Head title={`${product.name} — BagooPH`} />

            {/* Lightbox Modal for Customer Photo Zoom */}
            {lightboxImage && (
                <div 
                    className="fixed inset-0 z-50 bg-black/90 flex items-center justify-center p-4 backdrop-blur-sm animate-fade-in"
                    onClick={() => setLightboxImage(null)}
                >
                    <div className="relative max-w-3xl max-h-[85vh] overflow-hidden rounded-2xl bg-black">
                        <button
                            onClick={() => setLightboxImage(null)}
                            className="absolute top-3 right-3 w-8 h-8 rounded-full bg-white/20 hover:bg-white/40 text-white flex items-center justify-center z-10 transition"
                        >
                            <X className="w-5 h-5" />
                        </button>
                        <img 
                            src={lightboxImage} 
                            alt="Customer review photo preview" 
                            className="w-full h-full object-contain max-h-[80vh]" 
                        />
                    </div>
                </div>
            )}

            <div className="relative space-y-6 max-w-7xl mx-auto font-sans">
                
                {/* Dynamic Ethereal Ambient Glow Aura */}
                <div 
                    className="absolute -top-12 -left-12 -right-12 h-[520px] pointer-events-none rounded-3xl blur-3xl opacity-90 transition-all duration-700 ease-out z-0"
                    style={{ background: ambientGlow }}
                />

                {/* Breadcrumbs Navigation */}
                <nav className="relative z-10 flex items-center gap-2 text-xs font-mono text-slate-500">
                    <Link href={route('buyer.index')} className="hover:text-[#E00D42] transition flex items-center gap-1">
                        <ArrowLeft className="w-3.5 h-3.5" />
                        <span>Marketplace Home</span>
                    </Link>
                    <span>/</span>
                    <span className="text-slate-700">{product.category?.name || 'Catalog'}</span>
                    <span>/</span>
                    <span className="text-slate-900 font-bold truncate max-w-xs">{product.name}</span>
                </nav>

                {/* 1. MASTER PRODUCT STAGE (GALLERY + VARIATION ENGINE) */}
                <div className="relative z-10 bg-white/95 backdrop-blur-md rounded-3xl p-6 sm:p-8 shadow-md border border-slate-200/80 grid grid-cols-1 lg:grid-cols-12 gap-8">
                    
                    {/* Left Column: Multi-Angle Interactive Gallery */}
                    <div className="lg:col-span-5 space-y-4">
                        {/* Primary Viewport Image */}
                        <div className="aspect-square rounded-2xl bg-slate-50 border border-slate-200 overflow-hidden relative group">
                            <img
                                src={selectedImage || galleryImages[0]}
                                alt={product.name}
                                className="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
                            />
                            {discountPct > 0 && (
                                <span className="absolute top-3 right-3 px-2 py-1 rounded bg-[#E00D42] text-white font-mono text-xs font-black shadow-xs">
                                    -{discountPct}% OFF
                                </span>
                            )}
                            <span className="absolute top-3 left-3 px-2 py-1 rounded bg-black/80 backdrop-blur-xs text-white font-mono text-[10px] font-bold uppercase tracking-wider">
                                100% AUTHENTIC
                            </span>
                        </div>

                        {/* Thumbnail Selector Strip */}
                        <div className="flex items-center gap-2.5 overflow-x-auto pb-1 scrollbar-none">
                            {galleryImages.map((imgUrl, index) => (
                                <button
                                    key={index}
                                    type="button"
                                    onClick={() => setSelectedImage(imgUrl)}
                                    className={`w-16 h-16 rounded-xl overflow-hidden border-2 transition shrink-0 ${
                                        selectedImage === imgUrl ? 'border-[#E00D42] shadow-xs' : 'border-slate-200 hover:border-slate-400'
                                    }`}
                                >
                                    <img src={imgUrl} alt="" className="w-full h-full object-cover" />
                                </button>
                            ))}
                        </div>

                        {/* Share & Wishlist Bar */}
                        <div className="flex items-center justify-between pt-2 text-xs font-mono text-slate-500 border-t border-slate-100">
                            <div className="flex items-center gap-3">
                                <span>Share:</span>
                                <button type="button" className="hover:text-[#E00D42] transition"><Share2 className="w-4 h-4" /></button>
                                <button type="button" className="hover:text-[#E00D42] transition"><Heart className="w-4 h-4" /></button>
                            </div>
                            <div className="flex items-center gap-1 text-[#E00D42] font-bold">
                                <ShieldCheck className="w-4 h-4" />
                                <span>BagooPH Buyer Protection</span>
                            </div>
                        </div>
                    </div>

                    {/* Right Column: Title, Live Variation Selectors, Pricing & CTA */}
                    <div className="lg:col-span-7 space-y-6">
                        
                        {/* Title & Ratings Overview */}
                        <div className="space-y-2">
                            <div className="flex items-center gap-2">
                                <span className="px-2 py-0.5 rounded bg-[#E00D42] text-white font-mono text-[10px] font-black uppercase tracking-wider">
                                    MALL VERIFIED
                                </span>
                                <span className="text-xs text-slate-400 font-mono">SKU: {product.sku || 'BGO-7721-PH'}</span>
                            </div>

                            <h1 className="text-xl sm:text-2xl font-black text-slate-900 tracking-tight leading-snug">
                                {product.name}
                            </h1>

                            <div className="flex items-center gap-4 text-xs font-mono pt-1">
                                <div className="flex items-center gap-1 text-[#E00D42] font-bold">
                                    <span className="underline">{Number(product.rating || 5.0).toFixed(1)}</span>
                                    <div className="flex">
                                        {[...Array(5)].map((_, i) => (
                                            <Star key={i} className="w-3.5 h-3.5 fill-[#E00D42] text-[#E00D42]" />
                                        ))}
                                    </div>
                                </div>
                                <span className="text-slate-300">|</span>
                                <span className="text-slate-600 font-bold">{product.reviews?.length ?? 142} Ratings</span>
                                <span className="text-slate-300">|</span>
                                <span className="text-slate-600 font-bold">{product.sales_count ?? 120} Sold</span>
                            </div>
                        </div>

                        {/* High-Contrast Pricing Strip */}
                        <div className="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 flex flex-wrap items-baseline gap-3">
                            <span className="text-2xl sm:text-3xl font-black text-[#E00D42] font-sans">
                                {formatPrice(currentPrice)}
                            </span>
                            {discountPct > 0 && (
                                <span className="text-sm font-mono text-slate-400 line-through">
                                    {formatPrice(currentComparePrice)}
                                </span>
                            )}
                            <span className="px-2 py-0.5 rounded bg-rose-100 text-[#E00D42] font-mono text-xs font-bold uppercase">
                                Lowest Price Guaranteed
                            </span>
                        </div>

                        {/* Store Voucher Strip */}
                        <div className="p-3 rounded-xl bg-amber-50/80 border border-amber-200/60 flex items-center justify-between text-xs font-mono">
                            <div className="flex items-center gap-2 text-amber-950 font-bold">
                                <Tag className="w-4 h-4 text-amber-600" />
                                <span>₱100 OFF VOUCHER (Min. Spend ₱1,500)</span>
                            </div>
                            <button
                                type="button"
                                onClick={copyShopVoucher}
                                className="px-3 py-1 bg-amber-500 hover:bg-amber-600 text-white font-bold rounded-lg transition uppercase text-[11px]"
                            >
                                {copiedVoucher ? 'Claimed ✓' : 'Claim'}
                            </button>
                        </div>

                        {/* Delivery Guarantee */}
                        <div className="space-y-2 font-mono text-xs text-slate-600 border-y border-slate-100 py-3">
                            <div className="flex items-center gap-3">
                                <Truck className="w-4 h-4 text-emerald-600" />
                                <span><strong>Fast Doorstep Dispatch:</strong> Guaranteed delivery in 2-4 business days</span>
                            </div>
                            <div className="flex items-center gap-3">
                                <ShieldCheck className="w-4 h-4 text-[#E00D42]" />
                                <span><strong>COD Available:</strong> Cash on Delivery / GCash / Maya nationwide</span>
                            </div>
                        </div>

                        {/* 🎨 VARIATION SELECTOR 1: COLOR */}
                        {variations.colors.length > 0 && (
                            <div className="space-y-2">
                                <div className="flex items-center justify-between text-xs font-mono">
                                    <span className="text-slate-500 font-bold uppercase">Color Edition:</span>
                                    <span className="text-slate-900 font-black">{selectedColor?.name}</span>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {variations.colors.map((color) => (
                                        <button
                                            key={color.id}
                                            type="button"
                                            onClick={() => setSelectedColor(color)}
                                            className={`px-3.5 py-2 rounded-xl border flex items-center gap-2 text-xs font-bold transition ${
                                                selectedColor?.id === color.id
                                                    ? 'border-[#E00D42] bg-[#E00D42]/5 text-[#E00D42] shadow-2xs'
                                                    : 'border-slate-200 text-slate-700 hover:border-slate-400'
                                            }`}
                                        >
                                            <span className="w-3 h-3 rounded-full border border-black/20" style={{ backgroundColor: color.hex }}></span>
                                            <span>{color.name}</span>
                                            {selectedColor?.id === color.id && <Check className="w-3 h-3" />}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* 📏 VARIATION SELECTOR 2: SIZE & SPECS */}
                        {variations.sizes.length > 0 && (
                            <div className="space-y-2">
                                <div className="flex items-center justify-between text-xs font-mono">
                                    <span className="text-slate-500 font-bold uppercase">Specification / Size:</span>
                                    <span className="text-slate-900 font-black">{selectedSize?.name}</span>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {variations.sizes.map((size) => (
                                        <button
                                            key={size.id}
                                            type="button"
                                            onClick={() => setSelectedSize(size)}
                                            className={`px-3.5 py-2 rounded-xl border text-xs font-bold transition ${
                                                selectedSize?.id === size.id
                                                    ? 'border-[#E00D42] bg-[#E00D42]/5 text-[#E00D42] shadow-2xs'
                                                    : 'border-slate-200 text-slate-700 hover:border-slate-400'
                                            }`}
                                        >
                                            <span>{size.name}</span>
                                            {size.extra_price > 0 && (
                                                <span className="text-[10px] text-slate-400 ml-1">
                                                    (+{formatPrice(size.extra_price)})
                                                </span>
                                            )}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Quantity Selector */}
                        <div className="flex items-center gap-6 pt-2">
                            <span className="text-xs text-slate-500 font-bold uppercase font-mono w-24">Quantity:</span>
                            <div className="flex items-center gap-3 font-mono text-xs">
                                <div className="flex items-center border border-slate-300 rounded-xl overflow-hidden bg-white">
                                    <button
                                        type="button"
                                        onClick={() => handleQuantityChange(-1)}
                                        disabled={quantity <= 1}
                                        className="px-3.5 py-2 bg-slate-50 hover:bg-slate-100 text-slate-700 disabled:opacity-40"
                                    >
                                        <Minus className="w-3.5 h-3.5" />
                                    </button>
                                    <span className="px-4 py-2 font-bold text-slate-900">{quantity}</span>
                                    <button
                                        type="button"
                                        onClick={() => handleQuantityChange(1)}
                                        disabled={quantity >= maxAvailableStock}
                                        className="px-3.5 py-2 bg-slate-50 hover:bg-slate-100 text-slate-700 disabled:opacity-40"
                                    >
                                        <Plus className="w-3.5 h-3.5" />
                                    </button>
                                </div>
                                <span className="text-slate-500 text-[11px]">
                                    {maxAvailableStock} items available in dispatch hub
                                </span>
                            </div>
                        </div>

                        {/* Action Buttons: Add to Bag & Buy Now */}
                        <div className="pt-4 flex flex-col sm:flex-row gap-3">
                            <button
                                type="button"
                                onClick={() => handleAddToBag(false)}
                                disabled={isAdding || maxAvailableStock <= 0}
                                className={`flex-1 py-3.5 px-6 rounded-xl font-bold uppercase text-xs tracking-wider transition flex items-center justify-center gap-2 border-2 ${
                                    addedSuccess
                                        ? 'bg-emerald-600 border-emerald-600 text-white'
                                        : 'bg-[#E00D42]/10 border-[#E00D42] text-[#E00D42] hover:bg-[#E00D42] hover:text-white'
                                }`}
                            >
                                {addedSuccess ? (
                                    <>
                                        <Check className="w-4 h-4" />
                                        <span>Added to Bag!</span>
                                    </>
                                ) : (
                                    <>
                                        <ShoppingBag className="w-4 h-4" />
                                        <span>Add to Bag</span>
                                    </>
                                )}
                            </button>

                            <button
                                type="button"
                                onClick={() => handleAddToBag(true)}
                                disabled={isAdding || maxAvailableStock <= 0}
                                className="flex-1 py-3.5 px-6 rounded-xl bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold uppercase text-xs tracking-wider transition shadow-md flex items-center justify-center gap-2"
                            >
                                <span>Buy Now</span>
                            </button>
                        </div>
                    </div>
                </div>

                {/* 2. VERIFIED STORE CARD */}
                {product.shop && (
                    <div className="bg-white rounded-2xl p-6 shadow-xs border border-slate-100 flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
                        <div className="flex items-center gap-4">
                            <div className="w-14 h-14 rounded-2xl bg-slate-100 overflow-hidden border border-slate-200 shrink-0">
                                <img src={product.shop.logo || 'https://images.unsplash.com/photo-1544816155-12df9643f363?auto=format&fit=crop&w=300&q=80'} alt="" className="w-full h-full object-cover" />
                            </div>
                            <div>
                                <div className="flex items-center gap-2">
                                    <h3 className="font-bold text-slate-900 text-base">{product.shop.name}</h3>
                                    <span className="px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-700 text-[10px] font-bold font-mono">
                                        PREFERRED
                                    </span>
                                </div>
                                <p className="text-xs text-slate-500 font-mono mt-0.5">Active 2 mins ago • Metro Manila, PH</p>
                                <div className="flex items-center gap-2 mt-2 font-mono text-xs">
                                    <Link
                                        href={route('shop.show', product.shop.slug)}
                                        className="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold rounded-lg transition"
                                    >
                                        View Shop
                                    </Link>
                                    <button
                                        type="button"
                                        className="px-3 py-1.5 bg-[#E00D42] text-white font-bold rounded-lg transition flex items-center gap-1"
                                    >
                                        <MessageSquare className="w-3.5 h-3.5" />
                                        <span>Chat Now</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        {/* Store Metrics */}
                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-6 font-mono text-xs text-slate-600 border-t md:border-t-0 md:border-l border-slate-100 pt-4 md:pt-0 md:pl-8">
                            <div>
                                <span className="text-slate-400 block text-[10px] uppercase">Ratings</span>
                                <span className="font-bold text-[#E00D42] text-sm">{shopStats.rating} (5.0 Stars)</span>
                            </div>
                            <div>
                                <span className="text-slate-400 block text-[10px] uppercase">Products</span>
                                <span className="font-bold text-slate-800 text-sm">{shopStats.products_count} Items</span>
                            </div>
                            <div>
                                <span className="text-slate-400 block text-[10px] uppercase">Response Rate</span>
                                <span className="font-bold text-emerald-600 text-sm">{shopStats.response_rate}</span>
                            </div>
                        </div>
                    </div>
                )}

                {/* 3. PRODUCT DESCRIPTION & SPECIFICATIONS */}
                <div className="bg-white rounded-2xl p-6 sm:p-8 shadow-xs border border-slate-100 space-y-6 font-sans">
                    <div>
                        <h3 className="font-black text-slate-900 text-lg mb-4 uppercase tracking-wider font-mono">
                            Product Specifications
                        </h3>
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 font-mono text-xs">
                            <div className="flex gap-4 p-3 rounded-xl bg-slate-50">
                                <span className="text-slate-400 w-32 font-bold">Category:</span>
                                <span className="text-slate-800 font-bold">{product.category?.name || 'General'}</span>
                            </div>
                            <div className="flex gap-4 p-3 rounded-xl bg-slate-50">
                                <span className="text-slate-400 w-32 font-bold">Warehouse Stock:</span>
                                <span className="text-slate-800 font-bold">{product.stock} units available</span>
                            </div>
                            <div className="flex gap-4 p-3 rounded-xl bg-slate-50">
                                <span className="text-slate-400 w-32 font-bold">Authenticity:</span>
                                <span className="text-emerald-600 font-bold">100% Brand Direct Guarantee</span>
                            </div>
                            <div className="flex gap-4 p-3 rounded-xl bg-slate-50">
                                <span className="text-slate-400 w-32 font-bold">Dispatch Hub:</span>
                                <span className="text-slate-800 font-bold">Bagoo Express Express Hub</span>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 className="font-black text-slate-900 text-lg mb-2 uppercase tracking-wider font-mono">
                            Product Description
                        </h3>
                        <p className="text-sm text-slate-700 leading-relaxed whitespace-pre-line">
                            {product.description}
                        </p>
                    </div>
                </div>

                {/* 4. VERIFIED RATINGS & CUSTOMER PHOTO GALLERY */}
                <div className="bg-white rounded-2xl p-6 sm:p-8 shadow-xs border border-slate-100 space-y-6">
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-100">
                        <div>
                            <h3 className="font-black text-slate-900 text-lg uppercase tracking-wider font-mono">
                                Verified Customer Reviews & Photos
                            </h3>
                            <div className="flex items-center gap-2 mt-1">
                                <span className="text-2xl font-black text-[#E00D42]">{Number(product.rating || 5.0).toFixed(1)}</span>
                                <div className="flex text-amber-400">
                                    {[...Array(5)].map((_, i) => (
                                        <Star key={i} className="w-4 h-4 fill-amber-400 text-amber-400" />
                                    ))}
                                </div>
                                <span className="text-xs text-slate-500 font-mono">({product.reviews?.length ?? 0} Verified Reviews)</span>
                            </div>
                        </div>
                    </div>

                    {/* Customer Photo Gallery Thumbnails */}
                    {allCustomerPhotos.length > 0 && (
                        <div className="space-y-3 p-4 rounded-2xl bg-slate-50 border border-slate-200/80">
                            <div className="flex items-center gap-2 font-mono text-xs font-bold text-slate-800 uppercase">
                                <Camera className="w-4 h-4 text-[#E00D42]" />
                                <span>Customer Submitted Photos ({allCustomerPhotos.length})</span>
                            </div>
                            <div className="flex gap-2.5 overflow-x-auto pb-1 scrollbar-none">
                                {allCustomerPhotos.map((photoUrl, idx) => (
                                    <button
                                        key={idx}
                                        type="button"
                                        onClick={() => setLightboxImage(photoUrl)}
                                        className="w-20 h-20 rounded-xl overflow-hidden border-2 border-transparent hover:border-[#E00D42] shadow-xs shrink-0 group relative transition"
                                    >
                                        <img src={photoUrl} alt="Review photo" className="w-full h-full object-cover group-hover:scale-105 transition" />
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Individual Review Cards */}
                    <div className="space-y-4">
                        {product.reviews && product.reviews.length > 0 ? (
                            product.reviews.map((rev) => (
                                <div key={rev.id} className="p-5 rounded-2xl bg-slate-50 border border-slate-100 space-y-3">
                                    <div className="flex items-center justify-between text-xs font-mono">
                                        <div className="flex items-center gap-2.5">
                                            <div className="w-7 h-7 rounded-lg bg-slate-900 text-white font-bold text-xs flex items-center justify-center">
                                                {(rev.buyer?.name || 'V').charAt(0).toUpperCase()}
                                            </div>
                                            <div>
                                                <span className="font-bold text-slate-900">{rev.buyer?.name || 'Verified Shopper'}</span>
                                                <span className="text-[10px] text-emerald-600 font-bold ml-2">✓ Verified Purchase</span>
                                            </div>
                                        </div>
                                        <span className="text-slate-400">{rev.created_at ? new Date(rev.created_at).toLocaleDateString() : 'Recent'}</span>
                                    </div>

                                    {/* Star Rating */}
                                    <div className="flex text-amber-400">
                                        {[...Array(rev.rating)].map((_, i) => (
                                            <Star key={i} className="w-3.5 h-3.5 fill-amber-400 text-amber-400" />
                                        ))}
                                    </div>

                                    {/* Review Comment */}
                                    <p className="text-xs text-slate-700 leading-relaxed">{rev.comment}</p>

                                    {/* Attached Review Photos */}
                                    {rev.images && rev.images.length > 0 && (
                                        <div className="flex items-center gap-2 pt-1">
                                            {rev.images.map((imgUrl, imgIdx) => (
                                                <button
                                                    key={imgIdx}
                                                    type="button"
                                                    onClick={() => setLightboxImage(imgUrl)}
                                                    className="w-16 h-16 rounded-xl overflow-hidden border border-slate-200 hover:border-[#E00D42] transition"
                                                >
                                                    <img src={imgUrl} alt="Review attachment" className="w-full h-full object-cover" />
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            ))
                        ) : (
                            <div className="p-8 rounded-2xl bg-slate-50 text-slate-500 text-xs font-mono text-center space-y-2">
                                <Sparkles className="w-6 h-6 text-[#E00D42] mx-auto" />
                                <p className="font-bold text-slate-800">No customer reviews yet</p>
                                <p>Be the first verified customer to rate this item and upload photo proofs!</p>
                            </div>
                        )}
                    </div>
                </div>

            </div>
        </BuyerLayout>
    );
}
