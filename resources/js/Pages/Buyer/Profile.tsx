import React, { useState, useEffect } from 'react';
import { Head, useForm, Link } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import { User, Order } from '@/types';
import { 
    User as UserIcon, 
    ShieldCheck, 
    Wallet, 
    MapPin, 
    Plus, 
    Phone, 
    Mail, 
    Calendar, 
    CreditCard, 
    Check, 
    ArrowUpRight, 
    ArrowDownLeft, 
    Clock, 
    Sparkles, 
    AlertCircle,
    Package,
    Tag,
    Lock,
    KeyRound,
    ChevronRight,
    Building2,
    Shield,
    Truck,
    CheckCircle2,
    Store,
    MessageSquare,
    ShoppingBag,
    Star
} from 'lucide-react';

interface SavedAddress {
    id: number;
    is_default: boolean;
    recipient_name: string;
    phone: string;
    province: string;
    city: string;
    barangay: string;
    street: string;
    type: string;
}

interface WalletData {
    balance: number;
    currency: string;
    status: string;
    account_number: string;
    recent_transactions: {
        id: string;
        title: string;
        amount: number;
        type: 'credit' | 'debit';
        date: string;
    }[];
}

interface Props {
    user: User;
    addresses: SavedAddress[];
    wallet: WalletData;
    orders: Order[];
    ordersCount: number;
    initialTab?: TabType;
}

type TabType = 'orders' | 'account' | 'addresses' | 'wallet' | 'vouchers';

interface ProfileFormData {
    name: string;
    phone: string;
    birthday: string;
    gender: string;
}

export default function BuyerProfile({ 
    user, 
    addresses: initialAddresses, 
    wallet: initialWallet, 
    orders = [], 
    ordersCount = 0,
    initialTab = 'orders' 
}: Props) {
    const [activeTab, setActiveTab] = useState<TabType>(initialTab);
    const [selectedOrderStatus, setSelectedOrderStatus] = useState<string>('all');
    const [addresses, setAddresses] = useState<SavedAddress[]>(initialAddresses);
    const [wallet, setWallet] = useState<WalletData>(initialWallet);
    const [showAddressModal, setShowAddressModal] = useState(false);
    const [topupAmount, setTopupAmount] = useState<number>(1000);
    const [topupLoading, setTopupLoading] = useState(false);
    const [topupSuccess, setTopupSuccess] = useState(false);

    // Sync active tab if initialTab prop changes from URL navigation
    useEffect(() => {
        if (initialTab) {
            setActiveTab(initialTab);
        }
    }, [initialTab]);

    // Profile Form
    const { data, setData, post, processing, errors, recentlySuccessful } = useForm<ProfileFormData>({
        name: user.name || '',
        phone: user.phone || '',
        birthday: (user as Record<string, any>).birthday || '2000-01-15',
        gender: (user as Record<string, any>).gender || 'male',
    });

    // Password Form
    const passwordForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    // New Address Form (PSGC)
    const [newAddress, setNewAddress] = useState({
        recipient_name: user.name,
        phone: user.phone || '+63 912 345 6789',
        province: 'Metro Manila',
        city: 'Quezon City',
        barangay: 'Diliman',
        street: '',
        type: 'Home',
        is_default: false,
    });

    const handleProfileSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('buyer.profile.update'), {
            preserveScroll: true,
        });
    };

    const handlePasswordSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        passwordForm.put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => passwordForm.reset(),
        });
    };

    const handleAddAddress = (e: React.FormEvent) => {
        e.preventDefault();
        if (!newAddress.street) return;

        const created: SavedAddress = {
            id: Date.now(),
            ...newAddress,
        };

        if (created.is_default) {
            setAddresses(prev => prev.map(a => ({ ...a, is_default: false })).concat(created));
        } else {
            setAddresses(prev => [...prev, created]);
        }

        setShowAddressModal(false);
        setNewAddress({
            recipient_name: user.name,
            phone: user.phone || '+63 912 345 6789',
            province: 'Metro Manila',
            city: 'Quezon City',
            barangay: 'Diliman',
            street: '',
            type: 'Home',
            is_default: false,
        });
    };

    const setDefaultAddress = (id: number) => {
        setAddresses(prev => prev.map(a => ({
            ...a,
            is_default: a.id === id,
        })));
    };

    const handleTopup = (amount: number) => {
        setTopupLoading(true);
        setTimeout(() => {
            setWallet(prev => ({
                ...prev,
                balance: prev.balance + amount,
                recent_transactions: [
                    {
                        id: `tx-${Date.now()}`,
                        title: `Top-up Sandbox Simulation (+₱${amount.toLocaleString()})`,
                        amount: amount,
                        type: 'credit',
                        date: 'Just now',
                    },
                    ...prev.recent_transactions,
                ],
            }));
            setTopupLoading(false);
            setTopupSuccess(true);
            setTimeout(() => setTopupSuccess(false), 3000);
        }, 600);
    };

    const formatPrice = (val?: number | string | null) => {
        const num = Number(val || 0);
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2,
        }).format(num);
    };

    const filteredOrders = orders.filter(order => {
        if (selectedOrderStatus === 'all') return true;
        if (selectedOrderStatus === 'to_ship') return order.status === 'processing' || order.status === 'ready_for_pickup';
        if (selectedOrderStatus === 'to_receive') return order.status === 'shipped';
        if (selectedOrderStatus === 'completed') return order.status === 'delivered';
        return true;
    });

    const getStatusPill = (status: string) => {
        switch (status) {
            case 'delivered':
                return <span className="px-2.5 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full text-[10px] font-bold font-mono flex items-center gap-1"><CheckCircle2 className="w-3 h-3" /> Delivered</span>;
            case 'shipped':
                return <span className="px-2.5 py-0.5 bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-full text-[10px] font-bold font-mono flex items-center gap-1"><Truck className="w-3 h-3" /> Out for Delivery</span>;
            case 'ready_for_pickup':
                return <span className="px-2.5 py-0.5 bg-amber-50 text-amber-700 border border-amber-200 rounded-full text-[10px] font-bold font-mono flex items-center gap-1"><Clock className="w-3 h-3" /> Ready for Pickup</span>;
            case 'processing':
                return <span className="px-2.5 py-0.5 bg-blue-50 text-blue-700 border border-blue-200 rounded-full text-[10px] font-bold font-mono flex items-center gap-1"><Clock className="w-3 h-3" /> Packaging</span>;
            default:
                return <span className="px-2.5 py-0.5 bg-slate-100 text-slate-700 rounded-full text-[10px] font-bold font-mono uppercase">{status}</span>;
        }
    };

    const navItems: { id: TabType; label: string; icon: React.ComponentType<{ className?: string }>; badge?: string | number }[] = [
        { id: 'orders', label: 'My Purchases & Orders', icon: Package, badge: orders.length },
        { id: 'account', label: 'My Account & Security', icon: UserIcon },
        { id: 'addresses', label: 'Delivery Address Book', icon: MapPin, badge: addresses.length },
        { id: 'wallet', label: 'Simulated Digital Wallet', icon: Wallet, badge: formatPrice(wallet.balance) },
        { id: 'vouchers', label: 'My Vouchers & Promos', icon: Tag, badge: '3 Available' },
    ];

    return (
        <BuyerLayout>
            <Head title="My Purchases & Account Hub — BagooPH" />

            <div className="max-w-7xl mx-auto py-6 sm:py-8 px-4 sm:px-6 lg:px-8 space-y-6 font-sans">
                
                {/* 1. TOP HEADER STRIP */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-200">
                    <div>
                        <h1 className="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">
                            {activeTab === 'orders' ? 'My Purchases & Order Tracking' : 'Account Settings & Credentials'}
                        </h1>
                        <p className="text-xs text-slate-500 font-mono">
                            {activeTab === 'orders' ? 'Track real-time parcel dispatch, delivery timelines, and order receipts' : 'Manage personal credentials, PSGC shipping addresses, and simulated wallet'}
                        </p>
                    </div>

                    <Link
                        href={route('buyer.index')}
                        className="px-4 py-2 rounded-xl bg-slate-900 hover:bg-black text-white text-xs font-mono font-bold uppercase transition flex items-center gap-2 shadow-xs w-fit"
                    >
                        <ShoppingBag className="w-4 h-4 text-amber-400" />
                        <span>Continue Shopping</span>
                    </Link>
                </div>

                {/* 2. TWO-COLUMN WORKSPACE: LEFT SIDEBAR + RIGHT WORKSPACE */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                    
                    {/* LEFT SIDEBAR NAVIGATION */}
                    <div className="lg:col-span-4 bg-white rounded-3xl p-5 border border-slate-200 shadow-xs space-y-5">
                        
                        {/* User Identity Mini Card */}
                        <div className="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 flex items-center gap-3.5">
                            <div className="w-12 h-12 rounded-xl bg-slate-950 text-white font-black text-lg flex items-center justify-center shadow-xs shrink-0">
                                {user.name.charAt(0).toUpperCase()}
                            </div>
                            <div className="min-w-0">
                                <h3 className="font-bold text-slate-900 text-sm truncate">{user.name}</h3>
                                <p className="text-[11px] text-slate-500 font-mono truncate">{user.email}</p>
                                <span className="inline-flex items-center gap-1 text-[9px] font-bold uppercase text-emerald-600 font-mono mt-0.5">
                                    <ShieldCheck className="w-3 h-3" /> Tier 1 Verified Buyer
                                </span>
                            </div>
                        </div>

                        {/* Navigation Links */}
                        <nav className="space-y-1.5 font-mono text-xs">
                            {navItems.map((item) => {
                                const isActive = activeTab === item.id;
                                return (
                                    <button
                                        key={item.id}
                                        onClick={() => setActiveTab(item.id)}
                                        className={`w-full flex items-center justify-between px-4 py-3 rounded-2xl font-bold transition text-left ${
                                            isActive
                                                ? 'bg-[#E00D42] text-white shadow-xs'
                                                : 'text-slate-700 hover:bg-slate-50 hover:text-slate-900'
                                        }`}
                                    >
                                        <div className="flex items-center gap-3">
                                            <item.icon className={`w-4 h-4 ${isActive ? 'text-white' : 'text-slate-400'}`} />
                                            <span>{item.label}</span>
                                        </div>
                                        {item.badge !== undefined && (
                                            <span className={`text-[10px] px-2 py-0.5 rounded-full font-bold ${
                                                isActive ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600'
                                            }`}>
                                                {item.badge}
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                        </nav>

                        {/* Extra Direct Shortcuts */}
                        <div className="pt-3 border-t border-slate-100 space-y-1 font-mono text-xs">
                            <Link
                                href={route('buyer.messages')}
                                className="w-full flex items-center justify-between px-4 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 transition"
                            >
                                <span className="flex items-center gap-2.5">
                                    <MessageSquare className="w-4 h-4 text-emerald-500" />
                                    <span>Store & Courier Messages</span>
                                </span>
                                <ChevronRight className="w-3.5 h-3.5 text-slate-400" />
                            </Link>

                            <Link
                                href={route('buyer.disputes.index')}
                                className="w-full flex items-center justify-between px-4 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 transition"
                            >
                                <span className="flex items-center gap-2.5">
                                    <Shield className="w-4 h-4 text-amber-500" />
                                    <span>Returns & Dispute Desk</span>
                                </span>
                                <ChevronRight className="w-3.5 h-3.5 text-slate-400" />
                            </Link>
                        </div>

                    </div>

                    {/* RIGHT CONTENT WORKSPACE */}
                    <div className="lg:col-span-8 space-y-6">
                        
                        {/* TAB 1: MY PURCHASES & ORDERS (DEFAULT ACTIVE TAB) */}
                        {activeTab === 'orders' && (
                            <div className="space-y-4">
                                
                                {/* Status Filter Strip */}
                                <div className="bg-white rounded-2xl p-1.5 border border-slate-200 shadow-xs flex items-center gap-1.5 overflow-x-auto scrollbar-none font-mono text-xs">
                                    <button
                                        onClick={() => setSelectedOrderStatus('all')}
                                        className={`flex-1 py-2 px-3 rounded-xl font-bold uppercase transition text-center whitespace-nowrap ${
                                            selectedOrderStatus === 'all' ? 'bg-slate-900 text-white shadow-xs' : 'text-slate-700 hover:bg-slate-50'
                                        }`}
                                    >
                                        All ({orders.length})
                                    </button>
                                    <button
                                        onClick={() => setSelectedOrderStatus('to_ship')}
                                        className={`flex-1 py-2 px-3 rounded-xl font-bold uppercase transition text-center whitespace-nowrap ${
                                            selectedOrderStatus === 'to_ship' ? 'bg-amber-500 text-slate-950 shadow-xs' : 'text-slate-700 hover:bg-slate-50'
                                        }`}
                                    >
                                        To Ship
                                    </button>
                                    <button
                                        onClick={() => setSelectedOrderStatus('to_receive')}
                                        className={`flex-1 py-2 px-3 rounded-xl font-bold uppercase transition text-center whitespace-nowrap ${
                                            selectedOrderStatus === 'to_receive' ? 'bg-indigo-600 text-white shadow-xs' : 'text-slate-700 hover:bg-slate-50'
                                        }`}
                                    >
                                        In Transit
                                    </button>
                                    <button
                                        onClick={() => setSelectedOrderStatus('completed')}
                                        className={`flex-1 py-2 px-3 rounded-xl font-bold uppercase transition text-center whitespace-nowrap ${
                                            selectedOrderStatus === 'completed' ? 'bg-emerald-600 text-white shadow-xs' : 'text-slate-700 hover:bg-slate-50'
                                        }`}
                                    >
                                        Delivered
                                    </button>
                                </div>

                                {/* Orders List Cards */}
                                {filteredOrders.length === 0 ? (
                                    <div className="bg-white rounded-3xl p-12 text-center space-y-3 border border-slate-200 shadow-xs">
                                        <Package className="w-12 h-12 text-slate-300 mx-auto" />
                                        <h3 className="text-base font-bold text-slate-800">No orders found in this status</h3>
                                        <p className="text-xs text-slate-500 font-mono">
                                            Discover verified tactical apparel, bags, and EDC gear on BagooPH.
                                        </p>
                                        <Link
                                            href={route('buyer.index')}
                                            className="inline-block px-5 py-2.5 bg-[#E00D42] text-white rounded-xl text-xs font-mono font-bold uppercase shadow-xs"
                                        >
                                            Explore Marketplace
                                        </Link>
                                    </div>
                                ) : (
                                    filteredOrders.map((order) => (
                                        <div
                                            key={order.id}
                                            className="bg-white rounded-3xl p-6 border border-slate-200 shadow-xs hover:border-slate-300 transition space-y-4 font-sans"
                                        >
                                            {/* Header */}
                                            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-3 border-b border-slate-100 font-mono text-xs">
                                                <div className="flex items-center gap-2.5">
                                                    <Store className="w-4 h-4 text-slate-400" />
                                                    <span className="font-bold text-slate-900">
                                                        {order.items?.[0]?.product?.shop?.name || 'Bagoo Merchant Flagship'}
                                                    </span>
                                                    <span className="text-slate-300">•</span>
                                                    <span className="text-slate-500">Order #{order.order_number}</span>
                                                </div>

                                                <div className="flex items-center gap-2">
                                                    {getStatusPill(order.status)}
                                                </div>
                                            </div>

                                            {/* Items List */}
                                            <div className="divide-y divide-slate-100">
                                                {order.items?.map((item) => (
                                                    <div key={item.id} className="py-3 flex items-center gap-4">
                                                        <img
                                                            src={item.product?.featured_image || (item.product?.images && item.product.images[0]?.image_url) || 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=500&auto=format&fit=crop&q=60'}
                                                            alt={item.product?.name || 'Product Item'}
                                                            className="w-16 h-16 rounded-xl object-cover border border-slate-200 shrink-0"
                                                        />
                                                        <div className="flex-1 min-w-0">
                                                            <h4 className="font-bold text-slate-900 text-xs truncate">
                                                                {item.product?.name || 'Tactical Product'}
                                                            </h4>
                                                            <p className="text-[11px] text-slate-500 font-mono">
                                                                Qty: <span className="text-slate-800 font-bold">{item.quantity}</span> • Unit: <span className="text-slate-800">{formatPrice(item.unit_price)}</span>
                                                            </p>
                                                            <p className="text-xs font-bold text-slate-900 font-mono mt-0.5">
                                                                Subtotal: {formatPrice(item.subtotal || (Number(item.unit_price) * item.quantity))}
                                                            </p>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>

                                            {/* Delivery Telemetry / Notes */}
                                            {order.delivery && (
                                                <div className="p-3 bg-slate-50 rounded-xl border border-slate-200/80 flex items-center justify-between font-mono text-[11px]">
                                                    <div className="flex items-center gap-2 text-slate-600">
                                                        <Truck className="w-3.5 h-3.5 text-indigo-600" />
                                                        <span>
                                                            Tracking: <strong className="text-slate-900">#{order.delivery.tracking_number}</strong>
                                                        </span>
                                                        {order.delivery.courier && (
                                                            <span className="text-slate-400">• Rider: {order.delivery.courier.name}</span>
                                                        )}
                                                    </div>
                                                    <span className="text-indigo-600 font-bold uppercase text-[10px]">
                                                        {order.delivery.status.replace('_', ' ')}
                                                    </span>
                                                </div>
                                            )}

                                            {/* Footer Actions */}
                                            <div className="pt-3 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3 font-mono text-xs">
                                                <div>
                                                    <span className="text-slate-400 text-[11px]">Order Total: </span>
                                                    <span className="font-black text-slate-900 text-base">{formatPrice(order.total_amount)}</span>
                                                    <span className="text-slate-400 text-[10px] ml-1.5 uppercase">({order.payment_method})</span>
                                                </div>

                                                <div className="flex items-center gap-2">
                                                    <Link
                                                        href={route('buyer.orders.show', order.id)}
                                                        className="px-4 py-2 bg-slate-900 hover:bg-black text-white rounded-xl font-bold uppercase transition flex items-center gap-1.5 shadow-xs"
                                                    >
                                                        <Truck className="w-3.5 h-3.5 text-amber-400" />
                                                        <span>Track Parcel</span>
                                                    </Link>

                                                    {order.status === 'delivered' && (
                                                        <Link
                                                            href={route('buyer.disputes.index')}
                                                            className="px-3 py-2 border border-slate-200 text-slate-700 hover:bg-slate-50 rounded-xl font-bold transition"
                                                        >
                                                            Report Defect
                                                        </Link>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    ))
                                )}

                            </div>
                        )}

                        {/* TAB 2: PERSONAL ACCOUNT & SECURITY */}
                        {activeTab === 'account' && (
                            <div className="space-y-6">
                                
                                {/* Personal Profile Info Form */}
                                <div className="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-xs space-y-6">
                                    <div className="border-b border-slate-100 pb-4">
                                        <h3 className="text-base font-black text-slate-900">Personal Information</h3>
                                        <p className="text-xs text-slate-500 font-mono">Update your official contact and recipient profile details</p>
                                    </div>

                                    {recentlySuccessful && (
                                        <div className="p-3 bg-emerald-50 text-emerald-800 rounded-xl border border-emerald-200 text-xs font-mono flex items-center gap-2">
                                            <Check className="w-4 h-4 text-emerald-600" />
                                            <span>Profile details updated successfully!</span>
                                        </div>
                                    )}

                                    <form onSubmit={handleProfileSubmit} className="space-y-4 text-xs font-sans">
                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <div>
                                                <label className="block font-bold text-slate-700 mb-1.5 font-mono">Full Name</label>
                                                <input
                                                    type="text"
                                                    value={data.name}
                                                    onChange={(e) => setData('name', e.target.value)}
                                                    className="w-full rounded-xl bg-slate-50 border border-slate-200 p-2.5 text-xs focus:ring-[#E00D42] focus:border-[#E00D42]"
                                                    required
                                                />
                                                {errors.name && <p className="text-rose-500 text-[10px] mt-1 font-mono">{errors.name}</p>}
                                            </div>

                                            <div>
                                                <label className="block font-bold text-slate-700 mb-1.5 font-mono">Email Address (Immutable)</label>
                                                <input
                                                    type="email"
                                                    value={user.email}
                                                    disabled
                                                    className="w-full rounded-xl bg-slate-100 border border-slate-200 p-2.5 text-xs text-slate-500 cursor-not-allowed font-mono"
                                                />
                                            </div>
                                        </div>

                                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                            <div>
                                                <label className="block font-bold text-slate-700 mb-1.5 font-mono">Mobile Contact</label>
                                                <input
                                                    type="text"
                                                    value={data.phone}
                                                    onChange={(e) => setData('phone', e.target.value)}
                                                    placeholder="+63 9XX XXX XXXX"
                                                    className="w-full rounded-xl bg-slate-50 border border-slate-200 p-2.5 text-xs font-mono focus:ring-[#E00D42] focus:border-[#E00D42]"
                                                />
                                            </div>

                                            <div>
                                                <label className="block font-bold text-slate-700 mb-1.5 font-mono">Birthday</label>
                                                <input
                                                    type="date"
                                                    value={data.birthday}
                                                    onChange={(e) => setData('birthday', e.target.value)}
                                                    className="w-full rounded-xl bg-slate-50 border border-slate-200 p-2.5 text-xs font-mono focus:ring-[#E00D42] focus:border-[#E00D42]"
                                                />
                                            </div>

                                            <div>
                                                <label className="block font-bold text-slate-700 mb-1.5 font-mono">Gender</label>
                                                <select
                                                    value={data.gender}
                                                    onChange={(e) => setData('gender', e.target.value)}
                                                    className="w-full rounded-xl bg-slate-50 border border-slate-200 p-2.5 text-xs focus:ring-[#E00D42] focus:border-[#E00D42]"
                                                >
                                                    <option value="male">Male</option>
                                                    <option value="female">Female</option>
                                                    <option value="other">Prefer not to say</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div className="pt-2 flex justify-end">
                                            <button
                                                type="submit"
                                                disabled={processing}
                                                className="px-6 py-2.5 bg-[#E00D42] hover:bg-[#C20836] text-white font-mono text-xs font-bold uppercase rounded-xl transition shadow-xs"
                                            >
                                                {processing ? 'Saving...' : 'Save Profile Changes'}
                                            </button>
                                        </div>
                                    </form>
                                </div>

                                {/* Password & Security Settings */}
                                <div className="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-xs space-y-6">
                                    <div className="border-b border-slate-100 pb-4">
                                        <h3 className="text-base font-black text-slate-900">Account Password & Security</h3>
                                        <p className="text-xs text-slate-500 font-mono">Ensure your account password is at least 8 characters long</p>
                                    </div>

                                    {passwordForm.recentlySuccessful && (
                                        <div className="p-3 bg-emerald-50 text-emerald-800 rounded-xl border border-emerald-200 text-xs font-mono flex items-center gap-2">
                                            <Check className="w-4 h-4 text-emerald-600" />
                                            <span>Password updated successfully!</span>
                                        </div>
                                    )}

                                    <form onSubmit={handlePasswordSubmit} className="space-y-4 text-xs font-sans">
                                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                            <div>
                                                <label className="block font-bold text-slate-700 mb-1.5 font-mono">Current Password</label>
                                                <input
                                                    type="password"
                                                    value={passwordForm.data.current_password}
                                                    onChange={(e) => passwordForm.setData('current_password', e.target.value)}
                                                    className="w-full rounded-xl bg-slate-50 border border-slate-200 p-2.5 text-xs"
                                                    required
                                                />
                                            </div>

                                            <div>
                                                <label className="block font-bold text-slate-700 mb-1.5 font-mono">New Password</label>
                                                <input
                                                    type="password"
                                                    value={passwordForm.data.password}
                                                    onChange={(e) => passwordForm.setData('password', e.target.value)}
                                                    className="w-full rounded-xl bg-slate-50 border border-slate-200 p-2.5 text-xs"
                                                    required
                                                />
                                            </div>

                                            <div>
                                                <label className="block font-bold text-slate-700 mb-1.5 font-mono">Confirm New Password</label>
                                                <input
                                                    type="password"
                                                    value={passwordForm.data.password_confirmation}
                                                    onChange={(e) => passwordForm.setData('password_confirmation', e.target.value)}
                                                    className="w-full rounded-xl bg-slate-50 border border-slate-200 p-2.5 text-xs"
                                                    required
                                                />
                                            </div>
                                        </div>

                                        <div className="pt-2 flex justify-end">
                                            <button
                                                type="submit"
                                                disabled={passwordForm.processing}
                                                className="px-6 py-2.5 bg-slate-900 hover:bg-black text-white font-mono text-xs font-bold uppercase rounded-xl transition shadow-xs"
                                            >
                                                {passwordForm.processing ? 'Updating...' : 'Update Password'}
                                            </button>
                                        </div>
                                    </form>
                                </div>

                            </div>
                        )}

                        {/* TAB 3: DELIVERY ADDRESS BOOK */}
                        {activeTab === 'addresses' && (
                            <div className="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-xs space-y-6">
                                <div className="flex items-center justify-between border-b border-slate-100 pb-4">
                                    <div>
                                        <h3 className="text-base font-black text-slate-900">PSGC Delivery Address Book</h3>
                                        <p className="text-xs text-slate-500 font-mono">Manage doorstep drop-off destinations for Bagoo Express riders</p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => setShowAddressModal(true)}
                                        className="px-4 py-2 bg-[#E00D42] hover:bg-[#C20836] text-white rounded-xl text-xs font-mono font-bold uppercase transition flex items-center gap-1.5 shadow-xs"
                                    >
                                        <Plus className="w-3.5 h-3.5" />
                                        <span>Add New Address</span>
                                    </button>
                                </div>

                                <div className="space-y-4">
                                    {addresses.map((addr) => (
                                        <div
                                            key={addr.id}
                                            className={`p-5 rounded-2xl border transition space-y-2 relative ${
                                                addr.is_default ? 'bg-amber-50/40 border-amber-300 shadow-xs' : 'bg-slate-50 border-slate-200'
                                            }`}
                                        >
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-2 font-mono text-xs">
                                                    <span className="font-bold text-slate-900">{addr.recipient_name}</span>
                                                    <span className="text-slate-400">•</span>
                                                    <span className="text-slate-600">{addr.phone}</span>
                                                    <span className="px-2 py-0.5 rounded bg-slate-200 text-slate-700 text-[10px] uppercase font-bold">
                                                        {addr.type}
                                                    </span>
                                                </div>

                                                {addr.is_default ? (
                                                    <span className="px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-800 text-[10px] font-mono font-bold">
                                                        DEFAULT ADDRESS
                                                    </span>
                                                ) : (
                                                    <button
                                                        type="button"
                                                        onClick={() => setDefaultAddress(addr.id)}
                                                        className="text-[11px] font-mono text-slate-500 hover:text-slate-900 underline"
                                                    >
                                                        Set as Default
                                                    </button>
                                                )}
                                            </div>

                                            <p className="text-xs text-slate-700 font-sans">
                                                {addr.street}, {addr.barangay}, {addr.city}, {addr.province}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* TAB 4: SIMULATED DIGITAL WALLET */}
                        {activeTab === 'wallet' && (
                            <div className="space-y-6">
                                
                                {/* Wallet Hero Stage */}
                                <div className="bg-slate-950 rounded-3xl p-6 sm:p-8 text-white border border-slate-800 shadow-xl space-y-6">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <Wallet className="w-5 h-5 text-amber-400" />
                                            <span className="font-mono text-xs font-bold uppercase text-slate-400">Bagoo Digital Wallet Sandbox</span>
                                        </div>
                                        <span className="px-2.5 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 font-mono text-[10px] font-bold border border-emerald-500/30">
                                            AUTHORIZED ACTIVE
                                        </span>
                                    </div>

                                    <div>
                                        <span className="text-xs text-slate-400 font-mono">Available Account Balance</span>
                                        <h2 className="text-3xl sm:text-4xl font-black text-emerald-400 mt-1">
                                            {formatPrice(wallet.balance)}
                                        </h2>
                                        <p className="text-[11px] text-slate-400 font-mono mt-1">
                                            Account: {wallet.account_number} • Instant settlement at checkout without gateway fees
                                        </p>
                                    </div>

                                    {/* Quick Simulation Top-Up Strip */}
                                    <div className="pt-4 border-t border-slate-800 space-y-2">
                                        <span className="text-[10px] font-mono text-slate-400 uppercase font-bold">Simulate Instant Top-up:</span>
                                        <div className="flex flex-wrap items-center gap-2">
                                            {[500, 1000, 2500, 5000].map((amt) => (
                                                <button
                                                    key={amt}
                                                    type="button"
                                                    onClick={() => handleTopup(amt)}
                                                    disabled={topupLoading}
                                                    className="px-3.5 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-mono text-xs font-bold border border-slate-700 transition"
                                                >
                                                    +₱{amt.toLocaleString()}
                                                </button>
                                            ))}
                                        </div>
                                        {topupSuccess && (
                                            <p className="text-emerald-400 text-xs font-mono font-bold flex items-center gap-1.5 mt-2 animate-fade-in">
                                                <Check className="w-3.5 h-3.5" /> Balance updated in sandbox ledger!
                                            </p>
                                        )}
                                    </div>
                                </div>

                                {/* Transactions Statement */}
                                <div className="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-xs space-y-4">
                                    <h4 className="font-bold text-slate-900 text-sm uppercase font-mono">Recent Wallet Transactions</h4>
                                    <div className="divide-y divide-slate-100">
                                        {wallet.recent_transactions.map((tx) => (
                                            <div key={tx.id} className="py-3 flex items-center justify-between font-mono text-xs">
                                                <div className="flex items-center gap-3">
                                                    <div className={`w-8 h-8 rounded-lg flex items-center justify-center font-bold ${
                                                        tx.type === 'credit' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'
                                                    }`}>
                                                        {tx.type === 'credit' ? <ArrowDownLeft className="w-4 h-4" /> : <ArrowUpRight className="w-4 h-4" />}
                                                    </div>
                                                    <div>
                                                        <p className="font-bold text-slate-800">{tx.title}</p>
                                                        <span className="text-[10px] text-slate-400">{tx.date}</span>
                                                    </div>
                                                </div>

                                                <span className={`font-black ${tx.type === 'credit' ? 'text-emerald-600' : 'text-rose-600'}`}>
                                                    {tx.type === 'credit' ? '+' : ''}{formatPrice(tx.amount)}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </div>

                            </div>
                        )}

                        {/* TAB 5: VOUCHERS & PROMOS */}
                        {activeTab === 'vouchers' && (
                            <div className="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-xs space-y-6">
                                <div className="border-b border-slate-100 pb-4">
                                    <h3 className="text-base font-black text-slate-900">Claimed Vouchers & Promos</h3>
                                    <p className="text-xs text-slate-500 font-mono">Redeem discount vouchers automatically during checkout</p>
                                </div>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 font-mono text-xs">
                                    
                                    <div className="p-4 rounded-2xl bg-gradient-to-br from-rose-50 to-amber-50 border border-rose-200/80 space-y-2 relative overflow-hidden">
                                        <div className="flex items-center justify-between">
                                            <span className="px-2 py-0.5 rounded bg-[#E00D42] text-white text-[9px] font-bold">PLATFORM VOUCHER</span>
                                            <span className="text-[10px] text-slate-500">Exp: Dec 31, 2026</span>
                                        </div>
                                        <h4 className="text-lg font-black text-slate-900">₱100 OFF FIRST ORDER</h4>
                                        <p className="text-[11px] text-slate-600 font-sans">Min. spend ₱500 across all verified Bagoo Mall stores</p>
                                        <div className="pt-2 flex items-center justify-between border-t border-rose-200/60">
                                            <code className="text-[#E00D42] font-black">WELCOME100</code>
                                            <span className="text-emerald-600 font-bold text-[10px]">READY AT CHECKOUT</span>
                                        </div>
                                    </div>

                                    <div className="p-4 rounded-2xl bg-slate-50 border border-slate-200 space-y-2">
                                        <div className="flex items-center justify-between">
                                            <span className="px-2 py-0.5 rounded bg-slate-900 text-white text-[9px] font-bold">FREE SHIPPING</span>
                                            <span className="text-[10px] text-slate-500">Exp: Dec 31, 2026</span>
                                        </div>
                                        <h4 className="text-lg font-black text-slate-900">100% OFF SHIPPING FEE</h4>
                                        <p className="text-[11px] text-slate-600 font-sans">Capped at ₱60 for Luzon & Metro Manila express deliveries</p>
                                        <div className="pt-2 flex items-center justify-between border-t border-slate-200">
                                            <code className="text-slate-900 font-black">FREESHIP60</code>
                                            <span className="text-emerald-600 font-bold text-[10px]">ACTIVE IN BAG</span>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        )}

                    </div>

                </div>

                {/* 3. MODAL: ADD PSGC ADDRESS */}
                {showAddressModal && (
                    <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
                        <div className="bg-white rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl border border-slate-200 space-y-4 font-sans animate-scale-in">
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100 font-mono">
                                <h3 className="font-bold text-slate-900 text-sm uppercase">Add Philippine Delivery Address</h3>
                                <button onClick={() => setShowAddressModal(false)} className="text-slate-400 hover:text-slate-700 font-bold">✕</button>
                            </div>

                            <form onSubmit={handleAddAddress} className="space-y-4 text-xs">
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <label className="block font-bold text-slate-700 mb-1 font-mono">Recipient Name</label>
                                        <input
                                            type="text"
                                            value={newAddress.recipient_name}
                                            onChange={(e) => setNewAddress({ ...newAddress, recipient_name: e.target.value })}
                                            className="w-full rounded-xl bg-slate-50 border border-slate-200 p-2.5 text-xs"
                                            required
                                        />
                                    </div>
                                    <div>
                                        <label className="block font-bold text-slate-700 mb-1 font-mono">Phone Number</label>
                                        <input
                                            type="text"
                                            value={newAddress.phone}
                                            onChange={(e) => setNewAddress({ ...newAddress, phone: e.target.value })}
                                            className="w-full rounded-xl bg-slate-50 border border-slate-200 p-2.5 text-xs font-mono"
                                            required
                                        />
                                    </div>
                                </div>

                                <div className="grid grid-cols-3 gap-2">
                                    <div>
                                        <label className="block font-bold text-slate-700 mb-1 font-mono">Province</label>
                                        <select
                                            value={newAddress.province}
                                            onChange={(e) => setNewAddress({ ...newAddress, province: e.target.value })}
                                            className="w-full rounded-xl bg-slate-50 border border-slate-200 p-2 text-xs"
                                        >
                                            <option value="Metro Manila">Metro Manila</option>
                                            <option value="Cebu">Cebu</option>
                                            <option value="Davao del Sur">Davao del Sur</option>
                                            <option value="Laguna">Laguna</option>
                                            <option value="Cavite">Cavite</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block font-bold text-slate-700 mb-1 font-mono">City</label>
                                        <input
                                            type="text"
                                            value={newAddress.city}
                                            onChange={(e) => setNewAddress({ ...newAddress, city: e.target.value })}
                                            className="w-full rounded-xl bg-slate-50 border border-slate-200 p-2 text-xs"
                                            required
                                        />
                                    </div>
                                    <div>
                                        <label className="block font-bold text-slate-700 mb-1 font-mono">Barangay</label>
                                        <input
                                            type="text"
                                            value={newAddress.barangay}
                                            onChange={(e) => setNewAddress({ ...newAddress, barangay: e.target.value })}
                                            className="w-full rounded-xl bg-slate-50 border border-slate-200 p-2 text-xs"
                                            required
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label className="block font-bold text-slate-700 mb-1 font-mono">Street Name, Building, House No.</label>
                                    <input
                                        type="text"
                                        value={newAddress.street}
                                        onChange={(e) => setNewAddress({ ...newAddress, street: e.target.value })}
                                        placeholder="e.g. Unit 402, High Street Tower, 26th St."
                                        className="w-full rounded-xl bg-slate-50 border border-slate-200 p-2.5 text-xs"
                                        required
                                    />
                                </div>

                                <div className="flex items-center justify-between pt-2 border-t border-slate-100">
                                    <label className="flex items-center gap-2 font-mono text-xs cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={newAddress.is_default}
                                            onChange={(e) => setNewAddress({ ...newAddress, is_default: e.target.checked })}
                                            className="rounded text-[#E00D42] focus:ring-[#E00D42]"
                                        />
                                        <span>Set as default shipping address</span>
                                    </label>

                                    <button
                                        type="submit"
                                        className="px-5 py-2.5 bg-[#E00D42] hover:bg-[#C20836] text-white font-mono font-bold uppercase rounded-xl transition shadow-xs"
                                    >
                                        Save Address
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}

            </div>
        </BuyerLayout>
    );
}
