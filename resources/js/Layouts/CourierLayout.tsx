import React, { useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    ChevronDown,
    ClipboardList,
    ExternalLink,
    History,
    LogOut,
    Menu,
    MessageSquare,
    Power,
    Truck,
    UserRound,
    X,
} from 'lucide-react';
import BagooLogo from '@/Components/BagooLogo';
import { useCourierDutyControl } from '@/Components/CourierDutyControl';
import { getDomainUrl } from '@/utils/domain';
import { PageProps } from '@/types';

interface Scope {
    company?: string | null;
    hub?: string | null;
    hubCode?: string | null;
    hub_code?: string | null;
    barangay?: string | null;
    isAssigned?: boolean;
    isOperational?: boolean;
}

interface Props {
    children: React.ReactNode;
    title: string;
    subtitle?: React.ReactNode;
    isOnline?: boolean;
    actions?: React.ReactNode;
    scope?: Scope;
}

export default function CourierLayout({
    children,
    title,
    subtitle,
    isOnline = false,
    actions,
    scope,
}: Props) {
    const { auth, flash } = usePage<PageProps>().props;
    const { url, component } = usePage();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [userMenuOpen, setUserMenuOpen] = useState(false);
    const userMenuTimeout = useRef<ReturnType<typeof setTimeout> | null>(null);
    const user = auth?.user;
    const { confirmationDialog, dutyLoading, requestDutyChange } = useCourierDutyControl(isOnline);

    const isDeliveriesActive =
        component === 'Courier/Deliveries' ||
        url === '/deliveries' ||
        url.startsWith('/deliveries') ||
        url.includes('/courier/deliveries') ||
        url === '/courier';

    const isEarningsActive =
        component === 'Courier/Earnings' ||
        url === '/earnings' ||
        url.startsWith('/earnings') ||
        url.includes('/courier/earnings');

    const isMessagesActive =
        component === 'Courier/Messages' ||
        url === '/messages' ||
        url.startsWith('/messages') ||
        url.includes('/courier/messages');

    const isProfileActive =
        component === 'Courier/Profile' ||
        url === '/profile' ||
        url.startsWith('/profile') ||
        url.includes('/courier/profile');

    const navItems = [
        {
            name: 'Dispatch board',
            href: route('courier.deliveries'),
            icon: ClipboardList,
            current: isDeliveriesActive,
        },
        {
            name: 'Completed trips',
            href: route('courier.earnings'),
            icon: History,
            current: isEarningsActive,
        },
        {
            name: 'Messages',
            href: route('courier.messages'),
            icon: MessageSquare,
            current: isMessagesActive,
        },
        {
            name: 'Rider profile',
            href: route('courier.profile'),
            icon: UserRound,
            current: isProfileActive,
        },
    ];

    const openUserMenu = () => {
        if (userMenuTimeout.current) {
            clearTimeout(userMenuTimeout.current);
        }
        setUserMenuOpen(true);
    };

    const closeUserMenu = () => {
        userMenuTimeout.current = setTimeout(() => setUserMenuOpen(false), 200);
    };

    const hubCode = scope?.hubCode || scope?.hub_code || 'BH-LBN';
    const hubName = scope?.hub || 'Bayan Hub';

    return (
        <div className="h-screen w-full flex overflow-hidden bg-[#F8FAFC] text-slate-900 font-sans antialiased selection:bg-[#E00D42] selection:text-white">
            {/* Backdrop for mobile drawer */}
            {sidebarOpen && (
                <div
                    className="fixed inset-0 bg-black/50 backdrop-blur-xs z-50 lg:hidden transition-opacity"
                    onClick={() => setSidebarOpen(false)}
                />
            )}

            {/* Sidebar (Permanently Fixed on Desktop, Drawer on Mobile) */}
            <aside
                className={`
                    fixed inset-y-0 left-0 z-50 w-64 bg-white text-slate-700 border-r border-slate-200 
                    flex flex-col transition-transform duration-200 ease-in-out
                    ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'} 
                    lg:static lg:translate-x-0 lg:h-full lg:shrink-0 lg:z-30
                `}
            >
                {/* Brand Header */}
                <div className="p-4 border-b border-slate-100 shrink-0 bg-white">
                    <div className="flex items-center justify-between">
                        <Link href={route('courier.deliveries')} className="flex items-center gap-2.5">
                            <BagooLogo className="w-8 h-8 shadow-xs" rounded="rounded-xl" />
                            <div>
                                <span className="text-base font-black tracking-tight text-slate-900">
                                    Bagoo<span className="text-[#E00D42]">PH</span>
                                </span>
                                <span className="block text-[9px] uppercase font-bold tracking-widest text-slate-500 -mt-0.5 font-sans">
                                    Courier Operations
                                </span>
                            </div>
                        </Link>
                        <button
                            type="button"
                            onClick={() => setSidebarOpen(false)}
                            className="lg:hidden p-1.5 text-slate-400 hover:text-slate-700 rounded-lg hover:bg-slate-100 transition cursor-pointer"
                        >
                            <X className="w-5 h-5" />
                        </button>
                    </div>
                </div>

                {/* Rider Fleet Profile Card */}
                <div className="p-3 border-b border-slate-100 bg-slate-50/50 shrink-0">
                    <div className="flex items-center justify-between mb-1.5">
                        <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 font-sans flex items-center gap-1">
                            <Truck className="w-3 h-3 text-[#E00D42]" /> Rider Fleet
                        </span>
                        <span
                            className={`text-[9px] font-bold px-1.5 py-0.5 rounded-xs border uppercase font-sans ${
                                isOnline
                                    ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                                    : 'bg-rose-50 text-rose-700 border-rose-200'
                            }`}
                        >
                            {isOnline ? 'On Duty' : 'Off Duty'}
                        </span>
                    </div>

                    <div className="p-2.5 rounded-lg bg-white border border-slate-200 shadow-2xs">
                        <div className="min-w-0">
                            <p className="text-xs font-bold text-slate-800 truncate">{user?.name || 'Courier Rider'}</p>
                            <p className="text-[10px] text-slate-500 font-sans truncate mt-0.5">
                                Station: <span className="font-semibold text-slate-700">{hubCode}</span> • {hubName}
                            </p>
                        </div>

                        <button
                            type="button"
                            onClick={requestDutyChange}
                            disabled={dutyLoading}
                            className={`mt-2.5 w-full flex items-center justify-center gap-1.5 py-2 px-2.5 rounded-md text-[11px] font-bold transition shadow-2xs cursor-pointer disabled:cursor-wait disabled:opacity-60 ${
                                isOnline
                                    ? 'border border-rose-300 bg-rose-50 text-rose-800 hover:bg-rose-100'
                                    : 'border border-emerald-300 bg-emerald-600 text-white hover:bg-emerald-700'
                            }`}
                        >
                            <Power className={`w-3.5 h-3.5 ${isOnline ? 'text-[#E00D42]' : 'text-white'}`} />
                            <span>
                                {dutyLoading
                                    ? 'Updating...'
                                    : isOnline
                                      ? 'Go Off Duty'
                                      : 'Go On Duty'}
                            </span>
                        </button>
                    </div>
                </div>

                {/* Navigation Menu */}
                <nav className="flex-1 px-3 py-3 space-y-4 overflow-y-auto font-sans scrollbar-thin">
                    <div className="space-y-1">
                        <p className="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5 font-sans">
                            Menu
                        </p>
                        {navItems.map((item) => (
                            <Link
                                key={item.name}
                                href={item.href}
                                onClick={() => setSidebarOpen(false)}
                                className={`flex items-center justify-between px-3 py-2.5 rounded-lg text-xs transition group cursor-pointer ${
                                    item.current
                                        ? 'bg-[#E00D42] text-white shadow-xs font-bold'
                                        : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100 font-medium'
                                }`}
                            >
                                <div className="flex items-center gap-2.5">
                                    <item.icon
                                        className={`w-4 h-4 shrink-0 ${
                                            item.current ? 'text-white' : 'text-slate-400 group-hover:text-slate-900'
                                        }`}
                                    />
                                    <span>{item.name}</span>
                                </div>
                                {item.current && (
                                    <span className="w-1.5 h-1.5 rounded-full bg-white/90 shrink-0" />
                                )}
                            </Link>
                        ))}
                    </div>

                    {/* Quick Links */}
                    <div className="pt-2 border-t border-slate-100 space-y-1">
                        <p className="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5 font-sans">
                            Quick Links
                        </p>
                        <a
                            href={getDomainUrl('buyer', '/track')}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="flex items-center justify-between px-3 py-2 rounded-lg text-xs font-semibold text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition group"
                        >
                            <div className="flex items-center gap-2.5">
                                <Truck className="w-4 h-4 text-slate-400 group-hover:text-[#E00D42] transition" />
                                <span>Track Waybill</span>
                            </div>
                            <ExternalLink className="w-3.5 h-3.5 text-slate-400" />
                        </a>
                    </div>
                </nav>

                {/* Sidebar Bottom: Sign Out */}
                <div className="p-3 border-t border-slate-100 bg-white shrink-0 mt-auto">
                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        className="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-lg text-xs font-bold text-slate-700 hover:text-rose-600 hover:bg-rose-50 transition border border-slate-200 hover:border-rose-200 uppercase tracking-wider shadow-2xs cursor-pointer font-sans"
                    >
                        <LogOut className="w-3.5 h-3.5" />
                        <span>Sign Out</span>
                    </Link>
                </div>
            </aside>

            {/* Main Content Column */}
            <div className="flex-1 flex flex-col h-full min-w-0 overflow-hidden">
                {/* Topbar */}
                <header className="h-16 bg-white border-b border-slate-200 px-4 sm:px-8 flex items-center justify-between shrink-0 shadow-2xs z-20">
                    <div className="flex items-center gap-3 min-w-0 flex-1 mr-3">
                        <button
                            type="button"
                            onClick={() => setSidebarOpen(true)}
                            className="lg:hidden p-2 text-slate-600 hover:bg-slate-100 rounded-xl cursor-pointer shrink-0"
                            aria-label="Open sidebar"
                        >
                            <Menu className="w-5 h-5" />
                        </button>
                        <div className="min-w-0">
                            <h1 className="text-base font-black text-slate-900 tracking-tight truncate">
                                {title}
                            </h1>
                            {subtitle && (
                                <div className="text-[11px] text-slate-500 font-medium flex items-center gap-2 mt-0.5 truncate">
                                    {subtitle}
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Topbar Actions & User Avatar */}
                    <div className="flex items-center gap-2.5 sm:gap-3 shrink-0">
                        {actions}

                        {/* On-Duty Switch Button */}
                        <button
                            type="button"
                            onClick={requestDutyChange}
                            disabled={dutyLoading}
                            className={`inline-flex items-center gap-1.5 sm:gap-2 px-2.5 sm:px-3 py-1.5 rounded-lg border text-xs font-bold transition shadow-2xs cursor-pointer disabled:cursor-wait disabled:opacity-60 ${
                                isOnline
                                    ? 'bg-emerald-50/90 border-emerald-300 text-emerald-800 hover:bg-emerald-100/80'
                                    : 'bg-rose-50 border-rose-300 text-rose-800 hover:bg-rose-100'
                            }`}
                        >
                            <span
                                className={`w-2 h-2 rounded-full shrink-0 ${
                                    isOnline ? 'bg-emerald-500 animate-pulse' : 'bg-[#E00D42]'
                                }`}
                            />
                            <span className="hidden xs:inline">{isOnline ? 'On Duty' : 'Off Duty'}</span>
                            <span className="xs:hidden">{isOnline ? 'ON' : 'OFF'}</span>
                        </button>

                        {/* Rider User Avatar Interactive Dropdown */}
                        <div
                            className="relative"
                            onMouseEnter={openUserMenu}
                            onMouseLeave={closeUserMenu}
                        >
                            <button
                                type="button"
                                onClick={() => setUserMenuOpen(!userMenuOpen)}
                                className="flex items-center gap-2 p-1 rounded-xs hover:bg-slate-100 transition group focus:outline-hidden border border-transparent hover:border-slate-300 cursor-pointer"
                            >
                                {user?.avatar ? (
                                    <img
                                        src={user.avatar}
                                        alt={user.name}
                                        className="w-8 h-8 rounded-xs object-cover border border-slate-200 shrink-0"
                                    />
                                ) : (
                                    <div className="w-8 h-8 rounded-xs bg-slate-950 text-white font-bold text-xs flex items-center justify-center shadow-xs group-hover:bg-[#E00D42] transition font-sans">
                                        {user?.name?.charAt(0).toUpperCase() || 'C'}
                                    </div>
                                )}
                                <div className="hidden sm:block text-left font-sans">
                                    <div className="flex items-center gap-1">
                                        <p className="text-xs font-bold text-slate-800 leading-tight group-hover:text-[#E00D42] transition truncate max-w-[130px]">
                                            {user?.name || 'Courier'}
                                        </p>
                                        <ChevronDown className="w-3 h-3 text-slate-400 group-hover:text-slate-600 transition shrink-0" />
                                    </div>
                                    <span className="text-[10px] text-slate-400 font-sans block leading-tight">
                                        Courier Account
                                    </span>
                                </div>
                            </button>

                            {userMenuOpen && (
                                <div
                                    className="absolute right-0 top-full pt-1.5 w-56 z-50 animate-in fade-in zoom-in-95 duration-75"
                                    onMouseEnter={openUserMenu}
                                    onMouseLeave={closeUserMenu}
                                >
                                    <div className="bg-white rounded-lg shadow-xl border border-slate-200 py-1 font-sans text-slate-800">
                                        <div className="px-3.5 py-2.5 border-b border-slate-100">
                                            <p className="text-xs font-bold text-slate-900 truncate">{user?.name || 'Courier'}</p>
                                            <p className="text-[11px] text-slate-500 truncate">{user?.email || ''}</p>
                                            <div className="mt-1 flex items-center gap-1.5">
                                                <span className="w-1.5 h-1.5 rounded-full bg-emerald-500" />
                                                <span className="text-[10px] font-semibold text-slate-500">
                                                    Hub: {hubCode}
                                                </span>
                                            </div>
                                        </div>
                                        <div className="py-1">
                                            <Link
                                                href={route('courier.profile')}
                                                className="flex items-center gap-2 px-3.5 py-2 text-xs text-slate-700 hover:bg-slate-50 font-medium transition"
                                            >
                                                <UserRound className="w-3.5 h-3.5 text-slate-400" />
                                                <span>Rider Profile</span>
                                            </Link>
                                            <Link
                                                href={route('courier.earnings')}
                                                className="flex items-center gap-2 px-3.5 py-2 text-xs text-slate-700 hover:bg-slate-50 font-medium transition"
                                            >
                                                <History className="w-3.5 h-3.5 text-slate-400" />
                                                <span>Completed Trips</span>
                                            </Link>
                                            <Link
                                                href={route('courier.messages')}
                                                className="flex items-center gap-2 px-3.5 py-2 text-xs text-slate-700 hover:bg-slate-50 font-medium transition"
                                            >
                                                <MessageSquare className="w-3.5 h-3.5 text-slate-400" />
                                                <span>Messages</span>
                                            </Link>
                                        </div>
                                        <div className="border-t border-slate-100 pt-1">
                                            <Link
                                                href={route('logout')}
                                                method="post"
                                                as="button"
                                                className="w-full flex items-center gap-2 px-3.5 py-2 text-xs text-rose-600 hover:bg-rose-50 font-bold transition text-left cursor-pointer"
                                            >
                                                <LogOut className="w-3.5 h-3.5" />
                                                <span>Sign Out</span>
                                            </Link>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </header>

                {/* Flash Messages */}
                {flash?.success && (
                    <div className="flex shrink-0 items-center gap-2 border-b border-emerald-700 bg-emerald-600 px-4 py-2.5 text-sm font-medium text-white sm:px-6">
                        <CheckCircle2 className="h-4 w-4 shrink-0" />
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="shrink-0 border-b border-rose-800 bg-[#E00D42] px-4 py-2.5 text-sm font-medium text-white sm:px-6">
                        {flash.error}
                    </div>
                )}

                {/* Main Content Area (padding-bottom pb-24 on mobile so content is clear of bottom nav) */}
                <main className="flex-1 overflow-y-auto p-3 sm:p-6 lg:p-8 pb-24 lg:pb-8">
                    <div className="mx-auto max-w-7xl">{children}</div>
                </main>

                {/* Mobile Bottom Navigation Bar (Tailored for Riders on Mobile) */}
                <nav className="lg:hidden fixed bottom-0 left-0 right-0 z-40 bg-white/95 backdrop-blur-md border-t border-slate-200 px-2 py-1.5 flex items-center justify-around shadow-lg">
                    <Link
                        href={route('courier.deliveries')}
                        className={`flex flex-col items-center justify-center py-1 px-3 rounded-lg text-[10px] font-bold transition ${
                            isDeliveriesActive
                                ? 'text-[#E00D42] font-black'
                                : 'text-slate-500 hover:text-slate-800'
                        }`}
                    >
                        <div className="relative">
                            <ClipboardList className={`w-5 h-5 ${isDeliveriesActive ? 'text-[#E00D42]' : 'text-slate-500'}`} />
                            {isDeliveriesActive && (
                                <span className="absolute -top-1 -right-1 w-2 h-2 rounded-full bg-[#E00D42]" />
                            )}
                        </div>
                        <span className="mt-1">Dispatch</span>
                    </Link>

                    <Link
                        href={route('courier.earnings')}
                        className={`flex flex-col items-center justify-center py-1 px-3 rounded-lg text-[10px] font-bold transition ${
                            isEarningsActive
                                ? 'text-[#E00D42] font-black'
                                : 'text-slate-500 hover:text-slate-800'
                        }`}
                    >
                        <div className="relative">
                            <History className={`w-5 h-5 ${isEarningsActive ? 'text-[#E00D42]' : 'text-slate-500'}`} />
                            {isEarningsActive && (
                                <span className="absolute -top-1 -right-1 w-2 h-2 rounded-full bg-[#E00D42]" />
                            )}
                        </div>
                        <span className="mt-1">Trips</span>
                    </Link>

                    <Link
                        href={route('courier.messages')}
                        className={`flex flex-col items-center justify-center py-1 px-3 rounded-lg text-[10px] font-bold transition ${
                            isMessagesActive
                                ? 'text-[#E00D42] font-black'
                                : 'text-slate-500 hover:text-slate-800'
                        }`}
                    >
                        <div className="relative">
                            <MessageSquare className={`w-5 h-5 ${isMessagesActive ? 'text-[#E00D42]' : 'text-slate-500'}`} />
                            {isMessagesActive && (
                                <span className="absolute -top-1 -right-1 w-2 h-2 rounded-full bg-[#E00D42]" />
                            )}
                        </div>
                        <span className="mt-1">Messages</span>
                    </Link>

                    <Link
                        href={route('courier.profile')}
                        className={`flex flex-col items-center justify-center py-1 px-3 rounded-lg text-[10px] font-bold transition ${
                            isProfileActive
                                ? 'text-[#E00D42] font-black'
                                : 'text-slate-500 hover:text-slate-800'
                        }`}
                    >
                        <div className="relative">
                            <UserRound className={`w-5 h-5 ${isProfileActive ? 'text-[#E00D42]' : 'text-slate-500'}`} />
                            {isProfileActive && (
                                <span className="absolute -top-1 -right-1 w-2 h-2 rounded-full bg-[#E00D42]" />
                            )}
                        </div>
                        <span className="mt-1">Profile</span>
                    </Link>
                </nav>
                {confirmationDialog}
            </div>
        </div>
    );
}
