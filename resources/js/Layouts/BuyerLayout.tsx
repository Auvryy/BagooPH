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
    SlidersHorizontal
} from 'lucide-react';
import ChatModal from '@/Components/ChatModal';

interface Props {
    children: React.ReactNode;
    categories?: Category[];
}

export default function BuyerLayout({ children, categories = [] }: Props) {
    const { auth, cartCount } = usePage<PageProps>().props;
    const [searchQuery, setSearchQuery] = useState('');
    const [userDropdownOpen, setUserDropdownOpen] = useState(false);
    const [chatOpen, setChatOpen] = useState(false);
    const [chatMessage, setChatMessage] = useState('');
    const [chatHistory, setChatHistory] = useState([
        { sender: 'support', text: 'Mabuhay! Welcome to BagooPH Support. How can we assist your shopping today?' }
    ]);

    const trendingKeywords = [
        'Commuter Backpack',
        'ANC Headphones',
        'Techwear Hoodie',
        'GaN Fast Charger',
        'Tactical Watch',
    ];

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        if (searchQuery.trim()) {
            router.get(route('buyer.index'), { search: searchQuery.trim() });
        }
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
        <div className="min-h-screen bg-[#F4F3EF] text-[#111111] font-sans flex flex-col selection:bg-[#E00D42] selection:text-white">
            
            {/* 1. TOP UTILITY BAR (CLEAN & DISTINCTIVE) */}
            <div className="bg-[#111319] text-white/80 text-xs border-b border-white/10">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2 flex items-center justify-between font-mono text-[11px]">
                    <div className="flex items-center gap-4">
                        <Link href="/" className="hover:text-white transition flex items-center gap-1 font-bold text-white/90">
                            <span>Platform Overview</span>
                        </Link>
                        <span className="text-white/20">/</span>
                        <Link href={route('seller.register')} className="hover:text-[#E00D42] transition flex items-center gap-1 text-white/80">
                            <Store className="w-3.5 h-3.5 text-[#E00D42]" />
                            <span>Become a Merchant</span>
                        </Link>
                    </div>

                    <div className="flex items-center gap-4 text-white/70">
                        <span className="hidden sm:inline">Doorstep Logistics & Verified Brands</span>
                        <span className="text-white/20 hidden sm:inline">/</span>
                        <span className="text-[#E00D42] font-bold">PHP ₱ (PH)</span>
                    </div>
                </div>
            </div>

            {/* 2. MAIN HEADER: LOGO | SEARCH | (CART + PROFILE BESIDE EACH OTHER) */}
            <header className="bg-white text-slate-900 border-b border-black/10 sticky top-0 z-40 shadow-xs">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3.5">
                    <div className="flex items-center justify-between gap-4 sm:gap-6">
                        
                        {/* Logo */}
                        <Link href={route('buyer.index')} className="flex items-center gap-3 shrink-0 group">
                            <BagooLogo className="w-9 h-9 sm:w-10 sm:h-10 group-hover:scale-105 transition-transform" rounded="rounded-xl" />
                            <div className="flex flex-col">
                                <span className="text-xl sm:text-2xl font-black tracking-tight text-slate-900 leading-none">
                                    Bagoo<span className="text-[#E00D42]">PH</span>
                                </span>
                                <span className="text-[9px] font-mono tracking-widest text-slate-500 uppercase font-bold">
                                    MARKETPLACE
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
                                    className="w-full pl-4 pr-24 py-2 bg-slate-100 border border-slate-200 rounded-xl text-slate-900 text-xs placeholder-slate-400 focus:outline-hidden focus:ring-2 focus:ring-[#E00D42] focus:bg-white transition"
                                />
                                <button
                                    type="submit"
                                    className="absolute right-1 top-1 bottom-1 px-4 bg-[#E00D42] hover:bg-[#C20836] text-white rounded-lg text-xs font-bold uppercase transition flex items-center gap-1.5 shadow-2xs font-mono"
                                >
                                    <Search className="w-3.5 h-3.5" />
                                    <span>Search</span>
                                </button>
                            </form>
                        </div>

                        {/* RIGHT ACTIONS: BAG & PROFILE DIRECTLY BESIDE EACH OTHER */}
                        <div className="flex items-center gap-3 shrink-0">
                            
                            {/* BAG BUTTON */}
                            <Link 
                                href={route('buyer.cart')} 
                                className="relative flex items-center gap-2 px-3.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 border border-slate-200 text-slate-800 transition font-mono text-xs font-bold group"
                            >
                                <ShoppingBag className="w-4 h-4 text-[#E00D42] group-hover:scale-110 transition-transform" />
                                <span>Bag</span>
                                {cartCount > 0 && (
                                    <span className="min-w-[18px] h-[18px] px-1 bg-[#E00D42] text-white rounded-full text-[10px] font-black flex items-center justify-center shadow-xs">
                                        {cartCount}
                                    </span>
                                )}
                            </Link>

                            {/* PROFILE BUTTON (DIRECTLY BESIDE BAG AT THE RIGHT) */}
                            {auth.user ? (
                                <div className="relative">
                                    <button
                                        onClick={() => setUserDropdownOpen(!userDropdownOpen)}
                                        className="flex items-center gap-2 px-3.5 py-2 rounded-xl bg-slate-900 hover:bg-black text-white transition font-mono text-xs font-bold shadow-xs focus:outline-hidden"
                                    >
                                        <div className="w-5 h-5 rounded-md bg-[#E00D42] text-white text-[10px] font-black flex items-center justify-center">
                                            {auth.user.name.charAt(0).toUpperCase()}
                                        </div>
                                        <span className="truncate max-w-[100px] hidden sm:inline">{auth.user.name.split(' ')[0]}</span>
                                        <ChevronDown className="w-3 h-3 opacity-70" />
                                    </button>

                                    {/* User Dropdown */}
                                    {userDropdownOpen && (
                                        <div 
                                            className="absolute right-0 mt-2 w-56 bg-white rounded-2xl shadow-2xl border border-black/10 py-2 z-50 text-slate-800 font-sans animate-scale-in"
                                            onMouseLeave={() => setUserDropdownOpen(false)}
                                        >
                                            <div className="px-4 py-2.5 border-b border-slate-100 font-mono text-xs">
                                                <p className="font-bold text-slate-900 truncate">{auth.user.name}</p>
                                                <p className="text-[10px] text-[#E00D42] uppercase font-bold">{auth.user.role} Account</p>
                                            </div>

                                            <Link 
                                                href={route('profile.edit')} 
                                                className="flex items-center gap-2.5 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                            >
                                                <UserIcon className="w-4 h-4 text-[#E00D42]" />
                                                <span>My Profile & Addresses</span>
                                            </Link>

                                            <Link 
                                                href={route('buyer.orders.index')} 
                                                className="flex items-center gap-2.5 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                            >
                                                <Package className="w-4 h-4 text-indigo-500" />
                                                <span>My Purchases & Orders</span>
                                            </Link>

                                            <Link 
                                                href={route('buyer.cart')} 
                                                className="flex items-center gap-2.5 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                            >
                                                <ShoppingBag className="w-4 h-4 text-rose-500" />
                                                <span>My Shopping Bag</span>
                                            </Link>

                                            <div className="border-t border-slate-100 mt-1.5 pt-1">
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
                                    )}
                                </div>
                            ) : (
                                <div className="flex items-center gap-2 font-mono text-xs">
                                    <Link 
                                        href={route('login')} 
                                        className="px-3.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold transition uppercase"
                                    >
                                        Sign In
                                    </Link>
                                    <Link 
                                        href={route('register')} 
                                        className="px-3.5 py-2 rounded-xl bg-[#E00D42] hover:bg-[#C20836] text-white font-bold transition uppercase shadow-2xs"
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
                                className="w-full pl-3 pr-20 py-2 bg-slate-100 border border-slate-200 rounded-xl text-xs"
                            />
                            <button
                                type="submit"
                                className="absolute right-1 top-1 bottom-1 px-3 bg-[#E00D42] text-white rounded-lg text-xs font-bold font-mono"
                            >
                                Search
                            </button>
                        </form>
                    </div>
                </div>
            </header>

            {/* 3. MAIN CONTENT BODY */}
            <main className="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
                {children}
            </main>

            {/* 4. FLOATING LIVE CHAT TRIGGER & MODAL */}
            <div className="fixed bottom-6 right-6 z-50">
                <button
                    onClick={() => setChatOpen(true)}
                    className="px-4 py-3 bg-slate-900 hover:bg-black text-white font-bold rounded-full shadow-2xl transition duration-300 flex items-center gap-2 hover:scale-105 uppercase tracking-wider text-xs font-mono border border-white/20"
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
                        <h5 className="font-bold uppercase text-slate-900 mb-3 tracking-wider font-mono text-[11px]">Platform Directory</h5>
                        <ul className="space-y-2 text-slate-500">
                            <li><Link href={route('buyer.index')} className="hover:text-[#E00D42]">Marketplace Home</Link></li>
                            <li><Link href={route('buyer.orders.index')} className="hover:text-[#E00D42]">Track Purchases</Link></li>
                            <li><Link href={route('buyer.cart')} className="hover:text-[#E00D42]">Shopping Cart</Link></li>
                        </ul>
                    </div>

                    <div>
                        <h5 className="font-bold uppercase text-slate-900 mb-3 tracking-wider font-mono text-[11px]">Ecosystem</h5>
                        <ul className="space-y-2 text-slate-500">
                            <li><Link href="/" className="hover:text-[#E00D42]">Platform Overview</Link></li>
                            <li><Link href={route('seller.register')} className="hover:text-[#E00D42]">Seller Centre</Link></li>
                            <li><Link href={route('login')} className="hover:text-[#E00D42]">Member Sign In</Link></li>
                        </ul>
                    </div>

                    <div>
                        <h5 className="font-bold uppercase text-slate-900 mb-3 tracking-wider font-mono text-[11px]">Payment Modes</h5>
                        <div className="flex flex-wrap gap-1.5 font-mono text-[10px] font-bold">
                            <span className="px-2 py-0.5 bg-slate-100 rounded border border-slate-200">COD</span>
                            <span className="px-2 py-0.5 bg-slate-100 rounded border border-slate-200">GCASH</span>
                            <span className="px-2 py-0.5 bg-slate-100 rounded border border-slate-200">MAYA</span>
                            <span className="px-2 py-0.5 bg-slate-100 rounded border border-slate-200">CARDS</span>
                        </div>
                    </div>

                    <div>
                        <h5 className="font-bold uppercase text-slate-900 mb-3 tracking-wider font-mono text-[11px]">Dispatch Fleet</h5>
                        <p className="text-slate-500 text-[11px]">Bagoo Express integrated logistics connecting Metro Manila and regional dispatch hubs.</p>
                    </div>
                </div>

                <div className="border-t border-slate-100 py-4 text-center font-mono text-[11px] text-slate-400">
                    © 2026 BagooPH Ecosystem. All Rights Reserved.
                </div>
            </footer>
        </div>
    );
}
