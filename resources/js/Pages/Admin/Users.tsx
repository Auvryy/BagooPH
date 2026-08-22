import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { PaginatedData, Role, User } from '@/types';
import { Users, Search, ShieldCheck, Edit3, X, Check } from 'lucide-react';

interface Props {
    users: PaginatedData<User>;
    filters: {
        search?: string;
        role?: string;
    };
}

export default function AdminUsers({ users, filters }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [editingUser, setEditingUser] = useState<User | null>(null);
    const [selectedRole, setSelectedRole] = useState<Role>('buyer');
    const [selectedStatus, setSelectedStatus] = useState<string>('active');

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(route('admin.users'), { search, role: filters.role }, { preserveState: true });
    };

    const handleRoleFilter = (role?: string) => {
        router.get(route('admin.users'), { search, role: role || undefined }, { preserveState: true });
    };

    const openEditRole = (user: User) => {
        setEditingUser(user);
        setSelectedRole(user.role);
        setSelectedStatus(user.status || 'active');
    };

    const handleSaveRole = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editingUser) return;

        router.patch(route('admin.users.updateRole', editingUser.id), {
            role: selectedRole,
            status: selectedStatus,
        }, {
            onSuccess: () => setEditingUser(null),
        });
    };

    return (
        <DashboardLayout
            title="User & Access Governance"
            subtitle="Configure role permissions and account status"
        >
            <Head title="Users & Roles — Bagoo Admin" />

            <div className="space-y-6">
                {/* Search & Filter bar */}
                <div className="bg-white rounded-3xl border border-slate-200 p-4 flex flex-col sm:flex-row justify-between items-center gap-4">
                    <form onSubmit={handleSearch} className="relative w-full sm:w-80">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search name or email..."
                            className="w-full pl-9 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs"
                        />
                    </form>

                    <div className="flex gap-2 w-full sm:w-auto overflow-x-auto">
                        {['', 'buyer', 'seller', 'courier', 'logistics', 'admin'].map((r) => (
                            <button
                                key={r}
                                onClick={() => handleRoleFilter(r)}
                                className={`px-3 py-1.5 rounded-xl text-xs font-bold capitalize transition whitespace-nowrap ${
                                    (filters.role || '') === r
                                        ? 'bg-rose-600 text-white'
                                        : 'bg-slate-50 text-slate-700 hover:bg-slate-100'
                                }`}
                            >
                                {r || 'All Roles'}
                            </button>
                        ))}
                    </div>
                </div>

                {/* Users Table */}
                <div className="bg-white rounded-3xl border border-slate-200 overflow-hidden shadow-xs">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead className="bg-slate-50 border-b border-slate-200 text-slate-400 font-bold uppercase text-[10px] tracking-wider">
                                <tr>
                                    <th className="py-3.5 px-6">User Profile</th>
                                    <th className="py-3.5 px-4">Current Role</th>
                                    <th className="py-3.5 px-4">Contact Info</th>
                                    <th className="py-3.5 px-4">Account Status</th>
                                    <th className="py-3.5 px-6 text-right">Edit Role / Access</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {users.data.map((u) => (
                                    <tr key={u.id} className="hover:bg-slate-50 transition">
                                        <td className="py-4 px-6">
                                            <div className="flex items-center gap-3">
                                                <div className="w-9 h-9 rounded-full bg-indigo-600 text-white font-bold text-xs flex items-center justify-center">
                                                    {u.name.charAt(0)}
                                                </div>
                                                <div>
                                                    <p className="font-bold text-slate-900">{u.name}</p>
                                                    <p className="text-[11px] text-slate-400">{u.email}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="py-4 px-4">
                                            <span className={`px-2.5 py-1 rounded-md text-[11px] font-bold uppercase ${
                                                u.role === 'admin' ? 'bg-rose-50 text-rose-700' :
                                                u.role === 'seller' ? 'bg-emerald-50 text-emerald-700' :
                                                u.role === 'courier' ? 'bg-amber-50 text-amber-700' :
                                                u.role === 'logistics' ? 'bg-blue-50 text-blue-700' : 'bg-indigo-50 text-indigo-700'
                                            }`}>
                                                {u.role}
                                            </span>
                                        </td>
                                        <td className="py-4 px-4 text-slate-600">
                                            <p>{u.phone || 'No phone set'}</p>
                                            <p className="text-[11px] text-slate-400">{u.city || 'No city set'}</p>
                                        </td>
                                        <td className="py-4 px-4">
                                            <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold capitalize ${
                                                u.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                                            }`}>
                                                {u.status || 'active'}
                                            </span>
                                        </td>
                                        <td className="py-4 px-6 text-right">
                                            <button
                                                onClick={() => openEditRole(u)}
                                                className="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl transition inline-flex items-center gap-1"
                                            >
                                                <Edit3 className="w-3.5 h-3.5" />
                                                <span>Modify Role</span>
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {/* Edit Role Modal */}
            {editingUser && (
                <div className="fixed inset-0 bg-slate-900/60 z-50 flex items-center justify-center p-4">
                    <div className="bg-white rounded-3xl max-w-md w-full p-6 sm:p-8 space-y-6 shadow-2xl">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <h3 className="font-bold text-base text-slate-900">Modify Role: {editingUser.name}</h3>
                            <button onClick={() => setEditingUser(null)} className="p-1 text-slate-400 hover:text-black">
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <form onSubmit={handleSaveRole} className="space-y-4 text-xs">
                            <div>
                                <label className="block font-bold text-slate-700 mb-1">Assign User Role</label>
                                <select
                                    value={selectedRole}
                                    onChange={(e) => setSelectedRole(e.target.value as Role)}
                                    className="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl font-bold text-slate-800"
                                >
                                    <option value="buyer">Buyer (Customer)</option>
                                    <option value="seller">Seller (Merchant Storefront)</option>
                                    <option value="courier">Courier (Rider & Delivery Task Board)</option>
                                    <option value="logistics">Logistics Partner (Hub & Fleet Management)</option>
                                    <option value="admin">Administrator (Full Platform Control)</option>
                                </select>
                            </div>

                            <div>
                                <label className="block font-bold text-slate-700 mb-1">Account Status</label>
                                <select
                                    value={selectedStatus}
                                    onChange={(e) => setSelectedStatus(e.target.value)}
                                    className="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl"
                                >
                                    <option value="active">Active</option>
                                    <option value="pending">Pending Approval</option>
                                    <option value="suspended">Suspended / Restricted</option>
                                </select>
                            </div>

                            <div className="flex justify-end gap-3 pt-4 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={() => setEditingUser(null)}
                                    className="px-4 py-2 bg-slate-100 text-slate-700 font-bold rounded-xl"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    className="px-5 py-2 bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl"
                                >
                                    Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </DashboardLayout>
    );
}
