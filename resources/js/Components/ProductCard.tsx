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
            className={`group relative bg-white rounded-2xl border border-slate-200/90 shadow-[0_4px_14px_rgba(15,23,42,0.05)] hover:shadow-[0_20px_40px_rgba(224,13,66,0.1)] hover:border-red-200 hover:-translate-y-1.5 transition-all duration-300 ease-out flex flex-col justify-between overflow-hidden cursor-pointer ${className}`}
        >
            <CatalogueLink href={targetUrl} className="flex flex-1 flex-col">
                {/* 1. Product Image Container */}
                <div className="relative aspect-square bg-slate-50 overflow-hidden rounded-t-2xl">
                    {product.featured_image ? (
                        <img
                            src={product.featured_image}
                            alt={product.name}
                            loading="lazy"
                            className={`w-full h-full object-cover group-hover:scale-108 transition-transform duration-500 ease-out ${isOutOfStock ? 'opacity-60 grayscale-[40%]' : ''}`}
                        />
                    ) : (
                        <div className="w-full h-full flex flex-col items-center justify-center bg-slate-50 text-slate-300">
                            <ShoppingBag className="w-12 h-12 stroke-[1.25]" />
                            <span className="text-[10px] font-sans text-slate-400 mt-1 uppercase font-bold tracking-wider">No Preview</span>
                        </div>
                    )}

                    {/* Top Status Overlays (Red & Yellow-Orange only - No Black) */}
                    <div className="absolute top-2.5 left-2.5 flex flex-col gap-1.5 z-10 pointer-events-none">
                        {discountPct && (
                            <span className="bg-[#E00D42] text-white text-[10px] font-black uppercase px-2.5 py-0.5 rounded-md shadow-sm tracking-wider flex items-center gap-1">
                                <Flame className="w-2.5 h-2.5 fill-white" />
                                <span>-{discountPct}%</span>
                            </span>
                        )}
                        {salesCountVal > 1000 && !discountPct && (
                            <span className="bg-[#F97316] text-white text-[9px] font-black uppercase px-2 py-0.5 rounded-md shadow-sm tracking-wider flex items-center gap-1">
                                <Sparkles className="w-2.5 h-2.5 text-white" />
                                <span>HOT</span>
                            </span>
                        )}
                    </div>

                    {product.shop?.vacation_mode && (
                        <span className="absolute left-2.5 bottom-2.5 z-10 rounded-md bg-[#F59E0B] text-white px-2 py-0.5 text-[10px] font-bold shadow-xs">
                            On Vacation
                        </span>
                    )}

                    {isOutOfStock && (
                        <span className="absolute inset-0 bg-slate-800/40 backdrop-blur-[1px] flex items-center justify-center z-10">
                            <span className="bg-[#E00D42] text-white font-bold text-xs uppercase px-3 py-1 rounded-lg tracking-wider shadow-md">
                                Out of Stock
                            </span>
                        </span>
                    )}

                    {/* Delivery Pill Badge (Bottom-left of Image) */}
                    {showDeliveryBadge && !isOutOfStock && (
                        <div className="absolute bottom-2.5 left-2.5 z-10 pointer-events-none">
                            <div className="bg-white/95 text-slate-900 border border-slate-200/90 shadow-sm rounded-lg px-2 py-0.5 flex items-center gap-1 text-[9px] font-bold uppercase tracking-wider">
                                <Truck className="w-3 h-3 text-[#E00D42]" />
                                <span>Doorstep</span>
                            </div>
                        </div>
                    )}
                </div>

                {/* 2. Content Body with Tactile Spacing */}
                <div className="p-4 sm:p-4.5 space-y-2.5 font-sans flex-1 flex flex-col justify-between">
                    <div className="space-y-1.5">
                        {/* Product Title */}
                        <h4 className="font-bold text-sm sm:text-base text-slate-900 group-hover:text-[#E00D42] transition-colors line-clamp-2 leading-snug">
                            {product.name}
                        </h4>

                        {/* Price Block */}
                        <div className="flex items-baseline gap-2 pt-0.5 flex-wrap">
                            <span className="text-lg sm:text-xl font-black text-[#E00D42] tracking-tight">
                                {formatPrice(priceNum)}{!hasDefaultCombination && maxPrice > priceNum ? ` – ${formatPrice(maxPrice)}` : ''}
                            </span>
                            {discountPct && compareNum && compareNum > priceNum && (
                                <span className="text-xs text-slate-400 line-through font-medium">
                                    {formatPrice(compareNum)}
                                </span>
                            )}
                        </div>
                    </div>

                    {/* Metadata: Rating, Sales & Location */}
                    <div className="pt-2.5 border-t border-slate-100 space-y-1.5 text-xs">
                        <div className="flex items-center justify-between gap-1.5">
                            <div className="flex items-center gap-1.5 min-w-0">
                                <Star className={`w-3.5 h-3.5 ${recordedRating(product.rating) === null ? 'text-slate-300' : 'fill-amber-400 text-amber-400'}`} />
                                <span className="font-bold text-slate-800">{ratingVal}</span>
                                <span className="text-slate-300">•</span>
                                <span className="text-slate-500 font-medium">
                                    {salesCountVal > 0 ? `${salesCountVal.toLocaleString()} sold` : 'New listing'}
                                </span>
                            </div>
                        </div>

                        {/* Location */}
                        <div className="flex items-center gap-1 text-slate-500 text-[11px]" title={storeLocation}>
                            <MapPin className="w-3.5 h-3.5 shrink-0 text-slate-400" />
                            <span className="truncate">{storeLocation}</span>
                        </div>
                    </div>
                </div>
            </CatalogueLink>

            {/* Heart / Wishlist Control (Top-right of card) */}
            {heartControl && (
                <div className="absolute right-2.5 top-2.5 z-20 transition-transform duration-200 hover:scale-125 active:scale-90">
                    {heartControl}
                </div>
            )}
        </article>
    );
}
