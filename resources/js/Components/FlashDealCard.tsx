import React from 'react';
import CatalogueLink from '@/Components/CatalogueLink';
import { Flame } from 'lucide-react';
import { Product } from '@/types';
import { defaultVariantDisplayPrice, defaultVariantRegularPrice } from '@/utils/productPricing';

interface FlashDealCardProps {
    product: Product;
    heartControl?: React.ReactNode;
    href?: string;
}

export default function FlashDealCard({ product, heartControl, href }: FlashDealCardProps) {
    const priceNum = defaultVariantDisplayPrice(product);
    const regularPrice = defaultVariantRegularPrice(product);
    const compareNum = product.sale_offer?.active && priceNum < regularPrice 
        ? regularPrice
        : product.compare_at_price 
            ? regularPrice + Number(product.compare_at_price) - Number(product.price) 
            : null;
    const discountPct = compareNum && compareNum > priceNum
        ? Math.round(((compareNum - priceNum) / compareNum) * 100)
        : 25;

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

    const progressPct = Math.min(95, Math.max(45, ((product.sales_count || 12) % 60) + 35));

    return (
        <article className="group relative bg-white rounded-2xl border border-slate-200/90 shadow-[0_4px_14px_rgba(15,23,42,0.05)] hover:shadow-[0_18px_36px_rgba(224,13,66,0.12)] hover:border-red-200 hover:-translate-y-1.5 transition-all duration-300 ease-out flex flex-col justify-between overflow-hidden cursor-pointer">
            <CatalogueLink href={targetUrl} className="flex flex-1 flex-col">
                {/* 1. Square Image */}
                <div className="relative aspect-square bg-slate-50 overflow-hidden rounded-t-2xl">
                    {product.featured_image ? (
                        <img
                            src={product.featured_image}
                            alt={product.name}
                            loading="lazy"
                            className="w-full h-full object-cover group-hover:scale-108 transition-transform duration-500 ease-out"
                        />
                    ) : (
                        <div className="w-full h-full flex items-center justify-center bg-slate-50 text-slate-300">
                            <span className="text-[10px] font-sans text-slate-400 uppercase font-bold">No Image</span>
                        </div>
                    )}

                    {/* Discount badge in Top-Left */}
                    {discountPct > 0 && (
                        <div className="absolute top-2 left-2 z-10 pointer-events-none">
                            <span className="bg-[#E00D42] text-white text-[10px] font-black uppercase px-2 py-0.5 rounded-md shadow-sm tracking-wider flex items-center gap-1">
                                <Flame className="w-2.5 h-2.5 fill-white" />
                                <span>-{discountPct}%</span>
                            </span>
                        </div>
                    )}
                </div>

                {/* 2. Price & Progress Bar (Inside the Card) */}
                <div className="p-3 space-y-2.5 font-sans flex flex-col justify-between flex-1 bg-white">
                    {/* Price */}
                    <div className="space-y-0.5">
                        <div className="text-base sm:text-lg font-black text-[#E00D42] tracking-tight">
                            {formatPrice(priceNum)}
                        </div>
                        {discountPct > 0 && compareNum && compareNum > priceNum && (
                            <div className="text-[11px] text-slate-400 line-through font-medium">
                                {formatPrice(compareNum)}
                            </div>
                        )}
                    </div>

                    {/* Integrated Selling Fast Bar inside Card */}
                    <div className="space-y-1 pt-1 border-t border-slate-100">
                        <div className="bg-red-50 rounded-full h-2 w-full overflow-hidden border border-red-100/80">
                            <div
                                className="bg-[#E00D42] h-full rounded-full transition-all duration-500"
                                style={{ width: `${progressPct}%` }}
                            />
                        </div>
                        <div className="flex items-center justify-between text-[10px] font-bold">
                            <span className="flex items-center gap-1 text-[#E00D42]">
                                <Flame className="w-3 h-3 fill-[#E00D42]" />
                                <span>SELLING FAST</span>
                            </span>
                            <span className="text-[#F97316]">{product.stock ?? 20} left</span>
                        </div>
                    </div>
                </div>
            </CatalogueLink>

            {/* Heart Wishlist Control */}
            {heartControl && (
                <div className="absolute right-2 top-2 z-20 transition-transform duration-200 hover:scale-125 active:scale-90">
                    {heartControl}
                </div>
            )}
        </article>
    );
}
