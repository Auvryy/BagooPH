import React, { useState } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import { PageProps, Category } from '@/types';
import BagooLogo from '@/Components/BagooLogo';
import NotificationLink from '@/Components/NotificationLink';
import { 
    Search, 
    ShoppingBag, 
    User as UserIcon, 
    Package, 
    Tag, 
    LogOut, 
    Settings, 
    MessageSquare,
    ChevronDown, 
    ShieldCheck, 
    Truck, 
    Sparkles, 
    Store,
    X,
    ArrowRight,
    Heart,
    SlidersHorizontal,
    ShieldAlert
} from 'lucide-react';
import BuyerChatLauncher from '@/Components/BuyerChatLauncher';
import BuyerApplicationModal from '@/Components/BuyerApplicationModal';
import CustomerServiceAssistant from '@/Components/CustomerServiceAssistant';
import { getDomainUrl } from '@/utils/domain';
import useCatalogueSearch from '@/hooks/useCatalogueSearch';
import CatalogueNavigationNotice from '@/Components/CatalogueNavigationNotice';
import PortalLink from '@/Components/PortalLink';

interface Props {
    children: React.ReactNode;
    categories?: Category[];
    hideAuthButtons?: boolean;
    hideHeader?: boolean;
    hideFooter?: boolean;
    hideCustomerCare?: boolean;
    fullHeight?: boolean;
    topBanner?: React.ReactNode;
}

export default function BuyerLayout({ 
    children, 
    categories = [], 
    hideAuthButtons = false, 
    hideHeader = false,
    hideFooter = false,
    hideCustomerCare = false,
    fullHeight = false,
    topBanner,
}: Props) {
    const page = usePage<PageProps>();
    const { auth, cartCount = 0, unreadMessagesCount = 0, flash, buyerApplication } = page.props;
    const user = auth.user;

    const [logoutFeedback, setLogoutFeedback] = useState<string | null>(null);

    React.useEffect(() => {
        sessionStorage.removeItem('bagoo_just_logged_out');
        if (!auth.user && flash?.success && /sign(ed)?\s*out|logg(ed)?\s*out/i.test(flash.success)) {
            sessionStorage.removeItem('bagoo_buyer_id_prompt_dismissed');
            setLogoutFeedback(flash.success);
            const timer = setTimeout(() => setLogoutFeedback(null), 6000);
            return () => clearTimeout(timer);
        }
    }, [auth.user?.id, flash?.success]);

    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const search = useCatalogueSearch();
    const searchQuery = search.value;
    const [authModalOpen, setAuthModalOpen] = useState(false);
    const [authMode, setAuthMode] = useState<'login' | 'register'>('login');
    const [authForm, setAuthForm] = useState({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });
    const [userDropdownOpen, setUserDropdownOpen] = useState(false);
    const userDropdownTimeoutRef = React.useRef<NodeJS.Timeout | null>(null);
    const userDropdownRef = React.useRef<HTMLDivElement | null>(null);

    const handleUserDropdownEnter = () => {
        if (userDropdownTimeoutRef.current) clearTimeout(userDropdownTimeoutRef.current);
        setUserDropdownOpen(true);
    };

    const handleUserDropdownLeave = () => {
        userDropdownTimeoutRef.current = setTimeout(() => {
            setUserDropdownOpen(false);
        }, 250);
    };

    const toggleUserDropdown = () => {
        if (userDropdownTimeoutRef.current) clearTimeout(userDropdownTimeoutRef.current);
        setUserDropdownOpen((isOpen) => !isOpen);
    };

    React.useEffect(() => {
        if (!userDropdownOpen) return;

        const closeOnOutsidePointer = (event: PointerEvent) => {
            if (!userDropdownRef.current?.contains(event.target as Node)) {
                setUserDropdownOpen(false);
            }
        };
        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') setUserDropdownOpen(false);
        };

        document.addEventListener('pointerdown', closeOnOutsidePointer);
        document.addEventListener('keydown', closeOnEscape);
        return () => {
            document.removeEventListener('pointerdown', closeOnOutsidePointer);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [userDropdownOpen]);

    const [applicationOpen, setApplicationOpen] = useState(false);
    const applicationKey = buyerApplication ? `bagoo_application_${buyerApplication.user.id}_${buyerApplication.user.status}_${buyerApplication.user.kyc_status}_${buyerApplication.user.kyc_reviewed_at ?? ''}` : null;
    React.useEffect(() => {
        if (!buyerApplication || !applicationKey) { setApplicationOpen(false); return; }
        const requested = new URL(page.url, 'http://localhost').searchParams.get('application') === '1';
        let dismissed = false;
        try { dismissed = sessionStorage.getItem(applicationKey) === '1'; } catch { /* Storage may be unavailable in a private session. */ }
        if (requested || (!buyerApplication.can_use_portal && !dismissed)) setApplicationOpen(true);
    }, [page.url, applicationKey]);
    const closeApplication = () => {
        if (applicationKey) {
            try { sessionStorage.setItem(applicationKey, '1'); } catch { /* Dismissal still works for this view. */ }
        }
        setApplicationOpen(false);
    };

    React.useEffect(() => {
        if (fullHeight) {
            document.documentElement.classList.add('overflow-hidden');
            document.body.classList.add('overflow-hidden');
            return () => {
                document.documentElement.classList.remove('overflow-hidden');
                document.body.classList.remove('overflow-hidden');
            };
        }
    }, [fullHeight]);

    const sellerUrl = getDomainUrl('seller', '/');
    const sellerRegisterUrl = getDomainUrl('seller', '/register');
    const courierUrl = getDomainUrl('courier', '/');
    const courierRegisterUrl = getDomainUrl('courier', '/register');
    const hubUrl = getDomainUrl('hub', '/');
    const hubRegisterUrl = getDomainUrl('hub', '/register');
    const adminUrl = getDomainUrl('admin', '/');
    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        search.submit();
    };

    return (
        <div className={`${fullHeight ? 'h-screen overflow-hidden' : 'min-h-screen overflow-x-clip'} bg-[#F8FAFC] text-slate-800 font-sans flex flex-col w-full max-w-full selection:bg-[#E00D42] selection:text-white`}>
            
            <CatalogueNavigationNotice />
            {topBanner}

            {!hideHeader && (
                <>
                    {/* 1. TOP UTILITY BAR (VIBRANT BRAND ACCENT) */}
                    <div className="bg-[#C20836] text-white/90 text-xs border-b border-[#A8062E] shrink-0 font-sans">
                <div className="max-w-[1536px] mx-auto px-2 sm:px-3 md:px-4 py-1.5 flex items-center justify-between font-sans text-xs">
                    <div className="flex items-center gap-4">
                        <a href={sellerUrl} className="hover:text-white transition flex items-center gap-1.5 font-bold text-white">
                            <Store className="w-3.5 h-3.5 text-amber-300" />
                            <span>Seller Centre</span>
                        </a>
                        <span className="text-white/30">|</span>
                        <a href={sellerRegisterUrl} className="hover:text-white transition text-white/90 font-medium">
                            <span>Start Selling</span>
                        </a>
                        <span className="text-white/30">|</span>
                        <a href={courierUrl} className="hover:text-white transition flex items-center gap-1.5 text-white/90 font-medium">
                            <Truck className="w-3.5 h-3.5 text-emerald-300" />
                            <span>Courier Fleet</span>
                        </a>
                        <span className="text-white/30 hidden sm:inline">|</span>
                        <Link href="/overview" className="hover:text-white transition flex items-center gap-1 text-white/80 hidden sm:inline">
                            <span>Platform Showcase</span>
                        </Link>
                    </div>

                    <div className="flex items-center gap-4 text-white/90 font-medium">
                        <PortalLink href={route('buyer.orders.index')} className="hover:text-white transition hidden sm:inline">Track Order</PortalLink>
                        <span className="text-white/30 hidden sm:inline">|</span>
                        <span className="text-amber-300 font-bold">PHP ₱ (PH)</span>
                    </div>
                </div>
            </div>

            {/* 2. MAIN HEADER: VIBRANT BRAND CRIMSON BACKGROUND */}
            <header className="bg-[#E00D42] text-white border-b border-[#C20836] sticky top-0 z-50 shadow-md shrink-0 font-sans">
                <div className="max-w-[1536px] mx-auto px-2 sm:px-3 md:px-4 py-3">
                    <div className="flex items-center justify-between gap-4 sm:gap-6">
                        
                        {/* Logo */}
                        <PortalLink href={route('buyer.index')} className="flex items-center gap-3 shrink-0 group">
                            <BagooLogo className="w-10 h-10 group-hover:scale-105 transition-transform drop-shadow-sm" rounded="rounded-xl" />
                            <div className="flex flex-col">
                                <span className="text-xl sm:text-2xl font-black tracking-tight text-white leading-none flex items-center gap-0.5">
                                    Bagoo<span className="text-amber-300">PH</span>
                                </span>
                                <span className="text-[10px] tracking-wider text-rose-100 uppercase font-black mt-0.5">
                                    Official Marketplace
                                </span>
                            </div>
                        </PortalLink>

                        {/* Search Bar & Trending Keywords */}
                        <div className="flex-1 max-w-3xl hidden md:flex md:flex-col gap-1">
                            <form onSubmit={handleSearch} aria-busy={search.state.status === 'loading'} className="relative flex items-center h-11">
                                <input
                                    type="text"
                                    value={searchQuery}
                                    onChange={(e) => search.update(e.target.value, (e.nativeEvent as InputEvent).isComposing)}
                                    onCompositionStart={search.composing}
                                    onCompositionEnd={(e) => search.composed(e.currentTarget.value)}
                                    maxLength={100}
                                    aria-label="Search products"
                                    aria-invalid={search.state.invalid || undefined}
                                    placeholder="Search 14 departments, curated gear, or trending brands..."
                                    className="w-full h-11 pl-4 pr-28 bg-white border border-white rounded-xl text-slate-900 text-xs placeholder-slate-400 focus:outline-hidden focus:ring-2 focus:ring-white/40 shadow-xs transition"
                                />
                                {searchQuery && (
                                    <button
                                        type="button"
                                        onClick={search.clear}
                                        aria-label="Clear search"
                                        className="absolute right-24 text-slate-400 hover:text-slate-700 p-1 cursor-pointer"
                                    >
                                        <X className="w-3.5 h-3.5" />
                                    </button>
                                )}
                                <button
                                    type="submit"
                                    className="absolute right-1.5 top-1.5 bottom-1.5 px-5 bg-[#E00D42] hover:bg-[#C20836] active:scale-95 text-white rounded-lg text-xs font-bold transition flex items-center gap-1.5 shadow-xs font-sans cursor-pointer"
                                >
                                    <Search className="w-3.5 h-3.5" />
                                    <span>{search.state.status === 'loading' ? 'Searching…' : 'Search'}</span>
                                </button>
                                <p role="status" className={search.state.status === 'error' ? 'absolute top-full mt-1 bg-white text-xs text-red-700 rounded-md p-1 shadow-sm' : 'sr-only'}>{search.state.message}</p>
                            </form>

                            {/* Trending Search Chips */}
                            <div className="flex flex-wrap items-center gap-2.5 text-[11px] text-rose-100 font-medium pt-0.5">
                                <span className="text-white/60 text-[10px] font-bold uppercase tracking-wider">Trending:</span>
                                {['Oversized Tee', 'EDC Sling Bag', 'Paracord Gear', 'Field Watch', 'Tactical Gloves', 'Aviator Glasses'].map((term) => (
                                    <button
                                        key={term}
                                        type="button"
                                        onClick={() => {
                                            search.update(term, false);
                                            router.get(route('buyer.index'), { search: term }, { preserveState: true });
                                        }}
                                        className="hover:text-white hover:underline transition-colors whitespace-nowrap cursor-pointer"
                                    >
                                        {term}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* RIGHT ACTIONS: BAG & PROFILE */}
                        <div className="flex items-center gap-2 sm:gap-3 shrink-0">
                            {/* BAG ACTION */}
                            <NotificationLink />
                            <Link 
                                href={route('buyer.cart')} 
                                className="relative flex items-center gap-1.5 px-2.5 py-1.5 text-white hover:text-rose-100 transition font-sans text-sm font-bold tracking-tight group focus:outline-hidden"
                                aria-label={`Shopping Bag (${cartCount} items)`}
                            >
                                <div className="relative flex items-center justify-center">
                                    <ShoppingBag className="w-5 h-5 text-white group-hover:scale-105 transition-transform" />
                                    {cartCount > 0 && (
                                        <span className="absolute -top-1.5 -right-2 min-w-[17px] h-[17px] px-1 bg-[#F97316] text-white rounded-full text-[10px] font-black flex items-center justify-center shadow-xs">
                                            {cartCount}
                                        </span>
                                    )}
                                </div>
                                <span className="hidden sm:inline font-bold">Bag</span>
                            </Link>

                            {/* PROFILE BUTTON WITH SEAMLESS HOVER BRIDGE */}
                            {auth.user ? (
                                <div 
                                    ref={userDropdownRef}
                                    className="relative"
                                    onMouseEnter={handleUserDropdownEnter}
                                    onMouseLeave={handleUserDropdownLeave}
                                >
                                    <div className="h-11 flex items-stretch rounded-xl bg-white/15 hover:bg-white/25 border border-white/25 text-white transition font-sans text-xs font-bold shadow-xs group">
                                        <PortalLink
                                            href={route('buyer.orders.index')}
                                            className="flex min-w-0 items-center gap-2 pl-3 sm:pl-3.5 pr-1 focus:outline-hidden"
                                        >
                                            {auth.user.avatar ? (
                                                <img
                                                    src={auth.user.avatar}
                                                    alt={auth.user.name}
                                                    className="w-6 h-6 rounded-lg object-cover border border-white/40 shrink-0"
                                                />
                                            ) : (
                                                <div className="w-6 h-6 rounded-lg bg-white text-[#E00D42] text-[10px] font-black flex items-center justify-center shrink-0 shadow-2xs">
                                                    {auth.user.name.charAt(0).toUpperCase()}
                                                </div>
                                            )}
                                            <span className="truncate max-w-[80px] sm:max-w-[100px] hidden sm:inline font-bold">{auth.user.name.split(' ')[0]}</span>
                                        </PortalLink>
                                        <button
                                            type="button"
                                            onClick={toggleUserDropdown}
                                            aria-label="Toggle account menu"
                                            aria-haspopup="menu"
                                            aria-expanded={userDropdownOpen}
                                            aria-controls="buyer-account-menu"
                                            className="flex items-center justify-center px-2.5 cursor-pointer focus:outline-hidden"
                                        >
                                            <ChevronDown className={`w-3.5 h-3.5 text-white/80 group-hover:text-white transition-transform ${userDropdownOpen ? 'rotate-180' : ''}`} />
                                        </button>
                                    </div>

                                    {/* User Hover Dropdown with Instant Seamless Overlap */}
                                    {userDropdownOpen && (
                                        <div 
                                            id="buyer-account-menu"
                                            role="menu"
                                            className="absolute right-0 top-full -mt-0.5 pt-1 w-56 z-50 animate-scale-in"
                                            onMouseEnter={handleUserDropdownEnter}
                                            onMouseLeave={handleUserDropdownLeave}
                                        >
                                            <div className="bg-white rounded-md shadow-2xl border border-slate-300 py-1.5 text-slate-800 font-sans">
                                                <div className="px-4 py-2.5 border-b border-slate-100 text-xs flex items-center gap-2.5">
                                                    {auth.user.avatar ? (
                                                        <img
                                                            src={auth.user.avatar}
                                                            alt={auth.user.name}
                                                            className="w-8 h-8 rounded-lg object-cover border border-slate-200 shrink-0"
                                                        />
                                                    ) : (
                                                        <div className="w-8 h-8 rounded-lg bg-[#E00D42] text-white text-xs font-black flex items-center justify-center shrink-0">
                                                            {auth.user.name.charAt(0).toUpperCase()}
                                                        </div>
                                                    )}
                                                    <div className="min-w-0 flex-1">
                                                        <p className="font-bold text-slate-900 truncate">{auth.user.name}</p>
                                                        <p className="text-[10px] text-[#E00D42] font-bold uppercase">{auth.user.role} Account</p>
                                                    </div>
                                                </div>

                                                <PortalLink
                                                    href={route('buyer.profile', { tab: 'account' })} 
                                                    className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                                >
                                                    <UserIcon className="w-4 h-4 text-[#E00D42]" />
                                                    <span>Profile & Settings</span>
                                                </PortalLink>

                                                <Link href={route('account.status')} className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition">
                                                    <UserIcon className="w-4 h-4 text-slate-400" /><span>Account status</span>
                                                </Link>


                                                <PortalLink
                                                    href={route('buyer.orders.index')}
                                                    className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                                >
                                                    <Package className="w-4 h-4 text-indigo-500" />
                                                    <span>My Orders</span>
                                                </PortalLink>

                                                <PortalLink href={route('buyer.saved-products.index')} className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition">
                                                    <Heart className="h-4 w-4 text-[#E00D42]" /><span>Saved products</span>
                                                </PortalLink>
                                                <PortalLink href={route('buyer.followed-shops.index')} className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition">
                                                    <Store className="h-4 w-4 text-[#E00D42]" /><span>Followed shops</span>
                                                </PortalLink>

                                                <Link 
                                                    href={route('buyer.messages')} 
                                                    className="flex items-center justify-between px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                                >
                                                    <div className="flex items-center gap-2.5">
                                                        <MessageSquare className="w-4 h-4 text-rose-500" />
                                                        <span>Messages & Inquiries</span>
                                                    </div>
                                                    {Boolean(unreadMessagesCount && unreadMessagesCount > 0) && (
                                                        <span className="px-1.5 py-0.5 rounded-full bg-[#E00D42] text-white text-[9px] font-sans font-bold">
                                                            {unreadMessagesCount}
                                                        </span>
                                                    )}
                                                </Link>

                                                <div className="border-t border-slate-100 mt-1 pt-1">
                                                    <Link 
                                                        href={route('logout')} 
                                                        method="post" 
                                                        as="button" 
                                                        className="w-full text-left flex items-center gap-2.5 px-4 py-2 text-xs font-semibold text-rose-600 hover:bg-rose-50 transition cursor-pointer"
                                                    >
                                                        <LogOut className="w-4 h-4" />
                                                        <span>Sign Out</span>
                                                    </Link>
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            ) : hideAuthButtons ? null : (
                                <div className="flex items-center gap-2 sm:gap-2.5 font-sans text-xs sm:text-sm font-bold">
                                    <Link 
                                        href={route('login')} 
                                        className="text-white/95 hover:text-white hover:underline transition-colors py-1 cursor-pointer whitespace-nowrap"
                                    >
                                        Sign In
                                    </Link>
                                    <span className="text-white/40 text-xs select-none">|</span>
                                    <Link 
                                        href={route('register')} 
                                        className="text-white hover:text-amber-200 transition-colors py-1 cursor-pointer font-extrabold whitespace-nowrap"
                                    >
                                        Register
                                    </Link>
                                </div>
                            )}

                        </div>
                    </div>

                    {/* Mobile Search Bar */}
                    <div className="mt-3 block md:hidden">
                        <form onSubmit={handleSearch} aria-busy={search.state.status === 'loading'} className="relative flex items-center">
                            <input
                                type="text"
                                value={searchQuery}
                                onChange={(e) => search.update(e.target.value, (e.nativeEvent as InputEvent).isComposing)}
                                onCompositionStart={search.composing}
                                onCompositionEnd={(e) => search.composed(e.currentTarget.value)}
                                maxLength={100}
                                aria-label="Search products"
                                aria-invalid={search.state.invalid || undefined}
                                placeholder="Search products..."
                                className="w-full pl-3 pr-32 py-2.5 bg-slate-50 border border-slate-200 rounded-xs text-xs"
                            />
                            {searchQuery && <button type="button" onClick={search.clear} aria-label="Clear search" className="absolute right-24 p-1 text-slate-400 hover:text-slate-700"><X className="h-3.5 w-3.5" /></button>}
                            <button
                                type="submit"
                                className="absolute right-1 top-1 bottom-1 px-3 bg-[#E00D42] text-white rounded-xs text-xs font-bold"
                            >
                                {search.state.status === 'loading' ? 'Searching…' : 'Search'}
                            </button>
                            <p role="status" className={search.state.status === 'error' ? 'absolute top-full mt-1 bg-white text-xs text-red-700' : 'sr-only'}>{search.state.message}</p>
                        </form>
                    </div>
                </div>
            </header>
            </>
            )}

            {/* 3. MAIN CONTENT BODY */}
            <main aria-busy={search.state.status === 'loading'} className={`flex-1 min-h-0 max-w-[1536px] w-full mx-auto px-2 sm:px-3 md:px-4 flex flex-col ${
                fullHeight 
                    ? 'py-1 sm:py-2 overflow-hidden' 
                    : hideHeader 
                        ? 'pt-2 sm:pt-3 pb-8 overflow-x-hidden' 
                        : 'py-3 sm:py-4 overflow-x-hidden'
            }`}>
                {buyerApplication && !buyerApplication.can_use_portal && <div className="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-300 bg-white p-4 text-sm">
                    <p>Checkout is unavailable until your buyer account is active and approved.</p>
                    <button type="button" onClick={() => setApplicationOpen(true)} className="font-bold text-[#C20836] underline">View application</button>
                </div>}
                {auth.user && flash?.success && (
                    <p role="status" className="mb-5 shrink-0 rounded-2xl border border-emerald-300 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">{flash.success}</p>
                )}
                {auth.user && flash?.error && (
                    <p role="alert" className="mb-5 shrink-0 rounded-2xl border border-rose-300 bg-rose-50 p-4 text-sm font-semibold text-rose-800">{flash.error}</p>
                )}
                {children}
            </main>

            {/* 4. LOGOUT FEEDBACK NOTIFICATION CHIP */}
            {logoutFeedback && (
                <div className="fixed top-20 left-1/2 -translate-x-1/2 z-50 animate-fade-in pointer-events-auto">
                    <div className="flex items-center gap-2.5 sm:gap-3 px-3.5 sm:px-4 py-2 sm:py-2.5 bg-slate-950/95 text-white rounded-full shadow-2xl border border-slate-800 text-xs font-sans backdrop-blur-md max-w-[92vw]">
                        <div className="flex items-center gap-2 min-w-0">
                            <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse shrink-0"></span>
                            <LogOut className="w-3.5 h-3.5 text-slate-400 shrink-0 hidden sm:block" />
                            <span className="text-slate-100 truncate">{logoutFeedback}</span>
                        </div>
                        <div className="h-3.5 w-px bg-slate-800 shrink-0"></div>
                        <Link
                            href={route('login')}
                            className="text-[11px] font-bold text-[#E00D42] hover:text-[#ff386b] underline transition shrink-0 cursor-pointer"
                        >
                            Sign In
                        </Link>
                        <button
                            type="button"
                            onClick={() => setLogoutFeedback(null)}
                            className="p-1 -mr-1 text-slate-400 hover:text-white rounded-full hover:bg-slate-800 transition shrink-0 cursor-pointer"
                            title="Dismiss notification"
                        >
                            <X className="w-3.5 h-3.5" />
                        </button>
                    </div>
                </div>
            )}

            {!hideHeader && !route().current('buyer.messages') && !route().current('messages') && (
                <BuyerChatLauncher href={user ? route('buyer.messages') : route('login')} unreadCount={unreadMessagesCount} />
            )}

            {/* 4.5. BUYER IDENTITY VERIFICATION REMINDER MODAL */}
            {buyerApplication && <BuyerApplicationModal data={buyerApplication} open={applicationOpen} onClose={closeApplication} />}

            {/* 5. FOOTER */}
            {!hideFooter && (
                <footer className="bg-white border-t border-slate-200 text-slate-600 mt-12 font-sans text-xs">
                <div className="max-w-[1536px] mx-auto px-2 sm:px-3 md:px-4 py-8 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-6">
                    <div>
                        <h5 className="font-bold uppercase text-slate-900 mb-3 tracking-wider text-xs">Platform Directory</h5>
                        <ul className="space-y-2 text-slate-500">
                            <li><PortalLink href={route('marketplace')} className="hover:text-[#E00D42]">Marketplace Home</PortalLink></li>
                            <li><PortalLink href={route('buyer.search')} className="hover:text-[#E00D42]">Search 14 Departments</PortalLink></li>
                            <li><PortalLink href={route('buyer.orders.index')} className="hover:text-[#E00D42]">Track Purchases</PortalLink></li>
                            <li><Link href={route('buyer.cart')} className="hover:text-[#E00D42]">Shopping Bag</Link></li>
                        </ul>
                    </div>

                    <div>
                        <h5 className="font-bold uppercase text-slate-900 mb-3 tracking-wider text-xs">Partner with Us</h5>
                        <ul className="space-y-2 text-slate-500">
                            <li><a href={sellerUrl} className="hover:text-[#E00D42] font-semibold text-slate-800">Seller Centre Portal</a></li>
                            <li><a href={sellerRegisterUrl} className="hover:text-[#E00D42]">Open a Verified Store</a></li>
                            <li><a href={courierUrl} className="hover:text-emerald-700 font-semibold text-slate-800">Courier Rider Portal</a></li>
                            <li><a href={courierRegisterUrl} className="hover:text-emerald-700">Apply as Fleet Driver</a></li>
                            <li><a href={hubUrl} className="hover:text-indigo-700 font-semibold text-slate-800">Logistics Sorting Hub</a></li>
                            <li><a href={hubRegisterUrl} className="hover:text-indigo-700">Register Logistics Partner</a></li>
                        </ul>
                    </div>

                    <div>
                        <h5 className="font-bold uppercase text-slate-900 mb-3 tracking-wider text-xs">Payment Method</h5>
                        <div className="flex flex-wrap gap-1.5 text-xs font-medium">
                            <span className="px-2.5 py-1 bg-slate-100 rounded-lg border border-slate-200 text-slate-700 font-semibold">Cash on Delivery (COD)</span>
                        </div>
                        <p className="text-slate-400 text-xs mt-2">100% Escrow & Doorstep Inspection Guarantee</p>
                    </div>

                    <div>
                        <h5 className="font-bold uppercase text-slate-900 mb-3 tracking-wider text-xs">Help & information</h5>
                        <ul className="space-y-2 text-slate-500">
                            {user?.role === 'buyer' && !hideCustomerCare && <li><CustomerServiceAssistant onOpenMessages={() => router.visit(route('buyer.messages'))} /></li>}
                            <li><a href={adminUrl} className="hover:text-slate-900 text-slate-600 text-xs">Admin Governance Console</a></li>
                            <li><Link href="/overview" className="hover:text-[#E00D42]">Platform Architecture</Link></li>
                            <li><span className="text-slate-400">Strict KYC Compliance Verified</span></li>
                        </ul>
                    </div>
                </div>

                <div className="border-t border-slate-100 py-4 text-center text-xs text-slate-400">
                    © {new Date().getFullYear()} BagooPH Ecosystem. All Rights Reserved.
                </div>
            </footer>
            )}
        </div>
    );
}
