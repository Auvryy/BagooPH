import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { PaginatedData, User } from '@/types';
import { Search } from 'lucide-react';

interface Props {
    users: PaginatedData<User>;
    activityBaseUrl: string;
    filters: {
        search?: string;
        role?: string;
    };
}

export default function AdminUsers({ users, filters, activityBaseUrl }: Props) {
    const [search, setSearch] = useState(filters.search || '');

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(route('admin.users'), { search, role: filters.role }, { preserveState: true });
    };

    const handleRoleFilter = (role?: string) => {
        router.get(route('admin.users'), { search, role: role || undefined }, { preserveState: true });
    };

    return (
        <DashboardLayout
            title="User & Access Governance"
            subtitle="Review accounts by role and account status"
        >
            <Head title="Users — Bagoo Admin" />

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

                <p className="text-sm text-slate-600">
                    Account roles are fixed. Register a separate account for another role and complete its required approval.
                </p>

                {/* Users Table */}
                <div className="bg-white rounded-3xl border border-slate-200 overflow-hidden shadow-xs">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead className="bg-slate-50 border-b border-slate-200 text-slate-400 font-bold uppercase text-[10px] tracking-wider">
                                <tr>
                                    <th className="py-3.5 px-6">User Profile</th>
                                    <th className="py-3.5 px-4">Registered Role</th>
                                    <th className="py-3.5 px-4">Contact Info</th>
                                    <th className="py-3.5 px-4">Account Status</th>
                                    <th className="py-3.5 px-4">Activity review</th>
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
                                                {u.status || 'Unknown'}
                                            </span>
                                        </td>
                                        <td className="py-4 px-4"><Link href={`${activityBaseUrl}/${u.id}/activity`} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Review activity</Link></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </DashboardLayout>
    );
}
