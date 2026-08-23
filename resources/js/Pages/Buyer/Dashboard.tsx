import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { 
    ShoppingBag, 
    ShoppingCart, 
    Package, 
    Truck, 
    Clock, 
    CheckCircle2, 
    Star, 
    ArrowRight, 
    Store, 
    Tag, 
    MapPin, 
    ShieldCheck, 
    Plus, 
    Minus, 
    Trash2, 
    Search, 
    Copy, 
    Check, 
    ExternalLink,
    AlertCircle,
    UserCheck,
    CreditCard
} from 'lucide-react';
import { Category, Order, Product, Cart } from '@/types';

interface Voucher {
    code: string;
    title: string;
    description: string;
    discount: string;
    min_spend: number;
    expires_at: string;
    badge: string;
}

interface Stats {
    total_orders: number;
    active_shipments: number;
    completed_orders: number;
    cart_items: number;
    total_spent: number | string;
}

interface Props {
    activeOrders: Order[];
    recentOrders: Order[];
    cart: Cart | null;
    recommendedProducts: Product[];
    categories: (Category & { products_count?: number })[];
    vouchers: Voucher[];
    stats: Stats;
}

export default function BuyerDashboard({
    activeOrders,
    recentOrders,
    cart,
    recommendedProducts,
    categories,
    vouchers,
    stats,
}: Props) {
    const [copiedCode, setCopiedCode] = useState<string | null>(null);
    const [addingProductId, setAddingProductId] = useState<number | null>(null);
    const [addedSuccessId, setAddedSuccessId] = useState<number | null>(null);
    const [activeTab, setActiveTab] = useState<'overview' | 'orders' | 'cart' | 'vouchers' | 'catalog'>('overview');

    const copyVoucher = (code: string) => {
        navigator.clipboard.writeText(code);
        setCopiedCode(code);
        setTimeout(() => setCopiedCode(null), 2500);
    };

    const handleAddToCart = (e: React.MouseEvent, productId: number) => {
        e.preventDefault();
        e.stopPropagation();
        setAddingProductId(productId);

        router.post(route('cart.store'), {
            product_id: productId,
            quantity: 1,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setAddingProductId(null);
                setAddedSuccessId(productId);
                setTimeout(() => setAddedSuccessId(null), 2000);
            },
            onError: () => {
                setAddingProductId(null);
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

    const getOrderStatusPill = (status: string) => {
        switch (status) {
            case 'delivered':
                return <span className="px-2.5 py-1 bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 rounded-md text-[10px] font-bold uppercase flex items-center gap-1"><CheckCircle2 className="w-3 h-3" /> Delivered</span>;
            case 'shipped':
                return <span className="px-2.5 py-1 bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 rounded-md text-[10px] font-bold uppercase flex items-center gap-1"><Truck className="w-3 h-3" /> In Transit</span>;
            case 'ready_for_pickup':
                return <span className="px-2.5 py-1 bg-amber-500/10 text-amber-400 border border-amber-500/20 rounded-md text-[10px] font-bold uppercase flex items-center gap-1"><Clock className="w-3 h-3" /> Ready for Courier</span>;
            case 'processing':
                return <span className="px-2.5 py-1 bg-blue-500/10 text-blue-400 border border-blue-500/20 rounded-md text-[10px] font-bold uppercase flex items-center gap-1"><Clock className="w-3 h-3" /> Merchant Packaging</span>;
            default:
                return <span className="px-2.5 py-1 bg-slate-800 text-slate-300 rounded-md text-[10px] font-bold uppercase">{status}</span>;
        }
    };

    return (
        <DashboardLayout title="Buyer Command Center // Overview">
            <Head title="Buyer Portal & Dashboard — BagooPH" />

            <div className="space-y-8 font-sans">
                
                {/* 1. TOP BUYER TELEMETRY & STATS BANNER */}
                <div className="p-6 rounded-2xl bg-neutral-900 border border-neutral-800 text-white relative overflow-hidden shadow-xl">
                    <div className="absolute top-0 right-0 w-96 h-96 bg-[#E00D42]/10 rounded-full blur-3xl pointer-events-none -mr-20 -mt-20"></div>
                    
                    <div className="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                        <div className="space-y-1.5 font-mono">
                            <div className="flex items-center gap-2">
                                <span className="px-2 py-0.5 rounded bg-[#E00D42] text-white text-[10px] font-bold uppercase tracking-wider">
                                    VERIFIED BUYER
                                </span>
                                <span className="text-neutral-400 text-xs">•</span>
                                <span className="text-neutral-400 text-xs flex items-center gap-1">
                                    <ShieldCheck className="w-3.5 h-3.5 text-emerald-400" /> KYC Status: Active
                                </span>
                            </div>
                            <h1 className="text-2xl sm:text-3xl font-black text-white tracking-tight font-sans">
                                Welcome Back, Shopper
                            </h1>
                            <p className="text-xs text-neutral-400 uppercase tracking-wide">
                                LIVE TELEMETRY • DIRECT DOORSTEP FLEET • 14 VERIFIED DEPARTMENTS
                            </p>
                        </div>

                        {/* Quick CTA Actions */}
                        <div className="flex flex-wrap items-center gap-3 font-mono text-xs">
                            <Link
                                href={route('products.index')}
                                className="px-4 py-2.5 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold rounded-lg shadow-sm transition uppercase tracking-wider flex items-center gap-2"
                            >
                                <ShoppingBag className="w-4 h-4" />
                                <span>Shop 14 Departments</span>
                            </Link>

                            <Link
                                href={route('cart.index')}
                                className="px-4 py-2.5 bg-white/10 hover:bg-white/20 text-white font-bold rounded-lg border border-white/15 transition uppercase tracking-wider flex items-center gap-2"
                            >
                                <ShoppingCart className="w-4 h-4 text-[#E00D42]" />
                                <span>View Bag ({stats.cart_items})</span>
                            </Link>
                        </div>
                    </div>

                    {/* Stat Metrics Grid */}
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-6 pt-6 border-t border-neutral-800 font-mono">
                        <div className="p-3.5 rounded-xl bg-neutral-950/60 border border-neutral-800/80">
                            <span className="text-[10px] text-neutral-400 uppercase font-bold tracking-wider block">TOTAL ORDERS</span>
                            <span className="text-xl sm:text-2xl font-black font-sans text-white">{stats.total_orders}</span>
                        </div>

                        <div className="p-3.5 rounded-xl bg-neutral-950/60 border border-neutral-800/80">
                            <span className="text-[10px] text-[#E00D42] uppercase font-bold tracking-wider block flex items-center gap-1">
                                <span className="w-1.5 h-1.5 rounded-full bg-[#E00D42] animate-pulse"></span>
                                IN TRANSIT
                            </span>
                            <span className="text-xl sm:text-2xl font-black font-sans text-white">{stats.active_shipments}</span>
                        </div>

                        <div className="p-3.5 rounded-xl bg-neutral-950/60 border border-neutral-800/80">
                            <span className="text-[10px] text-neutral-400 uppercase font-bold tracking-wider block">COMPLETED</span>
                            <span className="text-xl sm:text-2xl font-black font-sans text-emerald-400">{stats.completed_orders}</span>
                        </div>

                        <div className="p-3.5 rounded-xl bg-neutral-950/60 border border-neutral-800/80">
                            <span className="text-[10px] text-neutral-400 uppercase font-bold tracking-wider block">PLATFORM SPEND</span>
                            <span className="text-xl sm:text-2xl font-black font-sans text-white">{formatPrice(stats.total_spent)}</span>
                        </div>
                    </div>
                </div>

                {/* 2. LIVE ORDER TRACKING & DISPATCH CAROUSEL */}
                {activeOrders.length > 0 && (
                    <div className="space-y-4">
                        <div className="flex items-center justify-between font-mono text-xs">
                            <div className="flex items-center gap-2">
                                <span className="w-2 h-2 rounded-full bg-indigo-500 animate-ping"></span>
                                <h2 className="font-bold uppercase tracking-wider text-neutral-200">
                                    LIVE SHIPMENTS IN TRANSIT ({activeOrders.length})
                                </h2>
                            </div>
                            <Link href={route('orders.index')} className="text-[#E00D42] hover:underline font-bold uppercase text-[11px]">
                                View All Orders ➔
                            </Link>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            {activeOrders.map((order) => (
                                <div 
                                    key={order.id}
                                    className="p-5 rounded-2xl bg-neutral-900 border border-neutral-800 text-white space-y-4 relative overflow-hidden shadow-md"
                                >
                                    <div className="flex items-start justify-between gap-3 pb-3 border-b border-neutral-800 font-mono text-xs">
                                        <div>
                                            <span className="font-bold text-white text-sm">Order #{order.order_number}</span>
                                            <p className="text-[11px] text-neutral-400">Placed: {new Date(order.created_at).toLocaleDateString()}</p>
                                        </div>
                                        {getOrderStatusPill(order.status)}
                                    </div>

                                    {/* Courier Telemetry Data */}
                                    {order.delivery ? (
                                        <div className="p-3.5 rounded-xl bg-neutral-950 border border-neutral-800/80 space-y-2 font-mono text-xs">
                                            <div className="flex items-center justify-between text-[11px]">
                                                <span className="text-neutral-400 flex items-center gap-1.5">
                                                    <Truck className="w-3.5 h-3.5 text-[#E00D42]" />
                                                    {order.delivery.logistics_partner}
                                                </span>
                                                <span className="text-[#E00D42] font-bold">
                                                    {order.delivery.tracking_number}
                                                </span>
                                            </div>
                                            <p className="text-[11px] text-neutral-300">
                                                {order.delivery.courier_notes || 'Package dispatched and secured on express courier transit route.'}
                                            </p>
                                            {order.delivery.estimated_delivery_at && (
                                                <p className="text-[10px] text-emerald-400 font-bold uppercase">
                                                    Est. Doorstep Arrival: {new Date(order.delivery.estimated_delivery_at).toLocaleString()}
                                                </p>
                                            )}
                                        </div>
                                    ) : (
                                        <div className="p-3 rounded-xl bg-neutral-950 text-neutral-400 text-xs font-mono">
                                            Awaiting merchant packaging & courier pickup assignment.
                                        </div>
                                    )}

                                    {/* Items Preview */}
                                    <div className="space-y-2">
                                        {order.items?.map((item) => (
                                            <div key={item.id} className="flex items-center justify-between gap-3 text-xs">
                                                <div className="flex items-center gap-2.5 truncate">
                                                    <img
                                                        src={item.product?.featured_image || ''}
                                                        alt={item.product?.name}
                                                        className="w-9 h-9 rounded-lg object-cover bg-neutral-800 shrink-0"
                                                    />
                                                    <span className="text-neutral-200 truncate">{item.product?.name}</span>
                                                </div>
                                                <span className="font-mono text-neutral-400 shrink-0">
                                                    Qty: {item.quantity} × {formatPrice(item.unit_price)}
                                                </span>
                                            </div>
                                        ))}
                                    </div>

                                    {/* Action Bar */}
                                    <div className="pt-2 flex items-center justify-between border-t border-neutral-800 font-mono text-xs">
                                        <span className="font-bold text-white">
                                            Total: {formatPrice(order.total_amount)}
                                        </span>

                                        <Link
                                            href={route('orders.show', order.id)}
                                            className="px-3 py-1.5 bg-neutral-800 hover:bg-[#E00D42] text-white rounded-lg text-[11px] font-bold uppercase transition flex items-center gap-1.5"
                                        >
                                            <span>Full Dispatch Details</span>
                                            <ArrowRight className="w-3 h-3" />
                                        </Link>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* 3. 14 VERIFIED DEPARTMENTS FAST DIRECTORY */}
                <div className="p-6 rounded-2xl bg-neutral-900 border border-neutral-800 space-y-4">
                    <div className="flex items-center justify-between font-mono text-xs">
                        <div>
                            <h2 className="font-bold text-white uppercase tracking-wider text-sm font-sans">
                                14 Verified Departments
                            </h2>
                            <p className="text-[11px] text-neutral-400 uppercase">
                                OFFICIAL REGULATED CATEGORIES • VERIFIED STORE INVENTORIES
                            </p>
                        </div>

                        <Link href={route('products.index')} className="text-[#E00D42] hover:underline font-bold uppercase text-[11px] font-mono">
                            Browse All ➔
                        </Link>
                    </div>

                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-7 gap-2.5 font-mono text-xs">
                        {categories.map((category) => (
                            <Link
                                key={category.id}
                                href={route('products.index', { category: category.slug })}
                                className="p-3 rounded-xl bg-neutral-950/80 hover:bg-neutral-800 border border-neutral-800/80 hover:border-[#E00D42]/50 transition group flex flex-col justify-between"
                            >
                                <span className="text-neutral-300 group-hover:text-white font-bold truncate text-[11px]">
                                    {category.name}
                                </span>
                                <span className="text-[10px] text-neutral-500 group-hover:text-[#E00D42] mt-2 font-mono">
                                    {category.products_count ?? 0} items
                                </span>
                            </Link>
                        ))}
                    </div>
                </div>

                {/* 4. RECOMMENDED PRODUCTS ACROSS DEPARTMENTS */}
                <div className="space-y-4">
                    <div className="flex items-center justify-between font-mono text-xs">
                        <div className="flex items-center gap-2">
                            <span className="w-2 h-2 rounded-full bg-[#E00D42]"></span>
                            <h2 className="font-bold uppercase tracking-wider text-white text-sm font-sans">
                                Recommended For You
                            </h2>
                        </div>

                        <Link href={route('products.index')} className="text-[#E00D42] hover:underline font-bold uppercase text-[11px]">
                            Full Directory ➔
                        </Link>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-5">
                        {recommendedProducts.map((product) => {
                            const isAdding = addingProductId === product.id;
                            const isSuccess = addedSuccessId === product.id;

                            return (
                                <div 
                                    key={product.id}
                                    className="p-4 rounded-2xl bg-neutral-900 border border-neutral-800 hover:border-neutral-700 transition flex flex-col justify-between group shadow-sm"
                                >
                                    <div className="space-y-3">
                                        <Link href={route('products.show', product.slug)} className="block aspect-square rounded-xl bg-neutral-950 overflow-hidden relative">
                                            <img
                                                src={product.featured_image || ''}
                                                alt={product.name}
                                                className="w-full h-full object-cover group-hover:scale-105 transition duration-300"
                                            />
                                            {product.category && (
                                                <span className="absolute top-2.5 left-2.5 px-2 py-0.5 rounded bg-black/80 backdrop-blur-xs text-white font-mono text-[9px] font-bold uppercase">
                                                    {product.category.name}
                                                </span>
                                            )}
                                        </Link>

                                        <div className="space-y-1 font-mono">
                                            <div className="flex items-center justify-between text-[10px] text-neutral-400">
                                                <span>{product.shop?.name || 'Verified Merchant'}</span>
                                                <div className="flex items-center gap-1 text-amber-400 font-bold">
                                                    <Star className="w-3 h-3 fill-amber-400" />
                                                    <span>{Number(product.rating || 5.0).toFixed(1)}</span>
                                                </div>
                                            </div>

                                            <Link 
                                                href={route('products.show', product.slug)}
                                                className="block font-sans font-bold text-sm text-white group-hover:text-[#E00D42] transition truncate"
                                            >
                                                {product.name}
                                            </Link>

                                            <div className="pt-1 flex items-baseline gap-2">
                                                <span className="text-base font-black font-sans text-white">
                                                    {formatPrice(product.price)}
                                                </span>
                                                {product.compare_at_price && Number(product.compare_at_price) > Number(product.price) && (
                                                    <span className="text-xs text-neutral-500 line-through">
                                                        {formatPrice(product.compare_at_price)}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        onClick={(e) => handleAddToCart(e, product.id)}
                                        disabled={isAdding}
                                        className={`mt-4 w-full py-2 rounded-lg font-mono text-xs font-bold uppercase tracking-wider transition flex items-center justify-center gap-1.5 ${
                                            isSuccess 
                                                ? 'bg-emerald-600 text-white'
                                                : 'bg-neutral-800 hover:bg-[#E00D42] text-white active:scale-[0.98]'
                                        }`}
                                    >
                                        {isSuccess ? (
                                            <>
                                                <Check className="w-3.5 h-3.5" />
                                                <span>Added to Bag</span>
                                            </>
                                        ) : isAdding ? (
                                            <span>Adding...</span>
                                        ) : (
                                            <>
                                                <ShoppingCart className="w-3.5 h-3.5" />
                                                <span>Add to Bag</span>
                                            </>
                                        )}
                                    </button>
                                </div>
                            );
                        })}
                    </div>
                </div>

                {/* 5. VOUCHERS & DISCOUNTS WALLET */}
                <div className="p-6 rounded-2xl bg-neutral-900 border border-neutral-800 space-y-4">
                    <div className="flex items-center justify-between font-mono text-xs">
                        <div className="flex items-center gap-2">
                            <Tag className="w-4 h-4 text-[#E00D42]" />
                            <h2 className="font-bold text-white uppercase tracking-wider text-sm font-sans">
                                Active Voucher Wallet
                            </h2>
                        </div>
                        <span className="text-[11px] text-neutral-400 uppercase">3 AVAILABLE DISCOUNTS</span>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4 font-mono text-xs">
                        {vouchers.map((voucher) => (
                            <div 
                                key={voucher.code}
                                className="p-4 rounded-xl bg-neutral-950 border border-dashed border-neutral-700 space-y-3 flex flex-col justify-between"
                            >
                                <div className="space-y-1.5">
                                    <div className="flex items-center justify-between">
                                        <span className="px-2 py-0.5 rounded bg-[#E00D42]/20 text-[#E00D42] text-[9px] font-bold uppercase tracking-wider border border-[#E00D42]/30">
                                            {voucher.badge}
                                        </span>
                                        <span className="text-[10px] text-neutral-500">{voucher.expires_at}</span>
                                    </div>
                                    <h4 className="text-base font-black font-sans text-white">{voucher.discount}</h4>
                                    <p className="text-[11px] text-neutral-400">{voucher.description}</p>
                                </div>

                                <div className="pt-2 border-t border-neutral-800 flex items-center justify-between">
                                    <code className="px-2 py-1 bg-neutral-900 rounded font-bold text-white text-xs border border-neutral-700">
                                        {voucher.code}
                                    </code>

                                    <button
                                        type="button"
                                        onClick={() => copyVoucher(voucher.code)}
                                        className="px-3 py-1 bg-[#E00D42] hover:bg-[#C20836] text-white font-bold text-[11px] rounded transition uppercase flex items-center gap-1"
                                    >
                                        {copiedCode === voucher.code ? (
                                            <>
                                                <Check className="w-3 h-3" />
                                                <span>Copied</span>
                                            </>
                                        ) : (
                                            <>
                                                <Copy className="w-3 h-3" />
                                                <span>Copy Code</span>
                                            </>
                                        )}
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

            </div>
        </DashboardLayout>
    );
}
