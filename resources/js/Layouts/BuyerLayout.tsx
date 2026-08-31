import React, { useState } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import { PageProps, Category } from '@/types';
import BagooLogo from '@/Components/BagooLogo';
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
    CreditCard, 
    Store,
    X,
    ArrowRight,
    Heart,
    SlidersHorizontal,
    ShieldAlert
} from 'lucide-react';
import ChatModal from '@/Components/ChatModal';

interface Props {
    children: React.ReactNode;
    categories?: Category[];
}

export default function BuyerLayout({ children, categories = [] }: Props) {
    const { auth, cartCount } = usePage<PageProps>().props;
    const [searchQuery, setSearchQuery] = useState(() => {
        if (typeof window !== 'undefined') {
            return new URLSearchParams(window.location.search).get('search') || '';
        }
        return '';
    });
    const [userDropdownOpen, setUserDropdownOpen] = useState(false);
    const userDropdownTimeoutRef = React.useRef<NodeJS.Timeout | null>(null);

    const handleUserDropdownEnter = () => {
        if (userDropdownTimeoutRef.current) clearTimeout(userDropdownTimeoutRef.current);
        setUserDropdownOpen(true);
    };

    const handleUserDropdownLeave = () => {
        userDropdownTimeoutRef.current = setTimeout(() => {
            setUserDropdownOpen(false);
        }, 250);
    };

    const [chatOpen, setChatOpen] = useState(false);
    const [chatMessage, setChatMessage] = useState('');
    const [chatHistory, setChatHistory] = useState([
        { sender: 'support', text: 'Mabuhay! Welcome to BagooPH Support. How can we assist your shopping today?' }
    ]);

    const getSubdomainUrl = (sub: string) => {
        if (typeof window === 'undefined') return route(`${sub}.login`);
        const { protocol, host, hostname, port } = window.location;
        const portSuffix = port ? `:${port}` : '';
        
        if (hostname.startsWith(`${sub}.`)) return `${protocol}//${host}`;
        
        let cleanHostname = hostname;
        ['seller.', 'courier.', 'admin.', 'www.'].forEach(prefix => {
            if (cleanHostname.startsWith(prefix)) {
                cleanHostname = cleanHostname.substring(prefix.length);
            }
        });

        return `${protocol}//${sub}.${cleanHostname}${portSuffix}`;
    };

    const sellerUrl = getSubdomainUrl('seller');
    const sellerRegisterUrl = `${sellerUrl}/register`;
    const courierUrl = getSubdomainUrl('courier');
    const courierRegisterUrl = `${courierUrl}/register`;
    const adminUrl = getSubdomainUrl('admin');

    const trendingKeywords = [
        'Commuter Backpack',
        'ANC Headphones',
        'Techwear Hoodie',
        'GaN Fast Charger',
        'Tactical Watch',
    ];

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        const trimmed = searchQuery.trim();
        if (trimmed) {
            router.get(route('buyer.search'), { search: trimmed });
        } else {
            router.get(route('buyer.search'));
        }
    };

    const handleQuickSearch = (keyword: string) => {
        setSearchQuery(keyword);
        router.get(route('buyer.search'), { search: keyword });
    };

    const handleSendChat = (e: React.FormEvent) => {
        e.preventDefault();
        if (!chatMessage.trim()) return;
        
        const userMsg = chatMessage.trim();
        setChatHistory(prev => [...prev, { sender: 'user', text: userMsg }]);
        setChatMessage('');

        setTimeout(() => {
            setChatHistory(prev => [
                ...prev, 
                { sender: 'support', text: 'Thank you for reaching out! A verified representative or merchant will attend to you shortly.' }
            ]);
        }, 1000);
    };

    return (
        <div className="min-h-screen bg-[#F4F3EF] text-[#111111] font-sans flex flex-col overflow-x-hidden w-full max-w-full selection:bg-[#E00D42] selection:text-white">
            
            {/* 1. TOP UTILITY BAR (CLEAN & DISTINCTIVE) */}
            <div className="bg-[#111319] text-white/80 text-xs border-b border-white/10">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2 flex items-center justify-between font-sans text-xs">
                    <div className="flex items-center gap-4">
                        <a href={sellerUrl} className="hover:text-[#E00D42] transition flex items-center gap-1.5 font-semibold text-white/90">
                            <Store className="w-3.5 h-3.5 text-[#E00D42]" />
                            <span>Sell on Bagoo</span>
                        </a>
                        <span className="text-white/20">/</span>
                        <a href={courierUrl} className="hover:text-emerald-400 transition flex items-center gap-1.5 text-white/80 font-medium">
                            <Truck className="w-3.5 h-3.5 text-emerald-400" />
                            <span>Courier Fleet</span>
                        </a>
                        <span className="text-white/20 hidden sm:inline">/</span>
                        <Link href="/overview" className="hover:text-white transition flex items-center gap-1 text-white/60 hidden sm:inline">
                            <span>Platform Showcase</span>
                        </Link>
                    </div>

                    <div className="flex items-center gap-4 text-white/70">
                        <Link href={route('buyer.orders.index')} className="hover:text-white transition hidden sm:inline">Track Order</Link>
                        <span className="text-white/20 hidden sm:inline">/</span>
                        <span className="text-[#E00D42] font-semibold">PHP ₱ (PH)</span>
                    </div>
                </div>
            </div>

            {/* 2. MAIN HEADER: LOGO | SEARCH | (CART + PROFILE BESIDE EACH OTHER) */}
            <header className="bg-white text-slate-900 border-b border-slate-200 sticky top-0 z-40 shadow-xs">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3.5">
                    <div className="flex items-center justify-between gap-4 sm:gap-6">
                        
                        {/* Logo */}
                        <Link href={route('buyer.index')} className="flex items-center gap-3 shrink-0 group">
                            <BagooLogo className="w-9 h-9 sm:w-10 sm:h-10 group-hover:scale-105 transition-transform" rounded="rounded-xl" />
                            <div className="flex flex-col">
                                <span className="text-xl sm:text-2xl font-black tracking-tight text-slate-900 leading-none">
                                    Bagoo<span className="text-[#E00D42]">PH</span>
                                </span>
                                <span className="text-[10px] tracking-wider text-slate-500 uppercase font-bold">
                                    Marketplace
                                </span>
                            </div>
                        </Link>

                        {/* Search Bar */}
                        <div className="flex-1 max-w-2xl hidden md:block">
                            <form onSubmit={handleSearch} className="relative flex items-center">
                                <input
                                    type="text"
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    placeholder="Search 14 departments, curated gear, or trending brands..."
                                    className="w-full pl-4 pr-28 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 text-xs placeholder-slate-400 focus:outline-hidden focus:ring-2 focus:ring-[#E00D42]/20 focus:border-[#E00D42] focus:bg-white transition"
                                />
                                {searchQuery && (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setSearchQuery('');
                                            router.get(route('buyer.index'), {}, { preserveState: true });
                                        }}
                                        className="absolute right-24 text-slate-400 hover:text-slate-700 p-1"
                                    >
                                        <X className="w-3.5 h-3.5" />
                                    </button>
                                )}
                                <button
                                    type="submit"
                                    className="absolute right-1.5 top-1.5 bottom-1.5 px-4 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white rounded-lg text-xs font-bold transition flex items-center gap-1.5 shadow-xs font-sans cursor-pointer"
                                >
                                    <Search className="w-3.5 h-3.5" />
                                    <span>Search</span>
                                </button>
                            </form>
                            
                            {/* Trending Keyword Tags */}
                            <div className="flex items-center gap-2 mt-2 overflow-x-auto scrollbar-none text-xs text-slate-500 font-sans">
                                <span className="font-bold text-slate-400 shrink-0 text-[11px]">Trending:</span>
                                {trendingKeywords.map((kw) => (
                                    <button
                                        key={kw}
                                        type="button"
                                        onClick={() => handleQuickSearch(kw)}
                                        className="hover:text-[#E00D42] hover:underline shrink-0 transition text-slate-600 text-[11px]"
                                    >
                                        {kw}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* RIGHT ACTIONS: BAG & PROFILE DIRECTLY BESIDE EACH OTHER */}
                        <div className="flex items-center gap-3 shrink-0">
                            
                            {/* BAG BUTTON */}
                            <Link 
                                href={route('buyer.cart')} 
                                className="relative flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-50 hover:bg-slate-100 border border-slate-200 text-slate-800 transition font-sans text-xs font-bold group shadow-2xs"
                            >
                                <ShoppingBag className="w-4 h-4 text-[#E00D42] group-hover:scale-105 transition-transform" />
                                <span>Bag</span>
                                {cartCount > 0 && (
                                    <span className="min-w-[18px] h-[18px] px-1 bg-[#E00D42] text-white rounded-full text-[10px] font-black flex items-center justify-center shadow-xs">
                                        {cartCount}
                                    </span>
                                )}
                            </Link>

                            {/* PROFILE BUTTON WITH SEAMLESS HOVER BRIDGE */}
                            {auth.user ? (
                                <div 
                                    className="relative"
                                    onMouseEnter={handleUserDropdownEnter}
                                    onMouseLeave={handleUserDropdownLeave}
                                >
                                    <Link
                                        href={route('buyer.profile', { tab: 'orders' })}
                                        className="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-900 hover:bg-black text-white transition font-sans text-xs font-bold shadow-xs focus:outline-hidden group border border-transparent"
                                    >
                                        <div className="w-5 h-5 rounded-md bg-[#E00D42] text-white text-[10px] font-black flex items-center justify-center">
                                            {auth.user.name.charAt(0).toUpperCase()}
                                        </div>
                                        <span className="truncate max-w-[100px] hidden sm:inline font-semibold">{auth.user.name.split(' ')[0]}</span>
                                        <ChevronDown className={`w-3 h-3 opacity-70 transition-transform ${userDropdownOpen ? 'rotate-180' : ''}`} />
                                    </Link>

                                    {/* User Hover Dropdown with Instant Seamless Overlap */}
                                    {userDropdownOpen && (
                                        <div 
                                            className="absolute right-0 top-full -mt-0.5 pt-1 w-56 z-50 animate-scale-in"
                                            onMouseEnter={handleUserDropdownEnter}
                                            onMouseLeave={handleUserDropdownLeave}
                                        >
                                            <div className="bg-white rounded-2xl shadow-xl border border-slate-200 py-2 text-slate-800 font-sans">
                                                <div className="px-4 py-2.5 border-b border-slate-100 text-xs">
                                                    <p className="font-bold text-slate-900 truncate">{auth.user.name}</p>
                                                    <p className="text-[10px] text-[#E00D42] font-bold uppercase">{auth.user.role} Account</p>
                                                </div>

                                                <Link 
                                                    href={route('buyer.profile', { tab: 'account' })} 
                                                    className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                                >
                                                    <UserIcon className="w-4 h-4 text-[#E00D42]" />
                                                    <span>Profile & Settings</span>
                                                </Link>

                                                <Link 
                                                    href={route('buyer.profile', { tab: 'orders' })} 
                                                    className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                                >
                                                    <Package className="w-4 h-4 text-indigo-500" />
                                                    <span>My Orders</span>
                                                </Link>

                                                <div className="border-t border-slate-100 mt-1 pt-1">
                                                    <Link 
                                                        href={route('logout')} 
                                                        method="post" 
                                                        as="button" 
                                                        className="w-full text-left flex items-center gap-2.5 px-4 py-2 text-xs font-semibold text-rose-600 hover:bg-rose-50 transition"
                                                    >
                                                        <LogOut className="w-4 h-4" />
                                                        <span>Sign Out</span>
                                                    </Link>
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="flex items-center gap-2 font-sans text-xs">
                                    <Link 
                                        href={route('login')} 
                                        className="px-4 py-2.5 rounded-xl bg-slate-50 hover:bg-slate-100 text-slate-800 font-bold transition border border-slate-200 shadow-2xs"
                                    >
                                        Sign In
                                    </Link>
                                    <Link 
                                        href={route('register')} 
                                        className="px-4 py-2.5 rounded-xl bg-[#E00D42] hover:bg-[#C20836] text-white font-bold transition shadow-xs"
                                    >
                                        Register
                                    </Link>
                                </div>
                            )}

                        </div>
                    </div>

                    {/* Mobile Search Bar */}
                    <div className="mt-3 block md:hidden">
                        <form onSubmit={handleSearch} className="relative flex items-center">
                            <input
                                type="text"
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                placeholder="Search products..."
                                className="w-full pl-3 pr-20 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs"
                            />
                            <button
                                type="submit"
                                className="absolute right-1 top-1 bottom-1 px-3 bg-[#E00D42] text-white rounded-lg text-xs font-bold"
                            >
                                Search
                            </button>
                        </form>
                    </div>
                </div>
            </header>

            {/* 3. MAIN CONTENT BODY */}
            <main className="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 overflow-x-hidden">
                {children}
            </main>

            {/* 4. FLOATING LIVE CHAT TRIGGER & MODAL */}
            <div className="fixed bottom-6 right-6 z-50">
                <button
                    onClick={() => setChatOpen(true)}
                    className="px-4 py-3 bg-slate-900 hover:bg-black text-white font-bold rounded-full shadow-2xl transition duration-300 flex items-center gap-2 hover:scale-105 tracking-wide text-xs font-sans border border-white/20 cursor-pointer"
                >
                    <MessageSquare className="w-4 h-4 text-[#E00D42]" />
                    <span>Customer Care</span>
                </button>
            </div>

            <ChatModal
                isOpen={chatOpen}
                onClose={() => setChatOpen(false)}
                receiverId={2}
                receiverName="Bagoo Customer Care"
                shopName="Bagoo Official Support & Merchant Dispatch"
            />

            {/* 5. FOOTER */}
            <footer className="bg-white border-t border-slate-200 text-slate-600 mt-16 font-sans text-xs">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 grid grid-cols-2 md:grid-cols-4 gap-8">
                    <div>
                        <h5 className="font-bold uppercase text-slate-900 mb-3 tracking-wider text-xs">Platform Directory</h5>
                        <ul className="space-y-2 text-slate-500">
                            <li><Link href={route('marketplace')} className="hover:text-[#E00D42]">Marketplace Home</Link></li>
                            <li><Link href={route('buyer.search')} className="hover:text-[#E00D42]">Search 14 Departments</Link></li>
                            <li><Link href={route('buyer.orders.index')} className="hover:text-[#E00D42]">Track Purchases</Link></li>
                            <li><Link href={route('buyer.cart')} className="hover:text-[#E00D42]">Shopping Bag</Link></li>
                        </ul>
                    </div>

                    <div>
                        <h5 className="font-bold uppercase text-slate-900 mb-3 tracking-wider text-xs">Partner with Us</h5>
                        <ul className="space-y-2 text-slate-500">
                            <li><a href={sellerUrl} className="hover:text-[#E00D42] font-semibold text-slate-800">Seller Centre Portal</a></li>
                            <li><a href={sellerRegisterUrl} className="hover:text-[#E00D42]">Open a Verified Store</a></li>
                            <li><a href={courierUrl} className="hover:text-emerald-700 font-semibold text-slate-800">Courier Rider Portal</a></li>
                            <li><Link href={route('hub.index')} className="hover:text-indigo-700">Logistics Sorting Hub</Link></li>
                        </ul>
                    </div>

                    <div>
                        <h5 className="font-bold uppercase text-slate-900 mb-3 tracking-wider text-xs">Payment Modes</h5>
                        <div className="flex flex-wrap gap-1.5 text-xs font-medium">
                            <span className="px-2.5 py-1 bg-slate-100 rounded-lg border border-slate-200 text-slate-700">COD</span>
                            <span className="px-2.5 py-1 bg-slate-100 rounded-lg border border-slate-200 text-slate-700">GCash</span>
                            <span className="px-2.5 py-1 bg-slate-100 rounded-lg border border-slate-200 text-slate-700">Maya</span>
                            <span className="px-2.5 py-1 bg-slate-100 rounded-lg border border-slate-200 text-slate-700">Cards</span>
                        </div>
                        <p className="text-slate-400 text-xs mt-2">100% Escrow & Anti-Fraud Protection</p>
                    </div>

                    <div>
                        <h5 className="font-bold uppercase text-slate-900 mb-3 tracking-wider text-xs">Governance & Security</h5>
                        <ul className="space-y-2 text-slate-500">
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
        </div>
    );
}
