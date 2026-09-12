import React, { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { QRCodeSVG } from 'qrcode.react';
import Barcode from '@/Components/Barcode';
import BagooLogo from '@/Components/BagooLogo';
import { PageProps } from '@/types';
import {
    Search,
    Package,
    Truck,
    CheckCircle2,
    Clock,
    MapPin,
    Building2,
    ShieldCheck,
    Copy,
    Check,
    AlertCircle,
    ArrowRight,
    User as UserIcon,
    Box,
    Layers,
    ChevronRight,
    QrCode,
    Sparkles,
    Phone,
    Info
} from 'lucide-react';

interface Checkpoint {
    id: number;
    checkpoint_type: string;
    location_name: string;
    notes?: string;
    created_at: string;
}

interface ParcelItem {
    id: number;
    product_name: string;
    featured_image: string;
    quantity: number;
    color?: string;
    size?: string;
    unit_price?: number | null;
}

interface ParcelData {
    id: number;
    tracking_number: string;
    status: string;
    order_number?: string;
    order_status?: string;
    payment_method: string;
    payment_status: string;
    total_amount: number;
    pickup_store_name: string;
    pickup_address: string;
    delivery_recipient_name: string;
    delivery_phone: string;
    delivery_address: string;
    estimated_delivery_at: string;
    assigned_at?: string;
    picked_up_at?: string;
    delivered_at?: string;
    courier_name: string;
    courier_vehicle: string;
    checkpoints: Checkpoint[];
    items: ParcelItem[];
}

interface AvailableAction {
    action: string;
    label: string;
    description: string;
    variant: 'primary' | 'success' | 'info' | 'warning' | 'secondary';
}

interface Props {
    parcel: ParcelData | null;
    searchedNumber: string;
    notFound: boolean;
    availableActions: AvailableAction[];
}

export default function Tracking({ parcel, searchedNumber, notFound, availableActions }: Props) {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user;

    const [inputNumber, setInputNumber] = useState(searchedNumber || '');
    const [copiedTracking, setCopiedTracking] = useState(false);
    const [isExecutingAction, setIsExecutingAction] = useState<string | null>(null);

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        const query = inputNumber.trim();
        if (query) {
            router.get(route('track.show', query));
        }
    };

    const handleCopy = (text: string) => {
        navigator.clipboard.writeText(text);
        setCopiedTracking(true);
        setTimeout(() => setCopiedTracking(false), 2000);
    };

    const handleAction = (actionKey: string) => {
        if (!parcel) return;
        setIsExecutingAction(actionKey);
        router.post(
            route('track.action', parcel.tracking_number),
            { action: actionKey },
            {
                preserveScroll: true,
                onFinish: () => setIsExecutingAction(null),
            }
        );
    };

    const formatPrice = (amount: number) => {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
        }).format(amount);
    };

    const getLifecycleStep = (status: string) => {
        switch (status?.toLowerCase()) {
            case 'placed':
            case 'pending':
                return 1;
            case 'confirmed':
            case 'preparing':
            case 'processing':
            case 'ready_for_pickup':
            case 'assigned':
                return 2;
            case 'picked_up':
            case 'in_transit':
            case 'at_sorting_center':
            case 'sorted':
            case 'assigned_to_rider':
                return 3;
            case 'out_for_delivery':
                return 4;
            case 'delivered':
            case 'completed':
                return 5;
            default:
                return 1;
        }
    };

    const currentStep = parcel ? getLifecycleStep(parcel.status) : 0;

    const steps = [
        { number: 1, title: 'Order Placed', desc: 'Verified by merchant' },
        { number: 2, title: 'Packed & Ready', desc: 'Waybill generated' },
        { number: 3, title: 'Sorting Hub', desc: 'In courier transit' },
        { number: 4, title: 'Out for Delivery', desc: 'With local courier' },
        { number: 5, title: 'Delivered', desc: 'Doorstep handover' },
    ];

    return (
        <div className="min-h-screen bg-[#F8FAFC] text-slate-900 font-sans flex flex-col selection:bg-rose-100 selection:text-rose-900">
            <Head title={parcel ? `Track Parcel #${parcel.tracking_number} | BagooPH Express` : 'Track Your Shipment | BagooPH Express'} />

            {/* Header */}
            <header className="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-2xs">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <Link href={route('marketplace')} className="flex items-center gap-2">
                            <BagooLogo className="h-8 w-auto" />
                        </Link>
                        <span className="text-slate-300">|</span>
                        <div className="flex items-center gap-1.5 text-xs font-mono font-bold tracking-wider text-slate-600 uppercase">
                            <Truck className="w-3.5 h-3.5 text-[#E00D42]" />
                            <span>Express Tracker</span>
                        </div>
                    </div>

                    <div className="flex items-center gap-3 text-xs font-mono">
                        {user ? (
                            <Link
                                href={route('dashboard')}
                                className="px-3.5 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold transition flex items-center gap-1.5"
                            >
                                <UserIcon className="w-3.5 h-3.5 text-slate-500" />
                                <span>{user.name} ({user.role})</span>
                            </Link>
                        ) : (
                            <Link
                                href={route('login')}
                                className="px-3.5 py-1.5 rounded-xl bg-white border border-slate-200 hover:border-slate-300 text-slate-700 font-bold transition"
                            >
                                Sign In
                            </Link>
                        )}
                    </div>
                </div>
            </header>

            {/* Main Content Area */}
            <main className="flex-1 max-w-5xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10 space-y-6">

                {/* Search Bar Container */}
                <div className="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/90 shadow-2xs space-y-4">
                    <div className="space-y-1">
                        <div className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-rose-50 text-[#E00D42] text-[11px] font-mono font-bold uppercase tracking-wider">
                            <QrCode className="w-3 h-3" />
                            <span>Universal Waybill & QR Code Tracking</span>
                        </div>
                        <h1 className="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">
                            Track Your BagooPH Shipment
                        </h1>
                        <p className="text-xs sm:text-sm text-slate-500">
                            Enter your Bagoo Express tracking number (BGO-TRK-...) or Order number to view real-time delivery milestones.
                        </p>
                    </div>

                    <form onSubmit={handleSearch} className="flex flex-col sm:flex-row items-center gap-2.5">
                        <div className="relative flex-1 w-full">
                            <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                            <input
                                type="text"
                                value={inputNumber}
                                onChange={(e) => setInputNumber(e.target.value)}
                                placeholder="Enter tracking code (e.g. BGO-TRK-749210 or order #)"
                                className="w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs sm:text-sm text-slate-900 font-mono placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-[#E00D42]/20 focus:border-[#E00D42] transition"
                            />
                        </div>
                        <button
                            type="submit"
                            className="w-full sm:w-auto px-6 py-3 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white rounded-2xl text-xs font-mono font-bold uppercase tracking-wider shadow-xs flex items-center justify-center gap-2 transition cursor-pointer shrink-0"
                        >
                            <Search className="w-4 h-4" />
                            <span>Track Parcel</span>
                        </button>
                    </form>
                </div>

                {/* Not Found Alert */}
                {notFound && (
                    <div className="p-6 bg-white border border-rose-200 rounded-3xl shadow-2xs text-center space-y-3">
                        <div className="w-12 h-12 rounded-2xl bg-rose-50 border border-rose-200 flex items-center justify-center mx-auto text-[#E00D42]">
                            <AlertCircle className="w-6 h-6" />
                        </div>
                        <div className="space-y-1">
                            <h3 className="font-bold text-slate-900 text-base">Shipment Not Found</h3>
                            <p className="text-xs text-slate-500 max-w-md mx-auto">
                                We could not find any active shipment matching <strong className="font-mono text-slate-800">#{searchedNumber}</strong>. Please check the thermal waybill label or your order history receipt.
                            </p>
                        </div>
                    </div>
                )}

                {/* Active Parcel Tracking Details */}
                {parcel && (
                    <div className="space-y-6">

                        {/* Top Shipment Status Overview Card */}
                        <div className="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/90 shadow-2xs space-y-6">
                            
                            {/* Card Top Title & Badges */}
                            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-slate-100">
                                <div>
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <span className="text-xs text-slate-400 font-mono uppercase tracking-wider font-semibold">Waybill Code:</span>
                                        <span className="font-mono font-black text-slate-900 text-base sm:text-lg">
                                            #{parcel.tracking_number}
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => handleCopy(parcel.tracking_number)}
                                            className="p-1 rounded-lg hover:bg-slate-100 text-slate-400 hover:text-slate-700 transition cursor-pointer"
                                            title="Copy Tracking Number"
                                        >
                                            {copiedTracking ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Copy className="w-3.5 h-3.5" />}
                                        </button>
                                    </div>
                                    {parcel.order_number && (
                                        <p className="text-xs text-slate-500 font-mono mt-0.5">
                                            Associated Order: #{parcel.order_number}
                                        </p>
                                    )}
                                </div>

                                <div className="flex items-center gap-2 shrink-0">
                                    <span className="px-3 py-1 rounded-full bg-slate-900 text-white text-xs font-mono font-bold uppercase tracking-wider shadow-2xs">
                                        {parcel.status.replace(/_/g, ' ')}
                                    </span>
                                    <span className="px-2.5 py-1 rounded-full bg-rose-50 text-[#E00D42] border border-rose-200 text-xs font-mono font-bold">
                                        {parcel.payment_method}
                                    </span>
                                </div>
                            </div>

                            {/* 5-Milestone Lifecycle Progress Stepper */}
                            <div className="py-2">
                                <div className="grid grid-cols-2 sm:grid-cols-5 gap-3 sm:gap-2">
                                    {steps.map((s) => {
                                        const isComplete = currentStep >= s.number;
                                        const isCurrent = currentStep === s.number;

                                        return (
                                            <div
                                                key={s.number}
                                                className={`p-3 rounded-2xl border transition ${
                                                    isComplete
                                                        ? 'bg-rose-50/20 border-rose-200'
                                                        : 'bg-slate-50/60 border-slate-200 text-slate-400'
                                                }`}
                                            >
                                                <div className="flex items-center justify-between mb-1.5">
                                                    <span className={`w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold font-mono ${
                                                        isComplete ? 'bg-[#E00D42] text-white' : 'bg-slate-200 text-slate-500'
                                                    }`}>
                                                        {isComplete ? <Check className="w-3.5 h-3.5 stroke-[3]" /> : s.number}
                                                    </span>
                                                    {isCurrent && (
                                                        <span className="w-2 h-2 rounded-full bg-[#E00D42] animate-ping" />
                                                    )}
                                                </div>
                                                <h4 className={`font-bold text-xs ${isComplete ? 'text-slate-900' : 'text-slate-500'}`}>
                                                    {s.title}
                                                </h4>
                                                <p className="text-[10px] text-slate-400 font-mono mt-0.5">
                                                    {s.desc}
                                                </p>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Key Delivery Specifications Grid */}
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3.5 pt-2 border-t border-slate-100 text-xs">
                                <div className="p-3.5 bg-slate-50 border border-slate-200 rounded-2xl space-y-1">
                                    <div className="flex items-center gap-1.5 text-slate-400 font-mono font-bold text-[10px] uppercase">
                                        <Clock className="w-3.5 h-3.5 text-[#E00D42]" />
                                        <span>Estimated Delivery</span>
                                    </div>
                                    <p className="font-bold text-slate-900 text-sm">
                                        {new Date(parcel.estimated_delivery_at).toLocaleDateString(undefined, {
                                            weekday: 'short',
                                            month: 'short',
                                            day: 'numeric',
                                        })}
                                    </p>
                                    <p className="text-[11px] text-slate-500">By 6:00 PM local time</p>
                                </div>

                                <div className="p-3.5 bg-slate-50 border border-slate-200 rounded-2xl space-y-1">
                                    <div className="flex items-center gap-1.5 text-slate-400 font-mono font-bold text-[10px] uppercase">
                                        <Truck className="w-3.5 h-3.5 text-[#E00D42]" />
                                        <span>Fulfillment Carrier</span>
                                    </div>
                                    <p className="font-bold text-slate-900 text-sm">{parcel.courier_name}</p>
                                    <p className="text-[11px] text-slate-500 font-mono">{parcel.courier_vehicle}</p>
                                </div>

                                <div className="p-3.5 bg-slate-50 border border-slate-200 rounded-2xl space-y-1">
                                    <div className="flex items-center gap-1.5 text-slate-400 font-mono font-bold text-[10px] uppercase">
                                        <MapPin className="w-3.5 h-3.5 text-[#E00D42]" />
                                        <span>Destination Region</span>
                                    </div>
                                    <p className="font-bold text-slate-900 text-sm truncate">{parcel.delivery_recipient_name}</p>
                                    <p className="text-[11px] text-slate-500 truncate">{parcel.delivery_address}</p>
                                </div>
                            </div>
                        </div>

                        {/* Smart Role-Aware Staff Action Bar */}
                        {availableActions.length > 0 && (
                            <div className="bg-slate-900 text-white rounded-3xl p-6 border border-slate-800 shadow-xl space-y-4 font-sans">
                                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-3 border-b border-slate-800">
                                    <div className="flex items-center gap-2">
                                        <ShieldCheck className="w-4 h-4 text-[#E00D42]" />
                                        <h3 className="font-bold text-sm text-white font-mono uppercase tracking-wider">
                                            Role-Aware Handover Control
                                        </h3>
                                    </div>
                                    <span className="text-[11px] text-slate-400 font-mono">
                                        Logged in as: <strong className="text-white">{user?.name}</strong> ({user?.role})
                                    </span>
                                </div>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    {availableActions.map((act) => (
                                        <div
                                            key={act.action}
                                            className="p-4 bg-slate-800/80 border border-slate-700/80 rounded-2xl flex flex-col justify-between gap-3"
                                        >
                                            <div>
                                                <h4 className="font-bold text-white text-xs">{act.label}</h4>
                                                <p className="text-[11px] text-slate-400 mt-0.5">{act.description}</p>
                                            </div>
                                            <button
                                                type="button"
                                                disabled={isExecutingAction !== null}
                                                onClick={() => handleAction(act.action)}
                                                className="w-full py-2.5 px-4 bg-[#E00D42] hover:bg-[#C20836] disabled:opacity-50 text-white text-xs font-mono font-bold uppercase rounded-xl transition shadow-xs flex items-center justify-center gap-1.5 cursor-pointer"
                                            >
                                                <span>Execute Transition</span>
                                                <ArrowRight className="w-3.5 h-3.5" />
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Two-Column Layout: Timeline & Package Breakdown */}
                        <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">

                            {/* Left (7 Cols): Detailed Checkpoint Timeline */}
                            <div className="lg:col-span-7 bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/90 shadow-2xs space-y-5">
                                <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                                    <div className="flex items-center gap-2 text-slate-900 font-bold text-sm">
                                        <Clock className="w-4 h-4 text-[#E00D42]" />
                                        <span>Checkpoints & Audit Logs</span>
                                    </div>
                                    <span className="text-[11px] font-mono text-slate-400">
                                        {parcel.checkpoints.length} Checkpoint{parcel.checkpoints.length !== 1 ? 's' : ''}
                                    </span>
                                </div>

                                {parcel.checkpoints.length === 0 ? (
                                    <div className="py-8 text-center text-xs font-mono text-slate-400">
                                        Parcel initialized. Awaiting pickup dispatch scan.
                                    </div>
                                ) : (
                                    <div className="relative pl-6 space-y-6 before:content-[''] before:absolute before:left-2 before:top-2 before:bottom-2 before:w-0.5 before:bg-slate-200">
                                        {parcel.checkpoints.map((cp, idx) => (
                                            <div key={cp.id} className="relative group">
                                                {/* Dot */}
                                                <div className={`absolute -left-6 top-1 w-3.5 h-3.5 rounded-full border-2 border-white ${
                                                    idx === parcel.checkpoints.length - 1
                                                        ? 'bg-[#E00D42] ring-2 ring-rose-200'
                                                        : 'bg-slate-400'
                                                }`} />

                                                <div className="space-y-1 text-xs">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <h4 className="font-bold text-slate-900">
                                                            {cp.checkpoint_type.replace(/_/g, ' ').toUpperCase()}
                                                        </h4>
                                                        <span className="text-[11px] text-slate-400 font-mono">
                                                            {new Date(cp.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} • {new Date(cp.created_at).toLocaleDateString()}
                                                        </span>
                                                    </div>
                                                    <p className="text-slate-600 font-medium">
                                                        {cp.location_name}
                                                    </p>
                                                    {cp.notes && (
                                                        <p className="text-[11px] text-slate-400 italic">
                                                            "{cp.notes}"
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>

                            {/* Right (5 Cols): Package Contents & Origin/Destination */}
                            <div className="lg:col-span-5 space-y-6">

                                {/* Parcel Contents Card */}
                                <div className="bg-white rounded-3xl p-6 border border-slate-200/90 shadow-2xs space-y-4">
                                    <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                                        <div className="flex items-center gap-2 text-slate-900 font-bold text-sm">
                                            <Package className="w-4 h-4 text-[#E00D42]" />
                                            <span>Package Manifest</span>
                                        </div>
                                        <span className="text-xs font-mono text-slate-500">
                                            Qty: {parcel.items.reduce((sum, it) => sum + it.quantity, 0)}
                                        </span>
                                    </div>

                                    <div className="divide-y divide-slate-100 space-y-3">
                                        {parcel.items.map((item) => (
                                            <div key={item.id} className="pt-3 first:pt-0 flex items-center gap-3">
                                                <img
                                                    src={item.featured_image || ''}
                                                    alt=""
                                                    className="w-12 h-12 rounded-xl object-cover bg-slate-100 border border-slate-200 shrink-0"
                                                />
                                                <div className="min-w-0 flex-1 space-y-0.5 text-xs">
                                                    <h5 className="font-bold text-slate-900 truncate">
                                                        {item.product_name}
                                                    </h5>
                                                    {(item.color || item.size) && (
                                                        <p className="text-[10px] text-slate-400 font-mono">
                                                            {[item.color, item.size].filter(Boolean).join(' / ')}
                                                        </p>
                                                    )}
                                                    <p className="text-[11px] text-slate-500 font-mono">
                                                        Quantity: {item.quantity}
                                                        {item.unit_price !== null && item.unit_price !== undefined && (
                                                            <span> • {formatPrice(item.unit_price)} each</span>
                                                        )}
                                                    </p>
                                                </div>
                                            </div>
                                        ))}
                                    </div>

                                    {parcel.total_amount > 0 && (
                                        <div className="pt-3 border-t border-slate-100 flex items-center justify-between text-xs font-mono">
                                            <span className="text-slate-500">Total Order Valuation:</span>
                                            <span className="font-bold text-slate-900 font-sans text-sm">
                                                {formatPrice(parcel.total_amount)}
                                            </span>
                                        </div>
                                    )}
                                </div>

                                {/* Digital Scannable Waybill Identifiers */}
                                <div className="bg-slate-50/70 border border-slate-200/90 rounded-3xl p-5 space-y-3.5">
                                    <div className="flex items-center justify-between border-b border-slate-200/60 pb-2.5">
                                        <div className="flex items-center gap-2">
                                            <QrCode className="w-4 h-4 text-[#E00D42]" />
                                            <span className="text-xs font-mono font-bold uppercase tracking-wider text-slate-900">Digital Waybill Codes</span>
                                        </div>
                                        <span className="text-[10px] font-mono font-bold px-2 py-0.5 rounded bg-slate-200/80 text-slate-700">Code 128 + QR</span>
                                    </div>
                                    <div className="space-y-2">
                                        {/* 1D Laser Barcode */}
                                        <div className="bg-white p-3 rounded-2xl border border-slate-200/80 flex flex-col items-center justify-center gap-1 shadow-2xs overflow-hidden">
                                            <Barcode
                                                value={parcel.tracking_number}
                                                height={34}
                                                width={1.5}
                                                className="max-w-full h-8 object-contain"
                                            />
                                            <p className="text-[11px] font-mono font-bold text-slate-800 tracking-wider">
                                                {parcel.tracking_number}
                                            </p>
                                        </div>
                                        {/* 2D QR Matrix */}
                                        <div className="bg-white p-3 rounded-2xl border border-slate-200/80 flex items-center justify-center shadow-2xs">
                                            <QRCodeSVG
                                                value={typeof window !== 'undefined' ? window.location.href : `https://bagooph.shop/track/${parcel.tracking_number}`}
                                                size={108}
                                                level="M"
                                            />
                                        </div>
                                    </div>
                                    <p className="text-[10px] text-slate-500 font-mono text-center">
                                        Scannable by optical warehouse laser guns and mobile camera apps.
                                    </p>
                                </div>

                                {/* Security & Trust Guarantee */}
                                <div className="bg-emerald-50/40 border border-emerald-200/80 rounded-3xl p-5 space-y-2 text-xs">
                                    <div className="flex items-center gap-2 text-emerald-800 font-bold font-mono">
                                        <ShieldCheck className="w-4 h-4 text-emerald-600" />
                                        <span>Bagoo Verified Parcel Guarantee</span>
                                    </div>
                                    <p className="text-emerald-900/80 leading-relaxed text-[11px]">
                                        This parcel is protected by Bagoo Secure Handover. Barcode and checkpoint scans are cryptographically verified in our logistics ledger.
                                    </p>
                                </div>

                            </div>

                        </div>

                    </div>
                )}

            </main>

            {/* Public Footer */}
            <footer className="mt-auto bg-white border-t border-slate-200 py-6 text-center text-xs text-slate-400 font-mono">
                <div className="max-w-7xl mx-auto px-4 space-y-1">
                    <p>Bagoo Express Logistics Network • The Artisan Marketplace of the Philippines</p>
                    <p className="text-[11px]">Customer Support: support@bagooph.shop • +63 2 8123 4567</p>
                </div>
            </footer>
        </div>
    );
}
