import React, { useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    ChevronDown,
    ClipboardList,
    History,
    LogOut,
    Menu,
    MessageSquare,
    Power,
    UserRound,
    X,
} from 'lucide-react';
import BagooLogo from '@/Components/BagooLogo';
import { PageProps } from '@/types';

interface Props {
    children: React.ReactNode;
    title: string;
    subtitle?: string;
    isOnline?: boolean;
}

export default function CourierLayout({
    children,
    title,
    subtitle,
    isOnline = false,
}: Props) {
    const { auth, flash } = usePage<PageProps>().props;
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [userMenuOpen, setUserMenuOpen] = useState(false);
    const [dutyLoading, setDutyLoading] = useState(false);
    const userMenuTimeout = useRef<ReturnType<typeof setTimeout> | null>(null);
    const user = auth.user;

    const navItems = [
        {
            name: 'Dispatch board',
            href: route('courier.deliveries'),
            icon: ClipboardList,
            current: route().current('courier.deliveries'),
        },
        {
            name: 'Completed trips',
            href: route('courier.earnings'),
            icon: History,
            current: route().current('courier.earnings'),
        },
        {
            name: 'Messages',
            href: route('courier.messages'),
            icon: MessageSquare,
            current: route().current('courier.messages'),
        },
        {
            name: 'Rider profile',
            href: route('courier.profile'),
            icon: UserRound,
            current: route().current('courier.profile'),
        },
    ];

    const toggleDuty = () => {
        setDutyLoading(true);
        router.post(
            route('courier.toggleDuty'),
            { is_available: !isOnline },
            {
                preserveScroll: true,
                onFinish: () => setDutyLoading(false),
            },
        );
    };

    const openUserMenu = () => {
        if (userMenuTimeout.current) {
            clearTimeout(userMenuTimeout.current);
        }
        setUserMenuOpen(true);
    };

    const closeUserMenu = () => {
        userMenuTimeout.current = setTimeout(() => setUserMenuOpen(false), 200);
    };

    return (
        <div className="flex h-[100dvh] min-h-[100dvh] w-full overflow-hidden bg-slate-50 font-sans text-slate-900 antialiased">
            {sidebarOpen && (
                <button
                    type="button"
                    aria-label="Close navigation"
                    className="fixed inset-0 z-40 bg-slate-950/35 lg:hidden"
                    onClick={() => setSidebarOpen(false)}
                />
            )}

            <aside
                className={`fixed inset-y-2 left-2 z-50 flex w-[min(20rem,calc(100vw-1rem))] flex-col overflow-hidden rounded-lg border border-slate-300 bg-white text-slate-900 shadow-2xl transition-transform duration-200 lg:static lg:inset-auto lg:z-30 lg:w-72 lg:shrink-0 lg:translate-x-0 lg:rounded-none lg:border-y-0 lg:border-l-0 lg:shadow-none ${
                    sidebarOpen ? 'translate-x-0' : 'max-lg:-translate-x-[calc(100%+1rem)]'
                }`}
            >
                <div className="border-b border-slate-300 p-4 sm:p-5">
                    <div className="flex items-center justify-between">
                        <Link href={route('courier.deliveries')} className="flex items-center gap-2.5">
                            <BagooLogo className="h-8 w-8" rounded="rounded-sm" />
                            <div>
                                <p className="text-sm font-extrabold text-slate-950">
                                    Bagoo<span className="text-[#E00D42]">PH</span>
                                </p>
                                <p className="text-xs text-slate-500">Courier operations</p>
                            </div>
                        </Link>
                        <button
                            type="button"
                            aria-label="Close navigation"
                            onClick={() => setSidebarOpen(false)}
                            className="rounded-sm border border-slate-300 p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-950 lg:hidden"
                        >
                            <X className="h-5 w-5" />
                        </button>
                    </div>

                    <div className="mt-5 rounded-lg border border-slate-300 bg-slate-50 p-3.5">
                        <div className="flex items-center justify-between gap-3">
                            <div className="min-w-0">
                                <p className="truncate text-sm font-bold text-slate-900">{user?.name}</p>
                                <p className="mt-0.5 text-xs leading-5 text-slate-500">
                                    {isOnline ? 'Available for eligible work' : 'Not accepting new work'}
                                </p>
                            </div>
                            <span
                                className={`rounded-sm border px-2 py-1 text-[11px] font-bold ${
                                    isOnline
                                        ? 'border-emerald-300 bg-emerald-50 text-emerald-800'
                                        : 'border-slate-300 bg-white text-slate-600'
                                }`}
                            >
                                {isOnline ? 'ON DUTY' : 'OFF DUTY'}
                            </span>
                        </div>
                        <button
                            type="button"
                            onClick={toggleDuty}
                            disabled={dutyLoading}
                            className={`mt-3 flex w-full items-center justify-center gap-2 rounded-sm border px-3 py-2.5 text-sm font-semibold transition disabled:cursor-wait disabled:opacity-60 ${
                                isOnline
                                    ? 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100'
                                    : 'border-[#E00D42] bg-[#E00D42] text-white hover:bg-[#C20836]'
                            }`}
                        >
                            <Power className="h-4 w-4" />
                            {dutyLoading
                                ? 'Updating...'
                                : isOnline
                                  ? 'Go off duty'
                                  : 'Go on duty'}
                        </button>
                    </div>
                </div>

                <nav className="flex-1 space-y-1 overflow-y-auto p-3 sm:p-4">
                    <p className="px-3 pb-2 text-xs font-bold text-slate-500">Rider workspace</p>
                    {navItems.map((item) => (
                        <Link
                            key={item.name}
                            href={item.href}
                            onClick={() => setSidebarOpen(false)}
                            className={`flex items-center gap-3 rounded-sm border px-3 py-2.5 text-sm font-semibold transition ${
                                item.current
                                    ? 'border-[#E00D42] bg-[#FDF2F4] text-[#C20836]'
                                    : 'border-transparent text-slate-700 hover:border-slate-300 hover:bg-slate-50'
                            }`}
                        >
                            <item.icon className="h-4 w-4 shrink-0" />
                            <span>{item.name}</span>
                        </Link>
                    ))}
                </nav>

                <div className="border-t border-slate-300 p-3 sm:p-4">
                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        className="flex w-full items-center justify-center gap-2 rounded-sm border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                    >
                        <LogOut className="h-4 w-4" />
                        Sign out
                    </Link>
                </div>
            </aside>

            <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
                <header className="flex min-h-[4.5rem] shrink-0 items-center justify-between border-b border-slate-300 bg-white px-3 py-3 sm:px-6">
                    <div className="flex min-w-0 items-center gap-3">
                        <button
                            type="button"
                            aria-label="Open navigation"
                            onClick={() => setSidebarOpen(true)}
                            className="rounded-sm border border-slate-300 p-2 text-slate-600 lg:hidden"
                        >
                            <Menu className="h-5 w-5" />
                        </button>
                        <div className="min-w-0">
                            <h1 className="truncate text-base font-extrabold tracking-tight text-slate-950 sm:text-lg">{title}</h1>
                            {subtitle && (
                                <p className="mt-0.5 truncate text-xs text-slate-500 sm:text-sm">{subtitle}</p>
                            )}
                        </div>
                    </div>

                    <div className="ml-3 flex items-center gap-2">
                        <button
                            type="button"
                            onClick={toggleDuty}
                            disabled={dutyLoading}
                            className={`inline-flex items-center gap-1.5 rounded-sm border px-2 py-2 text-xs font-bold sm:hidden ${
                                isOnline
                                    ? 'border-emerald-300 bg-emerald-50 text-emerald-800'
                                    : 'border-slate-300 bg-white text-slate-700'
                            }`}
                        >
                            <Power className="h-3.5 w-3.5" />
                            {isOnline ? 'On duty' : 'Off duty'}
                        </button>
                        <div
                            className="relative"
                            onMouseEnter={openUserMenu}
                            onMouseLeave={closeUserMenu}
                        >
                        <button
                            type="button"
                            onClick={() => setUserMenuOpen((open) => !open)}
                            className="flex items-center gap-2 rounded-sm border border-transparent p-1.5 hover:border-slate-300 hover:bg-slate-50"
                        >
                            <span className="flex h-8 w-8 items-center justify-center rounded-sm bg-slate-900 text-xs font-bold text-white">
                                {user?.name?.charAt(0).toUpperCase()}
                            </span>
                            <span className="hidden text-left sm:block">
                                <span className="block max-w-40 truncate text-xs font-semibold">{user?.name}</span>
                                <span className="block text-[11px] text-slate-500">Courier account</span>
                            </span>
                            <ChevronDown className="hidden h-3.5 w-3.5 text-slate-400 sm:block" />
                        </button>

                        {userMenuOpen && (
                            <div
                                className="absolute right-0 top-full z-50 -mt-px w-56 pt-2"
                                onMouseEnter={openUserMenu}
                                onMouseLeave={closeUserMenu}
                            >
                                <div className="rounded-md border border-slate-300 bg-white p-1.5 shadow-xl">
                                    <div className="border-b border-slate-200 px-3 py-2">
                                        <p className="truncate text-xs font-semibold">{user?.name}</p>
                                        <p className="truncate text-[11px] text-slate-500">{user?.email}</p>
                                    </div>
                                    <Link
                                        href={route('courier.profile')}
                                        className="mt-1 flex items-center gap-2 rounded-sm px-3 py-2 text-xs text-slate-700 hover:bg-slate-100"
                                    >
                                        <UserRound className="h-4 w-4" />
                                        View rider profile
                                    </Link>
                                    <Link
                                        href={route('logout')}
                                        method="post"
                                        as="button"
                                        className="flex w-full items-center gap-2 rounded-sm px-3 py-2 text-xs text-rose-700 hover:bg-rose-50"
                                    >
                                        <LogOut className="h-4 w-4" />
                                        Sign out
                                    </Link>
                                </div>
                            </div>
                        )}
                        </div>
                    </div>
                </header>

                {flash.success && (
                    <div className="flex shrink-0 items-center gap-2 border-b border-emerald-700 bg-emerald-600 px-4 py-2.5 text-sm font-medium text-white sm:px-6">
                        <CheckCircle2 className="h-4 w-4 shrink-0" />
                        {flash.success}
                    </div>
                )}
                {flash.error && (
                    <div className="shrink-0 border-b border-rose-800 bg-[#E00D42] px-4 py-2.5 text-sm font-medium text-white sm:px-6">
                        {flash.error}
                    </div>
                )}

                <main className="flex-1 overflow-y-auto p-3 sm:p-5 lg:p-6">
                    <div className="mx-auto max-w-7xl">{children}</div>
                </main>
            </div>
        </div>
    );
}
