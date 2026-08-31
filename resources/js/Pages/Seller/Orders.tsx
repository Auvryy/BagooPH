import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Order, OrderItem, PaginatedData, Shop } from '@/types';
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
    User, 
    X,
    QrCode,
    Barcode,
    Search,
    ShieldCheck,
    Check,
    ChevronRight,
    Tag
} from 'lucide-react';

interface Props {
    orderItems: PaginatedData<OrderItem & {
        order: Order;
    }>;
    shop: Shop;
    currentStatus?: string;
}

export default function SellerOrders({ orderItems, shop, currentStatus = 'all' }: Props) {
    const [selectedOrderForWaybill, setSelectedOrderForWaybill] = useState<any | null>(null);
    const [searchQuery, setSearchQuery] = useState('');

    const formatPrice = (val: string | number | undefined | null) => {
        const num = Number(val || 0);
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(num);
    };

    const handlePackOrder = (orderId: number) => {
        router.post(route('seller.orders.pack', orderId), {}, { preserveScroll: true });
    };

    const handleSchedulePickup = (orderId: number) => {
        router.post(route('seller.orders.ready', orderId), {}, { preserveScroll: true });
    };

    const getStatusPill = (status: string) => {
        switch (status) {
            case 'pending':
                return <span className="px-2.5 py-1 rounded-lg bg-amber-50 text-amber-700 border border-amber-200 text-[11px] font-bold font-mono uppercase flex items-center gap-1"><Clock className="w-3 h-3" /> New Order (To Pack)</span>;
            case 'processing':
                return <span className="px-2.5 py-1 rounded-lg bg-blue-50 text-blue-700 border border-blue-200 text-[11px] font-bold font-mono uppercase flex items-center gap-1"><Box className="w-3 h-3" /> Packed (Ready for Dispatch)</span>;
            case 'ready_for_pickup':
                return <span className="px-2.5 py-1 rounded-lg bg-indigo-50 text-indigo-700 border border-indigo-200 text-[11px] font-bold font-mono uppercase flex items-center gap-1"><Box className="w-3 h-3" /> Ready for Pickup</span>;
            case 'shipped':
                return <span className="px-2.5 py-1 rounded-lg bg-blue-50 text-blue-700 border border-blue-200 text-[11px] font-bold font-mono uppercase flex items-center gap-1"><Truck className="w-3 h-3" /> In Transit</span>;
            case 'delivered':
                return <span className="px-2.5 py-1 rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-200 text-[11px] font-bold font-mono uppercase flex items-center gap-1"><CheckCircle2 className="w-3 h-3" /> Delivered & Completed</span>;
            default:
                return <span className="px-2.5 py-1 rounded-lg bg-slate-100 text-slate-700 text-[11px] font-bold font-mono uppercase">{status}</span>;
        }
    };

    const filteredItems = orderItems.data.filter(item => {
        if (!searchQuery.trim()) return true;
        const q = searchQuery.toLowerCase();
        return (
            item.order?.order_number.toLowerCase().includes(q) ||
            (item.product?.name && item.product.name.toLowerCase().includes(q)) ||
            (item.order?.recipient_name && item.order.recipient_name.toLowerCase().includes(q))
        );
    });

    return (
        <DashboardLayout
            title="Order Fulfillment & Waybill Dispatch Hub"
            subtitle="Pack packages, generate thermal shipping labels, and track couriers"
        >
            <Head title="Order Management — BagooPH Seller" />

            {/* PRINTABLE WAYBILL / SHIPPING LABEL MODAL */}
            {selectedOrderForWaybill && (
                <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4 backdrop-blur-xs animate-fade-in print:p-0 print:bg-white">
                    <div className="bg-white text-slate-900 rounded-3xl p-6 sm:p-8 max-w-xl w-full shadow-2xl border border-slate-300 font-sans relative print:border-none print:shadow-none print:w-full print:max-w-none">
                        
                        {/* Close button (Hidden during print) */}
                        <div className="flex items-center justify-between pb-3 border-b border-slate-200 print:hidden">
                            <div className="flex items-center gap-2 text-xs font-mono font-bold text-slate-800 uppercase">
                                <Printer className="w-4 h-4 text-[#E00D42]" />
                                <span>Bagoo Express Thermal Waybill / Shipping Label</span>
                            </div>
                            <button onClick={() => setSelectedOrderForWaybill(null)} className="text-slate-400 hover:text-slate-700">
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        {/* Thermal Label Document Layout */}
                        <div className="p-4 border-2 border-dashed border-slate-400 rounded-2xl space-y-4 my-3 font-mono text-xs bg-white">
                            
                            {/* Label Header */}
                            <div className="flex items-center justify-between border-b-2 border-slate-900 pb-3">
                                <div>
                                    <h2 className="text-xl font-black tracking-tighter text-[#E00D42]">Bagoo<span className="text-slate-900">EXPRESS</span></h2>
                                    <p className="text-[10px] text-slate-500">STANDARD DOORSTEP DISPATCH</p>
                                </div>
                                <div className="text-right">
                                    <span className="px-2 py-0.5 rounded bg-black text-white font-black text-xs uppercase">
                                        {selectedOrderForWaybill.order?.payment_method?.toUpperCase() || 'COD'}
                                    </span>
                                    <p className="text-[10px] text-slate-600 mt-0.5">COLLECT: {formatPrice(selectedOrderForWaybill.order?.total_amount)}</p>
                                </div>
                            </div>

                            {/* Tracking Barcode Simulation */}
                            <div className="py-2 border-b border-slate-300 text-center space-y-1">
                                <div className="h-10 bg-slate-900 mx-auto flex items-center justify-center text-white tracking-[6px] font-mono text-xs font-black select-none">
                                    ||| | |||| | ||| |||| | ||| |||| |
                                </div>
                                <p className="text-xs font-black tracking-wider">
                                    {selectedOrderForWaybill.order?.delivery?.tracking_number || `BGO-WAYBILL-${selectedOrderForWaybill.order?.order_number}`}
                                </p>
                            </div>

                            {/* Origin & Destination Grid */}
                            <div className="grid grid-cols-2 gap-4 border-b border-slate-300 pb-3 text-[11px]">
                                <div className="space-y-1">
                                    <span className="text-[9px] font-bold text-slate-400 uppercase block">SENDER (ORIGIN):</span>
                                    <p className="font-bold text-slate-900">{shop.name}</p>
                                    <p className="text-slate-600 text-[10px]">{shop.address || 'Artisan Hub'}, {shop.city || 'Metro Manila'}</p>
                                    <p className="text-slate-600 text-[10px]">{shop.phone || '+63 912 345 6789'}</p>
                                </div>

                                <div className="space-y-1 border-l border-slate-200 pl-3">
                                    <span className="text-[9px] font-bold text-slate-400 uppercase block">RECIPIENT (DESTINATION):</span>
                                    <p className="font-bold text-slate-900">{selectedOrderForWaybill.order?.recipient_name || selectedOrderForWaybill.order?.buyer?.name || 'Customer'}</p>
                                    <p className="text-slate-600 text-[10px]">{selectedOrderForWaybill.order?.shipping_address || 'Delivery Address'}, {selectedOrderForWaybill.order?.shipping_city || 'Metro Manila'}</p>
                                    <p className="text-slate-600 text-[10px]">{selectedOrderForWaybill.order?.recipient_phone || selectedOrderForWaybill.order?.buyer?.phone || '+63 900 000 0000'}</p>
                                </div>
                            </div>

                            {/* Item Details */}
                            <div className="space-y-1 text-[11px]">
                                <span className="text-[9px] font-bold text-slate-400 uppercase block">PACKAGE CONTENTS:</span>
                                <div className="flex justify-between items-center bg-slate-50 p-2 rounded-lg border border-slate-200">
                                    <span className="font-bold truncate max-w-[280px] text-slate-900">{selectedOrderForWaybill.product?.name}</span>
                                    <span className="font-bold text-slate-900">Qty: {selectedOrderForWaybill.quantity}</span>
                                </div>
                            </div>
                        </div>

                        {/* Print Controls */}
                        <div className="flex items-center justify-end gap-3 pt-3 print:hidden">
                            <button
                                type="button"
                                onClick={() => setSelectedOrderForWaybill(null)}
                                className="px-4 py-2 text-xs font-mono font-bold text-slate-600 hover:bg-slate-100 rounded-xl uppercase"
                            >
                                Close
                            </button>
                            <button
                                type="button"
                                onClick={() => window.print()}
                                className="px-5 py-2 text-xs font-mono font-bold bg-[#E00D42] hover:bg-[#C20836] text-white rounded-xl uppercase shadow-md flex items-center gap-1.5"
                            >
                                <Printer className="w-4 h-4" />
                                <span>Print Thermal Label</span>
                            </button>
                        </div>
                    </div>
                </div>
            )}

            <div className="space-y-6 font-sans">
                
                {/* 1. STATUS FILTER TABS STRIP */}
                <div className="bg-white rounded-2xl p-1.5 border border-slate-200/90 shadow-2xs flex items-center gap-2 overflow-x-auto scrollbar-none font-mono text-xs">
                    <Link
                        href={route('seller.orders.index', { status: 'all' })}
                        className={`flex-1 py-2.5 px-4 rounded-xl font-bold uppercase transition text-center whitespace-nowrap ${
                            currentStatus === 'all' ? 'bg-[#E00D42] text-white shadow-xs' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
                        }`}
                    >
                        All Orders ({orderItems.total ?? orderItems.data.length})
                    </Link>
                    <Link
                        href={route('seller.orders.index', { status: 'to_pack' })}
                        className={`flex-1 py-2.5 px-4 rounded-xl font-bold uppercase transition text-center whitespace-nowrap ${
                            currentStatus === 'to_pack' ? 'bg-[#E00D42] text-white shadow-xs' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
                        }`}
                    >
                        To Pack
                    </Link>
                    <Link
                        href={route('seller.orders.index', { status: 'to_pickup' })}
                        className={`flex-1 py-2.5 px-4 rounded-xl font-bold uppercase transition text-center whitespace-nowrap ${
                            currentStatus === 'to_pickup' ? 'bg-[#E00D42] text-white shadow-xs' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
                        }`}
                    >
                        Ready for Pickup
                    </Link>
                    <Link
                        href={route('seller.orders.index', { status: 'in_transit' })}
                        className={`flex-1 py-2.5 px-4 rounded-xl font-bold uppercase transition text-center whitespace-nowrap ${
                            currentStatus === 'in_transit' ? 'bg-[#E00D42] text-white shadow-xs' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
                        }`}
                    >
                        In Transit
                    </Link>
                    <Link
                        href={route('seller.orders.index', { status: 'delivered' })}
                        className={`flex-1 py-2.5 px-4 rounded-xl font-bold uppercase transition text-center whitespace-nowrap ${
                            currentStatus === 'delivered' ? 'bg-[#E00D42] text-white shadow-xs' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
                        }`}
                    >
                        Completed
                    </Link>
                </div>

                {/* 2. SEARCH & LIST HEADER */}
                <div className="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/90 shadow-2xs flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div className="flex items-center gap-2 font-mono text-xs text-slate-500">
                        <Package className="w-4 h-4 text-[#E00D42]" />
                        <span>Showing <strong>{filteredItems.length}</strong> fulfillment packages</span>
                    </div>

                    <div className="w-full sm:w-80 relative font-mono text-xs">
                        <input
                            type="text"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            placeholder="Search by Order # or Buyer..."
                            className="w-full pl-9 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 text-xs focus:ring-1 focus:ring-[#E00D42]"
                        />
                        <Search className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" />
                    </div>
                </div>

                {/* 3. ORDERS TABLE & ACTION CARDS */}
                {filteredItems.length === 0 ? (
                    <div className="bg-white rounded-2xl p-12 text-center border border-slate-200 shadow-2xs space-y-3">
                        <Package className="w-12 h-12 text-slate-300 mx-auto" />
                        <h4 className="text-base font-bold text-slate-800">No customer orders in this category</h4>
                        <p className="text-xs text-slate-500 font-mono">Incoming purchases from buyers will appear here in real-time.</p>
                    </div>
                ) : (
                    <div className="space-y-4">
                        {filteredItems.map((item) => (
                            <div
                                key={item.id}
                                className="bg-white rounded-2xl border border-slate-200/90 p-5 sm:p-6 shadow-2xs hover:shadow-sm transition space-y-4 font-sans"
                            >
                                {/* Order Top Header */}
                                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 pb-3 border-b border-slate-100 font-mono text-xs">
                                    <div className="space-y-0.5">
                                        <div className="flex items-center gap-2">
                                            <span className="font-bold text-slate-900 text-sm">
                                                Order #{item.order?.order_number}
                                            </span>
                                            <span className="text-slate-400">
                                                • {item.order?.created_at ? new Date(item.order.created_at).toLocaleDateString() : 'Today'}
                                            </span>
                                        </div>
                                        <p className="text-slate-500 text-[11px]">
                                            Buyer: <strong className="text-slate-800">{item.order?.buyer?.name || 'Verified Shopper'}</strong> ({item.order?.shipping_city || 'Metro Manila'})
                                        </p>
                                    </div>

                                    <div className="flex items-center gap-3">
                                        {getStatusPill(item.order?.status || 'processing')}
                                    </div>
                                </div>

                                {/* Item Info */}
                                <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                                    <div className="flex items-center gap-4 min-w-0">
                                        <img
                                            src={item.product?.featured_image || ''}
                                            alt=""
                                            className="w-16 h-16 rounded-xl object-cover bg-slate-100 border border-slate-200 shrink-0"
                                        />
                                        <div className="space-y-1">
                                            <h4 className="font-bold text-slate-900 text-sm line-clamp-1">{item.product?.name}</h4>
                                            <p className="text-xs font-mono text-slate-500">
                                                Quantity: {item.quantity} × {formatPrice(item.unit_price)}
                                            </p>
                                            <p className="text-[11px] font-mono font-bold text-emerald-600">
                                                Gross Payout: {formatPrice(item.subtotal)}
                                            </p>
                                        </div>
                                    </div>

                                    {/* Action Buttons Workflow */}
                                    <div className="flex flex-wrap items-center gap-2.5 font-mono text-xs w-full sm:w-auto">
                                        {/* Print Waybill button */}
                                        <button
                                            type="button"
                                            onClick={() => setSelectedOrderForWaybill(item)}
                                            className="px-3.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 border border-slate-200 font-bold uppercase transition flex items-center gap-1.5 shadow-2xs"
                                        >
                                            <Printer className="w-3.5 h-3.5 text-[#E00D42]" />
                                            <span>Waybill Label</span>
                                        </button>

                                        {/* Step 1: Pack Order */}
                                        {item.order?.status === 'pending' && (
                                            <button
                                                type="button"
                                                onClick={() => handlePackOrder(item.order_id)}
                                                className="px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-bold uppercase transition shadow-xs flex items-center gap-1.5"
                                            >
                                                <Box className="w-3.5 h-3.5" />
                                                <span>Pack Order</span>
                                            </button>
                                        )}

                                        {/* Step 2: Schedule Courier Pickup */}
                                        {item.order?.status === 'processing' && (
                                            <button
                                                type="button"
                                                onClick={() => handleSchedulePickup(item.order_id)}
                                                className="px-4 py-2 rounded-xl bg-[#E00D42] hover:bg-[#C20836] text-white font-bold uppercase transition shadow-xs flex items-center gap-1.5"
                                            >
                                                <Truck className="w-3.5 h-3.5" />
                                                <span>Schedule Courier Pickup</span>
                                            </button>
                                        )}

                                        {item.order?.status === 'ready_for_pickup' && (
                                            <span className="px-3.5 py-2 rounded-xl bg-indigo-50 text-indigo-700 border border-indigo-200 font-bold uppercase flex items-center gap-1.5">
                                                <Clock className="w-3.5 h-3.5" />
                                                <span>Courier Collection Pending</span>
                                            </span>
                                        )}

                                        {item.order?.status === 'shipped' && (
                                            <span className="px-3.5 py-2 rounded-xl bg-blue-50 text-blue-700 border border-blue-200 font-bold uppercase flex items-center gap-1.5">
                                                <Truck className="w-3.5 h-3.5" />
                                                <span>Out with Courier</span>
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}

            </div>
        </DashboardLayout>
    );
}
