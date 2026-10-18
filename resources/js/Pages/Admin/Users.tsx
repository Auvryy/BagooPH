import React, { useEffect, useRef, useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { PageProps, PaginatedData, User } from '@/types';
import { Search } from 'lucide-react';
import AdminDirectoryPagination from '@/Components/AdminDirectoryPagination';
import { adminDirectoryProps } from '@/utils/portalNavigation';
import { visitPortal } from '@/utils/catalogueRuntime';

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
    const [pending, setPending] = useState(false);
    const busy = useRef(false);
    const { errors } = usePage<PageProps>().props;
    useEffect(() => { setSearch(filters.search || ''); }, [filters.search, filters.role]);

    const load = (role = filters.role) => {
        if (busy.current) return;
        busy.current = true; setPending(true);
        const query = new URLSearchParams();
        if (search.trim()) query.set('search', search.trim());
        if (role) query.set('role', role);
        const finish = () => { busy.current = false; setPending(false); };
        void visitPortal(activityBaseUrl + (query.size ? `?${query}` : ''), {
            only: adminDirectoryProps('users'), preserveState: true,
            onFinish: finish, onCancel: finish,
        });
    };

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        load();
    };

    const handleRoleFilter = (role?: string) => {
        load(role);
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
                    <form onSubmit={handleSearch} className="flex w-full gap-2 sm:w-96" aria-busy={pending}>
                        <div className="relative min-w-0 flex-1"><Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                        <input
                            type="text"
                            value={search}
                            aria-label="Search users by name or email"
                            aria-invalid={Boolean(errors.search)}
                            maxLength={100}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search name or email..."
                            className="w-full pl-9 pr-4 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs focus:border-[#E00D42] focus:ring-[#E00D42]"
                        />
                        </div><button disabled={pending} className="rounded-xl bg-[#E00D42] px-3 py-2 text-xs font-semibold text-white disabled:opacity-60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]">{pending ? 'Loading…' : 'Search'}</button>
                    </form>

                    <div className="flex gap-2 w-full sm:w-auto overflow-x-auto">
                        {['', 'buyer', 'seller', 'courier', 'logistics', 'admin'].map((r) => (
                            <button
                                key={r}
                                disabled={pending}
                                aria-pressed={(filters.role || '') === r}
                                onClick={() => handleRoleFilter(r)}
                                className={`px-3 py-1.5 rounded-xl text-xs font-bold capitalize transition whitespace-nowrap disabled:opacity-60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42] ${
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
                {Object.keys(errors).length > 0 && <div role="alert" className="text-sm text-rose-700">{Object.entries(errors).map(([key, message]) => <p key={key}>{message}</p>)}</div>}

                <Link href={`${activityBaseUrl.replace(/\/users$/, '')}/identity-corrections`} className="inline-flex rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold">Review identity corrections</Link>
                <p className="text-sm text-slate-600">
                    Account roles are fixed. Register a separate account for another role and complete its required approval.
                </p>

                {/* Users Table */}
                <div className="bg-white rounded-3xl border border-slate-200 overflow-hidden shadow-xs" aria-busy={pending}>
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead className="bg-slate-50 border-b border-slate-200 text-slate-400 font-bold uppercase text-[10px] tracking-wider">
                                <tr>
                                    <th className="py-3.5 px-6">User Profile</th>
                                    <th className="py-3.5 px-4">Registered Role</th>
                                    <th className="py-3.5 px-4">Contact Info</th>
                                    <th className="py-3.5 px-4">Approval / Activity</th>
                                    <th className="py-3.5 px-4">Activity review</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {users.data.length === 0 && <tr><td colSpan={5} className="px-6 py-12 text-center text-sm text-slate-500">No users match these filters.</td></tr>}
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
                                            <p className="mt-2 text-[11px] text-slate-500">Approval: {u.role === 'admin' ? 'Controlled admin access' : (u.kyc_status?.replaceAll('_', ' ') || 'Unknown')}</p>
                                        </td>
                                        <td className="py-4 px-4"><div className="flex flex-wrap gap-2"><Link href={`${activityBaseUrl}/${u.id}/context`} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-[#E00D42] hover:bg-rose-50">Inspect account</Link><Link href={`${activityBaseUrl}/${u.id}/activity`} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Review activity</Link>{(u.role === 'admin' || ['approved', 'verified'].includes(u.kyc_status ?? '')) && <Link href={`${activityBaseUrl}/${u.id}/identity-corrections`} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Identity corrections</Link>}<Link href={`${activityBaseUrl}/${u.id}/closure`} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Review closure</Link></div></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <AdminDirectoryPagination page={users} kind="users" />
                </div>
            </div>

        </DashboardLayout>
    );
}
