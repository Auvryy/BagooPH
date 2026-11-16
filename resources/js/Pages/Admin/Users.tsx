import React, { useEffect, useRef, useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { PageProps, PaginatedData, User } from '@/types';
import { ChevronDown, Search } from 'lucide-react';
import { Badge, buttonClasses, inputClasses } from '@/Components/ui';
import type { BadgeTone } from '@/Components/ui';
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

    const roles: { value: string; label: string }[] = [
        { value: '', label: 'All' }, { value: 'buyer', label: 'Buyers' }, { value: 'seller', label: 'Sellers' },
        { value: 'courier', label: 'Riders' }, { value: 'logistics', label: 'Logistics' }, { value: 'admin', label: 'Admins' },
    ];
    const roleLabel = (role: string) => ({ buyer: 'Buyer', seller: 'Seller', courier: 'Rider', logistics: 'Logistics', admin: 'Admin' } as Record<string, string>)[role] ?? role;
    const approval = (u: User) => u.role === 'admin' ? 'Admin access' : (u.kyc_status ? u.kyc_status.replaceAll('_', ' ').replace(/^./, letter => letter.toUpperCase()) : 'Not started');
    const statusTone = (status?: string): BadgeTone => status === 'active' ? 'success' : status === 'suspended' || status === 'restricted' ? 'warning' : 'neutral';

    return (
        <DashboardLayout title="Users">
            <Head title="Users — Bagoo Admin" />

            <div className="space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-sm text-ink-600"><span className="font-semibold tabular-nums text-ink-900">{users.total ?? users.data.length}</span> accounts{filters.role ? ` · ${roles.find(r => r.value === filters.role)?.label ?? filters.role}` : ''}</p>
                    <Link href={`${activityBaseUrl.replace(/\/users$/, '')}/identity-corrections`} className={buttonClasses('secondary', 'sm')}>Identity corrections</Link>
                </div>

                <div className="flex flex-col gap-3 rounded-card border border-line-200 bg-white p-3 lg:flex-row lg:items-center lg:justify-between">
                    <form onSubmit={handleSearch} className="flex w-full gap-2 lg:max-w-md" aria-busy={pending}>
                        <div className="relative min-w-0 flex-1">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" />
                            <input
                                type="search"
                                value={search}
                                aria-label="Search users by name or email"
                                aria-invalid={Boolean(errors.search)}
                                maxLength={100}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search name or email"
                                className={`${inputClasses} pl-9`}
                            />
                        </div>
                        <button disabled={pending} className={buttonClasses('secondary')}>{pending ? 'Searching…' : 'Search'}</button>
                    </form>
                    <div className="flex gap-1 overflow-x-auto" role="group" aria-label="Filter by role">
                        {roles.map(({ value, label }) => (
                            <button
                                key={value || 'all'}
                                type="button"
                                disabled={pending}
                                aria-pressed={(filters.role || '') === value}
                                onClick={() => handleRoleFilter(value)}
                                className={`h-9 whitespace-nowrap rounded-control px-3 text-[13px] font-semibold disabled:opacity-60 ${(filters.role || '') === value ? 'bg-brand-50 text-brand-700' : 'text-ink-700 hover:bg-surface-100'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>
                {Object.keys(errors).length > 0 && <div role="alert" className="text-[13px] text-brand-700">{Object.entries(errors).map(([key, message]) => <p key={key}>{message}</p>)}</div>}

                <div className="overflow-hidden rounded-card border border-line-200 bg-white" aria-busy={pending}>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[760px] text-left text-sm">
                            <thead className="border-b border-line-200 bg-surface-50 text-xs font-medium uppercase tracking-wide text-ink-600">
                                <tr>
                                    <th scope="col" className="px-4 py-3">Account</th>
                                    <th scope="col" className="px-4 py-3">Role</th>
                                    <th scope="col" className="px-4 py-3">Contact</th>
                                    <th scope="col" className="px-4 py-3">Status</th>
                                    <th scope="col" className="px-4 py-3 text-right"><span className="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line-200">
                                {users.data.length === 0 && <tr><td colSpan={5} className="px-4 py-12 text-center text-sm text-ink-600">No users match these filters.</td></tr>}
                                {users.data.map((u) => (
                                    <tr key={u.id} className="hover:bg-surface-50">
                                        <td className="px-4 py-3">
                                            <Link href={`${activityBaseUrl}/${u.id}/context`} className="flex min-w-0 items-center gap-3">
                                                <span aria-hidden="true" className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface-100 text-[13px] font-semibold text-ink-700">{u.name.charAt(0).toUpperCase()}</span>
                                                <span className="min-w-0">
                                                    <span className="block truncate font-semibold text-ink-900 hover:underline">{u.name}</span>
                                                    <span className="block truncate text-xs text-ink-600">{u.email}</span>
                                                </span>
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 text-ink-700">{roleLabel(u.role)}</td>
                                        <td className="px-4 py-3 text-ink-700">
                                            {u.phone || u.city ? <>{u.phone && <span className="block tabular-nums">{u.phone}</span>}{u.city && <span className="block text-xs text-ink-600">{u.city}</span>}</> : <span className="text-ink-500">Not provided</span>}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge tone={statusTone(u.status)}>{u.status ? u.status.replaceAll('_', ' ').replace(/^./, letter => letter.toUpperCase()) : 'Unknown'}</Badge>
                                            <span className="mt-1 block text-xs text-ink-600">{approval(u)}</span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center justify-end gap-1">
                                                <Link href={`${activityBaseUrl}/${u.id}/context`} className={buttonClasses('secondary', 'sm')}>Open account</Link>
                                                <details className="relative">
                                                    <summary aria-label={`More actions for ${u.name}`} className="flex h-8 cursor-pointer list-none items-center gap-1 rounded-control px-2 text-[13px] font-semibold text-ink-700 hover:bg-surface-100 [&::-webkit-details-marker]:hidden">
                                                        More <ChevronDown aria-hidden="true" className="h-3.5 w-3.5" />
                                                    </summary>
                                                    <div className="absolute right-0 z-10 mt-1 w-48 rounded-control border border-line-200 bg-white py-1 shadow-lg">
                                                        <Link href={`${activityBaseUrl}/${u.id}/activity`} className="block px-3 py-2 text-[13px] text-ink-700 hover:bg-surface-100">Review activity</Link>
                                                        {(u.role === 'admin' || ['approved', 'verified'].includes(u.kyc_status ?? '')) && <Link href={`${activityBaseUrl}/${u.id}/identity-corrections`} className="block px-3 py-2 text-[13px] text-ink-700 hover:bg-surface-100">Identity corrections</Link>}
                                                        <Link href={`${activityBaseUrl}/${u.id}/closure`} className="block px-3 py-2 text-[13px] text-ink-700 hover:bg-surface-100">Review closure</Link>
                                                    </div>
                                                </details>
                                            </div>
                                        </td>
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
