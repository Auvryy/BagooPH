import React from 'react';
import { Link } from '@inertiajs/react';
import { Star, Truck, ShoppingBag, ShieldCheck, Check } from 'lucide-react';
import { Product } from '@/types';

interface ProductCardProps {
    product: Product;
    href?: string;
    showBadges?: boolean;
    badgeLabel?: string;
    onQuickAdd?: (product: Product) => void;
    isAdding?: boolean;
    isSuccess?: boolean;
    actionLabel?: string;
    className?: string;
}

export default function ProductCard({
    product,
    href,
    showBadges = true,
    badgeLabel = 'MALL',
    onQuickAdd,
    isAdding = false,
    isSuccess = false,
    actionLabel = 'Add to Cart',
    className = '',
}: ProductCardProps) {
    const priceNum = Number(product.price);
    const compareNum = product.compare_at_price ? Number(product.compare_at_price) : null;
    const discountPct = compareNum && compareNum > priceNum
        ? Math.round(((compareNum - priceNum) / compareNum) * 100)
        : null;

    // Determine target URL safely:
    // If explicit href is provided, use it.
    // Otherwise fallback to buyer.products.show if available, or products.show.
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
    const categoryName = product.category?.name || 'General';

    return (
        <div
            className={`group relative bg-white rounded-xl sm:rounded-2xl border border-slate-200/90 overflow-hidden flex flex-col justify-between hover:shadow-xl hover:border-slate-300 transition-all duration-300 shadow-2xs ${className}`}
        >
            {/* Top Image & Overlay Badges Container */}
            <Link href={targetUrl} className="block relative aspect-square bg-slate-100 overflow-hidden">
                {product.featured_image ? (
                    <img
                        src={product.featured_image}
                        alt={product.name}
                        loading="lazy"
                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 ease-out"
                    />
                ) : (
                    <div className="w-full h-full flex flex-col items-center justify-center bg-slate-50 text-slate-300">
                        <ShoppingBag className="w-10 h-10 stroke-[1.25]" />
                        <span className="text-[10px] font-mono text-slate-400 mt-1 uppercase">No Preview</span>
                    </div>
                )}

                {/* Top Badges */}
                {showBadges && (
                    <div className="absolute top-2.5 left-2.5 right-2.5 flex items-center justify-between gap-1 pointer-events-none">
                        <div className="flex items-center gap-1">
                            <span className="px-1.5 py-0.5 rounded bg-[#E00D42] text-white font-mono text-[9px] font-black tracking-wider shadow-2xs">
                                {badgeLabel}
                            </span>
                            {product.shop && (
                                <span className="hidden sm:inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded bg-slate-900/80 backdrop-blur-xs text-white font-mono text-[8px] font-bold uppercase tracking-wider">
                                    <ShieldCheck className="w-2.5 h-2.5 text-emerald-400" />
                                    <span>Verified</span>
                                </span>
                            )}
                        </div>

                        {discountPct && (
                            <span className="px-1.5 py-0.5 rounded bg-amber-400 text-slate-950 font-mono text-[9px] sm:text-[10px] font-black shadow-2xs">
                                -{discountPct}%
                            </span>
                        )}
                    </div>
                )}

                {/* Free Delivery Tag */}
                <div className="absolute bottom-2.5 left-2.5 pointer-events-none">
                    <span className="px-1.5 py-0.5 rounded bg-emerald-600/90 backdrop-blur-xs text-white font-mono text-[8px] font-bold uppercase flex items-center gap-1 shadow-2xs">
                        <Truck className="w-2.5 h-2.5" />
                        <span>Free Delivery</span>
                    </span>
                </div>
            </Link>

            {/* Content Details Area */}
            <div className="p-3 sm:p-3.5 space-y-2 flex-1 flex flex-col justify-between font-sans">
                <div className="space-y-1">
                    {/* Micro Category / Shop Tag */}
                    <div className="flex items-center justify-between text-[10px] font-mono text-slate-400 uppercase tracking-wider">
                        <span className="truncate max-w-[130px]">{product.shop?.name || categoryName}</span>
                        {product.stock !== undefined && product.stock <= 10 && product.stock > 0 && (
                            <span className="text-amber-600 font-bold shrink-0">Low: {product.stock} left</span>
                        )}
                    </div>

                    {/* Product Title */}
                    <Link href={targetUrl} className="block">
                        <h4 className="font-bold text-xs sm:text-sm text-slate-900 group-hover:text-[#E00D42] transition-colors line-clamp-2 leading-snug">
                            {product.name}
                        </h4>
                    </Link>

                    {/* Price Block */}
                    <div className="flex items-baseline gap-1.5 pt-1 font-mono">
                        <span className="text-sm sm:text-base font-black text-[#E00D42]">
                            {formatPrice(product.price)}
                        </span>
                        {compareNum && compareNum > priceNum && (
                            <span className="text-[11px] text-slate-400 line-through">
                                {formatPrice(product.compare_at_price)}
                            </span>
                        )}
                    </div>
                </div>

                {/* Rating & Sales Velocity Footer */}
                <div className="pt-2 border-t border-slate-100 flex items-center justify-between text-[10px] sm:text-[11px]">
                    <div className="flex items-center gap-1 text-amber-500 font-bold font-mono">
                        <Star className="w-3 h-3 fill-amber-400 text-amber-400" />
                        <span>{ratingVal}</span>
                    </div>

                    <span className="text-slate-400 font-mono">
                        {salesCountVal > 0 ? `${salesCountVal} sold` : 'New arrival'}
                    </span>
                </div>
            </div>

            {/* Quick Add Action (Optional) */}
            {onQuickAdd && (
                <div className="px-3 pb-3 pt-0">
                    <button
                        type="button"
                        onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            onQuickAdd(product);
                        }}
                        disabled={isAdding || product.stock === 0}
                        className={`w-full py-2 px-2.5 rounded-lg font-mono text-[10px] font-bold uppercase tracking-wider transition flex items-center justify-center gap-1.5 cursor-pointer shadow-2xs ${
                            isSuccess
                                ? 'bg-emerald-600 text-white'
                                : product.stock === 0
                                    ? 'bg-slate-100 text-slate-400 cursor-not-allowed'
                                    : 'bg-slate-900 hover:bg-[#E00D42] text-white active:scale-[0.98]'
                        }`}
                    >
                        {isSuccess ? (
                            <>
                                <Check className="w-3.5 h-3.5" />
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
        </div>
    );
}
