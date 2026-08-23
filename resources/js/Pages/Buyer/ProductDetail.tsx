import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import { Product, Review } from '@/types';
import { 
    Star, 
    Truck, 
    ShieldCheck, 
    ShoppingCart, 
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
    CheckCircle2
} from 'lucide-react';

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

    // Dynamic price calculation based on selected size/variation
    const basePrice = Number(product.price);
    const extraPrice = selectedSize ? selectedSize.extra_price : 0;
    const currentPrice = basePrice + extraPrice;
    
    const baseCompare = product.compare_at_price ? Number(product.compare_at_price) : basePrice * 1.25;
    const currentComparePrice = baseCompare + extraPrice;
    const discountPct = Math.round(((currentComparePrice - currentPrice) / currentComparePrice) * 100);

    const maxAvailableStock = selectedSize ? selectedSize.stock : product.stock;

    const handleQuantityChange = (delta: number) => {
        setQuantity(prev => {
            const next = prev + delta;
            return Math.max(1, Math.min(next, maxAvailableStock));
        });
    };

    const handleAddToCart = (buyNow: boolean = false) => {
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
                    router.visit(route('buyer.cart'));
                }
            },
            onError: () => {
                setIsAdding(false);
            },
        });
    };

    const formatPrice = (amount?: number | string | null) => {
        const numeric = Number(amount || 0);
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2,
        }).format(numeric);
    };

    return (
        <BuyerLayout>
            <Head title={`${product.name} — BagooPH`} />

            <div className="space-y-6">
                
                {/* Breadcrumbs */}
                <div className="flex items-center gap-2 text-xs text-slate-500 font-mono">
                    <Link href={route('buyer.index')} className="hover:text-[#E00D42]">Home</Link>
                    <ChevronRight className="w-3 h-3 text-slate-400" />
                    {product.category && (
                        <>
                            <Link href={route('buyer.index', { category: product.category.slug })} className="hover:text-[#E00D42]">
                                {product.category.name}
                            </Link>
                            <ChevronRight className="w-3 h-3 text-slate-400" />
                        </>
                    )}
                    <span className="text-slate-800 font-bold truncate max-w-md">{product.name}</span>
                </div>

                {/* 1. MAIN PRODUCT STAGE (SHOPEE STYLE 2-COLUMN) */}
                <div className="bg-white rounded-2xl p-6 sm:p-8 shadow-xs border border-slate-100 grid grid-cols-1 lg:grid-cols-12 gap-8">
                    
                    {/* Left: Gallery & Zoom Preview */}
                    <div className="lg:col-span-5 space-y-4">
                        <div className="aspect-square rounded-2xl bg-slate-100 overflow-hidden relative shadow-inner border border-slate-200">
                            <img
                                src={selectedImage}
                                alt={product.name}
                                className="w-full h-full object-cover transition-all duration-300"
                            />
                            <span className="absolute top-3 left-3 px-2 py-1 rounded bg-[#E00D42] text-white font-mono text-[10px] font-black uppercase tracking-wider shadow-sm">
                                100% AUTHENTIC
                            </span>
                        </div>

                        {/* Image Thumbnails */}
                        <div className="flex items-center gap-2 overflow-x-auto pb-1">
                            <button
                                type="button"
                                onClick={() => setSelectedImage(product.featured_image || '')}
                                className={`w-16 h-16 rounded-xl overflow-hidden border-2 transition shrink-0 ${
                                    selectedImage === product.featured_image ? 'border-[#E00D42] shadow-sm' : 'border-slate-200 hover:border-slate-400'
                                }`}
                            >
                                <img src={product.featured_image || ''} alt="" className="w-full h-full object-cover" />
                            </button>

                            {product.images?.map((img) => (
                                <button
                                    key={img.id}
                                    type="button"
                                    onClick={() => setSelectedImage(img.image_url)}
                                    className={`w-16 h-16 rounded-xl overflow-hidden border-2 transition shrink-0 ${
                                        selectedImage === img.image_url ? 'border-[#E00D42] shadow-sm' : 'border-slate-200 hover:border-slate-400'
                                    }`}
                                >
                                    <img src={img.image_url} alt="" className="w-full h-full object-cover" />
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* Right: Product Buy Box & Variations */}
                    <div className="lg:col-span-7 space-y-5 font-sans">
                        
                        {/* Title & Ratings */}
                        <div>
                            <div className="flex items-center gap-2 mb-1.5">
                                <span className="px-2 py-0.5 rounded bg-[#E00D42] text-white font-mono text-[10px] font-black tracking-wider uppercase">
                                    BAGOO MALL
                                </span>
                                <span className="text-xs text-slate-500 font-mono">SKU: {product.sku || 'BGO-PROD'}</span>
                            </div>

                            <h1 className="text-xl sm:text-2xl font-black text-slate-900 leading-snug">
                                {product.name}
                            </h1>

                            {/* Ratings & Sold Stats Row */}
                            <div className="flex items-center gap-4 pt-2 text-xs font-mono">
                                <div className="flex items-center gap-1 text-[#E00D42] font-bold border-b border-[#E00D42] pb-0.5">
                                    <span>{Number(product.rating || 5.0).toFixed(1)}</span>
                                    <div className="flex text-amber-400">
                                        {[...Array(5)].map((_, i) => (
                                            <Star key={i} className="w-3.5 h-3.5 fill-amber-400 text-amber-400" />
                                        ))}
                                    </div>
                                </div>
                                <span className="text-slate-300">|</span>
                                <span className="text-slate-600 font-bold">{product.reviews?.length ?? 142} Ratings</span>
                                <span className="text-slate-300">|</span>
                                <span className="text-slate-600 font-bold">{product.sales_count ?? 310} Sold</span>
                            </div>
                        </div>

                        {/* Price Banner (Shopee Style) */}
                        <div className="p-4 rounded-xl bg-slate-50 border border-slate-100 flex items-baseline gap-3">
                            <span className="text-2xl sm:text-3xl font-black text-[#E00D42]">
                                {formatPrice(currentPrice)}
                            </span>
                            <span className="text-sm text-slate-400 line-through">
                                {formatPrice(currentComparePrice)}
                            </span>
                            <span className="px-2 py-0.5 rounded bg-[#E00D42] text-white font-mono text-xs font-bold shadow-2xs">
                                {discountPct}% OFF
                            </span>
                        </div>

                        {/* Platform Voucher Chip */}
                        <div className="flex items-center gap-3 text-xs font-mono">
                            <span className="text-slate-500 font-bold uppercase">Platform Voucher:</span>
                            <button
                                type="button"
                                onClick={() => {
                                    navigator.clipboard.writeText('BAGOO10');
                                    setCopiedVoucher(true);
                                    setTimeout(() => setCopiedVoucher(false), 2000);
                                }}
                                className="px-2.5 py-1 rounded bg-rose-50 text-[#E00D42] border border-rose-200 font-bold flex items-center gap-1.5 hover:bg-rose-100 transition"
                            >
                                <Tag className="w-3.5 h-3.5" />
                                <span>{copiedVoucher ? 'COPIED ₱200 OFF' : 'CLAIM ₱200 OFF (BAGOO10)'}</span>
                            </button>
                        </div>

                        {/* Shipping Telemetry */}
                        <div className="space-y-1.5 pt-2 border-t border-slate-100 text-xs">
                            <div className="flex items-center gap-3">
                                <span className="text-slate-500 font-bold uppercase font-mono w-24">Shipping:</span>
                                <div className="space-y-0.5">
                                    <p className="font-bold text-slate-800 flex items-center gap-1">
                                        <Truck className="w-4 h-4 text-emerald-600" />
                                        <span>Free Shipping with Bagoo Express (₱0 min spend)</span>
                                    </p>
                                    <p className="text-slate-500 text-[11px] font-mono">
                                        Estimated Doorstep Delivery: <strong>2 - 3 Days</strong>
                                    </p>
                                </div>
                            </div>
                        </div>

                        {/* Color Variations */}
                        <div className="space-y-2 pt-2 border-t border-slate-100">
                            <span className="text-xs text-slate-500 font-bold uppercase font-mono">Color:</span>
                            <div className="flex flex-wrap gap-2">
                                {variations.colors.map((color) => (
                                    <button
                                        key={color.id}
                                        type="button"
                                        onClick={() => setSelectedColor(color)}
                                        className={`px-3.5 py-1.5 rounded-lg border text-xs font-bold transition flex items-center gap-2 ${
                                            selectedColor?.id === color.id
                                                ? 'border-[#E00D42] bg-[#E00D42]/5 text-[#E00D42] shadow-2xs'
                                                : 'border-slate-200 text-slate-700 hover:border-slate-400'
                                        }`}
                                    >
                                        <span 
                                            className="w-3.5 h-3.5 rounded-full border border-black/20"
                                            style={{ backgroundColor: color.hex }}
                                        ></span>
                                        <span>{color.name}</span>
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* Size / Specs Variations */}
                        <div className="space-y-2 pt-2">
                            <span className="text-xs text-slate-500 font-bold uppercase font-mono">Specification / Size:</span>
                            <div className="flex flex-wrap gap-2">
                                {variations.sizes.map((size) => (
                                    <button
                                        key={size.id}
                                        type="button"
                                        onClick={() => setSelectedSize(size)}
                                        className={`px-3.5 py-1.5 rounded-lg border text-xs font-bold transition ${
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

                        {/* Quantity Selector */}
                        <div className="flex items-center gap-6 pt-2">
                            <span className="text-xs text-slate-500 font-bold uppercase font-mono w-24">Quantity:</span>
                            <div className="flex items-center gap-3 font-mono text-xs">
                                <div className="flex items-center border border-slate-300 rounded-lg overflow-hidden">
                                    <button
                                        type="button"
                                        onClick={() => handleQuantityChange(-1)}
                                        disabled={quantity <= 1}
                                        className="px-3 py-1.5 bg-slate-50 hover:bg-slate-100 text-slate-700 disabled:opacity-40"
                                    >
                                        <Minus className="w-3.5 h-3.5" />
                                    </button>
                                    <span className="px-4 py-1.5 font-bold text-slate-900">{quantity}</span>
                                    <button
                                        type="button"
                                        onClick={() => handleQuantityChange(1)}
                                        disabled={quantity >= maxAvailableStock}
                                        className="px-3 py-1.5 bg-slate-50 hover:bg-slate-100 text-slate-700 disabled:opacity-40"
                                    >
                                        <Plus className="w-3.5 h-3.5" />
                                    </button>
                                </div>
                                <span className="text-slate-500 text-[11px]">
                                    {maxAvailableStock} pieces available
                                </span>
                            </div>
                        </div>

                        {/* Action Buttons: Add to Cart & Buy Now */}
                        <div className="pt-4 flex flex-col sm:flex-row gap-3">
                            <button
                                type="button"
                                onClick={() => handleAddToCart(false)}
                                disabled={isAdding || maxAvailableStock <= 0}
                                className={`flex-1 py-3 px-6 rounded-xl font-bold uppercase text-xs tracking-wider transition flex items-center justify-center gap-2 border-2 ${
                                    addedSuccess
                                        ? 'bg-emerald-600 border-emerald-600 text-white'
                                        : 'bg-[#E00D42]/10 border-[#E00D42] text-[#E00D42] hover:bg-[#E00D42] hover:text-white'
                                }`}
                            >
                                {addedSuccess ? (
                                    <>
                                        <Check className="w-4 h-4" />
                                        <span>Added to Cart!</span>
                                    </>
                                ) : (
                                    <>
                                        <ShoppingCart className="w-4 h-4" />
                                        <span>Add to Cart</span>
                                    </>
                                )}
                            </button>

                            <button
                                type="button"
                                onClick={() => handleAddToCart(true)}
                                disabled={isAdding || maxAvailableStock <= 0}
                                className="flex-1 py-3 px-6 rounded-xl bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold uppercase text-xs tracking-wider transition shadow-md flex items-center justify-center gap-2"
                            >
                                <span>Buy Now</span>
                            </button>
                        </div>
                    </div>
                </div>

                {/* 2. VERIFIED STORE CARD (SHOPEE STYLE) */}
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
                                        className="px-3 py-1 bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold rounded-lg transition"
                                    >
                                        View Shop
                                    </Link>
                                    <button
                                        type="button"
                                        className="px-3 py-1 bg-[#E00D42] text-white font-bold rounded-lg transition flex items-center gap-1"
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
                            <div className="flex gap-4 p-2.5 rounded-lg bg-slate-50">
                                <span className="text-slate-400 w-32">Category:</span>
                                <span className="text-slate-800 font-bold">{product.category?.name || 'General'}</span>
                            </div>
                            <div className="flex gap-4 p-2.5 rounded-lg bg-slate-50">
                                <span className="text-slate-400 w-32">Stock:</span>
                                <span className="text-slate-800 font-bold">{product.stock} units in warehouse</span>
                            </div>
                            <div className="flex gap-4 p-2.5 rounded-lg bg-slate-50">
                                <span className="text-slate-400 w-32">Authenticity:</span>
                                <span className="text-emerald-600 font-bold">100% Brand Direct Guarantee</span>
                            </div>
                            <div className="flex gap-4 p-2.5 rounded-lg bg-slate-50">
                                <span className="text-slate-400 w-32">Dispatch Location:</span>
                                <span className="text-slate-800 font-bold">Bagoo Express Dispatch Hub</span>
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

                {/* 4. VERIFIED RATINGS & REVIEWS SECTION */}
                <div className="bg-white rounded-2xl p-6 sm:p-8 shadow-xs border border-slate-100 space-y-6">
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-100">
                        <div>
                            <h3 className="font-black text-slate-900 text-lg uppercase tracking-wider font-mono">
                                Product Ratings & Reviews
                            </h3>
                            <div className="flex items-center gap-2 mt-1">
                                <span className="text-2xl font-black text-[#E00D42]">{Number(product.rating || 5.0).toFixed(1)}</span>
                                <div className="flex text-amber-400">
                                    {[...Array(5)].map((_, i) => (
                                        <Star key={i} className="w-4 h-4 fill-amber-400 text-amber-400" />
                                    ))}
                                </div>
                                <span className="text-xs text-slate-500 font-mono">({product.reviews?.length ?? 142} Verified Reviews)</span>
                            </div>
                        </div>
                    </div>

                    {/* Review Cards */}
                    <div className="space-y-4">
                        {product.reviews && product.reviews.length > 0 ? (
                            product.reviews.map((rev) => (
                                <div key={rev.id} className="p-4 rounded-xl bg-slate-50 border border-slate-100 space-y-2">
                                    <div className="flex items-center justify-between text-xs font-mono">
                                        <span className="font-bold text-slate-900">{rev.buyer?.name || 'Verified Shopper'}</span>
                                        <span className="text-slate-400">{rev.created_at ? new Date(rev.created_at).toLocaleDateString() : 'Recent'}</span>
                                    </div>
                                    <div className="flex text-amber-400">
                                        {[...Array(rev.rating)].map((_, i) => (
                                            <Star key={i} className="w-3 h-3 fill-amber-400 text-amber-400" />
                                        ))}
                                    </div>
                                    <p className="text-xs text-slate-700">{rev.comment}</p>
                                </div>
                            ))
                        ) : (
                            <div className="p-4 rounded-xl bg-slate-50 text-slate-500 text-xs font-mono text-center">
                                ⭐ Be the first verified customer to rate and review this item!
                            </div>
                        )}
                    </div>
                </div>

            </div>
        </BuyerLayout>
    );
}
