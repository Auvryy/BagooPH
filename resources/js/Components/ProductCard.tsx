import React from 'react';
import CatalogueLink from '@/Components/CatalogueLink';
import { Star, Truck, ShoppingBag, MapPin, Sparkles, Flame } from 'lucide-react';
import { Product } from '@/types';
import { ratingLabel, recordedRating } from '@/Components/RatingStars';
import { defaultVariantDisplayPrice, defaultVariantRegularPrice } from '@/utils/productPricing';

interface ProductCardProps {
    product: Product;
    href?: string;
    onQuickAdd?: (product: Product) => void;
    isAdding?: boolean;
    isSuccess?: boolean;
    actionLabel?: string;
    className?: string;
    heartControl?: React.ReactNode;
    showDeliveryBadge?: boolean;
    rankBadge?: React.ReactNode;
}

export default function ProductCard({
    product,
    href,
    onQuickAdd,
    isAdding = false,
    isSuccess = false,
    actionLabel = 'Add to Bag',
    className = '',
    heartControl,
    showDeliveryBadge = true,
    rankBadge,
}: ProductCardProps) {
    const priceNum = defaultVariantDisplayPrice(product);
    const maxPrice = Number(product.price_range?.max ?? priceNum);
    const hasDefaultCombination = Boolean(product.variants?.combinations?.length);
    const regularPrice = defaultVariantRegularPrice(product);
    const compareNum = product.sale_offer?.active && priceNum < regularPrice 
        ? regularPrice
        : product.compare_at_price 
            ? regularPrice + Number(product.compare_at_price) - Number(product.price) 
            : null;
    const discountPct = (hasDefaultCombination || maxPrice === priceNum) && compareNum && compareNum > priceNum
        ? Math.round(((compareNum - priceNum) / compareNum) * 100)
        : null;

    // Target URL
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

    const ratingVal = ratingLabel(product.rating);
    const salesCountVal = product.sales_count ?? 0;
    const storeLocation = product.shop?.city?.trim() || 'Manila, Philippines';
    const isOutOfStock = Number(product.stock) <= 0;

    return (
        <article
            className={`group relative bg-white rounded-xl border border-slate-200/80 shadow-[0_2px_8px_rgba(15,23,42,0.04)] hover:shadow-[0_16px_32px_rgba(224,13,66,0.1)] hover:border-red-300 hover:-translate-y-1 transition-all duration-200 ease-out flex flex-col justify-between overflow-hidden cursor-pointer ${className}`}
        >
            <CatalogueLink href={targetUrl} className="flex flex-1 flex-col">
                {/* 1. Product Image Container */}
                <div className="relative aspect-square bg-slate-50 overflow-hidden rounded-t-xl">
                    {product.featured_image ? (
                        <img
                            src={product.featured_image}
                            alt={product.name}
                            loading="lazy"
                            className={`w-full h-full object-cover group-hover:scale-108 transition-transform duration-500 ease-out ${isOutOfStock ? 'opacity-60 grayscale-[40%]' : ''}`}
                        />
                    ) : (
                        <div className="w-full h-full flex flex-col items-center justify-center bg-slate-50 text-slate-300">
                            <ShoppingBag className="w-10 h-10 stroke-[1.25]" />
                            <span className="text-[9px] font-sans text-slate-400 mt-1 uppercase font-bold tracking-wider">No Preview</span>
                        </div>
                    )}

                    {/* Top Status Overlays (Rank + Discount Badges aligned side-by-side) */}
                    <div className="absolute top-1.5 left-1.5 flex items-center gap-1 z-10 pointer-events-none flex-wrap max-w-[calc(100%-2.5rem)]">
                        {rankBadge}
                        {discountPct && (
                            <span className="bg-[#E00D42] text-white text-[9px] font-black uppercase px-1.5 py-0.5 rounded shadow-xs tracking-wider flex items-center gap-0.5">
                                <Flame className="w-2.5 h-2.5 fill-white" />
                                <span>-{discountPct}%</span>
                            </span>
                        )}
                        {salesCountVal > 1000 && !discountPct && !rankBadge && (
                            <span className="bg-[#F97316] text-white text-[8px] font-black uppercase px-1.5 py-0.5 rounded shadow-xs tracking-wider flex items-center gap-0.5">
                                <Sparkles className="w-2 h-2 text-white" />
                                <span>HOT</span>
                            </span>
                        )}
                    </div>

                    {product.shop?.vacation_mode && (
                        <span className="absolute left-1.5 bottom-1.5 z-10 rounded bg-[#F59E0B] text-white px-1.5 py-0.5 text-[9px] font-bold shadow-xs">
                            On Vacation
                        </span>
                    )}

                    {isOutOfStock && (
                        <span className="absolute inset-0 bg-slate-800/40 backdrop-blur-[1px] flex items-center justify-center z-10">
                            <span className="bg-[#E00D42] text-white font-bold text-[11px] uppercase px-2.5 py-1 rounded-md tracking-wider shadow-md">
                                Out of Stock
                            </span>
                        </span>
                    )}

                    {/* Delivery Pill Badge (Bottom-left of Image) */}
                    {showDeliveryBadge && !isOutOfStock && (
                        <div className="absolute bottom-1.5 left-1.5 z-10 pointer-events-none">
                            <div className="bg-white/95 text-slate-900 border border-slate-200/90 shadow-2xs rounded px-1.5 py-0.2 flex items-center gap-1 text-[8px] font-bold uppercase tracking-wider">
                                <Truck className="w-2.5 h-2.5 text-[#E00D42]" />
                                <span>Express</span>
                            </div>
                        </div>
                    )}
                </div>

                {/* 2. Content Body with Dense Spacing */}
                <div className="p-2 sm:p-2.5 space-y-1.5 font-sans flex-1 flex flex-col justify-between">
                    <div className="space-y-1">
                        {/* Product Title */}
                        <h4 className="font-semibold text-xs sm:text-[13px] text-slate-800 group-hover:text-[#E00D42] transition-colors line-clamp-2 leading-tight min-h-[2.4em]">
                            {product.name}
                        </h4>

                        {/* Price Block */}
                        <div className="flex items-baseline gap-1.5 pt-0.5 flex-wrap">
                            <span className="text-sm sm:text-base font-black text-[#E00D42] tracking-tight">
                                {formatPrice(priceNum)}{!hasDefaultCombination && maxPrice > priceNum ? ` – ${formatPrice(maxPrice)}` : ''}
                            </span>
                            {discountPct && compareNum && compareNum > priceNum && (
                                <span className="text-[10px] sm:text-[11px] text-slate-400 line-through font-normal">
                                    {formatPrice(compareNum)}
                                </span>
                            )}
                        </div>
                    </div>

                    {/* Metadata: Rating, Sales & Location */}
                    <div className="pt-1.5 border-t border-slate-100 space-y-1 text-[10px] sm:text-[11px]">
                        <div className="flex items-center justify-between gap-1">
                            <div className="flex items-center gap-1 min-w-0">
                                <Star className={`w-3 h-3 ${recordedRating(product.rating) === null ? 'text-slate-300' : 'fill-amber-400 text-amber-400'}`} />
                                <span className="font-bold text-slate-800">{ratingVal}</span>
                                <span className="text-slate-300">•</span>
                                <span className="text-slate-500 font-medium truncate">
                                    {salesCountVal > 0 ? `${salesCountVal.toLocaleString()} sold` : 'New'}
                                </span>
                            </div>
                        </div>

                        {/* Location */}
                        <div className="flex items-center gap-1 text-slate-400 text-[9px] sm:text-[10px]" title={storeLocation}>
                            <MapPin className="w-3 h-3 shrink-0 text-slate-400" />
                            <span className="truncate">{storeLocation}</span>
                        </div>
                    </div>
                </div>
            </CatalogueLink>

            {/* Heart / Wishlist Control (Top-right of card) */}
            {heartControl && (
                <div className="absolute right-1.5 top-1.5 z-20 transition-transform duration-200 hover:scale-115 active:scale-90">
                    {heartControl}
                </div>
            )}
        </article>
    );
}
