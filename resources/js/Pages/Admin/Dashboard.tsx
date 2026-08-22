import React from 'react';
import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Order, User } from '@/types';
import { 
    Users, 
    DollarSign, 
    ShoppingCart, 
    Truck, 
    Package, 
    ShieldCheck, 
    TrendingUp, 
    ArrowRight,
    Store,
    UserCheck
} from 'lucide-react';

interface Props {
    stats: {
        totalUsers: number;
        usersByRole: {
            buyers: number;
            sellers: number;
            couriers: number;
            admins: number;
        };
        totalRevenue: number;
        totalOrders: number;
        totalProducts: number;
        activeDeliveries: number;
    };
    recentOrders: Order[];
    recentUsers: User[];
}

export default function AdminDashboard({ stats, recentOrders, recentUsers }: Props) {
    return (
        <DashboardLayout
            title="System Administration & Analytics"
            subtitle="Platform-wide governance and telemetry"
            actions={
                <Link
                    href={route('admin.users')}
                    className="flex items-center gap-1.5 px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold rounded-xl shadow-xs transition"
                >
                    <Users className="w-3.5 h-3.5" />
                    <span>Manage Platform Users</span>
                </Link>
            }
        >
            <Head title="Platform Control Center — Bagoo Admin" />

            <div className="space-y-8">
                {/* Platform Summary Metrics */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-xs space-y-2">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-bold uppercase text-slate-400">Total GMV</span>
                            <DollarSign className="w-5 h-5 text-emerald-600" />
                        </div>
                        <h3 className="text-2xl font-black text-slate-900">${stats.totalRevenue.toFixed(2)}</h3>
                        <p className="text-[11px] text-emerald-600 font-bold flex items-center gap-1">
                            <TrendingUp className="w-3 h-3" /> Processed transactions
                        </p>
                    </div>

                    <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-xs space-y-2">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-bold uppercase text-slate-400">Total Users</span>
                            <Users className="w-5 h-5 text-indigo-600" />
                        </div>
                        <h3 className="text-2xl font-black text-slate-900">{stats.totalUsers}</h3>
                        <p className="text-[11px] text-slate-500">
                            {stats.usersByRole.buyers} buyers • {stats.usersByRole.sellers} sellers • {stats.usersByRole.couriers} couriers
                        </p>
                    </div>

                    <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-xs space-y-2">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-bold uppercase text-slate-400">Platform Orders</span>
                            <ShoppingCart className="w-5 h-5 text-blue-600" />
                        </div>
                        <h3 className="text-2xl font-black text-slate-900">{stats.totalOrders}</h3>
                        <p className="text-[11px] text-slate-500">All-time lifetime orders</p>
                    </div>

                    <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-xs space-y-2">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-bold uppercase text-slate-400">Active Shipments</span>
                            <Truck className="w-5 h-5 text-amber-600" />
                        </div>
                        <h3 className="text-2xl font-black text-slate-900">{stats.activeDeliveries}</h3>
                        <p className="text-[11px] text-amber-600 font-bold">Currently in transit</p>
                    </div>
                </div>

                {/* Role Allocation Bar */}
                <div className="bg-white rounded-3xl border border-slate-200 p-6 space-y-4">
                    <h3 className="font-bold text-sm text-slate-900">User Role Distribution</h3>
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <div className="p-4 rounded-2xl bg-indigo-50 border border-indigo-100">
                            <p className="text-xs text-indigo-600 font-bold">Buyers</p>
                            <p className="text-2xl font-black text-indigo-900 mt-1">{stats.usersByRole.buyers}</p>
                        </div>
                        <div className="p-4 rounded-2xl bg-emerald-50 border border-emerald-100">
                            <p className="text-xs text-emerald-600 font-bold">Sellers / Merchants</p>
                            <p className="text-2xl font-black text-emerald-900 mt-1">{stats.usersByRole.sellers}</p>
                        </div>
                        <div className="p-4 rounded-2xl bg-amber-50 border border-amber-100">
                            <p className="text-xs text-amber-600 font-bold">Couriers & Logistics</p>
                            <p className="text-2xl font-black text-amber-900 mt-1">{stats.usersByRole.couriers}</p>
                        </div>
                        <div className="p-4 rounded-2xl bg-rose-50 border border-rose-100">
                            <p className="text-xs text-rose-600 font-bold">System Administrators</p>
                            <p className="text-2xl font-black text-rose-900 mt-1">{stats.usersByRole.admins}</p>
                        </div>
                    </div>
                </div>

                {/* Recent Platform Activities */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
                    {/* Recent Orders */}
                    <div className="lg:col-span-7 bg-white rounded-3xl border border-slate-200 p-6 space-y-4">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <h3 className="font-bold text-sm text-slate-900">Recent Platform Transactions</h3>
                        </div>

                        <div className="divide-y divide-slate-100 space-y-3">
                            {recentOrders.map((order) => (
                                <div key={order.id} className="pt-3 first:pt-0 flex items-center justify-between text-xs">
                                    <div>
                                        <p className="font-mono font-bold text-slate-900">#{order.order_number}</p>
                                        <p className="text-slate-400">
                                            Buyer: {order.buyer?.name} • Courier: {order.delivery?.courier?.name || 'Unassigned'}
                                        </p>
                                    </div>
                                    <div className="text-right">
                                        <p className="font-black text-slate-900">${Number(order.total_amount).toFixed(2)}</p>
                                        <span className="capitalize text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">
                                            {order.status.replace('_', ' ')}
                                        </span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Recent Registrations */}
                    <div className="lg:col-span-5 bg-white rounded-3xl border border-slate-200 p-6 space-y-4">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <h3 className="font-bold text-sm text-slate-900">Newly Registered Accounts</h3>
                            <Link href={route('admin.users')} className="text-xs font-bold text-rose-600 hover:text-rose-700">
                                All Users
                            </Link>
                        </div>

                        <div className="space-y-3">
                            {recentUsers.map((user) => (
                                <div key={user.id} className="flex items-center justify-between p-2.5 rounded-2xl bg-slate-50 text-xs">
                                    <div className="flex items-center gap-3">
                                        <div className="w-8 h-8 rounded-full bg-slate-200 text-slate-700 font-bold flex items-center justify-center text-xs">
                                            {user.name.charAt(0)}
                                        </div>
                                        <div>
                                            <p className="font-bold text-slate-800">{user.name}</p>
                                            <p className="text-slate-400 text-[11px]">{user.email}</p>
                                        </div>
                                    </div>
                                    <span className="text-[10px] font-bold uppercase px-2 py-0.5 rounded-md bg-white border border-slate-200 text-slate-700">
                                        {user.role}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </DashboardLayout>
    );
}
