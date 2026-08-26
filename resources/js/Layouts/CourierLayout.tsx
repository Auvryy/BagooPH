import React, { useState, useEffect } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import BagooLogo from '@/Components/BagooLogo';
import { 
    Truck, 
    DollarSign, 
    MessageSquare, 
    User as UserIcon, 
    LogOut, 
    Menu, 
    X, 
    CheckCircle2, 
    Power, 
    Clock, 
    ShieldCheck, 
    Navigation, 
    Package,
    TrendingUp,
    ChevronDown
} from 'lucide-react';

interface Props {
    children: React.ReactNode;
    title: string;
    subtitle?: string;
    isOnline?: boolean;
}

export default function CourierLayout({ children, title, subtitle, isOnline = true }: Props) {
    const { auth, flash } = usePage<PageProps>().props;
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [userMenuOpen, setUserMenuOpen] = useState(false);
    const [currentTime, setCurrentTime] = useState('');
    const [dutyLoading, setDutyLoading] = useState(false);

    const user = auth.user;

    useEffect(() => {
        const updateClock = () => {
            const now = new Date();
            setCurrentTime(now.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true }));
        };
        updateClock();
        const timer = setInterval(updateClock, 1000);
        return () => clearInterval(timer);
    }, []);

    const toggleDutyStatus = () => {
        setDutyLoading(true);
        router.post(route('courier.toggleDuty'), {}, {
            preserveScroll: true,
            onFinish: () => setDutyLoading(false),
        });
    };

    const navItems = [
        { name: 'Dispatch Board & Tasks', href: route('courier.deliveries'), icon: Navigation, current: route().current('courier.deliveries') },
        { name: 'Earnings & Trip History', href: route('courier.earnings'), icon: DollarSign, current: route().current('courier.earnings') },
        { name: 'Live Support & Chat', href: route('courier.messages'), icon: MessageSquare, current: route().current('courier.messages') },
        { name: 'Driver & Vehicle Profile', href: route('courier.profile'), icon: UserIcon, current: route().current('courier.profile') },
    ];

    return (
        <div className="h-screen w-full flex overflow-hidden bg-[#F8FAFC] text-slate-900 font-sans antialiased selection:bg-[#E00D42] selection:text-white">
            
            {/* Backdrop for mobile drawer */}
            {sidebarOpen && (
                <div 
                    className="fixed inset-0 bg-black/50 backdrop-blur-xs z-40 lg:hidden"
                    onClick={() => setSidebarOpen(false)}
                />
            )}

            {/* Courier Sidebar (Fixed & Sticky) */}
            <aside className={`
                fixed inset-y-0 left-0 z-50 w-72 bg-slate-950 text-white border-r border-slate-800 
                flex flex-col transition-transform duration-200 ease-in-out
                ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'} 
                lg:static lg:translate-x-0 lg:h-full lg:shrink-0 lg:z-30
            `}>
                
                {/* Header Brand */}
                <div className="p-4 border-b border-slate-800 space-y-3 shrink-0 bg-slate-950">
                    <div className="flex items-center justify-between">
                        <Link href={route('courier.deliveries')} className="flex items-center gap-2.5">
                            <BagooLogo className="w-8 h-8 shadow-xs" rounded="rounded-xl" />
                            <div>
                                <span className="text-base font-black tracking-tight text-white">Bagoo<span className="text-[#E00D42]">Express</span></span>
                                <span className="block text-[9px] uppercase font-bold tracking-widest text-amber-400 -mt-0.5 font-mono">
                                    Courier Rider Fleet
                                </span>
                            </div>
                        </Link>
                        <button 
                            onClick={() => setSidebarOpen(false)}
                            className="lg:hidden p-1 text-slate-400 hover:text-white"
                        >
                            <X className="w-5 h-5" />
                        </button>
                    </div>

                    {/* Driver Status Card with Live Online Toggle */}
                    <div className="p-3 rounded-xl bg-slate-900 border border-slate-800 space-y-2 font-mono">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <span className={`w-2.5 h-2.5 rounded-full ${isOnline ? 'bg-emerald-400 animate-ping' : 'bg-slate-500'}`}></span>
                                <span className="text-xs font-bold text-white truncate">{user?.name}</span>
                            </div>
                            <span className={`px-1.5 py-0.2 rounded text-[9px] font-bold uppercase ${isOnline ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40' : 'bg-slate-800 text-slate-400'}`}>
                                {isOnline ? 'ONLINE' : 'OFFLINE'}
                            </span>
                        </div>

                        <button
                            type="button"
                            onClick={toggleDutyStatus}
                            disabled={dutyLoading}
                            className={`w-full py-1.5 px-3 rounded-lg text-[10px] font-bold uppercase transition flex items-center justify-center gap-1.5 shadow-xs ${
                                isOnline 
                                    ? 'bg-rose-500/20 hover:bg-rose-500/30 text-rose-300 border border-rose-500/40' 
                                    : 'bg-emerald-500 hover:bg-emerald-600 text-slate-950'
                            }`}
                        >
                            <Power className="w-3 h-3" />
                            <span>{isOnline ? 'Go Off Duty' : 'Go Online (Accept Jobs)'}</span>
                        </button>
                    </div>
                </div>

                {/* Navigation Items */}
                <nav className="flex-1 px-3 py-3 space-y-1 overflow-y-auto font-sans scrollbar-thin">
                    <p className="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 font-mono">
                        Rider Workflows
                    </p>
                    {navItems.map((item) => (
                        <Link
                            key={item.name}
                            href={item.href}
                            className={`flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-xs font-bold transition ${
                                item.current 
                                    ? 'bg-[#E00D42] text-white shadow-xs' 
                                    : 'text-slate-300 hover:text-white hover:bg-slate-900'
                            }`}
                        >
                            <item.icon className="w-4 h-4 shrink-0" />
                            <span>{item.name}</span>
                        </Link>
                    ))}
                </nav>

                {/* Bottom Bar: Clock & Anchored Sign Out */}
                <div className="p-3.5 border-t border-slate-800 bg-slate-950 shrink-0 font-mono mt-auto space-y-2">
                    <div className="flex items-center justify-between text-[10px] text-slate-400">
                        <div className="flex items-center gap-1.5">
                            <Clock className="w-3 h-3 text-amber-400" />
                            <span>{currentTime || '12:00:00 PM'}</span>
                        </div>
                        <span className="text-[9px] text-emerald-400 font-bold">GPS ACTIVE</span>
                    </div>

                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        className="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-xl text-xs font-bold text-rose-400 hover:bg-rose-950/40 transition border border-rose-900/50 uppercase tracking-wider shadow-2xs"
                    >
                        <LogOut className="w-3.5 h-3.5" />
                        <span>Sign Out</span>
                    </Link>
                </div>
            </aside>

            {/* Main Content Column */}
            <div className="flex-1 flex flex-col h-full min-w-0 overflow-hidden">
                
                {/* Header Topbar */}
                <header className="h-16 bg-white border-b border-slate-200 px-4 sm:px-8 flex items-center justify-between shrink-0 shadow-2xs z-20">
                    <div className="flex items-center gap-3">
                        <button
                            onClick={() => setSidebarOpen(true)}
                            className="lg:hidden p-2 text-slate-600 hover:bg-slate-100 rounded-xl"
                        >
                            <Menu className="w-5 h-5" />
                        </button>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-base font-black text-slate-900 tracking-tight">{title}</h1>
                                <span className="text-[10px] font-mono px-2 py-0.5 rounded bg-amber-50 text-amber-800 border border-amber-200 font-bold uppercase">
                                    EXPRESS DISPATCH
                                </span>
                            </div>
                            {subtitle && <p className="text-[11px] text-slate-500 font-medium">{subtitle}</p>}
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        <div 
                            className="relative"
                            onMouseEnter={() => setUserMenuOpen(true)}
                            onMouseLeave={() => setUserMenuOpen(false)}
                        >
                            <button
                                type="button"
                                onClick={() => setUserMenuOpen(!userMenuOpen)}
                                className="flex items-center gap-2.5 p-1.5 rounded-xl hover:bg-slate-100 transition group focus:outline-hidden"
                            >
                                <div className="w-8 h-8 rounded-xl bg-slate-950 text-white font-bold text-xs flex items-center justify-center shadow-xs group-hover:bg-[#E00D42] transition">
                                    {user?.name.charAt(0)}
                                </div>
                                <div className="hidden sm:block text-left font-mono">
                                    <div className="flex items-center gap-1">
                                        <p className="text-xs font-bold text-slate-800 leading-tight group-hover:text-[#E00D42] transition">{user?.name}</p>
                                        <ChevronDown className="w-3 h-3 text-slate-400 group-hover:rotate-180 transition-transform" />
                                    </div>
                                    <span className="text-[10px] text-emerald-600 font-bold uppercase">Authorized Driver</span>
                                </div>
                            </button>

                            {/* Dropdown Menu */}
                            {userMenuOpen && (
                                <div className="absolute right-0 mt-1 w-56 bg-white rounded-2xl shadow-2xl border border-slate-200 py-2 z-50 text-slate-800 font-sans animate-scale-in">
                                    <div className="px-4 py-2 border-b border-slate-100 font-mono text-xs">
                                        <p className="font-bold text-slate-900 truncate">{user?.name}</p>
                                        <p className="text-[10px] text-slate-500 truncate">{user?.email}</p>
                                    </div>

                                    <Link
                                        href={route('courier.profile')}
                                        className="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                    >
                                        <UserIcon className="w-4 h-4 text-slate-400" />
                                        <span>Driver & Vehicle Specs</span>
                                    </Link>

                                    <Link
                                        href={route('profile.edit')}
                                        className="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                    >
                                        <ShieldCheck className="w-4 h-4 text-emerald-500" />
                                        <span>Account Security</span>
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
                            )}
                        </div>
                    </div>
                </header>

                {/* Flash Notifications */}
                {flash.success && (
                    <div className="bg-emerald-600 text-white py-2 px-6 text-xs font-bold font-mono shadow-xs flex items-center gap-2 shrink-0">
                        <CheckCircle2 className="w-4 h-4" />
                        <span>{flash.success}</span>
                    </div>
                )}
                {flash.error && (
                    <div className="bg-[#E00D42] text-white py-2 px-6 text-xs font-bold font-mono shadow-xs shrink-0">
                        {flash.error}
                    </div>
                )}

                {/* Content Viewport */}
                <main className="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 bg-[#F8FAFC]">
                    <div className="max-w-7xl mx-auto space-y-6">
                        {children}
                    </div>
                </main>
            </div>
        </div>
    );
}
