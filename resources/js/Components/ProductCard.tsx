import React from 'react';
import { Link } from '@inertiajs/react';
import { Star, Truck, ShoppingBag, Check } from 'lucide-react';
import { Product } from '@/types';

interface ProductCardProps {
    product: Product;
    href?: string;
    onQuickAdd?: (product: Product) => void;
    isAdding?: boolean;
    isSuccess?: boolean;
    actionLabel?: string;
    className?: string;
}

export default function ProductCard({
    product,
    href,
    onQuickAdd,
    isAdding = false,
    isSuccess = false,
    actionLabel = 'Add to Bag',
    className = '',
}: ProductCardProps) {
    const priceNum = Number(product.price);
    const compareNum = product.compare_at_price ? Number(product.compare_at_price) : null;
    const discountPct = compareNum && compareNum > priceNum
        ? Math.round(((compareNum - priceNum) / compareNum) * 100)
        : null;

    // Determine target URL safely
    const targetUrl = href || (
        typeof window !== 'undefined' && (window as any).route
            ? ((window as any).route().has('buyer.products.show') 
                ? route('buyer.products.show', product.slug) 
                : route('products.show', product.slug))
            : `/products/${product.slug}`
    );

    const formatPrice = (amount?: number | string | null) => {
        const numeric = Number(amount || 0);
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2,
        }).format(numeric);
    };

    const ratingVal = Number(product.rating || 5.0).toFixed(1);
    const salesCountVal = product.sales_count ?? 0;
    const storeLocation = product.shop?.city || 'Metro Manila';

    return (
        <Link
            href={targetUrl}
            className={`group bg-white rounded-xl border border-slate-200/90 overflow-hidden hover:shadow-xl hover:border-slate-300 transition-all duration-300 flex flex-col justify-between shadow-2xs ${className}`}
        >
            {/* 1. Product Image Container with Discount Badge at bottom of image */}
            <div className="relative aspect-square bg-slate-100 overflow-hidden">
                {product.featured_image ? (
                    <img
                        src={product.featured_image}
                        alt={product.name}
                        loading="lazy"
                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 ease-out"
                    />
                ) : (
                    <div className="w-full h-full flex flex-col items-center justify-center bg-slate-50 text-slate-300">
                        <ShoppingBag className="w-8 h-8 stroke-[1.25]" />
                        <span className="text-[9px] font-mono text-slate-400 mt-1 uppercase">No Preview</span>
                    </div>
                )}

                {/* Discount Tag inside the image at the bottom */}
                {discountPct && (
                    <div className="absolute bottom-2 right-2 pointer-events-none">
                        <span className="px-1.5 py-0.5 rounded bg-amber-400 text-slate-950 font-mono text-[9px] font-black shadow-2xs">
                            -{discountPct}%
                        </span>
                    </div>
                )}
            </div>

            {/* 2. Compact Info Area */}
            <div className="p-2.5 sm:p-3 space-y-1.5 font-sans flex-1 flex flex-col justify-between">
                <div className="space-y-1">
                    {/* Free Delivery Tag below image */}
                    <div className="flex items-center gap-1.5 flex-wrap">
                        <span className="px-1.5 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200/60 font-mono text-[8px] font-bold uppercase flex items-center gap-1">
                            <Truck className="w-2.5 h-2.5" />
                            <span>Free Delivery</span>
                        </span>
                    </div>

                    {/* Product Name */}
                    <h4 className="font-bold text-xs sm:text-sm text-slate-800 group-hover:text-[#E00D42] transition-colors line-clamp-2 leading-tight">
                        {product.name}
                    </h4>

                    {/* Price Block */}
                    <div className="flex items-baseline gap-1.5 pt-0.5 font-mono">
                        <span className="text-sm sm:text-base font-black text-[#E00D42]">
                            {formatPrice(product.price)}
                        </span>
                        {compareNum && compareNum > priceNum && (
                            <span className="text-[10px] text-slate-400 line-through">
                                {formatPrice(product.compare_at_price)}
                            </span>
                        )}
                    </div>
                </div>

                {/* Footer: Rating, Sold count & Store Location */}
                <div className="pt-1.5 border-t border-slate-100 flex items-center justify-between text-[10px]">
                    <div className="flex items-center gap-1.5">
                        <div className="flex items-center gap-0.5 text-amber-500 font-bold font-mono">
                            <Star className="w-3 h-3 fill-amber-400 text-amber-400" />
                            <span>{ratingVal}</span>
                        </div>
                        <span className="text-slate-400 font-mono">
                            {salesCountVal > 0 ? `${salesCountVal} sold` : '0 sold'}
                        </span>
                    </div>

                    {/* Store Location */}
                    <span
                        className="text-slate-400 font-sans truncate max-w-[85px] sm:max-w-[105px] text-right"
                        title={storeLocation}
                    >
                        {storeLocation}
                    </span>
                </div>
            </div>

            {/* Optional Quick Add Action (only rendered if explicitly passed) */}
            {onQuickAdd && (
                <div className="px-2.5 pb-2.5 pt-0">
                    <button
                        type="button"
                        onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            onQuickAdd(product);
                        }}
                        disabled={isAdding || product.stock === 0}
                        className={`w-full py-1.5 px-2 rounded-lg font-mono text-[9px] font-bold uppercase tracking-wider transition flex items-center justify-center gap-1 cursor-pointer shadow-2xs ${
                            isSuccess
                                ? 'bg-emerald-600 text-white'
                                : product.stock === 0
                                    ? 'bg-slate-100 text-slate-400 cursor-not-allowed'
                                    : 'bg-slate-900 hover:bg-[#E00D42] text-white active:scale-[0.98]'
                        }`}
                    >
                        {isSuccess ? (
                            <>
                                <Check className="w-3 h-3" />
                                <span>Added</span>
                            </>
                        ) : isAdding ? (
                            <span>Adding...</span>
                        ) : product.stock === 0 ? (
                            <span>Out of Stock</span>
                        ) : (
                            <>
                                <ShoppingBag className="w-3 h-3" />
                                <span>{actionLabel}</span>
                            </>
                        )}
                    </button>
                </div>
            )}
        </Link>
    );
}
