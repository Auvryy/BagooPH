import React, { useState, useEffect, useLayoutEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { Head, Link, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Order, OrderItem, PaginatedData, Shop, User } from '@/types';
import { 
    Package, 
    Truck, 
    Clock, 
    CheckCircle2, 
    Printer, 
    Box, 
    ArrowRight, 
    MapPin, 
    Phone, 
    User as UserIcon, 
    X, 
    Search, 
    ShieldCheck, 
    Check, 
    ChevronRight, 
    FileText, 
    AlertCircle, 
    Copy, 
    Ban, 
    DollarSign, 
    Info, 
    Layers,
    MessageSquare,
    Send,
    Loader2
} from 'lucide-react';

interface Counts {
    all: number;
    to_pack: number;
    to_pickup: number;
    in_transit: number;
    delivered: number;
    cancelled: number;
}

interface Props {
    orderItems: PaginatedData<OrderItem & {
        order: Order & {
            buyer?: User & {
                orders_count?: number;
                completed_orders_count?: number;
            };
            delivery?: {
                id: number;
                tracking_number: string;
                status: string;
                logistics_partner?: string;
                pickup_store_name?: string;
                pickup_address?: string;
                delivery_recipient_name?: string;
                delivery_address?: string;
                delivery_phone?: string;
                checkpoints?: Array<{
                    id: number;
                    checkpoint_type: string;
                    location_name: string;
                    notes?: string;
                    created_at: string;
                }>;
            };
            items?: OrderItem[];
            cancellation_reason?: string;
        };
    }>;
    shop: Shop;
    currentStatus?: string;
    counts?: Counts;
}

export default function SellerOrders({ orderItems, shop, currentStatus = 'all', counts }: Props) {
    const [selectedOrderForWaybill, setSelectedOrderForWaybill] = useState<any | null>(null);
    const [orderToAcceptAndPack, setOrderToAcceptAndPack] = useState<any | null>(null);
    const [selectedOrderForDetails, setSelectedOrderForDetails] = useState<any | null>(null);
    const [orderToCancel, setOrderToCancel] = useState<any | null>(null);
    const [cancelReason, setCancelReason] = useState('Out of stock / Inventory shortage');
    const [cancelNotes, setCancelNotes] = useState('');
    const [chatOrder, setChatOrder] = useState<any | null>(null);
    const [chatMessage, setChatMessage] = useState('');
    const [chatSent, setChatSent] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedOrderIds, setSelectedOrderIds] = useState<number[]>([]);
    const [copiedTracking, setCopiedTracking] = useState<string | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    // Infinite scroll & tab loading state
    const [items, setItems] = useState<any[]>(orderItems.data || []);
    const [nextPageUrl, setNextPageUrl] = useState<string | null>(orderItems.next_page_url ?? null);
    const [isTabLoading, setIsTabLoading] = useState(false);
    const [isLoadingMore, setIsLoadingMore] = useState(false);
    const sentinelRef = useRef<HTMLDivElement | null>(null);

    // Synchronize items when props change (replace on page 1, append on pagination)
    useEffect(() => {
        if (orderItems.current_page === 1) {
            setItems(orderItems.data || []);
        } else {
            setItems(prev => {
                const existingIds = new Set(prev.map((p: any) => p.id));
                const newItems = (orderItems.data || []).filter((item: any) => !existingIds.has(item.id));
                return [...prev, ...newItems];
            });
        }
        setNextPageUrl(orderItems.next_page_url ?? null);
    }, [orderItems]);

    // IntersectionObserver for infinite scrolling
    useEffect(() => {
        if (!nextPageUrl || isLoadingMore || isTabLoading) return;

        const observer = new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting && nextPageUrl && !isLoadingMore && !isTabLoading) {
                setIsLoadingMore(true);
                router.get(nextPageUrl, {}, {
                    preserveState: true,
                    preserveScroll: true,
                    only: ['orderItems'],
                    onFinish: () => setIsLoadingMore(false),
                });
            }
        }, {
            rootMargin: '200px',
        });

        const currentEl = sentinelRef.current;
        if (currentEl) {
            observer.observe(currentEl);
        }

        return () => {
            if (currentEl) observer.unobserve(currentEl);
            observer.disconnect();
        };
    }, [nextPageUrl, isLoadingMore, isTabLoading]);

    // Animated sliding tab state
    const [activeTab, setActiveTab] = useState(currentStatus || 'all');
    const tabRefs = useRef<{ [key: string]: HTMLButtonElement | null }>({});
    const [indicatorStyle, setIndicatorStyle] = useState<{ left: number; width: number }>({ left: 0, width: 0 });

    const tabConfig = [
        { key: 'all', label: 'All Orders', count: counts?.all ?? orderItems.total ?? 0 },
        { key: 'to_pack', label: 'To Pack', count: counts?.to_pack ?? 0 },
        { key: 'to_pickup', label: 'Ready for Pickup', count: counts?.to_pickup ?? 0 },
        { key: 'in_transit', label: 'In Transit', count: counts?.in_transit ?? 0 },
        { key: 'delivered', label: 'Completed', count: counts?.delivered ?? 0 },
        { key: 'cancelled', label: 'Cancelled', count: counts?.cancelled ?? 0 },
    ];

    useEffect(() => {
        setActiveTab(currentStatus || 'all');
    }, [currentStatus]);

    const updateIndicator = () => {
        const el = tabRefs.current[activeTab];
        if (el) {
            setIndicatorStyle({
                left: el.offsetLeft,
                width: el.offsetWidth,
            });
        }
    };

    useLayoutEffect(() => {
        updateIndicator();
    }, [activeTab, counts]);

    useEffect(() => {
        window.addEventListener('resize', updateIndicator);
        return () => window.removeEventListener('resize', updateIndicator);
    }, [activeTab]);

    useEffect(() => {
        if (selectedOrderForWaybill || orderToAcceptAndPack || selectedOrderForDetails || orderToCancel || chatOrder) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = 'unset';
        }
        return () => {
            document.body.style.overflow = 'unset';
        };
    }, [selectedOrderForWaybill, orderToAcceptAndPack, selectedOrderForDetails, orderToCancel, chatOrder]);

    const formatPrice = (val: string | number | undefined | null) => {
        const num = Number(val || 0);
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(num);
    };

    const handleTabClick = (statusKey: string) => {
        if (statusKey === activeTab && !isTabLoading) return;
        setActiveTab(statusKey);
        setIsTabLoading(true);
        const url = new URL(window.location.href);
        if (statusKey === 'all') {
            url.searchParams.delete('status');
        } else {
            url.searchParams.set('status', statusKey);
        }
        url.searchParams.delete('page');

        router.get(url.pathname + url.search, {}, {
            preserveState: true,
            preserveScroll: true,
            only: ['orderItems', 'counts', 'currentStatus'],
            onStart: () => setIsTabLoading(true),
            onFinish: () => setIsTabLoading(false),
        });
    };

    const handlePackOrder = (orderId: number) => {
        setIsSubmitting(true);
        router.post(route('seller.orders.pack', orderId), {}, {
            preserveScroll: true,
            onFinish: () => setIsSubmitting(false),
        });
    };

    const handleSchedulePickup = (orderId: number) => {
        setIsSubmitting(true);
        router.post(route('seller.orders.ready', orderId), {}, {
            preserveScroll: true,
            onFinish: () => setIsSubmitting(false),
        });
    };

    const handleHandoverOrder = (orderId: number) => {
        setIsSubmitting(true);
        router.post(route('seller.orders.handover', orderId), {}, {
            preserveScroll: true,
            onFinish: () => setIsSubmitting(false),
        });
    };

    const handleBatchSchedulePickup = () => {
        if (selectedOrderIds.length === 0) return;
        setIsSubmitting(true);
        router.post(route('seller.orders.batchReady'), { order_ids: selectedOrderIds }, {
            preserveScroll: true,
            onFinish: () => {
                setIsSubmitting(false);
                setSelectedOrderIds([]);
            },
        });
    };

    const handleConfirmCancel = (e: React.FormEvent) => {
        e.preventDefault();
        if (!orderToCancel) return;
        setIsSubmitting(true);
        router.post(route('seller.orders.cancel', orderToCancel.order_id), {
            reason: cancelReason,
            notes: cancelNotes,
        }, {
            preserveScroll: true,
            onFinish: () => {
                setIsSubmitting(false);
                setOrderToCancel(null);
                setCancelNotes('');
            },
        });
    };

    const handleSendQuickChat = (e: React.FormEvent) => {
        e.preventDefault();
        if (!chatOrder || !chatMessage.trim()) return;
        setIsSubmitting(true);
        router.post('/messages', {
            receiver_id: chatOrder.order?.buyer_id,
            order_id: chatOrder.order?.id,
            product_id: chatOrder.product_id,
            shop_id: shop.id,
            message: chatMessage.trim(),
        }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setChatSent(true);
                setTimeout(() => {
                    setChatOrder(null);
                    setChatMessage('');
                    setChatSent(false);
                }, 1400);
            },
            onFinish: () => setIsSubmitting(false),
        });
    };

    const copyToClipboard = (text: string) => {
        navigator.clipboard.writeText(text);
        setCopiedTracking(text);
        setTimeout(() => setCopiedTracking(null), 2000);
    };

    const toggleSelectOrder = (orderId: number) => {
        setSelectedOrderIds(prev => 
            prev.includes(orderId) ? prev.filter(id => id !== orderId) : [...prev, orderId]
        );
    };

    const getStatusPill = (status: string) => {
        switch (status) {
            case 'placed':
            case 'pending':
                return (
                    <span className="px-2.5 py-1 rounded-full bg-rose-50 text-[#E00D42] border border-rose-200 text-[11px] font-bold font-mono flex items-center gap-1.5 shadow-2xs">
                        <span className="w-1.5 h-1.5 rounded-full bg-[#E00D42]"></span>
                        <span>New Order (Action Required)</span>
                    </span>
                );
            case 'confirmed':
            case 'preparing':
            case 'processing':
                return (
                    <span className="px-2.5 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-200 text-[11px] font-bold font-mono flex items-center gap-1.5">
                        <Box className="w-3 h-3 text-amber-600" />
                        <span>To Pack</span>
                    </span>
                );
            case 'ready_for_pickup':
                return (
                    <span className="px-2.5 py-1 rounded-full bg-blue-50 text-blue-700 border border-blue-200 text-[11px] font-bold font-mono flex items-center gap-1.5">
                        <Clock className="w-3 h-3 text-blue-600" />
                        <span>Ready for Pickup</span>
                    </span>
                );
            case 'picked_up':
            case 'at_sorting_center':
            case 'sorted':
            case 'assigned_to_rider':
            case 'out_for_delivery':
            case 'shipped':
                return (
                    <span className="px-2.5 py-1 rounded-full bg-slate-100 text-slate-700 border border-slate-200 text-[11px] font-medium font-mono flex items-center gap-1.5">
                        <Truck className="w-3 h-3 text-slate-500" />
                        <span>In Transit</span>
                    </span>
                );
            case 'delivered':
            case 'completed':
                return (
                    <span className="px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-[11px] font-medium font-mono flex items-center gap-1.5">
                        <CheckCircle2 className="w-3 h-3 text-emerald-600" />
                        <span>Completed</span>
                    </span>
                );
            case 'cancelled':
            case 'returned':
            case 'delivery_failed':
                return (
                    <span className="px-2.5 py-1 rounded-full bg-slate-100 text-slate-500 border border-slate-200 text-[11px] font-medium font-mono flex items-center gap-1.5">
                        <Ban className="w-3 h-3 text-slate-400" />
                        <span>Cancelled / Returned</span>
                    </span>
                );
            default:
                return <span className="px-2.5 py-1 rounded-full bg-slate-100 text-slate-700 text-[11px] font-medium font-mono uppercase">{status}</span>;
        }
    };

    const renderCustomerInsight = (buyer?: any, isCompact: boolean = false) => {
        if (!buyer) return null;
        const totalOrders = buyer.orders_count ?? 1;
        const completedOrders = buyer.completed_orders_count ?? 0;
        const isRepeat = totalOrders > 1;

        if (isCompact) {
            return isRepeat ? (
                <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-200 text-[10px] font-mono font-bold">
                    Repeat ({totalOrders})
                </span>
            ) : (
                <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200 text-[10px] font-mono">
                    New Buyer
                </span>
            );
        }

        return (
            <div className="flex items-center gap-1.5 flex-wrap">
                {isRepeat ? (
                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-bold font-mono">
                        <ShieldCheck className="w-3 h-3 text-emerald-600" />
                        <span>Repeat Buyer ({totalOrders} Orders)</span>
                    </span>
                ) : (
                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-blue-50 text-blue-700 border border-blue-200 text-[10px] font-bold font-mono">
                        <UserIcon className="w-3 h-3 text-blue-600" />
                        <span>First-time Buyer</span>
                    </span>
                )}
                {completedOrders > 0 && (
                    <span className="text-[10px] text-slate-500 font-mono">
                        • {completedOrders} Delivered
                    </span>
                )}
                {buyer.kyc_status === 'approved' && (
                    <span className="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded bg-slate-100 text-slate-700 border border-slate-200 text-[9px] font-mono font-semibold">
                        <Check className="w-2.5 h-2.5 text-emerald-600" />
                        <span>ID Verified</span>
                    </span>
                )}
            </div>
        );
    };

    const filteredItems = items.filter(item => {
        if (!searchQuery.trim()) return true;
        const q = searchQuery.toLowerCase();
        return (
            item.order?.order_number?.toLowerCase().includes(q) ||
            (item.product?.name && item.product.name.toLowerCase().includes(q)) ||
            (item.order?.recipient_name && item.order.recipient_name.toLowerCase().includes(q)) ||
            (item.order?.delivery?.tracking_number && item.order.delivery.tracking_number.toLowerCase().includes(q))
        );
    });

    return (
        <DashboardLayout
            title="Orders & Fulfillment"
            subtitle="Review customer orders, print thermal waybills, and dispatch parcels."
        >
            <Head title="Orders — BagooPH Seller" />

            <div className="space-y-5 font-sans">
                
                {/* 1. STATUS FILTER TABS STRIP (WITH ANIMATED SLIDING INDICATOR) */}
                <div className="relative bg-white rounded-2xl p-1.5 border border-slate-200/90 shadow-2xs flex items-center gap-1.5 overflow-x-auto scrollbar-none font-sans text-xs">
                    
                    {/* Animated Sliding Active Background Pill */}
                    {indicatorStyle.width > 0 && (
                        <div
                            className="absolute top-1.5 bottom-1.5 rounded-xl bg-[#E00D42] shadow-xs transition-all duration-300 ease-[cubic-bezier(0.16,1,0.3,1)] pointer-events-none"
                            style={{
                                left: `${indicatorStyle.left}px`,
                                width: `${indicatorStyle.width}px`,
                            }}
                        />
                    )}

                    {tabConfig.map(tab => {
                        const isActive = activeTab === tab.key;
                        return (
                            <button
                                key={tab.key}
                                ref={el => tabRefs.current[tab.key] = el}
                                type="button"
                                onClick={() => handleTabClick(tab.key)}
                                className={`relative z-10 flex-1 py-2 px-3.5 rounded-xl font-bold transition-colors duration-200 text-center whitespace-nowrap flex items-center justify-center gap-2 text-xs cursor-pointer ${
                                    isActive 
                                        ? 'text-white' 
                                        : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50/80'
                                }`}
                            >
                                <span>{tab.label}</span>
                                {tab.count > 0 && (
                                    <span className={`px-1.5 py-0.5 rounded-full text-[10px] font-mono font-bold leading-none transition-colors duration-200 ${
                                        isActive ? 'bg-white/20 text-white' : 'bg-slate-200/80 text-slate-700'
                                    }`}>
                                        {tab.count}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>

                {/* 2. SEARCH & BATCH TOOLBAR */}
                <div className="bg-white rounded-2xl p-4 border border-slate-200/90 shadow-2xs flex flex-col sm:flex-row items-center justify-between gap-3 font-sans">
                    <div className="flex items-center gap-3 w-full sm:w-auto">
                        <div className="flex items-center gap-2 text-xs text-slate-500 font-mono">
                            <Package className="w-4 h-4 text-slate-400" />
                            <span>Showing <strong>{filteredItems.length}</strong> of {orderItems.total || filteredItems.length} items</span>
                        </div>

                        {/* Bulk Action Button if items selected */}
                        {selectedOrderIds.length > 0 && (
                            <button
                                type="button"
                                onClick={handleBatchSchedulePickup}
                                disabled={isSubmitting}
                                className="px-3 py-1.5 bg-slate-900 hover:bg-black text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-2xs cursor-pointer font-mono"
                            >
                                <Truck className="w-3.5 h-3.5" />
                                <span>Dispatch Selected ({selectedOrderIds.length})</span>
                            </button>
                        )}
                    </div>

                    <div className="w-full sm:w-80 relative text-xs">
                        <input
                            type="text"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            placeholder="Search order #, buyer, tracking..."
                            className="w-full pl-9 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 text-xs focus:ring-2 focus:ring-[#E00D42]/15 focus:border-[#E00D42] transition placeholder:text-slate-400"
                        />
                        <Search className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" />
                    </div>
                </div>

                {/* 3. ORDERS LIST */}
                {isTabLoading ? (
                    <div className="space-y-3 font-sans">
                        {[1, 2, 3].map((i) => (
                            <div key={i} className="bg-white rounded-2xl border border-slate-200 p-4 sm:p-5 animate-pulse space-y-3">
                                <div className="flex justify-between items-center pb-2.5 border-b border-slate-100">
                                    <div className="h-4 w-36 bg-slate-200 rounded" />
                                    <div className="h-5 w-20 bg-slate-200 rounded-full" />
                                </div>
                                <div className="flex items-center justify-between gap-4">
                                    <div className="flex items-center gap-3.5 flex-1">
                                        <div className="w-14 h-14 bg-slate-200 rounded-xl shrink-0" />
                                        <div className="space-y-2 flex-1">
                                            <div className="h-4 w-1/2 bg-slate-200 rounded" />
                                            <div className="h-3 w-1/4 bg-slate-100 rounded" />
                                        </div>
                                    </div>
                                    <div className="h-9 w-28 bg-slate-200 rounded-xl shrink-0" />
                                </div>
                            </div>
                        ))}
                    </div>
                ) : filteredItems.length === 0 ? (
                    <div className="bg-white rounded-3xl p-12 text-center border border-slate-200 shadow-2xs space-y-3 font-sans">
                        <Package className="w-12 h-12 text-slate-300 mx-auto" />
                        <h4 className="text-base font-bold text-slate-800">No orders found in this category</h4>
                        <p className="text-xs text-slate-500 max-w-sm mx-auto">
                            Customer orders and logistics requests will appear here in real time.
                        </p>
                    </div>
                ) : (
                    <div className="space-y-3 font-sans">
                        {filteredItems.map((item) => {
                            const orderStatus = item.order?.status || 'pending';
                            const isSelected = selectedOrderIds.includes(item.order_id);
                            const canSelect = ['pending', 'confirmed', 'preparing', 'processing', 'placed'].includes(orderStatus);
                            const isToPack = ['placed', 'pending', 'confirmed', 'preparing', 'processing'].includes(orderStatus);
                            const grossPrice = Number(item.subtotal || (Number(item.unit_price) * item.quantity));
                            const platformFee = grossPrice * 0.10;
                            const netSettlement = grossPrice - platformFee;

                            return (
                                <div
                                    key={item.id}
                                    className={`rounded-2xl border p-4 sm:p-5 space-y-3 font-sans ${
                                        isToPack
                                            ? 'bg-rose-50/15 border-rose-200/80 border-l-[3px] border-l-[#E00D42] shadow-2xs'
                                            : 'bg-white border-slate-200/90 shadow-2xs'
                                    }`}
                                >
                                    {/* Order Top Bar */}
                                    <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 pb-2.5 border-b border-slate-100 text-xs">
                                        <div className="flex items-center gap-2 flex-wrap">
                                            {canSelect && (
                                                <input
                                                    type="checkbox"
                                                    checked={isSelected}
                                                    onChange={() => toggleSelectOrder(item.order_id)}
                                                    className="w-4 h-4 text-[#E00D42] rounded border-slate-300 focus:ring-[#E00D42] cursor-pointer"
                                                />
                                            )}
                                            <button
                                                type="button"
                                                onClick={() => setSelectedOrderForDetails(item)}
                                                className="font-bold text-slate-900 font-mono text-sm hover:text-[#E00D42] hover:underline transition cursor-pointer"
                                                title="View order details and history"
                                            >
                                                #{item.order?.order_number}
                                            </button>
                                            <span className="text-slate-400 font-mono text-[11px]">
                                                • {item.order?.created_at ? new Date(item.order.created_at).toLocaleDateString() : 'Today'}
                                            </span>
                                            <span className="text-slate-300">•</span>
                                            <div className="flex items-center gap-1.5 text-xs text-slate-600">
                                                <span>Buyer: <strong className="text-slate-800">{item.order?.recipient_name || item.order?.buyer?.name || 'Customer'}</strong></span>
                                                {renderCustomerInsight(item.order?.buyer, true)}
                                                {item.order?.buyer_id && (
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setChatOrder(item);
                                                            setChatMessage(`Hello ${item.order?.recipient_name || 'Customer'}! We are preparing your order #${item.order?.order_number}.`);
                                                            setChatSent(false);
                                                        }}
                                                        className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[#E00D42] hover:bg-rose-50 border border-transparent hover:border-rose-200 text-[11px] font-mono font-bold transition cursor-pointer ml-0.5"
                                                        title="1-Click Customer Chat"
                                                    >
                                                        <MessageSquare className="w-3 h-3" />
                                                        <span>Chat</span>
                                                    </button>
                                                )}
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-2 shrink-0">
                                            {isToPack ? (
                                                <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-rose-100 text-[#E00D42] border border-rose-300/80 text-[10px] font-mono font-bold uppercase tracking-wider shadow-2xs">
                                                    <span className="w-1.5 h-1.5 rounded-full bg-[#E00D42]" />
                                                    To Pack
                                                </span>
                                            ) : (
                                                getStatusPill(orderStatus)
                                            )}
                                        </div>
                                    </div>

                                    {/* Order Body Details */}
                                    <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                                        <div className="flex items-center gap-3.5 min-w-0">
                                            <img
                                                src={item.product?.featured_image || ''}
                                                alt=""
                                                className="w-14 h-14 rounded-xl object-cover bg-slate-100 border border-slate-200 shrink-0"
                                            />
                                            <div className="space-y-0.5 min-w-0">
                                                <h4 className="font-bold text-slate-900 text-sm truncate max-w-sm sm:max-w-md">
                                                    {item.product?.name}
                                                </h4>
                                                {(item.color || item.size) && (
                                                    <p className="text-[11px] text-slate-400 font-mono">
                                                        Variant: {[item.color, item.size].filter(Boolean).join(' / ')}
                                                    </p>
                                                )}
                                                <div className="flex items-center gap-2 text-xs font-mono text-slate-500">
                                                    <span>Qty {item.quantity}</span>
                                                    <span className="text-slate-300">•</span>
                                                    <span className="font-bold text-slate-900">{formatPrice(grossPrice)}</span>
                                                    <span className="text-slate-300">•</span>
                                                    <span className="text-emerald-600 font-medium">Net: {formatPrice(netSettlement)}</span>
                                                </div>
                                            </div>
                                        </div>

                                        {/* Action Buttons (Clean, Streamlined, Minimal) */}
                                        <div className="flex items-center gap-2 text-xs font-sans w-full sm:w-auto justify-end">
                                            
                                            {/* Print Waybill Icon Button */}
                                            <button
                                                type="button"
                                                onClick={() => setSelectedOrderForWaybill(item)}
                                                className="p-2 rounded-xl bg-white hover:bg-slate-50 text-slate-600 border border-slate-200 transition shadow-2xs cursor-pointer"
                                                title="Print Thermal Waybill"
                                            >
                                                <Printer className="w-3.5 h-3.5" />
                                            </button>

                                            {/* Cancel Icon Button (only if pre-shipped) */}
                                            {canSelect && (
                                                <button
                                                    type="button"
                                                    onClick={() => setOrderToCancel(item)}
                                                    className="p-2 rounded-xl bg-white hover:bg-rose-50 text-slate-400 hover:text-rose-600 border border-slate-200 hover:border-rose-200 transition shadow-2xs cursor-pointer"
                                                    title="Decline / Cancel Order"
                                                >
                                                    <X className="w-3.5 h-3.5" />
                                                </button>
                                            )}

                                            {/* Single Primary Action Button */}
                                            {isToPack && (
                                                <button
                                                    type="button"
                                                    onClick={() => setOrderToAcceptAndPack(item)}
                                                    className="px-4 py-2 rounded-xl bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold transition shadow-xs flex items-center gap-1.5 cursor-pointer font-mono uppercase text-xs"
                                                >
                                                    <Box className="w-3.5 h-3.5" />
                                                    <span>Pack Order</span>
                                                </button>
                                            )}

                                            {orderStatus === 'ready_for_pickup' && (
                                                <button
                                                    type="button"
                                                    onClick={() => handleHandoverOrder(item.order_id)}
                                                    disabled={isSubmitting}
                                                    className="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 active:scale-[0.98] text-white font-bold transition shadow-xs flex items-center gap-1.5 cursor-pointer font-mono uppercase text-xs"
                                                >
                                                    <Check className="w-3.5 h-3.5" />
                                                    <span>Handover</span>
                                                </button>
                                            )}

                                            {['picked_up', 'at_sorting_center', 'sorted', 'assigned_to_rider', 'out_for_delivery', 'shipped'].includes(orderStatus) && (
                                                <button
                                                    type="button"
                                                    onClick={() => copyToClipboard(item.order?.delivery?.tracking_number || item.order?.order_number)}
                                                    className="px-3 py-2 rounded-xl bg-slate-50 hover:bg-slate-100 text-slate-700 border border-slate-200 font-mono text-xs flex items-center gap-1.5 transition cursor-pointer"
                                                    title="Click to copy tracking number"
                                                >
                                                    <span>{item.order?.delivery?.tracking_number || 'Tracking'}</span>
                                                    {copiedTracking === (item.order?.delivery?.tracking_number || item.order?.order_number) ? (
                                                        <Check className="w-3 h-3 text-emerald-600" />
                                                    ) : (
                                                        <Copy className="w-3 h-3 text-slate-400" />
                                                    )}
                                                </button>
                                            )}

                                            {['delivered', 'completed'].includes(orderStatus) && (
                                                <span className="px-3 py-2 rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-200 font-bold flex items-center gap-1 font-mono text-xs">
                                                    <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" />
                                                    <span>Delivered</span>
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}

                {/* Infinite Scroll Sentinel & Loading Indicator */}
                <div ref={sentinelRef} className="py-2">
                    {isLoadingMore && (
                        <div className="flex items-center justify-center gap-2 py-4 text-xs font-mono text-slate-500">
                            <Loader2 className="w-4 h-4 animate-spin text-[#E00D42]" />
                            <span>Loading more orders...</span>
                        </div>
                    )}
                </div>

            </div>

            {/* SELLER ACCEPT & REVIEW ORDER MODAL (THE BELOVED OLDER DESIGN) */}
            {typeof document !== 'undefined' && orderToAcceptAndPack && createPortal(
                <div className="fixed inset-0 z-[9999] bg-black/80 flex items-center justify-center p-4 backdrop-blur-xs overflow-y-auto animate-fade-in font-sans">
                    <div className="bg-white text-slate-900 rounded-3xl p-6 sm:p-8 max-w-xl w-full shadow-2xl border border-slate-200 font-sans my-auto space-y-5">
                        
                        {/* Modal Header */}
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <div>
                                <div className="flex items-center gap-2 text-xs font-bold text-[#E00D42] font-mono">
                                    <Box className="w-4 h-4" />
                                    <span>Accept Order Step</span>
                                </div>
                                <h3 className="text-lg sm:text-xl font-bold text-slate-900 mt-0.5">
                                    Review & Accept Order #{orderToAcceptAndPack.order?.order_number}
                                </h3>
                                <p className="text-xs text-slate-500 font-sans">
                                    Verify items and preview the generated waybill before packing.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setOrderToAcceptAndPack(null)}
                                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition cursor-pointer"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        {/* Order Item Snapshot */}
                        <div className="p-4 bg-slate-50 border border-slate-200 rounded-2xl space-y-2 text-xs font-sans">
                            <span className="text-[11px] text-slate-400 font-semibold block">Purchased Item</span>
                            <div className="flex items-center justify-between gap-3">
                                <div className="flex items-center gap-3.5 min-w-0">
                                    <img
                                        src={orderToAcceptAndPack.product?.featured_image || ''}
                                        alt=""
                                        className="w-14 h-14 rounded-xl object-cover bg-white border border-slate-200 shrink-0"
                                    />
                                    <div className="truncate">
                                        <p className="font-bold text-slate-900 truncate text-xs">{orderToAcceptAndPack.product?.name}</p>
                                        {(orderToAcceptAndPack.color || orderToAcceptAndPack.size) && (
                                            <p className="text-slate-400 text-[11px]">
                                                Variant: {[orderToAcceptAndPack.color, orderToAcceptAndPack.size].filter(Boolean).join(' / ')}
                                            </p>
                                        )}
                                        <p className="text-slate-500 text-xs">Qty: {orderToAcceptAndPack.quantity} × {formatPrice(orderToAcceptAndPack.unit_price)}</p>
                                    </div>
                                </div>
                                <div className="text-right shrink-0">
                                    <p className="font-bold text-slate-900 text-sm">{formatPrice(Number(orderToAcceptAndPack.unit_price) * orderToAcceptAndPack.quantity)}</p>
                                </div>
                            </div>
                        </div>

                        {/* Customer & Order Insights */}
                        <div className="p-3.5 bg-slate-50 border border-slate-200 rounded-xl space-y-2 font-sans text-xs">
                            <div className="flex items-center justify-between">
                                <span className="text-[11px] text-slate-400 font-semibold block flex items-center gap-1.5">
                                    <MapPin className="w-3.5 h-3.5 text-[#E00D42]" />
                                    <span>Delivery Address & Customer</span>
                                </span>
                                {renderCustomerInsight(orderToAcceptAndPack.order?.buyer)}
                            </div>
                            <p className="font-bold text-slate-900">{orderToAcceptAndPack.order?.recipient_name} ({orderToAcceptAndPack.order?.recipient_phone})</p>
                            <p className="text-slate-600 text-xs">{orderToAcceptAndPack.order?.shipping_address}, {orderToAcceptAndPack.order?.shipping_city}</p>
                        </div>

                        {/* Thermal Waybill Preview Box */}
                        <div className="p-4 border-2 border-dashed border-slate-300 rounded-2xl space-y-3 text-xs bg-slate-50/50 font-sans">
                            <div className="flex items-center justify-between border-b border-slate-300 pb-2">
                                <div>
                                    <h4 className="text-sm font-black text-[#E00D42] tracking-tighter">Bagoo<span className="text-slate-900">EXPRESS</span></h4>
                                    <p className="text-[9px] text-slate-500 font-mono">THERMAL WAYBILL PREVIEW</p>
                                </div>
                                <span className="px-2 py-0.5 rounded bg-black text-white font-bold text-[10px] uppercase font-mono">
                                    {orderToAcceptAndPack.order?.payment_method?.toUpperCase() || 'COD'}
                                </span>
                            </div>

                            {/* Barcode Mock */}
                            <div className="text-center space-y-0.5">
                                <div className="h-7 bg-slate-900 mx-auto flex items-center justify-center text-white tracking-[4px] text-[10px] font-black select-none font-mono">
                                    ||| | |||| | ||| |||| | ||| |||| |
                                </div>
                                <p className="text-[10px] font-mono text-slate-600">
                                    {orderToAcceptAndPack.order?.delivery?.tracking_number || `BGO-WAYBILL-${orderToAcceptAndPack.order?.order_number}`}
                                </p>
                            </div>

                            {/* Origin & Destination */}
                            <div className="grid grid-cols-2 gap-3 border-t border-slate-300 pt-2 text-[10px]">
                                <div>
                                    <span className="font-bold text-slate-400 block uppercase text-[9px]">FROM (SENDER):</span>
                                    <p className="font-bold text-slate-900 truncate">{shop.name}</p>
                                    <p className="text-slate-500 truncate">{shop.city || 'Metro Manila'}</p>
                                </div>
                                <div className="border-l border-slate-200 pl-2">
                                    <span className="font-bold text-slate-400 block uppercase text-[9px]">TO (RECIPIENT):</span>
                                    <p className="font-bold text-slate-900 truncate">{orderToAcceptAndPack.order?.recipient_name || 'Customer'}</p>
                                    <p className="text-slate-500 truncate">{orderToAcceptAndPack.order?.shipping_city || 'Metro Manila'}</p>
                                </div>
                            </div>
                        </div>

                        {/* Action Buttons */}
                        <div className="grid grid-cols-2 gap-3 pt-2 font-sans text-xs">
                            <button
                                type="button"
                                onClick={() => setOrderToAcceptAndPack(null)}
                                className="py-3 px-4 rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-100 font-semibold transition text-center cursor-pointer"
                            >
                                Review Later
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    handlePackOrder(orderToAcceptAndPack.order_id);
                                    setOrderToAcceptAndPack(null);
                                }}
                                className="py-3 px-4 rounded-xl bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold transition text-center shadow-md flex items-center justify-center gap-1.5 cursor-pointer font-mono uppercase"
                            >
                                <Box className="w-4 h-4" />
                                <span>Confirm & Pack Order</span>
                            </button>
                        </div>
                    </div>
                </div>,
                document.body
            )}

            {/* 1-CLICK CUSTOMER CHAT MODAL */}
            {typeof document !== 'undefined' && chatOrder && createPortal(
                <div className="fixed inset-0 z-[9999] bg-black/80 flex items-center justify-center p-4 backdrop-blur-xs overflow-y-auto animate-fade-in font-sans">
                    <div className="bg-white text-slate-900 rounded-3xl p-6 sm:p-7 max-w-md w-full shadow-2xl border border-slate-200 my-auto space-y-4 font-sans">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <div className="flex items-center gap-2 text-[#E00D42] text-xs font-bold font-mono">
                                <MessageSquare className="w-4 h-4" />
                                <span>Customer Chat • #{chatOrder.order?.order_number}</span>
                            </div>
                            <button
                                type="button"
                                onClick={() => { setChatOrder(null); setChatMessage(''); setChatSent(false); }}
                                className="p-1 rounded-lg text-slate-400 hover:text-slate-700 transition cursor-pointer"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        {/* Customer info preview */}
                        <div className="p-3 bg-slate-50 border border-slate-200 rounded-xl space-y-1.5 text-xs">
                            <div className="flex items-center justify-between">
                                <span className="font-bold text-slate-900 text-sm">
                                    {chatOrder.order?.recipient_name || chatOrder.order?.buyer?.name || 'Customer'}
                                </span>
                                <span className="text-[11px] text-slate-500 font-mono">
                                    {chatOrder.order?.shipping_city || 'Metro Manila'}
                                </span>
                            </div>
                            {renderCustomerInsight(chatOrder.order?.buyer)}
                        </div>

                        {/* Quick Templates */}
                        <div className="space-y-1.5">
                            <label className="text-[11px] font-bold text-slate-500 uppercase tracking-wider font-mono block">
                                Quick Templates
                            </label>
                            <div className="flex flex-col gap-1.5">
                                {[
                                    `Hello ${chatOrder.order?.recipient_name || 'Customer'}! We are currently packing your order #${chatOrder.order?.order_number} and preparing it for courier pickup.`,
                                    `Hi! Your parcel #${chatOrder.order?.order_number} is packed and scheduled for courier collection today.`,
                                    `Hello! Could you please verify your delivery address or nearest landmark for order #${chatOrder.order?.order_number}?`,
                                ].map((template, idx) => (
                                    <button
                                        key={idx}
                                        type="button"
                                        onClick={() => setChatMessage(template)}
                                        className="text-left p-2 rounded-lg bg-slate-50 hover:bg-slate-100 border border-slate-200 text-slate-700 text-xs transition cursor-pointer leading-snug"
                                    >
                                        "{template}"
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* Message input */}
                        <form onSubmit={handleSendQuickChat} className="space-y-3">
                            <div className="space-y-1">
                                <label className="text-[11px] font-bold text-slate-500 uppercase tracking-wider font-mono block">
                                    Your Message
                                </label>
                                <textarea
                                    value={chatMessage}
                                    onChange={(e) => setChatMessage(e.target.value)}
                                    rows={3}
                                    placeholder="Type a message to the customer..."
                                    className="w-full bg-slate-50 border border-slate-200 rounded-xl p-3 text-xs text-slate-800 focus:ring-2 focus:ring-[#E00D42]/20 focus:border-[#E00D42]"
                                />
                            </div>

                            {chatSent && (
                                <div className="p-2.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs flex items-center gap-2 font-medium">
                                    <Check className="w-4 h-4 text-emerald-600" />
                                    <span>Message delivered to customer inbox.</span>
                                </div>
                            )}

                            <div className="flex items-center justify-between gap-3 pt-2">
                                <Link
                                    href={route('seller.messages.index')}
                                    className="text-xs text-slate-500 hover:text-slate-800 underline font-mono"
                                >
                                    Open Full Inbox
                                </Link>
                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={() => { setChatOrder(null); setChatMessage(''); setChatSent(false); }}
                                        className="py-2 px-3.5 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 text-xs font-semibold cursor-pointer"
                                    >
                                        Close
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={isSubmitting || !chatMessage.trim()}
                                        className="py-2 px-4 rounded-xl bg-[#E00D42] hover:bg-[#C20836] disabled:opacity-50 text-white text-xs font-bold transition shadow-xs flex items-center gap-1.5 cursor-pointer font-mono"
                                    >
                                        <Send className="w-3.5 h-3.5" />
                                        <span>Send</span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>,
                document.body
            )}

            {/* ORDER DETAILS & CHECKPOINT MODAL */}
            {typeof document !== 'undefined' && selectedOrderForDetails && createPortal(
                <div className="fixed inset-0 z-[9999] bg-black/80 flex items-center justify-center p-4 backdrop-blur-xs overflow-y-auto animate-fade-in font-sans">
                    <div className="bg-white text-slate-900 rounded-3xl p-6 sm:p-7 max-w-lg w-full shadow-2xl border border-slate-200 my-auto space-y-4 font-sans">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <div className="flex items-center gap-2 text-slate-800 text-xs font-bold font-mono">
                                <FileText className="w-4 h-4 text-[#E00D42]" />
                                <span>Order Details #{selectedOrderForDetails.order?.order_number}</span>
                            </div>
                            <button
                                type="button"
                                onClick={() => setSelectedOrderForDetails(null)}
                                className="p-1 rounded-lg text-slate-400 hover:text-slate-700 transition cursor-pointer"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        {/* Customer & Address Details */}
                        <div className="p-3.5 bg-slate-50 border border-slate-200 rounded-xl space-y-1.5 text-xs">
                            <div className="flex items-center justify-between">
                                <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block font-sans">Customer Information</span>
                                {renderCustomerInsight(selectedOrderForDetails.order?.buyer)}
                            </div>
                            <p className="font-bold text-slate-900">{selectedOrderForDetails.order?.recipient_name || selectedOrderForDetails.order?.buyer?.name}</p>
                            <p className="text-slate-600">{selectedOrderForDetails.order?.shipping_address}, {selectedOrderForDetails.order?.shipping_city}</p>
                            <p className="text-slate-600 font-mono text-[11px]">{selectedOrderForDetails.order?.recipient_phone || selectedOrderForDetails.order?.buyer?.phone}</p>
                        </div>

                        {/* Financial Breakdown */}
                        <div className="p-3.5 bg-slate-50 border border-slate-200 rounded-xl space-y-2 text-xs font-mono">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block font-sans">Financial Breakdown</span>
                            <div className="flex justify-between text-slate-600">
                                <span>Gross Item Subtotal:</span>
                                <span>{formatPrice(selectedOrderForDetails.subtotal || (Number(selectedOrderForDetails.unit_price) * selectedOrderForDetails.quantity))}</span>
                            </div>
                            <div className="flex justify-between text-slate-600">
                                <span>Platform Commission (10%):</span>
                                <span className="text-rose-600">-{formatPrice(Number(selectedOrderForDetails.subtotal || (Number(selectedOrderForDetails.unit_price) * selectedOrderForDetails.quantity)) * 0.10)}</span>
                            </div>
                            <div className="border-t border-slate-200 pt-2 flex justify-between font-bold text-slate-900 text-sm">
                                <span>Net Seller Settlement (90%):</span>
                                <span className="text-emerald-600">{formatPrice(Number(selectedOrderForDetails.subtotal || (Number(selectedOrderForDetails.unit_price) * selectedOrderForDetails.quantity)) * 0.90)}</span>
                            </div>
                        </div>

                        {/* Logistics Checkpoints Timeline if any */}
                        {selectedOrderForDetails.order?.delivery?.checkpoints && selectedOrderForDetails.order.delivery.checkpoints.length > 0 && (
                            <div className="space-y-2 text-xs">
                                <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block font-sans">Logistics Checkpoints</span>
                                <div className="space-y-2 pl-2 border-l-2 border-slate-200 font-sans">
                                    {selectedOrderForDetails.order.delivery.checkpoints.map((cp: any) => (
                                        <div key={cp.id} className="space-y-0.5 text-xs">
                                            <p className="font-bold text-slate-800">{cp.location_name} — {cp.checkpoint_type}</p>
                                            <p className="text-slate-500 text-[11px]">{cp.notes || 'Status verified'}</p>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        <div className="pt-2 flex justify-end">
                            <button
                                type="button"
                                onClick={() => setSelectedOrderForDetails(null)}
                                className="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition cursor-pointer"
                            >
                                Close
                            </button>
                        </div>
                    </div>
                </div>,
                document.body
            )}

            {/* CANCEL ORDER REASON MODAL */}
            {typeof document !== 'undefined' && orderToCancel && createPortal(
                <div className="fixed inset-0 z-[9999] bg-black/80 flex items-center justify-center p-4 backdrop-blur-xs overflow-y-auto animate-fade-in font-sans">
                    <form onSubmit={handleConfirmCancel} className="bg-white text-slate-900 rounded-3xl p-6 sm:p-7 max-w-md w-full shadow-2xl border border-slate-200 my-auto space-y-4 font-sans">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <div className="flex items-center gap-2 text-rose-600 text-xs font-bold font-mono">
                                <Ban className="w-4 h-4" />
                                <span>Cancel Order #{orderToCancel.order?.order_number}</span>
                            </div>
                            <button
                                type="button"
                                onClick={() => setOrderToCancel(null)}
                                className="p-1 rounded-lg text-slate-400 hover:text-slate-700 transition cursor-pointer"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <div className="space-y-3 text-xs">
                            <div>
                                <label className="block text-slate-700 font-semibold mb-1">Reason for Cancellation</label>
                                <select
                                    value={cancelReason}
                                    onChange={(e) => setCancelReason(e.target.value)}
                                    className="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-xs text-slate-800 focus:ring-2 focus:ring-rose-200"
                                >
                                    <option value="Out of stock / Inventory shortage">Out of stock / Inventory shortage</option>
                                    <option value="Damaged item during inspection">Damaged item during inspection</option>
                                    <option value="Pricing / Listing discrepancy">Pricing / Listing discrepancy</option>
                                    <option value="Buyer requested cancellation via chat">Buyer requested cancellation via chat</option>
                                    <option value="Unable to deliver to destination">Unable to deliver to destination</option>
                                    <option value="Other reason">Other reason</option>
                                </select>
                            </div>

                            <div>
                                <label className="block text-slate-700 font-semibold mb-1">Additional Notes (Optional)</label>
                                <textarea
                                    value={cancelNotes}
                                    onChange={(e) => setCancelNotes(e.target.value)}
                                    rows={3}
                                    placeholder="Explain the reason for cancellation..."
                                    className="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-xs text-slate-800 focus:ring-2 focus:ring-rose-200"
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-2.5 pt-2">
                            <button
                                type="button"
                                onClick={() => setOrderToCancel(null)}
                                className="py-2.5 px-4 rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50 font-semibold text-xs transition text-center cursor-pointer"
                            >
                                Keep Order
                            </button>
                            <button
                                type="submit"
                                disabled={isSubmitting}
                                className="py-2.5 px-4 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs transition text-center shadow-xs cursor-pointer"
                            >
                                Confirm Cancel
                            </button>
                        </div>
                    </form>
                </div>,
                document.body
            )}

            {/* PRINTABLE THERMAL WAYBILL / SHIPPING LABEL MODAL */}
            {typeof document !== 'undefined' && selectedOrderForWaybill && createPortal(
                <div className="fixed inset-0 z-[9999] bg-black/80 flex items-center justify-center p-4 backdrop-blur-xs animate-fade-in print:p-0 print:bg-white overflow-y-auto font-sans">
                    <div className="bg-white text-slate-900 rounded-3xl p-6 sm:p-7 max-w-lg w-full shadow-2xl border border-slate-300 font-sans relative my-auto print:border-none print:shadow-none print:w-full print:max-w-none">
                        
                        {/* Modal Top Bar */}
                        <div className="flex items-center justify-between pb-3 border-b border-slate-200 print:hidden text-xs">
                            <div className="flex items-center gap-2 font-semibold text-slate-800">
                                <Printer className="w-4 h-4 text-[#E00D42]" />
                                <span>Bagoo Express Standard Thermal Waybill</span>
                            </div>
                            <button onClick={() => setSelectedOrderForWaybill(null)} className="text-slate-400 hover:text-slate-700 p-1 rounded-lg cursor-pointer">
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        {/* Thermal Document Content */}
                        <div className="p-4 border-2 border-dashed border-slate-400 rounded-2xl space-y-3.5 my-3 text-xs bg-white font-sans">
                            
                            {/* Header */}
                            <div className="flex items-center justify-between border-b-2 border-slate-900 pb-2.5">
                                <div>
                                    <h2 className="text-lg font-black tracking-tighter text-[#E00D42]">Bagoo<span className="text-slate-900">EXPRESS</span></h2>
                                    <p className="text-[9px] text-slate-500 uppercase tracking-widest font-mono">STANDARD COURIER DISPATCH</p>
                                </div>
                                <div className="text-right">
                                    <span className="px-2 py-0.5 rounded bg-black text-white font-black text-xs uppercase font-mono">
                                        {selectedOrderForWaybill.order?.payment_method?.toUpperCase() || 'COD'}
                                    </span>
                                    <p className="text-[10px] text-slate-700 font-mono font-bold mt-0.5">
                                        AMOUNT: {formatPrice(selectedOrderForWaybill.order?.total_amount || selectedOrderForWaybill.subtotal)}
                                    </p>
                                </div>
                            </div>

                            {/* Barcode Simulation */}
                            <div className="py-1.5 border-b border-slate-300 text-center space-y-1">
                                <div className="h-9 bg-slate-900 mx-auto flex items-center justify-center text-white tracking-[5px] text-xs font-black select-none font-mono">
                                    ||| | |||| | ||| |||| | ||| |||| |
                                </div>
                                <p className="text-xs font-bold font-mono tracking-wider text-slate-800">
                                    {selectedOrderForWaybill.order?.delivery?.tracking_number || `BGO-WAYBILL-${selectedOrderForWaybill.order?.order_number}`}
                                </p>
                            </div>

                            {/* Origin & Destination */}
                            <div className="grid grid-cols-2 gap-3 border-b border-slate-300 pb-3 text-[11px]">
                                <div className="space-y-0.5">
                                    <span className="text-[9px] font-bold text-slate-400 uppercase block font-mono">FROM (MERCHANT):</span>
                                    <p className="font-bold text-slate-900">{shop.name}</p>
                                    <p className="text-slate-600 text-[10px]">{shop.address || 'Artisan Hub'}, {shop.city || 'Metro Manila'}</p>
                                    <p className="text-slate-600 text-[10px]">{shop.phone || '+63 912 345 6789'}</p>
                                </div>

                                <div className="space-y-0.5 border-l border-slate-200 pl-3">
                                    <span className="text-[9px] font-bold text-slate-400 uppercase block font-mono">TO (BUYER):</span>
                                    <p className="font-bold text-slate-900">{selectedOrderForWaybill.order?.recipient_name || selectedOrderForWaybill.order?.buyer?.name || 'Customer'}</p>
                                    <p className="text-slate-600 text-[10px]">{selectedOrderForWaybill.order?.shipping_address || 'Customer Address'}, {selectedOrderForWaybill.order?.shipping_city || 'Metro Manila'}</p>
                                    <p className="text-slate-600 text-[10px]">{selectedOrderForWaybill.order?.recipient_phone || selectedOrderForWaybill.order?.buyer?.phone || '+63 900 000 0000'}</p>
                                </div>
                            </div>

                            {/* Package Breakdown */}
                            <div className="space-y-1 text-[11px]">
                                <span className="text-[9px] font-bold text-slate-400 uppercase block font-mono">PACKAGE CONTENTS:</span>
                                <div className="flex justify-between items-center bg-slate-50 p-2 rounded-lg border border-slate-200">
                                    <span className="font-bold truncate max-w-[260px] text-slate-900">{selectedOrderForWaybill.product?.name}</span>
                                    <span className="font-bold text-slate-900 font-mono">Qty: {selectedOrderForWaybill.quantity}</span>
                                </div>
                            </div>
                        </div>

                        {/* Modal Action Controls */}
                        <div className="flex items-center justify-end gap-2.5 pt-2 print:hidden text-xs">
                            <button
                                type="button"
                                onClick={() => setSelectedOrderForWaybill(null)}
                                className="px-4 py-2 font-semibold text-slate-600 hover:bg-slate-100 rounded-xl transition cursor-pointer"
                            >
                                Close
                            </button>
                            <button
                                type="button"
                                onClick={() => window.print()}
                                className="px-5 py-2 font-bold bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white rounded-xl shadow-xs flex items-center gap-1.5 transition cursor-pointer font-mono"
                            >
                                <Printer className="w-4 h-4" />
                                <span>Print Thermal Label</span>
                            </button>
                        </div>
                    </div>
                </div>,
                document.body
            )}
        </DashboardLayout>
    );
}
