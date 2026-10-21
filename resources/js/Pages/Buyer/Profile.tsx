import UploadHint from '@/Components/UploadHint';
import React, { useState, useEffect, useRef } from 'react';
import { Head, useForm, Link, router, usePage } from '@inertiajs/react';
import AccountEmailSettings from '@/Components/AccountEmailSettings';
import AccountSectionHeading from '@/Components/AccountSectionHeading';
import AccountIdentitySettings from '@/Components/AccountIdentitySettings';
import AccountSessionSettings from '@/Components/AccountSessionSettings';
import BuyerAccountNavigation from '@/Components/BuyerAccountNavigation';
import BuyerOrderFilters from '@/Components/BuyerOrderFilters';
import BuyerPurchaseNavigation from '@/Components/BuyerPurchaseNavigation';
import BuyAgainButton from '@/Components/BuyAgainButton';
import BuyerLayout from '@/Layouts/BuyerLayout';
import BuyerWalletPanel, { WalletData } from '@/Components/BuyerWalletPanel';
import VoucherOfferList from '@/Components/VoucherOfferList';
import BuyerVoucherCollection, { BuyerVoucherCollectionData } from '@/Components/Vouchers/BuyerVoucherCollection';
import { VoucherOffer } from '@/hooks/usePurchaseQuote';
import PhoneInput from '@/Components/PhoneInput';
import BuyerAddressEditor from '@/Components/BuyerAddressEditor';
import EmailVerificationStatus from '@/Components/EmailVerificationStatus';
import { OrderTrackingStatus } from '@/Components/OrderTrackingPanel';
import { setupBuyerProfileWorkspace } from '@/utils/buyerProfileWorkspace';
import { buyerOrderIssueStages, isBuyerOrderIssue } from '@/utils/orderWorkspace';
import { buyerProfileProps } from '@/utils/portalNavigation';
import { visitPortal } from '@/utils/catalogueRuntime';
import useResolvedPage from '@/hooks/useResolvedPage';
import useSettingsSections from '@/hooks/useSettingsSections';
import { buyerAccountSections, type BuyerAccountSection } from '@/utils/accountSections';
import '../../../css/buyer-profile.css';
import { User, Order, Address, PageProps, PaginatedData } from '@/types';
import { 
    Fingerprint,
    UserRound,
    ShieldCheck, 
    ShieldAlert,
    Wallet, 
    MapPin, 
    Plus, 
    Phone, 
    Mail, 
    Calendar, 
    Check, 
    Clock, 
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

interface Props {
    user: User;
    addresses: Address[];
    editingAddressId?: number | null;
    addressBookLimit?: number;
    wallet: WalletData;
    orders: PaginatedData<Order>;
    orderCounts: Record<string, number>;
    currentOrderStatus: string;
    ordersCount: number;
    initialTab?: TabType;
    availableVouchers?: VoucherOffer[];
    availableVoucherCount?: number;
    voucherCollection?: BuyerVoucherCollectionData;
    canUsePortal?: boolean;
}

type TabType = 'orders' | 'account' | 'addresses' | 'wallet' | 'vouchers';
const accountSectionIds = buyerAccountSections.map(section => section.id);

interface ProfileFormData {
    name: string;
    phone: string;
    avatar: File | string | null;
    remove_avatar?: boolean;
}

export default function BuyerProfile({ 
    user, 
    addresses: initialAddresses,
    editingAddressId = null,
    addressBookLimit = 10,
    wallet: initialWallet, 
    orders,
    orderCounts,
    currentOrderStatus = 'all',
    ordersCount = 0,
    initialTab = 'orders',
    availableVouchers = [],
    availableVoucherCount = 0,
    voucherCollection,
    canUsePortal = true,
}: Props) {
    const page = usePage<PageProps>();
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

    const selectedOrderStatus = currentOrderStatus;
    useResolvedPage(orders.current_page, activeTab === 'orders');
    const showingOrderIssues = isBuyerOrderIssue(selectedOrderStatus);
    const workspaceRef = useRef<HTMLDivElement>(null);
    const orderScrollRef = useRef<HTMLDivElement>(null);
    const accountScrollRef = useRef<HTMLDivElement>(null);
    const accountNavigation = useSettingsSections(activeTab === 'account', accountSectionIds, accountScrollRef);
    useEffect(() => {
        if (workspaceRef.current) return setupBuyerProfileWorkspace(workspaceRef.current);
    }, []);
    useEffect(() => {
        if (orderScrollRef.current) orderScrollRef.current.scrollTop = 0;
    }, [selectedOrderStatus, orders.current_page]);
    useEffect(() => {
        if (accountScrollRef.current) accountScrollRef.current.scrollTop = 0;
    }, [activeTab]);
    const [orderBusy, setOrderBusy] = useState(false);
    const sectionPending = useRef(false);
    const [orderActionError, setOrderActionError] = useState('');
    const [receiptPending, setReceiptPending] = useState<number | null>(null);
    const receiptGuard = useRef(false);
    const loadOrderPage = (target: string, query: Record<string, string> = {}) => {
        if (sectionPending.current || receiptGuard.current) return;
        sectionPending.current = true;
        setOrderBusy(true); setOrderActionError('');
        const destination = new URL(target, window.location.origin);
        for (const [key, value] of Object.entries(query)) destination.searchParams.set(key, value);
        void visitPortal(destination.pathname + destination.search, { preserveState: true, preserveScroll: true,
            only: buyerProfileProps('orders'),
            onError: errors => setOrderActionError(Object.values(errors).join(' ')),
            onFinish: () => { sectionPending.current = false; setOrderBusy(false); },
            onCancel: () => { sectionPending.current = false; setOrderBusy(false); },
        });
    };
    const confirmReceipt = (id: number) => {
        if (sectionPending.current || receiptGuard.current || !confirm('Confirm that you received this order?')) return;
        receiptGuard.current = true; setReceiptPending(id); setOrderActionError('');
        router.post(route('buyer.orders.confirm', id), {}, { preserveScroll: true,
            onError: errors => setOrderActionError(Object.values(errors).join(' ')),
            onFinish: () => { receiptGuard.current = false; setReceiptPending(null); },
        });
    };
    const [addresses, setAddresses] = useState<Address[]>(initialAddresses);
    const wallet = initialWallet;
    const [showAddressModal, setShowAddressModal] = useState(Boolean(editingAddressId));
    const [editingAddress, setEditingAddress] = useState<number | null>(editingAddressId);
    const addressGuard = useRef(false);
    const [addressPending, setAddressPending] = useState<number | null>(null);
    const [addressActionError, setAddressActionError] = useState('');

    const [avatarPreview, setAvatarPreview] = useState<string | null>(user.avatar || null);
    const fileInputRef = React.useRef<HTMLInputElement | null>(null);

    // Recheck the selected section on the server before displaying its records.
    const handleTabChange = (tabId: TabType) => {
        if (sectionPending.current || receiptGuard.current) return;
        if (activeTab === tabId && typeof window !== 'undefined' && (window.location.search.includes(`tab=${tabId}`) || (tabId === 'orders' && window.location.pathname.endsWith('/orders')))) {
            return;
        }

        const targetUrl = route('buyer.profile', { tab: tabId, ...(tabId === 'orders' ? { order_status: currentOrderStatus, page: orders.current_page } : {}) });
        sectionPending.current = true;
        setOrderBusy(true); setOrderActionError('');
        void visitPortal(targetUrl, { only: buyerProfileProps(tabId), preserveState: true, preserveScroll: true,
            onError: errors => setOrderActionError(Object.values(errors).join(' ')),
            onFinish: () => { sectionPending.current = false; setOrderBusy(false); },
            onCancel: () => { sectionPending.current = false; setOrderBusy(false); },
        });
    };

    const openPurchaseGroup = (group: 'progress' | 'issues') => {
        if (orderBusy || receiptGuard.current) return;
        if (showingOrderIssues === (group === 'issues')) {
            handleTabChange('orders');
            return;
        }
        loadOrderPage(route('buyer.profile'), { tab: 'orders', order_status: group === 'issues' ? 'delivery_failed' : 'all' });
    };

    const openAccountSection = (section: BuyerAccountSection) => {
        if (orderBusy || receiptGuard.current) return;
        accountNavigation.select(section);
        handleTabChange('account');
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
    const { data, setData, post, processing, errors, recentlySuccessful, setDefaults } = useForm<ProfileFormData>({
        name: user.name || '',
        phone: user.phone || '',
        avatar: null,
        remove_avatar: false,
    });

    // Password Form
    const passwordForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
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
            onSuccess: ({ props }) => {
                const saved = props.user as User;
                const values = { name: saved.name, phone: saved.phone || '', avatar: null, remove_avatar: false };
                setData(values);
                setDefaults(values);
                setAvatarPreview(saved.avatar || null);
                if (fileInputRef.current) fileInputRef.current.value = '';
            },
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

    const openAddress = (address?: Address) => {
        if (!canUsePortal || (!address && addresses.length >= addressBookLimit)) return;
        setAddressActionError('');
        if (address) {
            router.get(route('buyer.addresses.edit', address.id), {}, { preserveScroll: true });
            return;
        }
        setEditingAddress(null);
        setShowAddressModal(true);
    };
    const addressOptions = {
        preserveScroll: true,
        onError: (errors: Record<string, string>) => setAddressActionError(Object.values(errors).join(' ')),
        onFinish: () => { addressGuard.current = false; setAddressPending(null); },
    };
    const setDefaultAddress = (id: number) => {
        if (addressGuard.current) return;
        addressGuard.current = true;
        setAddressPending(id); setAddressActionError('');
        router.post(route('buyer.addresses.default', id), {}, addressOptions);
    };
    const handleDeleteAddress = (id: number) => {
        if (addressGuard.current || !confirm('Delete this saved address? Existing orders keep their delivery details.')) return;
        addressGuard.current = true;
        setAddressPending(id); setAddressActionError('');
        router.delete(route('buyer.addresses.destroy', id), addressOptions);
    };

    const formatPrice = (val?: number | string | null) => {
        const num = Number(val || 0);
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2,
        }).format(num);
    };

    const filteredOrders = orders.data;
    const orderIssueCount = buyerOrderIssueStages.reduce((total, stage) => total + (orderCounts[stage.id] ?? 0), 0);

    const navItems: { id: TabType; label: string; icon: React.ComponentType<{ className?: string }>; badge?: string | number }[] = [
        { id: 'addresses', label: 'Delivery Address Book', icon: MapPin, badge: addresses.length },
        { id: 'wallet', label: 'Bagoo Wallet', icon: Wallet, badge: wallet.available ? undefined : 'Unavailable' },
        { id: 'vouchers', label: 'Vouchers & Promos', icon: Tag, badge: `${availableVoucherCount} Available` },
    ];

    return (
        <BuyerLayout>
            <Head title={`${activeTab === 'orders' ? (showingOrderIssues ? 'Order issues' : 'My Purchases & Order Tracking') : activeTab === 'account' ? 'Personal Information & Security' : activeTab === 'addresses' ? 'Delivery Address Book' : activeTab === 'wallet' ? 'Bagoo Wallet' : 'Vouchers & Promos'} — BagooPH`} />

            <div className="w-full space-y-6 font-sans">
                
                {/* 1. TOP HEADER STRIP */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-200">
                    <div>
                        <h1 className="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">
                            {activeTab === 'orders' ? (showingOrderIssues ? 'Order issues' : 'My Purchases & Order Tracking') : 'Account Settings & Credentials'}
                        </h1>
                        <p className="text-xs text-slate-500 font-sans">
                            {activeTab === 'orders' ? (showingOrderIssues ? 'View delivery issues, returned orders and cancellations' : 'View recorded delivery updates and order receipts') : 'Manage your account information and delivery addresses'}
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

                {orderBusy && <p role="status" className="text-xs text-slate-500">Loading current details…</p>}
                {orderActionError && <p role="alert" className="rounded-xl border border-rose-300 bg-rose-50 p-3 text-xs text-rose-700">{orderActionError}</p>}
                <input
                    ref={fileInputRef}
                    type="file"
                    accept="image/jpeg,image/png,image/jpg,image/webp,image/gif"
                    onChange={handleFileChange}
                    className="hidden"
                />

                {/* 2. TWO-COLUMN WORKSPACE: LEFT SIDEBAR + RIGHT WORKSPACE */}
                <div ref={workspaceRef} className="buyer-profile-workspace grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                    
                    {/* LEFT SIDEBAR NAVIGATION */}
                    <div data-buyer-profile-scroll scroll-region="" className="buyer-profile-sidebar lg:col-span-4 bg-white rounded-3xl p-5 border border-slate-200 shadow-xs space-y-5">
                        
                        {/* User Identity Mini Card */}
                        <div className="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 flex items-center gap-3.5">
                            <button
                                type="button"
                                onClick={() => {
                                    handleTabChange('account');
                                    fileInputRef.current?.click();
                                }}
                                className="relative group shrink-0 rounded-xl overflow-hidden focus:outline-hidden focus:ring-2 focus:ring-[#E00D42] cursor-pointer"
                                title="Click to update avatar photo"
                            >
                                {avatarPreview ? (
                                    <img
                                        src={avatarPreview || ''}
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
                        <nav aria-label="Buyer profile" className="space-y-1.5 font-sans text-xs">
                            <BuyerPurchaseNavigation
                                active={activeTab === 'orders' ? (showingOrderIssues ? 'issues' : 'progress') : null}
                                ordersCount={ordersCount}
                                issueCount={orderIssueCount}
                                disabled={orderBusy || receiptPending !== null}
                                onSelect={openPurchaseGroup}
                            />
                            <BuyerAccountNavigation
                                active={activeTab === 'account'}
                                selected={accountNavigation.selected}
                                disabled={orderBusy || receiptPending !== null}
                                onSelect={openAccountSection}
                            />
                            {navItems.map((item) => {
                                const isActive = activeTab === item.id;
                                return (
                                    <button
                                        key={item.id}
                                        type="button"
                                        disabled={orderBusy || receiptPending !== null}
                                        aria-busy={orderBusy || undefined}
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
                                    <span>Returns &amp; Refunds</span>
                                </span>
                                <ChevronRight className="w-3.5 h-3.5 text-slate-400" />
                            </Link>
                        </div>

                    </div>

                    {/* RIGHT CONTENT WORKSPACE */}
                    <div className="buyer-profile-content min-w-0 lg:col-span-8">
                        
                        {/* TAB 1: MY PURCHASES & ORDERS (DEFAULT ACTIVE TAB) */}
                        {activeTab === 'orders' && (
                            <div className="buyer-profile-orders min-w-0 gap-4">
                                <BuyerOrderFilters
                                    selected={selectedOrderStatus}
                                    counts={orderCounts}
                                    disabled={orderBusy || receiptPending !== null}
                                    refreshing={orderBusy}
                                    onChange={status => loadOrderPage(route('buyer.profile'), { tab: 'orders', order_status: status })}
                                    onRefresh={() => loadOrderPage(orders.current_page > 1 ? `${route('buyer.profile')}?page=${orders.current_page}` : route('buyer.profile'), { tab: 'orders', order_status: currentOrderStatus })}
                                />

                                <div ref={orderScrollRef} data-buyer-profile-scroll scroll-region="" role="region" aria-label="Purchase results" tabIndex={0} className={`buyer-profile-scroll mt-4 space-y-4 lg:mt-0 ${filteredOrders.length === 0 ? 'flex flex-col' : ''}`} aria-busy={orderBusy}>
                                {orderBusy && <p role="status" className="text-xs text-slate-500">Loading orders…</p>}

                                {/* Orders List Cards */}
                                {filteredOrders.length === 0 ? (
                                    <div className="flex min-h-[20rem] flex-1 flex-col rounded-3xl border border-slate-200 bg-white p-6 text-center shadow-xs sm:p-12 lg:min-h-0">
                                        <div className="m-auto w-full max-w-md space-y-3">
                                            <Package className="mx-auto h-12 w-12 text-slate-300" aria-hidden="true" />
                                            <h3 className="text-base font-bold text-slate-800">{ordersCount === 0 ? 'No orders yet' : 'No orders found in this status'}</h3>
                                            <p className="text-xs leading-5 text-slate-500">
                                                {showingOrderIssues ? 'You have no orders with this issue. Choose another status or return to My Purchases & Orders.' : 'Your orders will appear here. Browse the marketplace to find something for your Bag.'}
                                            </p>
                                            {showingOrderIssues ? (
                                                <button
                                                    type="button"
                                                    disabled={orderBusy || receiptPending !== null}
                                                    onClick={() => openPurchaseGroup('progress')}
                                                    className="rounded-xl bg-[#E00D42] px-5 py-2.5 text-xs font-bold text-white shadow-xs disabled:opacity-50"
                                                >
                                                    View purchases
                                                </button>
                                            ) : (
                                                <Link
                                                    href={route('buyer.index')}
                                                    className="inline-block rounded-xl bg-[#E00D42] px-5 py-2.5 text-xs font-bold uppercase text-white shadow-xs"
                                                >
                                                    Explore Marketplace
                                                </Link>
                                            )}
                                        </div>
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
                                                    {order.payment_method === 'wallet' && order.wallet_payment && <Link href={`/wallet/payments/${order.wallet_payment.id}`} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-[#C20836]">{order.payment_status === 'pending' ? 'Pay with wallet' : 'Wallet payment'}</Link>}
                                                    {<OrderTrackingStatus tracking={order.tracking} />}
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
                                                        {order.tracking && <span className="text-slate-500"> · Holder: {order.tracking.custodian}</span>}
                                                    </div>
                                                    <span className="text-slate-600 font-bold uppercase text-[10px] bg-slate-200/60 px-2 py-0.5 rounded-md">
                                                        {order.tracking?.stage_label ?? 'Parcel stage not loaded'}
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
                                                    {order.can_confirm_receipt && (
                                                        <button
                                                            type="button"
                                                            disabled={receiptPending !== null || orderBusy}
                                                            onClick={() => confirmReceipt(order.id)}
                                                            className="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-700 active:scale-[0.98] text-white rounded-xl font-bold uppercase transition flex items-center gap-1.5 shadow-2xs text-xs cursor-pointer"
                                                        >
                                                            <CheckCircle2 className="w-3.5 h-3.5" />
                                                            <span>{receiptPending === order.id ? 'Confirming…' : 'Confirm Received'}</span>
                                                        </button>
                                                    )}
                                                    {order.can_buy_again && order.buy_again_token && <BuyAgainButton url={route('buyer.orders.buy-again.store', order.id)} token={order.buy_again_token} />}

                                                    <Link
                                                        href={route('buyer.orders.show', order.id)}
                                                        className="px-4 py-2 bg-white hover:bg-slate-50 border border-slate-200 hover:border-slate-300 text-slate-800 rounded-xl font-bold uppercase transition flex items-center gap-1.5 shadow-2xs text-xs"
                                                    >
                                                        <Truck className="w-3.5 h-3.5 text-slate-600" />
                                                        <span>Track Parcel</span>
                                                    </Link>

                                                    {order.return_availability?.can_request && (
                                                        <Link
                                                            href={`/buyer-returns/orders/${order.id}/create`}
                                                            className="px-3 py-2 border border-slate-200 text-slate-700 hover:bg-slate-50 rounded-xl font-bold transition"
                                                        >
                                                            Request a return
                                                        </Link>
                                                    )}
                                                    {order.buyer_returns?.map(item => <Link key={item.id} href={`/buyer-returns/${item.id}`} className="rounded-xl border border-slate-300 px-3 py-2 font-semibold text-[#C20836]">Return {item.reference}</Link>)}
                                                </div>
                                            </div>
                                        </div>
                                    ))
                                )}

                                {orders.last_page > 1 && <nav aria-label="Purchase pages" className="flex items-center justify-between gap-3 rounded-2xl border border-slate-300 bg-white p-4 text-xs">
                                    <button type="button" disabled={!orders.prev_page_url || orderBusy || receiptPending !== null} onClick={() => orders.prev_page_url && loadOrderPage(orders.prev_page_url)} className="rounded-xl border border-slate-300 px-4 py-2 font-bold text-slate-700 disabled:opacity-40">Previous</button>
                                    <span>Page {orders.current_page} of {orders.last_page} · {orders.total} orders in this stage</span>
                                    <button type="button" disabled={!orders.next_page_url || orderBusy || receiptPending !== null} onClick={() => orders.next_page_url && loadOrderPage(orders.next_page_url)} className="rounded-xl border border-slate-300 px-4 py-2 font-bold text-slate-700 disabled:opacity-40">Next</button>
                                </nav>}
                                </div>
                            </div>
                        )}

                        {/* TAB 2: PERSONAL ACCOUNT & SECURITY */}
                        {activeTab === 'account' && (
                            <div ref={accountScrollRef} data-buyer-profile-scroll scroll-region="" role="region" aria-label="Account and security settings" tabIndex={0} className="buyer-profile-scroll space-y-6">
                                
                                {/* Personal Profile Info Form */}
                                <div id="account-personal" tabIndex={-1} className="scroll-mt-28 bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-xs space-y-6 focus:outline-none">
                                    <div className="border-b border-slate-100 pb-4">
                                        <AccountSectionHeading icon={UserRound} title="Personal Information" description="Update your contact information and profile photo." />
                                    </div>

                                    {recentlySuccessful && (
                                        <div className="p-3 bg-emerald-50 text-emerald-800 rounded-xl border border-emerald-200 text-xs font-sans flex items-center gap-2">
                                            <Check className="w-4 h-4 text-emerald-600" />
                                            <span>Profile details updated successfully!</span>
                                        </div>
                                    )}

                                    <form onSubmit={handleProfileSubmit} className="space-y-5 text-xs font-sans">
                                        {Object.keys(errors).length > 0 && <div role="alert" className="rounded-xl border border-rose-300 bg-rose-50 p-3 text-rose-800">{Object.values(errors).join(' ')}</div>}


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
                                                <div className="grid grid-cols-[1fr_5rem_1fr] gap-2">
                                                    {([['first_name', 'First name'], ['middle_name', 'Middle name'], ['last_name', 'Last name']] as const).map(([key, label]) => <label key={key} className="block font-bold text-slate-700">{label}<input type="text" readOnly value={user[key] ?? ''} placeholder="Not recorded" className="mt-1.5 w-full rounded-xl border border-slate-300 bg-slate-50 p-2.5 text-xs" /></label>)}
                                                </div>
                                                {!user.first_name && !user.last_name && <p className="mt-2 text-xs text-slate-500">Earlier registration recorded: {user.name}. Request a correction to record the separate names.</p>}
                                                {isKycApproved ? <a href="#identity-correction" className="mt-2 block text-xs font-semibold text-[#C20836] underline">Request an identity correction</a> : <Link href={route('kyc.pending')} className="mt-2 block text-xs font-semibold text-[#C20836] underline">Update application details</Link>}
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
                                                    error={errors.phone}
                                                />
                                            </div>

                                            <div>
                                                <label className="block font-bold text-slate-700 mb-1.5 font-sans">Birthday</label>
                                                <input
                                                    type="date"
                                                    value={user.birthday?.slice(0, 10) || ''}
                                                    readOnly
                                                    className="w-full rounded-xl bg-slate-50 border border-slate-200 p-2.5 text-xs font-sans focus:ring-[#E00D42] focus:border-[#E00D42]"
                                                />
                                            </div>

                                            <div>
                                                <label className="block font-bold text-slate-700 mb-1.5 font-sans">Sex</label>
                                                <input
                                                    value={user.sex || 'Not recorded'}
                                                    readOnly
                                                    className="w-full rounded-xl bg-slate-50 border border-slate-300 p-2.5 text-xs"
                                                />
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

                                <AccountEmailSettings theme="buyer" />

                                {/* Password & Security Settings */}
                                <div id="account-password" tabIndex={-1} className="scroll-mt-28 bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-xs space-y-6 focus:outline-none">
                                    <div className="border-b border-slate-100 pb-4">
                                        <AccountSectionHeading icon={KeyRound} title="Account Password & Security" description="Use a unique password between 8 and 128 characters." />
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

                                    <EmailVerificationStatus
                                        email={user.email}
                                        verifiedAt={user.email_verified_at}
                                    />

                                    {user.email_verified_at && (
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
                                                {passwordForm.errors.current_password && (
                                                    <p className="mt-1 text-xs font-semibold text-[#E00D42]">
                                                        {passwordForm.errors.current_password}
                                                    </p>
                                                )}
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
                                                {passwordForm.errors.password && (
                                                    <p className="mt-1 text-xs font-semibold text-[#E00D42]">
                                                        {passwordForm.errors.password}
                                                    </p>
                                                )}
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
                                                {passwordForm.errors.password_confirmation && (
                                                    <p className="mt-1 text-xs font-semibold text-[#E00D42]">
                                                        {passwordForm.errors.password_confirmation}
                                                    </p>
                                                )}
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
                                    )}
                                </div>

                                <section id="account-identity" tabIndex={-1} className="scroll-mt-28 space-y-6 rounded-3xl border border-slate-300 bg-white p-6 shadow-xs focus:outline-none sm:p-8" aria-label="Identity correction">
                                    <AccountSectionHeading icon={Fingerprint} title="Identity correction" description="Review your verification and request changes to reviewed identity details. Corrections need supporting evidence and admin approval." />
                                    <div id="account-verification" tabIndex={-1} className="scroll-mt-28 rounded-2xl border border-slate-200 bg-slate-50/60 p-4 space-y-5 focus:outline-none sm:p-5">
                                        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-slate-100 pb-4">
                                            <div>
                                                <AccountSectionHeading icon={ShieldCheck} title="Identity verification" as="h3" compact description="Check your identity review and document on file." />
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
                                                    <p className="font-bold text-emerald-900">Your identity review is approved</p>
                                                    <p className="text-emerald-700 leading-relaxed">
                                                        Your submitted identity document has been approved. Other account restrictions still apply.
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
                                                        Upload a clear government photo ID so your account can be reviewed.
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
                                                <UploadHint />

                                                {!kycFile ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => kycFileInputRef.current?.click()}
                                                        className="w-full border-2 border-dashed border-slate-300 hover:border-[#E00D42] bg-white hover:bg-rose-50/20 rounded-2xl p-6 text-center transition cursor-pointer group focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]"
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
                                                    </button>
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

                                    <AccountIdentitySettings theme="buyer" embedded />
                                </section>

                                <AccountSessionSettings />
                            </div>
                        )}

                        {/* TAB 3: DELIVERY ADDRESS BOOK */}
                        {activeTab === 'addresses' && (
                            <div data-buyer-profile-scroll scroll-region="" role="region" aria-label="Delivery addresses" tabIndex={0} className="buyer-profile-scroll buyer-profile-card-scroll flex flex-col gap-6 bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-xs">
                                <div className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-4">
                                    <div>
                                        <h3 className="text-base font-black text-slate-900">Delivery addresses</h3>
                                        <p className="text-xs text-slate-500 font-sans">Save up to {addressBookLimit} addresses for future deliveries. {addresses.length} of {addressBookLimit} saved.</p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => openAddress()}
                                        disabled={!canUsePortal || addresses.length >= addressBookLimit}
                                        className="disabled:opacity-50 disabled:cursor-not-allowed px-4 py-2 bg-[#E00D42] hover:bg-[#C20836] text-white rounded-xl text-xs font-sans font-bold uppercase transition flex items-center gap-1.5 shadow-xs"
                                    >
                                        <Plus className="w-3.5 h-3.5" />
                                        <span>Add New Address</span>
                                    </button>
                                </div>

                                <div className={addresses.length === 0 ? 'flex min-h-[16rem] flex-1 flex-col gap-4 lg:min-h-0' : 'space-y-4'}>
                                    {!canUsePortal && <p className="rounded-xl border border-slate-300 bg-slate-50 p-3 text-xs text-slate-600">Address changes become available when your buyer account is active and approved.</p>}
                                    {addresses.length >= addressBookLimit && <p role="status" className="rounded-xl border border-slate-300 bg-slate-50 p-3 text-xs text-slate-700">Your address book is full. Remove a non-default address before adding another.</p>}
                                    {addressActionError && <p role="alert" className="text-sm text-rose-700">{addressActionError}</p>}
                                    {addressPending !== null && <p role="status" className="text-sm text-slate-600">Updating your address book...</p>}
                                    {addresses.length === 0 && (
                                        <div className="m-auto w-full max-w-md space-y-3 py-6 text-center">
                                            <MapPin className="mx-auto h-10 w-10 text-slate-300" aria-hidden="true" />
                                            <h4 className="text-base font-bold text-slate-800">No saved addresses</h4>
                                            <p className="text-sm leading-6 text-slate-600">Add a delivery address to make your next checkout easier.</p>
                                        </div>
                                    )}
                                    {addresses.map((addr) => (
                                        <div
                                            key={addr.id}
                                            className={`p-5 rounded-2xl border transition space-y-2 relative ${
                                                addr.is_default ? 'bg-amber-50/40 border-amber-300 shadow-xs' : 'bg-slate-50 border-slate-200'
                                            }`}
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-3">
                                                <div className="flex min-w-0 flex-wrap items-center gap-2 font-sans text-xs">
                                                    <span className="font-bold text-slate-900">{addr.recipient_name}</span>
                                                    <span className="text-slate-400">•</span>
                                                    <span className="text-slate-600">{addr.phone}</span>
                                                    <span className="px-2 py-0.5 rounded bg-slate-200 text-slate-700 text-[10px] uppercase font-bold">
                                                        {addr.type}
                                                    </span>
                                                </div>

                                                <div className="flex flex-wrap items-center gap-3">
                                                    <button type="button" disabled={!canUsePortal || addressPending !== null} onClick={() => openAddress(addr)} className="text-[11px] font-semibold text-[#C20836] underline disabled:opacity-50">Edit</button>
                                                    {addr.is_default ? (
                                                        <span className="px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-800 text-[10px] font-sans font-bold">
                                                            DEFAULT ADDRESS
                                                        </span>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            onClick={() => setDefaultAddress(addr.id)}
                                                            disabled={!canUsePortal || addressPending !== null}
                                                            className="text-[11px] font-sans text-slate-500 hover:text-slate-900 underline cursor-pointer"
                                                        >
                                                            Set as Default
                                                        </button>
                                                    )}

                                                    {(
                                                        <button
                                                            type="button"
                                                            onClick={() => handleDeleteAddress(addr.id)}
                                                            disabled={!canUsePortal || addressPending !== null || addr.is_default}
                                                            className="text-slate-400 hover:text-rose-600 transition p-1 cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed"
                                                            title={addr.is_default ? 'Choose another default before deleting this address' : 'Delete address'}
                                                            aria-label={addr.is_default ? 'Default address cannot be deleted' : 'Delete address'}
                                                        >
                                                            <Trash2 className="w-3.5 h-3.5" />
                                                        </button>
                                                    )}
                                                </div>
                                            </div>

                                            {addr.is_default && <p className="text-xs text-slate-500">To remove this address, choose another saved address as default first.</p>}
                                            <p className="text-xs text-slate-700 font-sans">
                                                {[addr.street, addr.barangay, addr.city, addr.province, addr.postal_code].filter(Boolean).join(', ')}
                                            </p>
                                            {addr.region_name && <p className="text-xs text-slate-500">{addr.region_name}</p>}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* TAB 4: BAGOO WALLET */}
                        {activeTab === 'wallet' && (
                            <section data-buyer-profile-scroll scroll-region="" role="region" aria-label="Bagoo Wallet" tabIndex={0} className="buyer-profile-scroll buyer-profile-card-scroll flex min-h-[20rem] flex-col rounded-2xl border border-slate-300 bg-white p-6 sm:p-8 shadow-xs lg:min-h-0">
                                <BuyerWalletPanel wallet={wallet} />
                            </section>
                        )}

                        {/* TAB 5: VOUCHERS & PROMOS */}
                        {activeTab === 'vouchers' && (
                            <div data-buyer-profile-scroll scroll-region="" role="region" aria-label="Vouchers and promos" tabIndex={0} className="buyer-profile-scroll buyer-profile-card-scroll flex flex-col gap-6 bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-xs">
                                {voucherCollection ? <BuyerVoucherCollection collection={voucherCollection} allowSave={canUsePortal} /> : <><div className="border-b border-slate-100 pb-4">
                                    <h3 className="text-base font-black text-slate-900">Available Offers</h3>
                                    <p className="mt-1 text-xs text-slate-500">Choose an offer at checkout. Eligibility depends on your selected items and delivery method.</p>
                                    {availableVoucherCount > availableVouchers.length && <p className="mt-2 text-xs text-slate-500">
                                        Showing {availableVouchers.length} of {availableVoucherCount} available offers. You can also enter a code at checkout.
                                    </p>}
                                </div>
                                <VoucherOfferList offers={availableVouchers} /></>}
                            </div>
                        )}

                    </div>

                </div>

                {showAddressModal && <BuyerAddressEditor user={user} address={addresses.find(address => address.id === editingAddress)} count={addresses.length} limit={addressBookLimit} onClose={() => setShowAddressModal(false)} />}

            </div>
        </BuyerLayout>
    );
}
