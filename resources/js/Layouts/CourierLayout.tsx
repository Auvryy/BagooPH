import '../../css/courier.css';
import { useEffect, useRef, useState, type PropsWithChildren, type ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { CalendarDays, ChevronRight, LogOut, RefreshCw, UserRound } from 'lucide-react';
import CourierSidebar, { CourierSidebarToggle, courierNavItems } from '@/Components/CourierSidebar';
import { CourierDutySwitch, useCourierDutyControl } from '@/Components/CourierDutyControl';
import { courierButton, courierClasses } from '@/Components/CourierUI';
import { courierDayKey, courierDesktopMedia, courierInitials, courierPath, type CourierScope } from '@/utils/courier';
import { PageProps } from '@/types';
import useCourierRequestError from '@/hooks/useCourierRequestError';

interface Props {
    title: string;
    subtitle?: ReactNode;
    isOnline?: boolean;
    actions?: ReactNode;
    scope?: CourierScope;
    pageLabel?: string;
    contentMode?: 'page' | 'chat';
    hidePageHeading?: boolean;
}

export default function CourierLayout({ children, title, subtitle, isOnline = false, actions, scope, pageLabel, contentMode = 'page', hidePageHeading = false }: PropsWithChildren<Props>) {
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
    const navItems = courierNavItems;
    const isDashboard = component === 'Courier/Deliveries';
    const isChat = contentMode === 'chat';
    const portal = useRef<HTMLDivElement>(null);
    const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
    const [mobileSidebarOpen, setMobileSidebarOpen] = useState(false);
    const [desktop, setDesktop] = useState(false);
    const [sidebarReady, setSidebarReady] = useState(false);
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

    useEffect(() => {
        const media = window.matchMedia(courierDesktopMedia);
        const resized = () => {
            setDesktop(media.matches);
            if (media.matches) setMobileSidebarOpen(false);
        };
        resized();
        media.addEventListener('change', resized);
        try { setSidebarCollapsed(window.localStorage.getItem('bagoo.rider.sidebar-collapsed') === 'true'); } catch {}
        const frame = requestAnimationFrame(() => setSidebarReady(true));
        return () => { media.removeEventListener('change', resized); cancelAnimationFrame(frame); };
    }, []);

    const setCollapsed = (collapsed: boolean) => {
        setSidebarCollapsed(collapsed);
        try { window.localStorage.setItem('bagoo.rider.sidebar-collapsed', String(collapsed)); } catch {}
    };
    useEffect(() => {
        if (!isChat) return;
        const viewport = window.visualViewport;
        let frame = 0;
        const measure = () => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => {
                const height = viewport?.height ?? window.innerHeight;
                portal.current?.style.setProperty('--courier-chat-height', `${height}px`);
                portal.current?.toggleAttribute('data-chat-short-viewport', height < 480);
            });
        };
        measure();
        viewport?.addEventListener('resize', measure);
        window.addEventListener('resize', measure);
        return () => {
            viewport?.removeEventListener('resize', measure);
            window.removeEventListener('resize', measure);
            cancelAnimationFrame(frame);
            portal.current?.style.removeProperty('--courier-chat-height');
            portal.current?.removeAttribute('data-chat-short-viewport');
        };
    }, [isChat]);
    const toggleSidebar = () => {
        closeMenu();
        if (desktop) setCollapsed(!sidebarCollapsed);
        else setMobileSidebarOpen((open) => !open);
    };

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
        <div ref={portal} data-sidebar-collapsed={sidebarCollapsed} data-sidebar-ready={sidebarReady} className={courierClasses('courier-portal courier-soft bg-[#FFFAFB] font-sans text-slate-900 antialiased', isChat ? 'courier-chat-layout min-h-0' : 'min-h-dvh', isDashboard && 'courier-dashboard')}>
            <a href="#rider-content" className="sr-only z-[90] bg-white p-4 text-base font-bold focus:not-sr-only focus:fixed focus:left-3 focus:top-3">Skip to content</a>
            <CourierSidebar component={component} hub={hub} company={scope?.company || 'Company not assigned'} name={auth.user?.name || 'Rider'} initials={initials} subtle collapsed={sidebarCollapsed} mobileOpen={mobileSidebarOpen && !desktop} onCloseMobile={() => setMobileSidebarOpen(false)} />

            <div className="courier-content min-w-0">
                <header className="relative z-30 shrink-0 border-b border-slate-100/80 bg-white px-4 py-3 sm:px-6">
                    <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3">
                        <div className={courierClasses('flex min-w-0 items-center gap-3 sm:w-auto sm:flex-1', isChat ? 'w-auto' : 'w-full')}>
                            <CourierSidebarToggle open={desktop ? !sidebarCollapsed : mobileSidebarOpen} mobile={!desktop} onClick={toggleSidebar} />
                            <div className={courierClasses('min-w-0', isChat && 'hidden sm:block')}>
                                <Link href={courierPath('/deliveries')} className="inline-flex min-h-12 items-center text-lg font-bold xl:hidden">Bagoo<span className="text-[#E00D42]">PH</span></Link>
                                <p className="hidden items-center gap-2 text-sm text-slate-600 xl:flex"><span>Workspace</span><ChevronRight className="h-4 w-4" aria-hidden="true" /><span className="font-medium text-slate-900">{currentPage}</span></p>
                                <p className="mt-1 break-words text-xs text-slate-600">{hub}{hubCode ? ` · ${hubCode}` : ''}</p>
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <CourierDutySwitch isOnline={isOnline} busy={dutyLoading} compact={isChat} onChange={requestDutyChange} />
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

                <main id="rider-content" tabIndex={-1} className={courierClasses('outline-none', isChat ? 'flex min-h-0 flex-1 flex-col overflow-hidden' : 'mx-auto max-w-7xl px-4 pb-[calc(6rem+env(safe-area-inset-bottom))] pt-6 sm:px-6 xl:pb-8')}>
                    {!isChat && !hidePageHeading && <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0 flex-1"><h1 className="break-words text-2xl font-semibold tracking-tight sm:text-[28px]">{title}</h1>{subtitle && <div className="mt-2 max-w-2xl break-words text-sm leading-relaxed text-slate-600">{subtitle}</div>}</div>
                        <div className="flex flex-wrap items-center gap-2">{actions}<time dateTime={courierDayKey(today)} className="inline-flex min-h-12 items-center gap-2 rounded-[8px] border border-transparent bg-white px-4 py-2 text-sm font-medium text-[#C20836] shadow-[0_2px_8px_rgba(15,23,42,0.04)]"><CalendarDays className="h-4 w-4" aria-hidden="true" />{dateLabel}</time></div>
                    </div>}
                    <div className={courierClasses('shrink-0', isChat && 'px-4 sm:px-6')}>
                        <p role="status" className={refreshResult ? 'my-3 text-sm text-slate-600' : 'sr-only'}>{refreshResult}</p>
                        {flash?.success && <p role="status" className="my-3 rounded-[8px] bg-[#ECFDF5] p-3 text-sm text-[#047857]">{flash.success}</p>}
                        {(flash?.error || dutyError) && <p role="alert" className="my-3 rounded-[8px] bg-[#FDF2F4] p-3 text-sm text-rose-800">{dutyError || flash.error}</p>}
                    </div>
                    {children}
                    {!isChat && <footer className="mt-7 flex flex-wrap justify-between gap-2 text-xs leading-relaxed text-slate-600"><span>One parcel at a time. Every handoff matters.</span><span className="break-words">{scope?.company || 'Assignment pending'}</span></footer>}
                </main>
            </div>

            <nav aria-label="Rider navigation" className="courier-mobile-nav fixed inset-x-0 bottom-0 z-40 grid grid-cols-4 gap-2 border-t border-slate-100/80 bg-white px-2 pb-[env(safe-area-inset-bottom)] pt-2 xl:hidden">
                {navItems.map(({ name, path, page, icon: Icon }) => (
                    <Link key={path} href={courierPath(path)} aria-current={component === page ? 'page' : undefined} className={`flex min-h-14 min-w-0 flex-col items-center justify-center gap-1 rounded-[8px] px-1 py-2 text-xs font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42] ${component === page ? 'bg-[#FDF2F4] text-[#C20836]' : 'text-slate-600'}`}><Icon className="h-5 w-5" aria-hidden="true" /><span className="break-words">{name}</span></Link>
                ))}
            </nav>
            {confirmationDialog}
        </div>
    );
}
