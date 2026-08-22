import React from 'react';
import { Head, Link } from '@inertiajs/react';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout';
import { PaginatedData, Product, Shop } from '@/types';
import { Store, Star, MapPin, Phone, ShoppingBag, ArrowLeft } from 'lucide-react';

interface Props {
    shop: Shop;
    products: PaginatedData<Product>;
}

export default function ShopDetail({ shop, products }: Props) {
    return (
        <MarketplaceLayout>
            <Head title={`${shop.name} — Bagoo Storefront`} />

            {/* Store Banner */}
            <div className="relative bg-slate-900 text-white py-12 px-4 sm:px-6 lg:px-8 overflow-hidden">
                <div className="max-w-7xl mx-auto flex flex-col md:flex-row items-center md:items-start gap-6 relative z-10">
                    <div className="w-24 h-24 rounded-3xl bg-white p-2 shadow-xl shrink-0 overflow-hidden">
                        <img 
                            src={shop.logo || 'https://images.unsplash.com/photo-1544816155-12df9643f363?auto=format&fit=crop&w=300&q=80'} 
                            alt={shop.name} 
                            className="w-full h-full object-cover rounded-2xl" 
                        />
                    </div>
                    <div className="text-center md:text-left space-y-2 flex-1">
                        <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-500/20 text-emerald-300 text-xs font-bold">
                            <Store className="w-3.5 h-3.5" />
                            <span>Verified Bagoo Merchant</span>
                        </div>
                        <h1 className="text-3xl font-black">{shop.name}</h1>
                        <p className="text-xs text-slate-300 max-w-xl">{shop.description}</p>
                        <div className="flex flex-wrap items-center justify-center md:justify-start gap-4 text-xs text-slate-400 pt-1">
                            <span className="flex items-center gap-1 text-amber-400 font-bold">
                                <Star className="w-3.5 h-3.5 fill-amber-400" />
                                {shop.rating} Store Rating
                            </span>
                            {shop.city && (
                                <span className="flex items-center gap-1">
                                    <MapPin className="w-3.5 h-3.5 text-slate-400" />
                                    {shop.city}
                                </span>
                            )}
                            {shop.phone && (
                                <span className="flex items-center gap-1">
                                    <Phone className="w-3.5 h-3.5 text-slate-400" />
                                    {shop.phone}
                                </span>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {/* Store Products */}
            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
                <h2 className="text-xl font-bold text-slate-900 mb-6">Products from {shop.name}</h2>
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                    {products.data.map((product) => (
                        <Link
                            key={product.id}
                            href={route('products.show', product.slug)}
                            className="bg-white rounded-3xl border border-slate-200 p-5 hover:shadow-lg transition group flex flex-col justify-between"
                        >
                            <div>
                                <div className="aspect-square rounded-2xl bg-slate-100 overflow-hidden mb-3">
                                    <img src={product.featured_image || ''} alt={product.name} className="w-full h-full object-cover group-hover:scale-105 transition" />
                                </div>
                                <h3 className="font-bold text-sm text-slate-900 group-hover:text-indigo-600 line-clamp-2 mb-1">
                                    {product.name}
                                </h3>
                            </div>
                            <div className="pt-3 border-t border-slate-100 flex items-center justify-between mt-3">
                                <span className="text-base font-black text-slate-900">${Number(product.price).toFixed(2)}</span>
                                <span className="text-[11px] text-slate-500 font-medium">Stock: {product.stock}</span>
                            </div>
                        </Link>
                    ))}
                </div>
            </div>
        </MarketplaceLayout>
    );
}
