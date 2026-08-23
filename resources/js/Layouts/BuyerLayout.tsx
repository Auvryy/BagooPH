import React, { useState, useEffect } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import { PageProps, Category } from '@/types';
import BagooLogo from '@/Components/BagooLogo';
import { 
    Search, 
    ShoppingCart, 
    Bell, 
    HelpCircle, 
    Globe, 
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
    ArrowRight
} from 'lucide-react';

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
        { sender: 'support', text: 'Mabuhay! Welcome to BagooPH Customer Care. How can we assist your shopping today?' }
    ]);

    const trendingKeywords = [
        'Commuter Backpack',
        'ANC Headphones',
        'Techwear Hoodie',
        'GaN Fast Charger',
        'Tactical Watch',
        'ScreenBar Light',
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
                { sender: 'support', text: 'Thank you for reaching out. A verified customer support agent or merchant will respond shortly.' }
            ]);
        }, 1000);
    };

    return (
        <div className="min-h-screen bg-[#F5F5F5] text-[#222222] font-sans flex flex-col selection:bg-[#E00D42] selection:text-white">
            
            {/* 1. TOP UTILITY STRIP (SHOPEE / SHEIN STYLE) */}
            <nav className="bg-[#E00D42] text-white text-xs border-b border-[#C20836]">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-1.5 flex items-center justify-between font-mono text-[11px]">
                    
                    {/* Left Utility Links */}
                    <div className="flex items-center gap-4 text-white/90">
                        <Link href={route('seller.register')} className="hover:text-white transition flex items-center gap-1 font-bold">
                            <Store className="w-3.5 h-3.5" />
                            <span>Seller Centre</span>
                        </Link>
                        <span className="text-white/40">|</span>
                        <span className="hidden sm:inline hover:text-white cursor-pointer">Download App</span>
                        <span className="text-white/40 hidden sm:inline">|</span>
                        <span className="hidden sm:flex items-center gap-1">
                            Follow us on <span className="font-bold">FB / IG</span>
                        </span>
                    </div>

                    {/* Right Utility Links & Account */}
                    <div className="flex items-center gap-4 text-white/90">
                        <Link href={route('buyer.index')} className="hover:text-white transition flex items-center gap-1">
                            <Bell className="w-3.5 h-3.5" />
                            <span>Notifications</span>
                        </Link>
                        <span className="hover:text-white transition flex items-center gap-1 cursor-pointer">
                            <HelpCircle className="w-3.5 h-3.5" />
                            <span>Help</span>
                        </span>
                        <span className="flex items-center gap-1 cursor-pointer">
                            <Globe className="w-3.5 h-3.5" />
                            <span>English (PHP ₱)</span>
                        </span>
                        <span className="text-white/40">|</span>

                        {auth.user ? (
                            <div className="relative">
                                <button
                                    onClick={() => setUserDropdownOpen(!userDropdownOpen)}
                                    className="flex items-center gap-2 hover:text-white font-bold transition focus:outline-hidden"
                                >
                                    <div className="w-5 h-5 rounded-full bg-white text-[#E00D42] text-[10px] font-black flex items-center justify-center">
                                        {auth.user.name.charAt(0).toUpperCase()}
                                    </div>
                                    <span className="truncate max-w-[120px]">{auth.user.name}</span>
                                    <ChevronDown className="w-3 h-3 opacity-70" />
                                </button>

                                {userDropdownOpen && (
                                    <div 
                                        className="absolute right-0 mt-2 w-48 bg-white rounded-xl shadow-2xl border border-black/10 py-1.5 z-50 text-slate-800 font-sans"
                                        onMouseLeave={() => setUserDropdownOpen(false)}
                                    >
                                        <div className="px-4 py-2 border-b border-slate-100 font-mono text-xs">
                                            <p className="font-bold text-slate-900 truncate">{auth.user.name}</p>
                                            <p className="text-[10px] text-[#E00D42] uppercase font-bold">{auth.user.role}</p>
                                        </div>

                                        <Link 
                                            href={route('buyer.dashboard')} 
                                            className="flex items-center gap-2.5 px-4 py-2 text-xs text-slate-700 hover:bg-slate-50 transition"
                                        >
                                            <Sparkles className="w-3.5 h-3.5 text-[#E00D42]" />
                                            <span>Buyer Dashboard</span>
                                        </Link>

                                        <Link 
                                            href={route('buyer.orders.index')} 
                                            className="flex items-center gap-2.5 px-4 py-2 text-xs text-slate-700 hover:bg-slate-50 transition"
                                        >
                                            <Package className="w-3.5 h-3.5 text-indigo-500" />
                                            <span>My Purchases & Orders</span>
                                        </Link>

                                        <Link 
                                            href={route('buyer.cart')} 
                                            className="flex items-center gap-2.5 px-4 py-2 text-xs text-slate-700 hover:bg-slate-50 transition"
                                        >
                                            <ShoppingCart className="w-3.5 h-3.5 text-amber-500" />
                                            <span>My Shopping Bag</span>
                                        </Link>

                                        <Link 
                                            href={route('profile.edit')} 
                                            className="flex items-center gap-2.5 px-4 py-2 text-xs text-slate-700 hover:bg-slate-50 transition"
                                        >
                                            <Settings className="w-3.5 h-3.5 text-slate-500" />
                                            <span>Account Settings</span>
                                        </Link>

                                        <div className="border-t border-slate-100 mt-1">
                                            <Link 
                                                href={route('logout')} 
                                                method="post" 
                                                as="button" 
                                                className="w-full text-left flex items-center gap-2.5 px-4 py-2 text-xs text-rose-600 hover:bg-rose-50 transition"
                                            >
                                                <LogOut className="w-3.5 h-3.5" />
                                                <span>Sign Out</span>
                                            </Link>
                                        </div>
                                    </div>
                                )}
                            </div>
                        ) : (
                            <div className="flex items-center gap-3 font-bold">
                                <Link href={route('login')} className="hover:text-white transition">Sign In</Link>
                                <span className="text-white/40">|</span>
                                <Link href={route('register')} className="hover:text-white transition">Register</Link>
                            </div>
                        )}
                    </div>
                </div>
            </nav>

            {/* 2. MAIN MEGA SEARCH HEADER (SHOPEE BRANDED) */}
            <header className="bg-[#E00D42] text-white shadow-md sticky top-0 z-40">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3.5">
                    <div className="flex items-center justify-between gap-4 md:gap-8">
                        
                        {/* Logo & Brand */}
                        <Link href={route('buyer.index')} className="flex items-center gap-3 shrink-0 group">
                            <BagooLogo className="w-10 h-10 group-hover:scale-105 transition-transform" rounded="rounded-xl" />
                            <div className="flex flex-col">
                                <span className="text-2xl font-black tracking-tighter text-white leading-none">
                                    Bagoo<span className="text-white/80">PH</span>
                                </span>
                                <span className="text-[9px] font-mono tracking-widest text-white/70 uppercase font-bold">
                                    BUYER MARKETPLACE
                                </span>
                            </div>
                        </Link>

                        {/* Mega Central Search Bar */}
                        <div className="flex-1 max-w-3xl">
                            <form onSubmit={handleSearch} className="relative flex items-center">
                                <input
                                    type="text"
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    placeholder="Search products across 14 departments, brands, or deals..."
                                    className="w-full pl-4 pr-24 py-2.5 rounded-lg bg-white text-slate-900 text-sm placeholder-slate-400 focus:outline-hidden focus:ring-2 focus:ring-black shadow-inner"
                                />
                                <button
                                    type="submit"
                                    className="absolute right-1.5 top-1.5 bottom-1.5 px-5 bg-[#E00D42] hover:bg-[#C20836] text-white rounded-md text-xs font-bold uppercase transition flex items-center gap-1.5 shadow-sm"
                                >
                                    <Search className="w-4 h-4" />
                                    <span className="hidden sm:inline">Search</span>
                                </button>
                            </form>

                            {/* Trending Search Chips */}
                            <div className="hidden lg:flex items-center gap-3 mt-1.5 text-[11px] text-white/80 font-mono">
                                <span className="text-white/50 text-[10px] uppercase font-bold">Trending:</span>
                                {trendingKeywords.map((kw, i) => (
                                    <button
                                        key={i}
                                        type="button"
                                        onClick={() => {
                                            setSearchQuery(kw);
                                            router.get(route('buyer.index'), { search: kw });
                                        }}
                                        className="hover:text-white hover:underline transition"
                                    >
                                        {kw}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* Cart Floating Action Shortcut */}
                        <Link 
                            href={route('buyer.cart')} 
                            className="relative p-2.5 rounded-xl bg-white/10 hover:bg-white/20 transition flex items-center gap-2 text-white shrink-0 group"
                        >
                            <ShoppingCart className="w-6 h-6 group-hover:scale-110 transition-transform" />
                            {cartCount > 0 && (
                                <span className="absolute -top-1.5 -right-1.5 min-w-[20px] h-5 px-1 bg-white text-[#E00D42] rounded-full text-xs font-black flex items-center justify-center shadow-md border-2 border-[#E00D42] animate-bounce">
                                    {cartCount}
                                </span>
                            )}
                            <span className="font-mono text-xs font-bold hidden md:inline">
                                Cart
                            </span>
                        </Link>
                    </div>
                </div>
            </header>

            {/* 3. MAIN CONTENT BODY */}
            <main className="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
                {children}
            </main>

            {/* 4. FLOATING LIVE CHAT WIDGET (SHOPEE STYLE) */}
            <div className="fixed bottom-6 right-6 z-50">
                {chatOpen ? (
                    <div className="w-80 sm:w-96 bg-white rounded-2xl shadow-2xl border border-slate-200 overflow-hidden flex flex-col font-sans animate-scale-in">
                        {/* Chat Header */}
                        <div className="bg-[#E00D42] text-white p-3.5 flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <MessageSquare className="w-4 h-4" />
                                <span className="font-bold text-sm">BagooPH Live Care & Merchant Chat</span>
                            </div>
                            <button onClick={() => setChatOpen(false)} className="text-white/80 hover:text-white">
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        {/* Messages Stream */}
                        <div className="p-4 h-64 overflow-y-auto space-y-3 bg-slate-50 text-xs font-sans">
                            {chatHistory.map((msg, idx) => (
                                <div 
                                    key={idx} 
                                    className={`flex ${msg.sender === 'user' ? 'justify-end' : 'justify-start'}`}
                                >
                                    <div className={`p-3 rounded-2xl max-w-[80%] ${
                                        msg.sender === 'user' 
                                            ? 'bg-[#E00D42] text-white rounded-br-xs' 
                                            : 'bg-white text-slate-800 border border-slate-200 shadow-2xs rounded-bl-xs'
                                    }`}>
                                        <p>{msg.text}</p>
                                    </div>
                                </div>
                            ))}
                        </div>

                        {/* Chat Input */}
                        <form onSubmit={handleSendChat} className="p-2.5 bg-white border-t border-slate-200 flex gap-2">
                            <input
                                type="text"
                                value={chatMessage}
                                onChange={(e) => setChatMessage(e.target.value)}
                                placeholder="Type a message to support or merchant..."
                                className="flex-1 px-3 py-1.5 text-xs bg-slate-100 rounded-lg border-0 focus:ring-1 focus:ring-[#E00D42] outline-hidden"
                            />
                            <button
                                type="submit"
                                className="px-3 py-1.5 bg-[#E00D42] text-white font-bold text-xs rounded-lg uppercase"
                            >
                                Send
                            </button>
                        </form>
                    </div>
                ) : (
                    <button
                        onClick={() => setChatOpen(true)}
                        className="px-4 py-3 bg-[#E00D42] hover:bg-[#C20836] text-white font-bold rounded-full shadow-2xl transition duration-300 flex items-center gap-2 hover:scale-105 uppercase tracking-wider text-xs font-mono"
                    >
                        <MessageSquare className="w-4 h-4" />
                        <span>Chat Support</span>
                    </button>
                )}
            </div>

            {/* 5. SHOPEE / SHEIN GRADE E-COMMERCE FOOTER */}
            <footer className="bg-white border-t border-slate-200 text-slate-600 mt-16 font-sans text-xs">
                {/* Assurance Badges Strip */}
                <div className="border-b border-slate-100 py-6 bg-slate-50/50">
                    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div className="flex items-center gap-3.5">
                            <div className="w-11 h-11 rounded-xl bg-rose-50 text-[#E00D42] flex items-center justify-center shrink-0">
                                <ShieldCheck className="w-6 h-6" />
                            </div>
                            <div>
                                <h4 className="font-bold text-slate-900 text-sm">100% Authentic Guarantee</h4>
                                <p className="text-slate-500 text-[11px]">Direct verified merchants & verified brands.</p>
                            </div>
                        </div>

                        <div className="flex items-center gap-3.5">
                            <div className="w-11 h-11 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0">
                                <Truck className="w-6 h-6" />
                            </div>
                            <div>
                                <h4 className="font-bold text-slate-900 text-sm">Bagoo Express Dispatch</h4>
                                <p className="text-slate-500 text-[11px]">Live GPS telemetry & doorstep delivery.</p>
                            </div>
                        </div>

                        <div className="flex items-center gap-3.5">
                            <div className="w-11 h-11 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0">
                                <CreditCard className="w-6 h-6" />
                            </div>
                            <div>
                                <h4 className="font-bold text-slate-900 text-sm">Flexible Payments</h4>
                                <p className="text-slate-500 text-[11px]">Cash on Delivery, Cards, Bank & E-Wallets.</p>
                            </div>
                        </div>

                        <div className="flex items-center gap-3.5">
                            <div className="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                                <Sparkles className="w-6 h-6" />
                            </div>
                            <div>
                                <h4 className="font-bold text-slate-900 text-sm">Daily Vouchers & Rewards</h4>
                                <p className="text-slate-500 text-[11px]">Free shipping & discount promo codes.</p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Footer Links */}
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 grid grid-cols-2 md:grid-cols-4 gap-8 text-xs">
                    <div>
                        <h5 className="font-bold uppercase text-slate-900 mb-3 tracking-wider font-mono text-[11px]">Customer Service</h5>
                        <ul className="space-y-2 text-slate-500">
                            <li><Link href={route('buyer.index')} className="hover:text-[#E00D42]">Help Centre</Link></li>
                            <li><Link href={route('buyer.orders.index')} className="hover:text-[#E00D42]">Order Tracking</Link></li>
                            <li><Link href={route('buyer.cart')} className="hover:text-[#E00D42]">Shopping Cart</Link></li>
                            <li><span className="hover:text-[#E00D42] cursor-pointer">Return & Refund Policy</span></li>
                        </ul>
                    </div>

                    <div>
                        <h5 className="font-bold uppercase text-slate-900 mb-3 tracking-wider font-mono text-[11px]">About BagooPH</h5>
                        <ul className="space-y-2 text-slate-500">
                            <li><Link href="/" className="hover:text-[#E00D42]">Platform Overview</Link></li>
                            <li><Link href={route('seller.register')} className="hover:text-[#E00D42]">Seller Centre</Link></li>
                            <li><span className="hover:text-[#E00D42] cursor-pointer">Terms & Privacy</span></li>
                            <li><span className="hover:text-[#E00D42] cursor-pointer">Flash Deals Calendar</span></li>
                        </ul>
                    </div>

                    <div>
                        <h5 className="font-bold uppercase text-slate-900 mb-3 tracking-wider font-mono text-[11px]">Payment Methods</h5>
                        <div className="flex flex-wrap gap-2 pt-1 font-mono text-[10px] font-bold">
                            <span className="px-2.5 py-1 bg-slate-100 rounded border border-slate-200">COD</span>
                            <span className="px-2.5 py-1 bg-slate-100 rounded border border-slate-200">GCASH</span>
                            <span className="px-2.5 py-1 bg-slate-100 rounded border border-slate-200">VISA</span>
                            <span className="px-2.5 py-1 bg-slate-100 rounded border border-slate-200">MASTERCARD</span>
                            <span className="px-2.5 py-1 bg-slate-100 rounded border border-slate-200">MAYA</span>
                        </div>
                    </div>

                    <div>
                        <h5 className="font-bold uppercase text-slate-900 mb-3 tracking-wider font-mono text-[11px]">Logistics Fleet</h5>
                        <div className="space-y-2 text-slate-500">
                            <p className="font-bold text-slate-800">Bagoo Express Courier Fleet</p>
                            <p className="text-[11px]">Integrated real-time dispatcher dispatching across Metro Manila, Luzon, Visayas, and Mindanao.</p>
                        </div>
                    </div>
                </div>

                <div className="border-t border-slate-100 py-4 text-center font-mono text-[11px] text-slate-400">
                    © 2026 BagooPH Ecosystem. All Rights Reserved. Designed for High-Performance E-Commerce.
                </div>
            </footer>
        </div>
    );
}
