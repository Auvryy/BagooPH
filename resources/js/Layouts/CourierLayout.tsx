import '../../css/courier.css';
import { useEffect, useRef, useState, type PropsWithChildren, type ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { ClipboardList, History, LogOut, MessageSquare, Power, RefreshCw, UserRound } from 'lucide-react';
import BagooLogo from '@/Components/BagooLogo';
import { useCourierDutyControl } from '@/Components/CourierDutyControl';
import { courierButton, courierClasses } from '@/Components/CourierUI';
import { courierPath, type CourierScope } from '@/utils/courier';
import { PageProps } from '@/types';
import useCourierRequestError from '@/hooks/useCourierRequestError';

interface Props {
    title: string;
    subtitle?: ReactNode;
    isOnline?: boolean;
    actions?: ReactNode;
    scope?: CourierScope;
}

export default function CourierLayout({ children, title, subtitle, isOnline = false, actions, scope }: PropsWithChildren<Props>) {
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
        { name: 'Tasks', path: '/deliveries', page: 'Courier/Deliveries', icon: ClipboardList },
        { name: 'Trips', path: '/earnings', page: 'Courier/Earnings', icon: History },
        { name: 'Messages', path: '/messages', page: 'Courier/Messages', icon: MessageSquare },
        { name: 'Profile', path: '/profile', page: 'Courier/Profile', icon: UserRound },
    ];
    const hub = scope?.hub || 'Hub not assigned';
    const hubCode = scope?.hubCode || scope?.hub_code;
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
        <div className="courier-portal min-h-dvh bg-[#F8F6F2] font-sans text-slate-900 antialiased">
            <a href="#rider-content" className="sr-only z-[90] bg-white p-4 text-base font-bold focus:not-sr-only focus:fixed focus:left-3 focus:top-3">Skip to content</a>
            <aside className="fixed inset-y-0 left-0 hidden w-60 flex-col border-r border-slate-300 bg-white lg:flex">
                <Link href={courierPath('/deliveries')} className="flex min-h-20 items-center gap-3 border-b border-slate-300 px-5 focus-visible:outline-[#E00D42]">
                    <BagooLogo className="h-10 w-10" rounded="rounded-[14px]" />
                    <span><span className="text-xl font-extrabold">Bagoo<span className="text-[#E00D42]">PH</span></span><span className="block text-sm text-slate-600">Rider portal</span></span>
                </Link>
                <nav aria-label="Rider navigation" className="space-y-2 p-4">
                    {navItems.map(({ name, path, page, icon: Icon }) => (
                        <Link key={path} href={courierPath(path)} aria-current={component === page ? 'page' : undefined} className={`flex min-h-12 items-center gap-3 rounded-[14px] border px-4 py-3 text-base font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42] ${component === page ? 'border-[#E00D42] bg-[#E00D42] text-white' : 'border-transparent text-slate-700 hover:border-slate-300 hover:bg-slate-50'}`}><Icon className="h-5 w-5" aria-hidden="true" />{name}</Link>
                    ))}
                </nav>
                <div className="mt-auto space-y-2 border-t border-slate-300 p-5 text-sm leading-relaxed text-slate-600">
                    <p className="break-words font-semibold text-slate-900">{auth.user?.name}</p>
                    <p className="break-words">{scope?.company || 'Company not assigned'}</p>
                    <p>Use the portal while safely stopped.</p>
                </div>
            </aside>

            <div className="min-w-0 lg:ml-60">
                <header className="relative z-30 border-b border-slate-300 bg-white px-4 py-3 sm:px-6">
                    <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3">
                        <div className="min-w-0 flex-1">
                            <Link href={courierPath('/deliveries')} className="inline-flex min-h-12 items-center text-lg font-extrabold lg:hidden">Bagoo<span className="text-[#E00D42]">PH</span></Link>
                            <p className="break-words text-sm font-semibold text-slate-700">{hub}{hubCode ? ` · ${hubCode}` : ''}</p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <button type="button" onClick={requestDutyChange} disabled={dutyLoading} aria-label={isOnline ? 'On duty. Go off duty' : 'Off duty. Go on duty'} className={courierClasses(courierButton, isOnline ? 'border-emerald-300 bg-[#ECFDF5] text-[#047857]' : 'border-amber-300 bg-[#FFF4DF] text-[#92400E]')}><Power className="h-4 w-4" aria-hidden="true" />{dutyLoading ? 'Updating…' : isOnline ? 'On duty' : 'Off duty'}</button>
                            <div ref={menu} className="relative" onMouseEnter={openMenu} onMouseLeave={scheduleClose} onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) scheduleClose(); }}>
                                <button ref={menuButton} type="button" aria-label="Account options" aria-expanded={menuOpen} aria-controls="rider-account-options" onClick={() => { clearMenuTimeout(); if (menuOpen && menuClicked.current) closeMenu(); else { menuClicked.current = true; setMenuOpen(true); } }} className={courierClasses(courierButton, 'h-12 w-12 rounded-full p-0')}><UserRound className="h-5 w-5" aria-hidden="true" /></button>
                                {menuOpen && (
                                    <div id="rider-account-options" className="courier-enter absolute right-0 top-[calc(100%-2px)] w-[min(18rem,calc(100vw-2rem))] pt-2" onMouseEnter={openMenu} onMouseLeave={scheduleClose}>
                                        <div className="rounded-[18px] border border-slate-300 bg-white p-2 shadow-xl">
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

                <main id="rider-content" tabIndex={-1} className="mx-auto max-w-6xl px-4 pb-[calc(6rem+env(safe-area-inset-bottom))] pt-6 outline-none sm:px-6 lg:pb-10">
                    <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0 flex-1"><h1 className="break-words text-2xl font-extrabold sm:text-3xl">{title}</h1>{subtitle && <div className="mt-2 max-w-2xl break-words text-base leading-relaxed text-slate-600">{subtitle}</div>}</div>
                        <div className="flex flex-wrap gap-2">{actions}<button type="button" onClick={refresh} disabled={refreshing} className={courierButton}><RefreshCw className="h-4 w-4" aria-hidden="true" />{refreshing ? 'Refreshing…' : 'Refresh'}</button></div>
                    </div>
                    <p role="status" className="mb-3 text-sm text-slate-600">{refreshResult}</p>
                    {flash?.success && <p role="status" className="mb-4 rounded-[18px] border border-emerald-300 bg-[#ECFDF5] p-4 text-base text-[#047857]">{flash.success}</p>}
                    {(flash?.error || dutyError) && <p role="alert" className="mb-4 rounded-[18px] border border-rose-300 bg-[#FDF2F4] p-4 text-base text-rose-800">{dutyError || flash.error}</p>}
                    {children}
                </main>
            </div>

            <nav aria-label="Rider navigation" className="fixed inset-x-0 bottom-0 z-40 grid grid-cols-4 gap-2 border-t border-slate-300 bg-white px-2 pb-[env(safe-area-inset-bottom)] pt-2 lg:hidden">
                {navItems.map(({ name, path, page, icon: Icon }) => (
                    <Link key={path} href={courierPath(path)} aria-current={component === page ? 'page' : undefined} className={`flex min-h-14 min-w-0 flex-col items-center justify-center gap-1 rounded-[18px] px-1 py-2 text-xs font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42] ${component === page ? 'bg-[#FDF2F4] text-[#C20836]' : 'text-slate-600'}`}><Icon className="h-5 w-5" aria-hidden="true" /><span className="break-words">{name}</span></Link>
                ))}
            </nav>
            {confirmationDialog}
        </div>
    );
}
