import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout';
import { Product } from '@/types';
import { 
    Star, 
    ShoppingCart, 
    Truck, 
    ShieldCheck, 
    Store, 
    ArrowLeft, 
    Plus, 
    Minus, 
    Check, 
    RotateCcw,
    Sparkles
} from 'lucide-react';

interface Props {
    product: Product;
    relatedProducts: Product[];
}

export default function ProductDetail({ product, relatedProducts }: Props) {
    const [quantity, setQuantity] = useState(1);
    const [selectedImage, setSelectedImage] = useState(product.featured_image || '');

    const handleAddToCart = () => {
        router.post(route('cart.store'), {
            product_id: product.id,
            quantity: quantity,
        }, {
            preserveScroll: true,
        });
    };

    const handleBuyNow = () => {
        router.post(route('cart.store'), {
            product_id: product.id,
            quantity: quantity,
        }, {
            onSuccess: () => router.visit(route('checkout.index')),
        });
    };

    return (
        <MarketplaceLayout>
            <Head title={`${product.name} — Bagoo Marketplace`} />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                {/* Breadcrumbs */}
                <div className="flex items-center gap-2 text-xs text-slate-500 mb-6">
                    <Link href={route('marketplace')} className="hover:text-indigo-600 flex items-center gap-1">
                        <ArrowLeft className="w-3.5 h-3.5" />
                        <span>Back to Catalog</span>
                    </Link>
                    <span>/</span>
                    <span className="text-slate-400">{product.category?.name || 'Category'}</span>
                    <span>/</span>
                    <span className="text-slate-800 font-semibold truncate max-w-[200px]">{product.name}</span>
                </div>

                {/* Product Main Showcase */}
                <div className="bg-white rounded-3xl border border-slate-200 p-6 lg:p-10 grid grid-cols-1 lg:grid-cols-12 gap-10">
                    {/* Left: Images */}
                    <div className="lg:col-span-6 space-y-4">
                        <div className="aspect-square rounded-3xl bg-slate-100 overflow-hidden border border-slate-200/80">
                            <img
                                src={selectedImage || product.featured_image || 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?auto=format&fit=crop&w=800&q=80'}
                                alt={product.name}
                                className="w-full h-full object-cover"
                            />
                        </div>

                        {product.images && product.images.length > 0 && (
                            <div className="flex gap-3 overflow-x-auto pb-2">
                                {product.images.map((img) => (
                                    <button
                                        key={img.id}
                                        onClick={() => setSelectedImage(img.image_url)}
                                        className={`w-20 h-20 rounded-2xl overflow-hidden border-2 transition shrink-0 ${
                                            selectedImage === img.image_url ? 'border-indigo-600 shadow-md' : 'border-transparent opacity-70 hover:opacity-100'
                                        }`}
                                    >
                                        <img src={img.image_url} alt="Thumbnail" className="w-full h-full object-cover" />
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Right: Info & Actions */}
                    <div className="lg:col-span-6 flex flex-col justify-between space-y-6">
                        <div className="space-y-4">
                            {/* Seller Tag */}
                            {product.shop && (
                                <Link
                                    href={route('shop.show', product.shop.slug)}
                                    className="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold transition"
                                >
                                    <Store className="w-3.5 h-3.5 text-indigo-600" />
                                    <span>{product.shop.name}</span>
                                    <span className="text-[10px] text-emerald-600 font-bold">★ {product.shop.rating}</span>
                                </Link>
                            )}

                            <h1 className="text-2xl sm:text-3xl font-black text-slate-900 leading-snug">
                                {product.name}
                            </h1>

                            {/* Ratings & Sales */}
                            <div className="flex items-center gap-4 text-xs">
                                <div className="flex items-center gap-1 text-amber-500 font-bold">
                                    <Star className="w-4 h-4 fill-amber-400 text-amber-400" />
                                    <span>{product.rating}</span>
                                    <span className="text-slate-400 font-normal">({product.reviews?.length ?? 0} reviews)</span>
                                </div>
                                <span className="text-slate-300">•</span>
                                <span className="text-slate-500 font-medium">{product.sales_count} items sold</span>
                                <span className="text-slate-300">•</span>
                                <span className={`font-bold ${product.stock > 0 ? 'text-emerald-600' : 'text-rose-600'}`}>
                                    {product.stock > 0 ? `In Stock (${product.stock})` : 'Out of Stock'}
                                </span>
                            </div>

                            {/* Price */}
                            <div className="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 flex items-baseline gap-3">
                                <span className="text-3xl font-black text-slate-900">
                                    ${Number(product.price).toFixed(2)}
                                </span>
                                {product.compare_at_price && (
                                    <span className="text-sm text-slate-400 line-through">
                                        ${Number(product.compare_at_price).toFixed(2)}
                                    </span>
                                )}
                                {product.compare_at_price && Number(product.compare_at_price) > Number(product.price) && (
                                    <span className="px-2 py-0.5 bg-rose-100 text-rose-700 text-xs font-bold rounded-md">
                                        Save ${(Number(product.compare_at_price) - Number(product.price)).toFixed(2)}
                                    </span>
                                )}
                            </div>

                            {/* Description */}
                            <div>
                                <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Description</h3>
                                <p className="text-sm text-slate-600 leading-relaxed whitespace-pre-line">
                                    {product.description}
                                </p>
                            </div>

                            {/* Trust badges */}
                            <div className="grid grid-cols-2 gap-3 pt-2">
                                <div className="flex items-center gap-2.5 p-3 rounded-xl bg-slate-50 text-xs text-slate-700">
                                    <Truck className="w-4 h-4 text-indigo-600 shrink-0" />
                                    <span>Fast Courier Delivery</span>
                                </div>
                                <div className="flex items-center gap-2.5 p-3 rounded-xl bg-slate-50 text-xs text-slate-700">
                                    <ShieldCheck className="w-4 h-4 text-emerald-600 shrink-0" />
                                    <span>Bagoo Buyer Protection</span>
                                </div>
                            </div>
                        </div>

                        {/* Quantity & Add to cart actions */}
                        <div className="space-y-4 pt-6 border-t border-slate-100">
                            <div className="flex items-center gap-4">
                                <span className="text-xs font-bold text-slate-700">Quantity:</span>
                                <div className="flex items-center border border-slate-200 rounded-xl bg-slate-50 p-1">
                                    <button
                                        onClick={() => setQuantity(Math.max(1, quantity - 1))}
                                        disabled={quantity <= 1}
                                        className="p-1.5 text-slate-600 hover:text-black disabled:opacity-30 rounded-lg hover:bg-white transition"
                                    >
                                        <Minus className="w-3.5 h-3.5" />
                                    </button>
                                    <span className="px-4 text-xs font-bold text-slate-900">{quantity}</span>
                                    <button
                                        onClick={() => setQuantity(Math.min(product.stock, quantity + 1))}
                                        disabled={quantity >= product.stock}
                                        className="p-1.5 text-slate-600 hover:text-black disabled:opacity-30 rounded-lg hover:bg-white transition"
                                    >
                                        <Plus className="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            </div>

                            <div className="flex flex-col sm:flex-row gap-3">
                                <button
                                    onClick={handleAddToCart}
                                    disabled={product.stock <= 0}
                                    className="flex-1 py-3.5 px-6 rounded-2xl bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold text-sm border border-indigo-200 transition flex items-center justify-center gap-2 active:scale-95 disabled:opacity-50"
                                >
                                    <ShoppingCart className="w-4 h-4" />
                                    <span>Add to Cart</span>
                                </button>
                                <button
                                    onClick={handleBuyNow}
                                    disabled={product.stock <= 0}
                                    className="flex-1 py-3.5 px-6 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-sm shadow-lg shadow-indigo-600/25 transition flex items-center justify-center gap-2 active:scale-95 disabled:opacity-50"
                                >
                                    <Sparkles className="w-4 h-4" />
                                    <span>Buy Now</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Related Products */}
                {relatedProducts.length > 0 && (
                    <div className="mt-16">
                        <h2 className="text-xl font-bold text-slate-900 mb-6">Related Products</h2>
                        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-6">
                            {relatedProducts.map((rel) => (
                                <Link
                                    key={rel.id}
                                    href={route('products.show', rel.slug)}
                                    className="bg-white rounded-3xl border border-slate-200 p-4 hover:shadow-lg transition group flex flex-col"
                                >
                                    <div className="aspect-square rounded-2xl bg-slate-100 overflow-hidden mb-3">
                                        <img src={rel.featured_image || ''} alt={rel.name} className="w-full h-full object-cover group-hover:scale-105 transition" />
                                    </div>
                                    <h4 className="font-bold text-xs text-slate-900 line-clamp-1 mb-1">{rel.name}</h4>
                                    <p className="text-sm font-black text-indigo-600 mt-auto">${Number(rel.price).toFixed(2)}</p>
                                </Link>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </MarketplaceLayout>
    );
}
