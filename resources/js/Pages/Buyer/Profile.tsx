import React, { useState, useEffect, useRef } from 'react';
import { Head, useForm, Link, router, usePage } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import PhoneInput from '@/Components/PhoneInput';
import PhilippineAddressSelector from '@/Components/PhilippineAddressSelector';
import { User, Order, Address, PageProps } from '@/types';
import { 
    User as UserIcon, 
    ShieldCheck, 
    ShieldAlert,
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
    Star,
    Trash2,
    Camera,
    Upload,
    FileText,
    X
} from 'lucide-react';

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
    addresses: Address[];
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
    avatar: File | string | null;
    remove_avatar?: boolean;
}

export default function BuyerProfile({ 
    user, 
    addresses: initialAddresses, 
    wallet: initialWallet, 
    orders = [], 
    ordersCount = 0,
    initialTab = 'orders' 
}: Props) {
    const page = usePage<PageProps>();
    const { flash } = page.props;
    const url = page.url;

    const isKycApproved = user.kyc_status === 'approved' || user.kyc_status === 'verified';

    // Helper to extract tab from any URL string or fallback
    const getTabFromUrl = (targetUrl?: string): TabType | null => {
        try {
            const urlObj = new URL(targetUrl || (typeof window !== 'undefined' ? window.location.href : ''), 'http://localhost');
            const tabParam = urlObj.searchParams.get('tab') as TabType | null;
            if (tabParam && ['orders', 'account', 'addresses', 'wallet', 'vouchers'].includes(tabParam)) {
                return tabParam;
            }
            if (urlObj.pathname.endsWith('/orders')) {
                return 'orders';
            }
        } catch {
            // fallback
        }
        return null;
    };

    const [activeTab, setActiveTab] = useState<TabType>(() => {
        if (typeof window !== 'undefined') {
            const fromUrl = getTabFromUrl(window.location.href);
            if (fromUrl) return fromUrl;
        }
        return initialTab || 'orders';
    });

    const [selectedOrderStatus, setSelectedOrderStatus] = useState<string>('all');
    const [addresses, setAddresses] = useState<Address[]>(initialAddresses);
    const [wallet, setWallet] = useState<WalletData>(initialWallet);
    const [showAddressModal, setShowAddressModal] = useState(false);
    const [topupAmount, setTopupAmount] = useState<number>(1000);
    const [topupLoading, setTopupLoading] = useState(false);
    const [topupSuccess, setTopupSuccess] = useState(false);

    const [avatarPreview, setAvatarPreview] = useState<string | null>(user.avatar || null);
    const fileInputRef = React.useRef<HTMLInputElement | null>(null);

    // Tab switcher that updates URL history client-side without full-page network reloads
    const handleTabChange = (tabId: TabType) => {
        if (activeTab === tabId && typeof window !== 'undefined' && (window.location.search.includes(`tab=${tabId}`) || (tabId === 'orders' && window.location.pathname.endsWith('/orders')))) {
            return;
        }

        setActiveTab(tabId);

        const targetUrl = route('buyer.profile', { tab: tabId });
        try {
            router.push({
                url: targetUrl,
                props: (currentProps: any) => ({
                    ...currentProps,
                    initialTab: tabId,
                }),
                preserveState: true,
                preserveScroll: true,
            });
        } catch {
            if (typeof window !== 'undefined') {
                window.history.pushState(null, '', targetUrl);
            }
        }
    };

    // Synchronize tab state with URL on browser Back / Forward (popstate)
    useEffect(() => {
        const handlePopState = () => {
            const tabFromUrl = getTabFromUrl(window.location.href);
            setActiveTab(tabFromUrl || initialTab || 'orders');
        };

        window.addEventListener('popstate', handlePopState);
        return () => window.removeEventListener('popstate', handlePopState);
    }, [initialTab]);

    // Synchronize tab state if Inertia's page URL changes
    useEffect(() => {
        const tabFromUrl = getTabFromUrl(url);
        if (tabFromUrl) {
            setActiveTab(tabFromUrl);
        }
    }, [url]);

    // Sync addresses when initialAddresses prop updates
    useEffect(() => {
        setAddresses(initialAddresses);
    }, [initialAddresses]);

    // Sync active tab if initialTab prop changes from external navigation
    useEffect(() => {
        if (initialTab) {
            setActiveTab(initialTab);
        }
    }, [initialTab]);

    // Sync avatar preview if user prop updates
    useEffect(() => {
        setAvatarPreview(user.avatar || null);
    }, [user.avatar]);

    // Profile Form
    const { data, setData, post, processing, errors, recentlySuccessful } = useForm<ProfileFormData>({
        name: user.name || '',
        phone: user.phone || '',
        birthday: (user as Record<string, any>).birthday || '2000-01-15',
        gender: (user as Record<string, any>).gender || 'male',
        avatar: null,
        remove_avatar: false,
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

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;

        if (file.size > 3 * 1024 * 1024) {
            alert('Image file size must be less than 3MB.');
            return;
        }

        const validTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp', 'image/gif'];
        if (!validTypes.includes(file.type)) {
            alert('Please select a valid image file (JPEG, PNG, WEBP, or GIF).');
            return;
        }

        const reader = new FileReader();
        reader.onload = () => {
            setAvatarPreview(reader.result as string);
        };
        reader.readAsDataURL(file);

        setData(prev => ({
            ...prev,
            avatar: file,
            remove_avatar: false,
        }));
    };

    const handleRemoveAvatar = () => {
        setAvatarPreview(null);
        setData(prev => ({
            ...prev,
            avatar: null,
            remove_avatar: true,
        }));
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const handleProfileSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('buyer.profile.update'), {
            preserveScroll: true,
            forceFormData: true,
        });
    };

    const handlePasswordSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        passwordForm.put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => passwordForm.reset(),
        });
    };

    // KYC Verification Upload State & Handlers
    const [kycFile, setKycFile] = useState<File | null>(null);
    const [kycPreview, setKycPreview] = useState<string | null>(null);
    const [kycUploading, setKycUploading] = useState(false);
    const [kycError, setKycError] = useState<string | null>(null);
    const [kycSuccess, setKycSuccess] = useState(false);
    const kycFileInputRef = useRef<HTMLInputElement>(null);

    const handleKycFileSelect = (selectedFile: File | undefined) => {
        if (!selectedFile) return;

        if (selectedFile.size > 5 * 1024 * 1024) {
            setKycError('File size exceeds 5MB limit. Please choose a smaller file.');
            return;
        }

        const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp', 'application/pdf'];
        if (!allowedTypes.includes(selectedFile.type)) {
            setKycError('Invalid file format. Please upload JPG, PNG, WEBP, or PDF.');
            return;
        }

        setKycError(null);
        setKycSuccess(false);
        setKycFile(selectedFile);

        if (selectedFile.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = (e) => setKycPreview(e.target?.result as string);
            reader.readAsDataURL(selectedFile);
        } else {
            setKycPreview(null);
        }
    };

    const handleKycSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!kycFile || kycUploading) return;

        setKycUploading(true);
        setKycError(null);

        const formData = new FormData();
        formData.append('id_document', kycFile);

        router.post(route('buyer.kyc.upload'), formData, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setKycUploading(false);
                setKycFile(null);
                setKycPreview(null);
                setKycSuccess(true);
            },
            onError: (errs) => {
                setKycUploading(false);
                setKycError(errs.id_document || 'Failed to upload ID document. Please try again.');
            },
        });
    };

    const handleAddAddress = (e: React.FormEvent) => {
        e.preventDefault();
        if (!newAddress.street) return;

        router.post(route('buyer.addresses.store'), {
            ...newAddress,
            recipient_name: user.name,
        }, {
            preserveScroll: true,
            onSuccess: () => {
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
            },
        });
    };

    const setDefaultAddress = (id: number) => {
        router.post(route('buyer.addresses.default', id), {}, {
            preserveScroll: true,
        });
    };

    const handleDeleteAddress = (id: number) => {
        if (!confirm('Are you sure you want to delete this delivery address?')) return;
        router.delete(route('buyer.addresses.destroy', id), {
            preserveScroll: true,
        });
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
        if (selectedOrderStatus === 'to_ship') return ['pending', 'placed', 'confirmed', 'preparing', 'processing', 'ready_for_pickup'].includes(order.status);
        if (selectedOrderStatus === 'to_receive') return ['picked_up', 'at_sorting_center', 'sorted', 'assigned_to_rider', 'out_for_delivery', 'shipped', 'in_transit'].includes(order.status);
        if (selectedOrderStatus === 'completed') return ['delivered', 'completed'].includes(order.status);
        return true;
    });

    const getStatusPill = (status: string) => {
        switch (status) {
            case 'completed':
                return <span className="px-2.5 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full text-[10px] font-bold font-sans flex items-center gap-1"><CheckCircle2 className="w-3 h-3" /> Completed</span>;
            case 'delivered':
                return <span className="px-2.5 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full text-[10px] font-bold font-sans flex items-center gap-1"><CheckCircle2 className="w-3 h-3" /> Delivered</span>;
            case 'out_for_delivery':
            case 'shipped':
                return <span className="px-2.5 py-0.5 bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-full text-[10px] font-bold font-sans flex items-center gap-1"><Truck className="w-3 h-3 text-indigo-500" /> In Transit</span>;
            case 'assigned_to_rider':
            case 'sorted':
            case 'at_sorting_center':
            case 'picked_up':
                return <span className="px-2.5 py-0.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-full text-[10px] font-bold font-sans flex items-center gap-1"><Truck className="w-3 h-3 text-purple-500" /> In Logistics</span>;
            case 'ready_for_pickup':
                return <span className="px-2.5 py-0.5 bg-slate-100 text-slate-700 border border-slate-200 rounded-full text-[10px] font-bold font-sans flex items-center gap-1"><Clock className="w-3 h-3 text-slate-500" /> Ready for Pickup</span>;
            case 'preparing':
            case 'processing':
                return <span className="px-2.5 py-0.5 bg-slate-100 text-slate-700 border border-slate-200 rounded-full text-[10px] font-bold font-sans flex items-center gap-1"><Clock className="w-3 h-3 text-slate-500" /> Packaging</span>;
            case 'pending':
            case 'placed':
                return <span className="px-2.5 py-0.5 bg-amber-50 text-amber-700 border border-amber-200 rounded-full text-[10px] font-bold font-sans flex items-center gap-1"><Clock className="w-3 h-3 text-amber-600" /> Order Placed</span>;
            default:
                return <span className="px-2.5 py-0.5 bg-slate-100 text-slate-700 border border-slate-200 rounded-full text-[10px] font-bold font-sans uppercase">{status.replace('_', ' ')}</span>;
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
            <Head title={`${activeTab === 'orders' ? 'My Purchases & Order Tracking' : activeTab === 'account' ? 'Personal Information & Security' : activeTab === 'addresses' ? 'Delivery Address Book' : activeTab === 'wallet' ? 'Simulated Digital Wallet' : 'My Vouchers & Promos'} — BagooPH`} />

            <div className="w-full space-y-6 font-sans">
                
                {/* 1. TOP HEADER STRIP */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-200">
                    <div>
                        <h1 className="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">
                            {activeTab === 'orders' ? 'My Purchases & Order Tracking' : 'Account Settings & Credentials'}
                        </h1>
                        <p className="text-xs text-slate-500 font-sans">
                            {activeTab === 'orders' ? 'Track real-time parcel dispatch, delivery timelines, and order receipts' : 'Manage personal credentials, PSGC shipping addresses, and simulated wallet'}
                        </p>
                    </div>

                    <Link
                        href={route('buyer.index')}
                        className="px-4 py-2 rounded-xl bg-white hover:bg-slate-50 text-slate-700 hover:text-slate-900 border border-slate-200 hover:border-slate-300 text-xs font-sans font-bold uppercase transition flex items-center gap-2 shadow-2xs w-fit"
                    >
                        <ShoppingBag className="w-4 h-4 text-slate-500" />
                        <span>Continue Shopping</span>
                    </Link>
                </div>

                {/* FLASH NOTIFICATIONS */}
                {flash?.success && (
                    <div className="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs flex items-center justify-between gap-3 font-sans shadow-2xs">
                        <div className="flex items-center gap-2.5">
                            <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
                            <span className="font-semibold">{flash.success}</span>
                        </div>
                    </div>
                )}
                {flash?.error && (
                    <div className="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center justify-between gap-3 font-sans shadow-2xs">
                        <div className="flex items-center gap-2.5">
                            <AlertCircle className="w-4 h-4 text-[#E00D42] shrink-0" />
                            <span className="font-semibold">{flash.error}</span>
                        </div>
                    </div>
                )}

                {/* 2. TWO-COLUMN WORKSPACE: LEFT SIDEBAR + RIGHT WORKSPACE */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                    
                    {/* LEFT SIDEBAR NAVIGATION */}
                    <div className="lg:col-span-4 bg-white rounded-3xl p-5 border border-slate-200 shadow-xs space-y-5">
                        
                        {/* User Identity Mini Card */}
                        <div className="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 flex items-center gap-3.5">
                            <button
                                type="button"
                                onClick={() => {
                                    handleTabChange('account');
                                    setTimeout(() => {
                                        fileInputRef.current?.click();
                                    }, 50);
                                }}
                                className="relative group shrink-0 rounded-xl overflow-hidden focus:outline-hidden focus:ring-2 focus:ring-[#E00D42] cursor-pointer"
                                title="Click to update avatar photo"
                            >
                                {avatarPreview || user.avatar ? (
                                    <img
                                        src={avatarPreview || user.avatar || ''}
                                        alt={user.name}
                                        className="w-12 h-12 rounded-xl object-cover border border-slate-200 shadow-xs group-hover:scale-105 transition-transform"
                                    />
                                ) : (
                                    <div className="w-12 h-12 rounded-xl bg-rose-50 border border-rose-200 text-[#E00D42] font-black text-lg flex items-center justify-center shadow-2xs group-hover:bg-rose-100 transition">
                                        {user.name.charAt(0).toUpperCase()}
                                    </div>
                                )}
                                <div className="absolute inset-0 bg-black/40 rounded-xl opacity-0 group-hover:opacity-100 flex items-center justify-center transition-opacity text-white">
                                    <Camera className="w-4 h-4 text-white drop-shadow-xs" />
                                </div>
                            </button>
                            <div className="min-w-0">
                                <h3 className="font-bold text-slate-900 text-sm truncate">{user.name}</h3>
                                <p className="text-[11px] text-slate-500 font-sans truncate">{user.email}</p>
                                {isKycApproved ? (
                                    <span className="inline-flex items-center gap-1 text-[9px] font-bold uppercase text-emerald-600 font-sans mt-0.5">
                                        <ShieldCheck className="w-3 h-3" /> Verified Buyer
                                    </span>
                                ) : user.kyc_status === 'pending_approval' ? (
                                    <span className="inline-flex items-center gap-1 text-[9px] font-bold uppercase text-amber-600 font-sans mt-0.5">
                                        <Clock className="w-3 h-3" /> KYC In Review
                                    </span>
                                ) : user.kyc_status === 'rejected' ? (
                                    <span className="inline-flex items-center gap-1 text-[9px] font-bold uppercase text-rose-600 font-sans mt-0.5">
                                        <ShieldAlert className="w-3 h-3" /> KYC Action Required
                                    </span>
                                ) : (
                                    <span className="inline-flex items-center gap-1 text-[9px] font-bold uppercase text-slate-400 font-sans mt-0.5">
                                        <Shield className="w-3 h-3" /> Unverified Account
                                    </span>
                                )}
                            </div>
                        </div>

                        {/* Navigation Links */}
                        <nav className="space-y-1.5 font-sans text-xs">
                            {navItems.map((item) => {
                                const isActive = activeTab === item.id;
                                return (
                                    <button
                                        key={item.id}
                                        type="button"
                                        onClick={() => handleTabChange(item.id)}
                                        className={`w-full flex items-center justify-between px-4 py-3 rounded-2xl font-bold transition text-left cursor-pointer ${
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
                        <div className="pt-3 border-t border-slate-100 space-y-1 font-sans text-xs">
                            <Link
                                href={route('buyer.messages')}
                                className="w-full flex items-center justify-between px-4 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 transition"
                            >
                                <span className="flex items-center gap-2.5">
                                    <MessageSquare className="w-4 h-4 text-slate-400" />
                                    <span>Store & Courier Messages</span>
                                </span>
                                <ChevronRight className="w-3.5 h-3.5 text-slate-400" />
                            </Link>

                            <Link
                                href={route('buyer.disputes.index')}
                                className="w-full flex items-center justify-between px-4 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 transition"
                            >
                                <span className="flex items-center gap-2.5">
                                    <Shield className="w-4 h-4 text-slate-400" />
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
                                <div className="bg-white rounded-2xl p-1.5 border border-slate-200 shadow-xs flex items-center gap-1.5 overflow-x-auto scrollbar-none font-sans text-xs">
                                    {[
                                        { id: 'all', label: `All (${orders.length})` },
                                        { id: 'to_ship', label: 'To Ship' },
                                        { id: 'to_receive', label: 'In Transit' },
                                        { id: 'completed', label: 'Delivered' },
                                    ].map((tab) => {
                                        const isActive = selectedOrderStatus === tab.id;
                                        return (
                                            <button
                                                key={tab.id}
                                                type="button"
                                                onClick={() => setSelectedOrderStatus(tab.id)}
                                                className={`flex-1 py-2 px-3 rounded-xl font-bold uppercase transition text-center whitespace-nowrap text-xs ${
                                                    isActive
                                                        ? 'bg-[#E00D42] text-white shadow-xs'
                                                        : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50'
                                                }`}
                                            >
                                                {tab.label}
                                            </button>
                                        );
                                    })}
                                </div>

                                {/* Orders List Cards */}
                                {filteredOrders.length === 0 ? (
                                    <div className="bg-white rounded-3xl p-12 text-center space-y-3 border border-slate-200 shadow-xs">
                                        <Package className="w-12 h-12 text-slate-300 mx-auto" />
                                        <h3 className="text-base font-bold text-slate-800">No orders found in this status</h3>
                                        <p className="text-xs text-slate-500 font-sans">
                                            Discover verified tactical apparel, bags, and EDC gear on BagooPH.
                                        </p>
                                        <Link
                                            href={route('buyer.index')}
                                            className="inline-block px-5 py-2.5 bg-[#E00D42] text-white rounded-xl text-xs font-sans font-bold uppercase shadow-xs"
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
                                            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-3 border-b border-slate-100 font-sans text-xs">
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
                                                            <p className="text-[11px] text-slate-500 font-sans">
                                                                Qty: <span className="text-slate-800 font-bold">{item.quantity}</span> • Unit: <span className="text-slate-800">{formatPrice(item.unit_price)}</span>
                                                            </p>
                                                            <p className="text-xs font-bold text-slate-900 font-sans mt-0.5">
                                                                Subtotal: {formatPrice(item.subtotal || (Number(item.unit_price) * item.quantity))}
                                                            </p>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>

                                            {/* Delivery Telemetry / Notes */}
                                            {order.delivery && (
                                                <div className="p-3 bg-slate-50 rounded-xl border border-slate-200/80 flex items-center justify-between font-sans text-[11px]">
                                                    <div className="flex items-center gap-2 text-slate-600">
                                                        <Truck className="w-3.5 h-3.5 text-slate-500" />
                                                        <span>
                                                            Tracking: <strong className="text-slate-900">#{order.delivery.tracking_number}</strong>
                                                        </span>
                                                        {order.delivery.courier && (
                                                            <span className="text-slate-400">• Rider: {order.delivery.courier.name}</span>
                                                        )}
                                                    </div>
                                                    <span className="text-slate-600 font-bold uppercase text-[10px] bg-slate-200/60 px-2 py-0.5 rounded-md">
                                                        {order.delivery.status.replace('_', ' ')}
                                                    </span>
                                                </div>
                                            )}

                                            {/* Footer Actions */}
                                            <div className="pt-3 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3 font-sans text-xs">
                                                <div>
                                                    <span className="text-slate-400 text-[11px]">Order Total: </span>
                                                    <span className="font-black text-slate-900 text-base">{formatPrice(order.total_amount)}</span>
                                                    <span className="text-slate-400 text-[10px] ml-1.5 uppercase">({order.payment_method})</span>
                                                </div>

                                                <div className="flex items-center gap-2">
                                                    {order.status === 'delivered' && (
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                if (confirm("Confirm that you have received your order in good condition?")) {
                                                                    router.post(route('buyer.orders.confirm', order.id), {}, { preserveScroll: true });
                                                                }
                                                            }}
                                                            className="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-700 active:scale-[0.98] text-white rounded-xl font-bold uppercase transition flex items-center gap-1.5 shadow-2xs text-xs cursor-pointer"
                                                        >
                                                            <CheckCircle2 className="w-3.5 h-3.5" />
                                                            <span>Confirm Received</span>
                                                        </button>
                                                    )}

                                                    <Link
                                                        href={route('buyer.orders.show', order.id)}
                                                        className="px-4 py-2 bg-white hover:bg-slate-50 border border-slate-200 hover:border-slate-300 text-slate-800 rounded-xl font-bold uppercase transition flex items-center gap-1.5 shadow-2xs text-xs"
                                                    >
                                                        <Truck className="w-3.5 h-3.5 text-slate-600" />
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
                                        <p className="text-xs text-slate-500 font-sans">Update your official contact and recipient profile details</p>
                                    </div>

                                    {recentlySuccessful && (
                                        <div className="p-3 bg-emerald-50 text-emerald-800 rounded-xl border border-emerald-200 text-xs font-sans flex items-center gap-2">
                                            <Check className="w-4 h-4 text-emerald-600" />
                                            <span>Profile details updated successfully!</span>
                                        </div>
                                    )}

                                    <form onSubmit={handleProfileSubmit} className="space-y-5 text-xs font-sans">
                                        <input
                                            ref={fileInputRef}
                                            type="file"
                                            accept="image/jpeg,image/png,image/jpg,image/webp,image/gif"
                                            onChange={handleFileChange}
                                            className="hidden"
                                        />

                                        {/* Avatar Customization Section */}
                                        <div className="p-5 bg-slate-50 rounded-2xl border border-slate-200/80 space-y-4">
                                            <div className="flex flex-col sm:flex-row sm:items-center gap-5">
                                                {/* Avatar Live Preview */}
                                                <div className="relative group shrink-0 w-20 h-20 rounded-2xl overflow-hidden border-2 border-slate-200 shadow-xs bg-white">
                                                    {avatarPreview ? (
                                                        <img
                                                            src={avatarPreview}
                                                            alt={user.name}
                                                            className="w-full h-full object-cover"
                                                        />
                                                    ) : (
                                                        <div className="w-full h-full bg-slate-950 text-white font-black text-2xl flex items-center justify-center">
                                                            {user.name.charAt(0).toUpperCase()}
                                                        </div>
                                                    )}
                                                    <button
                                                        type="button"
                                                        onClick={() => fileInputRef.current?.click()}
                                                        className="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 flex flex-col items-center justify-center text-white transition-opacity text-[10px] font-sans font-bold cursor-pointer"
                                                    >
                                                        <Camera className="w-4 h-4 mb-0.5" />
                                                        <span>Change</span>
                                                    </button>
                                                </div>

                                                {/* Upload Actions & Guidelines */}
                                                <div className="space-y-2 flex-1">
                                                    <div className="flex flex-wrap items-center gap-2.5">
                                                        <button
                                                            type="button"
                                                            onClick={() => fileInputRef.current?.click()}
                                                            className="px-3.5 py-2 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white rounded-xl text-xs font-sans font-bold uppercase transition flex items-center gap-1.5 shadow-xs cursor-pointer"
                                                        >
                                                            <Upload className="w-3.5 h-3.5 text-white" />
                                                            <span>{avatarPreview ? 'Change Photo' : 'Upload Photo'}</span>
                                                        </button>

                                                        {avatarPreview && (
                                                            <button
                                                                type="button"
                                                                onClick={handleRemoveAvatar}
                                                                className="px-3 py-2 border border-slate-300 hover:border-rose-300 hover:bg-rose-50 text-slate-700 hover:text-rose-600 rounded-xl text-xs font-sans font-bold uppercase transition flex items-center gap-1.5 cursor-pointer"
                                                            >
                                                                <Trash2 className="w-3.5 h-3.5" />
                                                                <span>Remove Photo</span>
                                                            </button>
                                                        )}

                                                        {avatarPreview !== user.avatar && (
                                                            <span className="text-[10px] text-amber-700 bg-amber-50 border border-amber-200 px-2.5 py-1 rounded-lg font-sans font-semibold">
                                                                Photo selected (click Save below to apply)
                                                            </span>
                                                        )}
                                                    </div>

                                                    <p className="text-[11px] text-slate-500 font-sans">
                                                        Upload JPG, PNG, WEBP, or GIF. Max file size: 3MB.
                                                    </p>
                                                    {errors.avatar && (
                                                        <p className="text-rose-500 text-[11px] font-sans font-bold mt-1">
                                                            {errors.avatar}
                                                        </p>
                                                    )}
                                                </div>
                                            </div>

                                        </div>

                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <div>
                                                <label className="block font-bold text-slate-700 mb-1.5 font-sans">Full Name</label>
                                                <input
                                                    type="text"
                                                    value={data.name}
                                                    onChange={(e) => setData('name', e.target.value)}
                                                    className="w-full rounded-xl bg-slate-50 border border-slate-200 p-2.5 text-xs focus:ring-[#E00D42] focus:border-[#E00D42]"
                                                    required
                                                />
                                                {errors.name && <p className="text-rose-500 text-[10px] mt-1 font-sans">{errors.name}</p>}
                                            </div>

                                            <div>
                                                <label className="block font-bold text-slate-700 mb-1.5 font-sans">Email Address (Immutable)</label>
                                                <input
                                                    type="email"
                                                    value={user.email}
                                                    disabled
                                                    className="w-full rounded-xl bg-slate-100 border border-slate-200 p-2.5 text-xs text-slate-500 cursor-not-allowed font-sans"
                                                />
                                            </div>
                                        </div>

                                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                            <div>
                                                <label className="block font-bold text-slate-700 mb-1.5 font-sans">Mobile Contact</label>
                                                <PhoneInput
                                                    value={data.phone}
                                                    onChange={(val) => setData('phone', val)}
                                                    placeholder="917 123 4567"
                                                    accentColor="primary"
                                                    helperText="10-digit mobile number (e.g. 917 123 4567)"
                                                />
                                            </div>

                                            <div>
                                                <label className="block font-bold text-slate-700 mb-1.5 font-sans">Birthday</label>
                                                <input
                                                    type="date"
                                                    value={data.birthday}
                                                    onChange={(e) => setData('birthday', e.target.value)}
                                                    className="w-full rounded-xl bg-slate-50 border border-slate-200 p-2.5 text-xs font-sans focus:ring-[#E00D42] focus:border-[#E00D42]"
                                                />
                                            </div>

                                            <div>
                                                <label className="block font-bold text-slate-700 mb-1.5 font-sans">Gender</label>
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
                                                className="px-6 py-2.5 bg-[#E00D42] hover:bg-[#C20836] text-white font-sans text-xs font-bold uppercase rounded-xl transition shadow-xs"
                                            >
                                                {processing ? 'Saving...' : 'Save Profile Changes'}
                                            </button>
                                        </div>
                                    </form>
                                </div>

                                {/* Identity & Trust Verification (KYC) */}
                                <div className="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-xs space-y-6">
                                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-slate-100 pb-4">
                                        <div>
                                            <h3 className="text-base font-black text-slate-900">Identity Verification & Trust Status</h3>
                                            <p className="text-xs text-slate-500 font-sans">Government ID validation for 100% Cash on Delivery protection</p>
                                        </div>
                                        <div>
                                            {isKycApproved && (
                                                <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-sans font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                    <ShieldCheck className="w-3.5 h-3.5 text-emerald-600" />
                                                    <span>Verified Account</span>
                                                </span>
                                            )}
                                            {user.kyc_status === 'pending_approval' && (
                                                <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-sans font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                    <Clock className="w-3.5 h-3.5 text-amber-600" />
                                                    <span>In Review</span>
                                                </span>
                                            )}
                                            {user.kyc_status === 'rejected' && (
                                                <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-sans font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                                    <ShieldAlert className="w-3.5 h-3.5 text-rose-600" />
                                                    <span>Action Required</span>
                                                </span>
                                            )}
                                            {(!user.kyc_status || user.kyc_status === 'none') && (
                                                <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-sans font-bold bg-slate-100 text-slate-600 border border-slate-200">
                                                    <ShieldAlert className="w-3.5 h-3.5 text-slate-400" />
                                                    <span>Unverified</span>
                                                </span>
                                            )}
                                        </div>
                                    </div>

                                    {/* Status Details */}
                                    {isKycApproved && (
                                        <div className="p-4 bg-emerald-50/60 border border-emerald-200 rounded-2xl flex items-start gap-3">
                                            <div className="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0 mt-0.5">
                                                <CheckCircle2 className="w-4 h-4" />
                                            </div>
                                            <div className="text-xs space-y-1">
                                                <p className="font-bold text-emerald-900">Your account is fully verified</p>
                                                <p className="text-emerald-700 leading-relaxed">
                                                    Your submitted government ID has been authenticated by Bagoo compliance. You have unlocked unlimited Cash on Delivery privileges and doorstep item inspection.
                                                </p>
                                                {user.id_document_path && (
                                                    <p className="font-sans text-[11px] text-emerald-600 pt-1">
                                                        ID Document on file: Validated
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    )}

                                    {user.kyc_status === 'pending_approval' && (
                                        <div className="p-4 bg-amber-50/70 border border-amber-200 rounded-2xl flex items-start gap-3">
                                            <div className="w-8 h-8 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center shrink-0 mt-0.5">
                                                <Clock className="w-4 h-4" />
                                            </div>
                                            <div className="text-xs space-y-1">
                                                <p className="font-bold text-amber-900">Verification in progress</p>
                                                <p className="text-amber-700 leading-relaxed">
                                                    Your government ID has been received and is in the compliance review queue. Approvals are typically processed within 24 hours.
                                                </p>
                                                {user.id_document_path && (
                                                    <p className="font-sans text-[11px] text-amber-600 pt-1">
                                                        Document submitted: Valid government ID
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    )}

                                    {user.kyc_status === 'rejected' && (
                                        <div className="p-4 bg-rose-50 border border-rose-200 rounded-2xl flex items-start gap-3">
                                            <div className="w-8 h-8 rounded-xl bg-rose-100 text-rose-700 flex items-center justify-center shrink-0 mt-0.5">
                                                <ShieldAlert className="w-4 h-4" />
                                            </div>
                                            <div className="text-xs space-y-1">
                                                <p className="font-bold text-rose-900">ID Verification Rejected</p>
                                                <p className="text-rose-700 leading-relaxed">
                                                    {user.kyc_feedback || 'Your previous document could not be verified. Please provide a clear, valid government photo ID.'}
                                                </p>
                                            </div>
                                        </div>
                                    )}

                                    {(!user.kyc_status || user.kyc_status === 'none') && (
                                        <div className="p-4 bg-slate-50 border border-slate-200 rounded-2xl flex items-start gap-3">
                                            <div className="w-8 h-8 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center shrink-0 mt-0.5">
                                                <Shield className="w-4 h-4" />
                                            </div>
                                            <div className="text-xs space-y-1">
                                                <p className="font-bold text-slate-800">No government ID uploaded</p>
                                                <p className="text-slate-600 leading-relaxed">
                                                    Complete verification to protect against fake delivery attempts and unlock instant priority order dispatch.
                                                </p>
                                            </div>
                                        </div>
                                    )}

                                    {/* Upload / Re-Upload Section for non-verified users */}
                                    {!isKycApproved && (
                                        <form onSubmit={handleKycSubmit} className="space-y-4 pt-1">
                                            <input
                                                ref={kycFileInputRef}
                                                type="file"
                                                accept="image/jpeg,image/png,image/jpg,image/webp,application/pdf"
                                                className="hidden"
                                                onChange={(e) => handleKycFileSelect(e.target.files?.[0])}
                                            />

                                            {!kycFile ? (
                                                <div
                                                    onClick={() => kycFileInputRef.current?.click()}
                                                    className="border-2 border-dashed border-slate-200 hover:border-[#E00D42] bg-slate-50/60 hover:bg-rose-50/20 rounded-2xl p-6 text-center transition cursor-pointer group"
                                                >
                                                    <div className="w-10 h-10 rounded-xl bg-white border border-slate-200 text-slate-600 group-hover:text-[#E00D42] flex items-center justify-center mx-auto mb-2 shadow-2xs transition">
                                                        <Upload className="w-4 h-4" />
                                                    </div>
                                                    <p className="text-xs font-bold text-slate-800 group-hover:text-[#E00D42] transition">
                                                        {user.kyc_status === 'rejected' ? 'Upload New Government ID' : 'Click to upload Government ID'}
                                                    </p>
                                                    <p className="text-[11px] text-slate-400 font-sans mt-1">
                                                        PhilID, Passport, Driver's License, UMID, Postal ID, SSS (Max 5MB)
                                                    </p>
                                                </div>
                                            ) : (
                                                <div className="bg-slate-50 rounded-2xl p-3.5 border border-slate-200 flex items-center justify-between gap-3">
                                                    <div className="flex items-center gap-3 min-w-0">
                                                        {kycPreview ? (
                                                            <img
                                                                src={kycPreview}
                                                                alt="ID Preview"
                                                                className="w-12 h-12 rounded-xl object-cover border border-slate-200 shrink-0"
                                                            />
                                                        ) : (
                                                            <div className="w-12 h-12 rounded-xl bg-white border border-slate-200 text-slate-600 flex items-center justify-center shrink-0">
                                                                <FileText className="w-5 h-5" />
                                                            </div>
                                                        )}
                                                        <div className="min-w-0">
                                                            <p className="text-xs font-bold text-slate-900 truncate">{kycFile.name}</p>
                                                            <p className="text-[10px] text-slate-400 font-sans">
                                                                {(kycFile.size / (1024 * 1024)).toFixed(2)} MB
                                                            </p>
                                                        </div>
                                                    </div>

                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setKycFile(null);
                                                            setKycPreview(null);
                                                        }}
                                                        className="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition"
                                                        title="Remove"
                                                    >
                                                        <X className="w-4 h-4" />
                                                    </button>
                                                </div>
                                            )}

                                            {kycError && (
                                                <div className="p-3 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-600 flex items-center gap-2">
                                                    <AlertCircle className="w-4 h-4 shrink-0" />
                                                    <span>{kycError}</span>
                                                </div>
                                            )}

                                            {kycSuccess && (
                                                <div className="p-3 bg-emerald-50 border border-emerald-200 rounded-xl text-xs text-emerald-700 flex items-center gap-2">
                                                    <Check className="w-4 h-4 shrink-0" />
                                                    <span>ID document uploaded successfully! Status updated to review.</span>
                                                </div>
                                            )}

                                            {kycFile && (
                                                <div className="flex justify-end pt-1">
                                                    <button
                                                        type="submit"
                                                        disabled={kycUploading}
                                                        className="px-6 py-2.5 bg-[#E00D42] hover:bg-[#C20836] text-white font-sans text-xs font-bold uppercase rounded-xl transition shadow-xs disabled:opacity-50 flex items-center gap-2 cursor-pointer"
                                                    >
                                                        {kycUploading ? 'Uploading...' : 'Submit ID for Verification'}
                                                    </button>
                                                </div>
                                            )}
                                        </form>
                                    )}
                                </div>

                                {/* Password & Security Settings */}
                                <div className="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-xs space-y-6">
                                    <div className="border-b border-slate-100 pb-4">
                                        <h3 className="text-base font-black text-slate-900">Account Password & Security</h3>
                                        <p className="text-xs text-slate-500 font-sans">Ensure your account password is at least 8 characters long</p>
                                    </div>

                                    {user.google_id && (
                                        <div className="p-3.5 bg-blue-50/60 border border-blue-200 rounded-2xl flex items-center gap-3">
                                            <svg className="w-4 h-4 shrink-0" viewBox="0 0 24 24">
                                                <path
                                                    fill="#4285F4"
                                                    d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.66-5.17 3.66-9.17z"
                                                />
                                                <path
                                                    fill="#34A853"
                                                    d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.1-6.72-4.93H1.25v3.15C3.26 21.36 7.33 24 12 24z"
                                                />
                                                <path
                                                    fill="#FBBC05"
                                                    d="M5.28 14.27c-.25-.72-.38-1.49-.38-2.27s.13-1.55.38-2.27V6.58H1.25C.45 8.16 0 9.94 0 12s.45 3.84 1.25 5.42l4.03-3.15z"
                                                />
                                                <path
                                                    fill="#EA4335"
                                                    d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.33 0 3.26 2.64 1.25 6.58l4.03 3.15c.95-2.83 3.6-4.98 6.72-4.98z"
                                                />
                                            </svg>
                                            <div className="text-xs text-blue-900 font-sans">
                                                Connected with Google OAuth (<span className="font-bold">{user.email}</span>). You can sign in using your Google account.
                                            </div>
                                        </div>
                                    )}

                                    {passwordForm.recentlySuccessful && (
                                        <div className="p-3 bg-emerald-50 text-emerald-800 rounded-xl border border-emerald-200 text-xs font-sans flex items-center gap-2">
                                            <Check className="w-4 h-4 text-emerald-600" />
                                            <span>Password updated successfully!</span>
                                        </div>
                                    )}

                                    <form onSubmit={handlePasswordSubmit} className="space-y-4 text-xs font-sans">
                                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                            <div>
                                                <label className="block font-bold text-slate-700 mb-1.5 font-sans">Current Password</label>
                                                <input
                                                    type="password"
                                                    value={passwordForm.data.current_password}
                                                    onChange={(e) => passwordForm.setData('current_password', e.target.value)}
                                                    className="w-full rounded-xl bg-slate-50 border border-slate-200 p-2.5 text-xs"
                                                    required
                                                />
                                            </div>

                                            <div>
                                                <label className="block font-bold text-slate-700 mb-1.5 font-sans">New Password</label>
                                                <input
                                                    type="password"
                                                    value={passwordForm.data.password}
                                                    onChange={(e) => passwordForm.setData('password', e.target.value)}
                                                    className="w-full rounded-xl bg-slate-50 border border-slate-200 p-2.5 text-xs"
                                                    required
                                                />
                                            </div>

                                            <div>
                                                <label className="block font-bold text-slate-700 mb-1.5 font-sans">Confirm New Password</label>
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
                                                className="px-6 py-2.5 bg-slate-900 hover:bg-black text-white font-sans text-xs font-bold uppercase rounded-xl transition shadow-xs"
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
                                        <p className="text-xs text-slate-500 font-sans">Manage doorstep drop-off destinations for Bagoo Express riders</p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => setShowAddressModal(true)}
                                        className="px-4 py-2 bg-[#E00D42] hover:bg-[#C20836] text-white rounded-xl text-xs font-sans font-bold uppercase transition flex items-center gap-1.5 shadow-xs"
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
                                                <div className="flex items-center gap-2 font-sans text-xs">
                                                    <span className="font-bold text-slate-900">{addr.recipient_name}</span>
                                                    <span className="text-slate-400">•</span>
                                                    <span className="text-slate-600">{addr.phone}</span>
                                                    <span className="px-2 py-0.5 rounded bg-slate-200 text-slate-700 text-[10px] uppercase font-bold">
                                                        {addr.type}
                                                    </span>
                                                </div>

                                                <div className="flex items-center gap-3">
                                                    {addr.is_default ? (
                                                        <span className="px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-800 text-[10px] font-sans font-bold">
                                                            DEFAULT ADDRESS
                                                        </span>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            onClick={() => setDefaultAddress(addr.id)}
                                                            className="text-[11px] font-sans text-slate-500 hover:text-slate-900 underline cursor-pointer"
                                                        >
                                                            Set as Default
                                                        </button>
                                                    )}

                                                    {addresses.length > 1 && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleDeleteAddress(addr.id)}
                                                            className="text-slate-400 hover:text-rose-600 transition p-1 cursor-pointer"
                                                            title="Delete address"
                                                        >
                                                            <Trash2 className="w-3.5 h-3.5" />
                                                        </button>
                                                    )}
                                                </div>
                                            </div>

                                            <p className="text-xs text-slate-700 font-sans">
                                                {[addr.street, addr.barangay, addr.city, addr.province].filter(Boolean).join(', ')}
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
                                            <span className="font-sans text-xs font-bold uppercase text-slate-400">Bagoo Digital Wallet Sandbox</span>
                                        </div>
                                        <span className="px-2.5 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 font-sans text-[10px] font-bold border border-emerald-500/30">
                                            AUTHORIZED ACTIVE
                                        </span>
                                    </div>

                                    <div>
                                        <span className="text-xs text-slate-400 font-sans">Available Account Balance</span>
                                        <h2 className="text-3xl sm:text-4xl font-black text-emerald-400 mt-1">
                                            {formatPrice(wallet.balance)}
                                        </h2>
                                        <p className="text-[11px] text-slate-400 font-sans mt-1">
                                            Account: {wallet.account_number} • Instant settlement at checkout without gateway fees
                                        </p>
                                    </div>

                                    {/* Quick Simulation Top-Up Strip */}
                                    <div className="pt-4 border-t border-slate-800 space-y-2">
                                        <span className="text-[10px] font-sans text-slate-400 uppercase font-bold">Simulate Instant Top-up:</span>
                                        <div className="flex flex-wrap items-center gap-2">
                                            {[500, 1000, 2500, 5000].map((amt) => (
                                                <button
                                                    key={amt}
                                                    type="button"
                                                    onClick={() => handleTopup(amt)}
                                                    disabled={topupLoading}
                                                    className="px-3.5 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-sans text-xs font-bold border border-slate-700 transition"
                                                >
                                                    +₱{amt.toLocaleString()}
                                                </button>
                                            ))}
                                        </div>
                                        {topupSuccess && (
                                            <p className="text-emerald-400 text-xs font-sans font-bold flex items-center gap-1.5 mt-2 animate-fade-in">
                                                <Check className="w-3.5 h-3.5" /> Balance updated in sandbox ledger!
                                            </p>
                                        )}
                                    </div>
                                </div>

                                {/* Transactions Statement */}
                                <div className="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-xs space-y-4">
                                    <h4 className="font-bold text-slate-900 text-sm uppercase font-sans">Recent Wallet Transactions</h4>
                                    <div className="divide-y divide-slate-100">
                                        {wallet.recent_transactions.map((tx) => (
                                            <div key={tx.id} className="py-3 flex items-center justify-between font-sans text-xs">
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
                                    <p className="text-xs text-slate-500 font-sans">Redeem discount vouchers automatically during checkout</p>
                                </div>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 font-sans text-xs">
                                    
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
                        <div className="bg-white rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl border border-slate-200 space-y-4 font-sans animate-scale-in max-h-[92vh] overflow-y-auto">
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100 font-sans">
                                <h3 className="font-bold text-slate-900 text-sm uppercase">Add Philippine Delivery Address</h3>
                                <button onClick={() => setShowAddressModal(false)} className="text-slate-400 hover:text-slate-700 font-bold">✕</button>
                            </div>

                            <form onSubmit={handleAddAddress} className="space-y-4 text-xs">
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label className="block font-bold text-slate-700 mb-1 font-sans flex items-center justify-between">
                                            <span>Recipient Name</span>
                                            <span className="text-[10px] text-slate-400 font-sans font-normal flex items-center gap-1">
                                                <Lock className="w-3 h-3 text-slate-400" />
                                                Verified Real Name
                                            </span>
                                        </label>
                                        <input
                                            type="text"
                                            value={user.name}
                                            readOnly
                                            disabled
                                            className="w-full rounded-xl bg-slate-100 border border-slate-200 p-2.5 text-xs text-slate-600 cursor-not-allowed select-none font-medium"
                                        />
                                    </div>
                                    <div>
                                        <label className="block font-bold text-slate-700 mb-1 font-sans">Phone Number</label>
                                        <PhoneInput
                                            value={newAddress.phone}
                                            onChange={(val) => setNewAddress({ ...newAddress, phone: val })}
                                            placeholder="917 123 4567"
                                            accentColor="primary"
                                            helperText="10-digit mobile number (e.g. 917 123 4567)"
                                            required
                                        />
                                    </div>
                                </div>

                                <PhilippineAddressSelector
                                    values={{
                                        province: newAddress.province,
                                        city: newAddress.city,
                                        municipality: newAddress.city,
                                        barangay: newAddress.barangay,
                                        address: newAddress.street,
                                    }}
                                    onChange={(addr) => {
                                        setNewAddress(prev => ({
                                            ...prev,
                                            province: addr.province,
                                            city: addr.city || addr.municipality,
                                            barangay: addr.barangay,
                                            street: addr.address,
                                        }));
                                    }}
                                    accentColor="primary"
                                    required={true}
                                    streetLabel="Street Name, Building, House No."
                                    streetPlaceholder="e.g. Unit 402, High Street Tower, 26th St."
                                />

                                <div className="flex items-center justify-between pt-2 border-t border-slate-100">
                                    <label className="flex items-center gap-2 font-sans text-xs cursor-pointer">
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
                                        className="px-5 py-2.5 bg-[#E00D42] hover:bg-[#C20836] text-white font-sans font-bold uppercase rounded-xl transition shadow-xs"
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
