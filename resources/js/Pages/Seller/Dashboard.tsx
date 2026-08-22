import React from 'react';
import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { OrderItem, Product, Shop } from '@/types';
import { 
    DollarSign, 
    Package, 
    ShoppingCart, 
    Store, 
    ArrowRight, 
    Plus, 
    TrendingUp,
    Star,
    CheckCircle2
} from 'lucide-react';

interface Props {
    shop: Shop;
    stats: {
        totalProducts: number;
        totalSales: number;
        totalRevenue: number;
    };
    recentOrders: OrderItem[];
    topProducts: Product[];
}

export default function SellerDashboard({ shop, stats, recentOrders, topProducts }: Props) {
    return (
        <DashboardLayout
            title="Seller Management Hub"
            subtitle={`Managing storefront: ${shop.name}`}
            actions={
                <Link
                    href={route('seller.products.index')}
                    className="flex items-center gap-1.5 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl shadow-xs transition"
                >
                    <Plus className="w-3.5 h-3.5" />
                    <span>Add New Product</span>
                </Link>
            }
        >
            <Head title="Seller Hub — Bagoo" />

            <div className="space-y-8">
                {/* Metric Cards */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-6">
                    <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-xs flex items-center gap-5">
                        <div className="w-14 h-14 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold">
                            <DollarSign className="w-7 h-7" />
                        </div>
                        <div>
                            <p className="text-xs font-bold uppercase text-slate-400">Total Revenue</p>
                            <h3 className="text-2xl font-black text-slate-900">${stats.totalRevenue.toFixed(2)}</h3>
                            <span className="text-[11px] text-emerald-600 font-bold flex items-center gap-0.5">
                                <TrendingUp className="w-3 h-3" /> Live sales balance
                            </span>
                        </div>
                    </div>

                    <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-xs flex items-center gap-5">
                        <div className="w-14 h-14 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold">
                            <ShoppingCart className="w-7 h-7" />
                        </div>
                        <div>
                            <p className="text-xs font-bold uppercase text-slate-400">Items Sold</p>
                            <h3 className="text-2xl font-black text-slate-900">{stats.totalSales}</h3>
                            <span className="text-[11px] text-slate-500 font-medium">Customer units fulfilled</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-xs flex items-center gap-5">
                        <div className="w-14 h-14 rounded-2xl bg-violet-50 text-violet-600 flex items-center justify-center font-bold">
                            <Package className="w-7 h-7" />
                        </div>
                        <div>
                            <p className="text-xs font-bold uppercase text-slate-400">Catalog Inventory</p>
                            <h3 className="text-2xl font-black text-slate-900">{stats.totalProducts}</h3>
                            <span className="text-[11px] text-slate-500 font-medium">Active marketplace listings</span>
                        </div>
                    </div>
                </div>

                {/* Main Content Grid: Recent Orders & Top Selling Products */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
                    {/* Left: Recent Customer Orders */}
                    <div className="lg:col-span-7 bg-white rounded-3xl border border-slate-200 p-6 space-y-4">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <h3 className="font-bold text-sm text-slate-900">Recent Customer Purchases</h3>
                            <Link href={route('seller.orders.index')} className="text-xs font-bold text-emerald-600 hover:text-emerald-700 flex items-center gap-1">
                                <span>View all orders</span>
                                <ArrowRight className="w-3 h-3" />
                            </Link>
                        </div>

                        {recentOrders.length === 0 ? (
                            <p className="text-xs text-slate-400 py-6 text-center">No orders received yet.</p>
                        ) : (
                            <div className="divide-y divide-slate-100 space-y-3">
                                {recentOrders.map((item) => (
                                    <div key={item.id} className="pt-3 first:pt-0 flex items-center justify-between text-xs">
                                        <div className="flex items-center gap-3">
                                            <img
                                                src={item.product?.featured_image || ''}
                                                alt={item.product?.name || ''}
                                                className="w-10 h-10 rounded-xl object-cover bg-slate-100"
                                            />
                                            <div>
                                                <p className="font-bold text-slate-800 line-clamp-1">{item.product?.name}</p>
                                                <p className="text-slate-400">
                                                    Order #{item.order?.order_number} • Buyer: {item.order?.buyer?.name || 'Customer'}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="text-right">
                                            <p className="font-black text-slate-900">${Number(item.subtotal).toFixed(2)}</p>
                                            <span className="text-[10px] px-2 py-0.5 rounded-full bg-slate-100 font-bold text-slate-600">
                                                Qty: {item.quantity}
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Right: Top Selling Products */}
                    <div className="lg:col-span-5 bg-white rounded-3xl border border-slate-200 p-6 space-y-4">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <h3 className="font-bold text-sm text-slate-900">Top Performing Products</h3>
                            <Link href={route('seller.products.index')} className="text-xs font-bold text-emerald-600 hover:text-emerald-700">
                                Manage
                            </Link>
                        </div>

                        <div className="space-y-3">
                            {topProducts.map((p) => (
                                <div key={p.id} className="flex items-center justify-between p-2.5 rounded-2xl bg-slate-50 text-xs">
                                    <div className="flex items-center gap-3 truncate">
                                        <img src={p.featured_image || ''} alt={p.name} className="w-9 h-9 rounded-xl object-cover" />
                                        <div className="truncate">
                                            <p className="font-bold text-slate-800 truncate">{p.name}</p>
                                            <p className="text-slate-400">${Number(p.price).toFixed(2)}</p>
                                        </div>
                                    </div>
                                    <div className="text-right shrink-0">
                                        <span className="text-xs font-black text-emerald-600">{p.sales_count} sold</span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </DashboardLayout>
    );
}
