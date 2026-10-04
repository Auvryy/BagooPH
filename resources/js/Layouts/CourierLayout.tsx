import '../../css/courier.css';
import { useEffect, useRef, useState, type PropsWithChildren, type ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowUpRight, CalendarDays, ChevronRight, History, LayoutDashboard, LogOut, MapPin, MessageSquare, RefreshCw, UserRound } from 'lucide-react';
import BagooLogo from '@/Components/BagooLogo';
import { CourierDutySwitch, useCourierDutyControl } from '@/Components/CourierDutyControl';
import { courierButton, courierClasses } from '@/Components/CourierUI';
import { courierDayKey, courierInitials, courierPath, type CourierScope } from '@/utils/courier';
import { PageProps } from '@/types';
import useCourierRequestError from '@/hooks/useCourierRequestError';

interface Props {
    title: string;
    subtitle?: ReactNode;
    isOnline?: boolean;
    actions?: ReactNode;
    scope?: CourierScope;
    pageLabel?: string;
}

export default function CourierLayout({ children, title, subtitle, isOnline = false, actions, scope, pageLabel }: PropsWithChildren<Props>) {
    const { auth, flash } = usePage<PageProps>().props;
    const { component } = usePage();
    const { confirmationDialog, dutyLoading, dutyError, requestDutyChange } = useCourierDutyControl(isOnline);
    const [menuOpen, setMenuOpen] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [refreshResult, setRefreshResult] = useState('');
    const menu = useRef<HTMLDivElement>(null);
    const menuButton = useRef<HTMLButtonElement>(null);
    const menuTimeout = useRef<ReturnType<typeof setTimeout> | null>(null);
    const menuClicked = useRef(false);
    const refreshPending = useRef(false);
    useCourierRequestError(refreshPending, setRefreshResult);
    const navItems = [
        { name: 'Dashboard', path: '/deliveries', page: 'Courier/Deliveries', icon: LayoutDashboard },
        { name: 'Trips', path: '/earnings', page: 'Courier/Earnings', icon: History },
        { name: 'Messages', path: '/messages', page: 'Courier/Messages', icon: MessageSquare },
        { name: 'Profile', path: '/profile', page: 'Courier/Profile', icon: UserRound },
    ];
    const hub = scope?.hub || 'Hub not assigned';
    const hubCode = scope?.hubCode || scope?.hub_code;
    const initials = courierInitials(auth.user?.name);
    const today = new Date();
    const dateLabel = new Intl.DateTimeFormat('en-PH', { weekday: 'long', month: 'short', day: 'numeric', timeZone: 'Asia/Manila' }).format(today);
    const currentPage = pageLabel || navItems.find((item) => item.page === component)?.name || title;
    const clearMenuTimeout = () => {
        if (menuTimeout.current) clearTimeout(menuTimeout.current);
    };
    const openMenu = () => { clearMenuTimeout(); setMenuOpen(true); };
    const closeMenu = () => { menuClicked.current = false; setMenuOpen(false); };
    const scheduleClose = () => {
        clearMenuTimeout();
        menuTimeout.current = setTimeout(() => {
            if (!menu.current?.contains(document.activeElement)) closeMenu();
        }, 250);
    };

    useEffect(() => {
        const dismiss = (event: PointerEvent) => {
            if (!menu.current?.contains(event.target as Node)) closeMenu();
        };
        const escape = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && menuOpen) { closeMenu(); menuButton.current?.focus(); }
        };
        document.addEventListener('pointerdown', dismiss);
        document.addEventListener('keydown', escape);
        return () => {
            document.removeEventListener('pointerdown', dismiss);
            document.removeEventListener('keydown', escape);
            clearMenuTimeout();
        };
    }, [menuOpen]);

    const refresh = () => {
        if (refreshPending.current) return;
        refreshPending.current = true;
        setRefreshing(true);
        setRefreshResult('');
        router.reload({
            onSuccess: () => setRefreshResult('Latest records loaded.'),
            onError: () => setRefreshResult('Could not refresh. Try again.'),
            onFinish: () => { refreshPending.current = false; setRefreshing(false); },
        });
    };

    return (
        <div className="courier-portal min-h-dvh bg-[#FFF2F4] font-sans text-slate-900 antialiased">
            <a href="#rider-content" className="sr-only z-[90] bg-white p-4 text-base font-bold focus:not-sr-only focus:fixed focus:left-3 focus:top-3">Skip to content</a>
            <aside className="fixed inset-y-0 left-0 hidden w-52 flex-col overflow-y-auto border-r border-slate-300 bg-white md:flex">
                <Link href={courierPath('/deliveries')} className="flex min-h-[76px] shrink-0 items-center gap-2 px-4 focus-visible:outline-[#E00D42]">
                    <BagooLogo className="h-8 w-8" rounded="rounded-[8px]" />
                    <span className="text-lg font-bold tracking-tight">Bagoo<span className="text-[#E00D42]">PH</span></span>
                    <span className="ml-auto rounded-[8px] bg-[#FCE7EA] px-2 py-1 text-xs font-semibold text-[#A1052B]">Rider</span>
                </Link>
                <nav aria-label="Rider navigation" className="shrink-0 space-y-1.5 px-3 py-4">
                    <p className="mb-3 px-3 text-xs font-medium tracking-wide text-slate-500">Your workspace</p>
                    {navItems.map(({ name, path, page, icon: Icon }) => (
                        <Link key={path} href={courierPath(path)} aria-current={component === page ? 'page' : undefined} className={`flex min-h-12 items-center gap-3 rounded-[8px] border px-3 py-3 text-sm font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42] motion-reduce:transition-none ${component === page ? 'border-rose-200 bg-[#FFF2F4] text-[#C20836]' : 'border-transparent text-slate-600 hover:border-slate-300 hover:bg-slate-50'}`}><Icon className="h-[18px] w-[18px]" aria-hidden="true" />{name}</Link>
                    ))}
                </nav>
                <div className="mt-auto shrink-0 px-3 pb-4 pt-8">
                    <div className="rounded-[8px] border border-rose-200 bg-[#FFF2F4] p-4">
                        <MapPin className="h-5 w-5 text-[#E00D42]" aria-hidden="true" />
                        <p className="mt-3 text-sm font-semibold">Your dispatch point</p>
                        <p className="mt-2 break-words text-sm leading-relaxed text-slate-600">{hub}</p>
                        <Link href={courierPath('/profile')} className="mt-3 inline-flex min-h-12 items-center gap-1.5 text-sm font-semibold text-[#C20836] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]">View profile<ArrowUpRight className="h-4 w-4" aria-hidden="true" /></Link>
                    </div>
                    <p className="px-2 py-4 text-xs leading-relaxed text-slate-600">A clear handoff, every step of the way. Use the portal while safely stopped.</p>
                    <Link href={courierPath('/profile')} className="flex min-h-14 items-center gap-3 rounded-[8px] border border-transparent px-2 py-2 hover:border-slate-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]">
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#E00D42] text-sm font-semibold text-white" aria-hidden="true">{initials}</span>
                        <span className="min-w-0"><span className="block truncate text-sm font-semibold">{auth.user?.name}</span><span className="block truncate text-xs text-slate-600">{scope?.company || 'Company not assigned'}</span></span>
                    </Link>
                </div>
            </aside>

            <div className="min-w-0 md:ml-52">
                <header className="relative z-30 border-b border-slate-300 bg-white px-4 py-3 sm:px-6">
                    <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3">
                        <div className="w-full min-w-0 sm:w-auto sm:flex-1">
                            <Link href={courierPath('/deliveries')} className="inline-flex min-h-12 items-center text-lg font-bold md:hidden">Bagoo<span className="text-[#E00D42]">PH</span></Link>
                            <p className="hidden items-center gap-2 text-sm text-slate-600 md:flex"><span>Workspace</span><ChevronRight className="h-4 w-4" aria-hidden="true" /><span className="font-medium text-slate-900">{currentPage}</span></p>
                            <p className="mt-1 break-words text-xs text-slate-600">{hub}{hubCode ? ` · ${hubCode}` : ''}</p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <CourierDutySwitch isOnline={isOnline} busy={dutyLoading} onChange={requestDutyChange} />
                            <button type="button" onClick={refresh} disabled={refreshing} aria-label={refreshing ? 'Refreshing records' : 'Refresh records'} className={courierClasses(courierButton, 'h-12 w-12 border-transparent p-0 hover:border-slate-300')}><RefreshCw className="h-[18px] w-[18px]" aria-hidden="true" /></button>
                            <div ref={menu} className="relative" onMouseEnter={openMenu} onMouseLeave={scheduleClose} onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) scheduleClose(); }}>
                                <button ref={menuButton} type="button" aria-label="Account options" aria-expanded={menuOpen} aria-controls="rider-account-options" onClick={() => { clearMenuTimeout(); if (menuOpen && menuClicked.current) closeMenu(); else { menuClicked.current = true; setMenuOpen(true); } }} className={courierClasses(courierButton, 'h-12 w-12 rounded-full border-[#E00D42] bg-[#E00D42] p-0 text-white hover:bg-[#C20836]')}><span aria-hidden="true">{initials}</span></button>
                                {menuOpen && (
                                    <div id="rider-account-options" className="courier-enter absolute right-0 top-[calc(100%-2px)] w-[min(18rem,calc(100vw-2rem))] pt-2" onMouseEnter={openMenu} onMouseLeave={scheduleClose}>
                                        <div className="rounded-[8px] border border-slate-300 bg-white p-2 shadow-xl">
                                            <p className="break-words px-3 py-2 text-sm font-semibold">{auth.user?.name}<span className="mt-1 block break-all font-normal text-slate-600">{auth.user?.email}</span></p>
                                            <Link href={courierPath('/profile')} className={`${courierButton} w-full justify-start border-transparent`}><UserRound className="h-4 w-4" aria-hidden="true" />Account settings</Link>
                                            <Link href="/logout" method="post" as="button" className={`${courierButton} w-full justify-start border-transparent`}><LogOut className="h-4 w-4" aria-hidden="true" />Sign out</Link>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </header>

                <main id="rider-content" tabIndex={-1} className="mx-auto max-w-7xl px-4 pb-[calc(6rem+env(safe-area-inset-bottom))] pt-6 outline-none sm:px-6 md:pb-8">
                    <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0 flex-1"><h1 className="break-words text-2xl font-semibold tracking-tight sm:text-[28px]">{title}</h1>{subtitle && <div className="mt-2 max-w-2xl break-words text-sm leading-relaxed text-slate-600">{subtitle}</div>}</div>
                        <div className="flex flex-wrap items-center gap-2">{actions}<time dateTime={courierDayKey(today)} className="inline-flex min-h-12 items-center gap-2 rounded-[8px] border border-[#E00D42] px-4 py-2 text-sm font-medium text-[#C20836]"><CalendarDays className="h-4 w-4" aria-hidden="true" />{dateLabel}</time></div>
                    </div>
                    <p role="status" className={refreshResult ? 'mb-3 text-sm text-slate-600' : 'sr-only'}>{refreshResult}</p>
                    {flash?.success && <p role="status" className="mb-4 rounded-[8px] border border-emerald-300 bg-[#ECFDF5] p-4 text-base text-[#047857]">{flash.success}</p>}
                    {(flash?.error || dutyError) && <p role="alert" className="mb-4 rounded-[8px] border border-rose-300 bg-[#FDF2F4] p-4 text-base text-rose-800">{dutyError || flash.error}</p>}
                    {children}
                    <footer className="mt-7 flex flex-wrap justify-between gap-2 text-xs leading-relaxed text-slate-600"><span>One parcel at a time. Every handoff matters.</span><span className="break-words">{scope?.company || 'Assignment pending'}</span></footer>
                </main>
            </div>

            <nav aria-label="Rider navigation" className="fixed inset-x-0 bottom-0 z-40 grid grid-cols-4 gap-2 border-t border-slate-300 bg-white px-2 pb-[env(safe-area-inset-bottom)] pt-2 md:hidden">
                {navItems.map(({ name, path, page, icon: Icon }) => (
                    <Link key={path} href={courierPath(path)} aria-current={component === page ? 'page' : undefined} className={`flex min-h-14 min-w-0 flex-col items-center justify-center gap-1 rounded-[8px] px-1 py-2 text-xs font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42] ${component === page ? 'bg-[#FDF2F4] text-[#C20836]' : 'text-slate-600'}`}><Icon className="h-5 w-5" aria-hidden="true" /><span className="break-words">{name}</span></Link>
                ))}
            </nav>
            {confirmationDialog}
        </div>
    );
}
