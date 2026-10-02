import React, { useMemo, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    Building2,
    Check,
    CheckCircle2,
    Clock3,
    Copy,
    CreditCard,
    Filter,
    MapPin,
    PackageCheck,
    Search,
    ShieldAlert,
    ShieldCheck,
    Truck,
    User,
    Wallet,
} from 'lucide-react';
import CourierLayout from '@/Layouts/CourierLayout';

interface Props {
    scope: {
        company: string | null;
        hub: string | null;
        hubCode: string | null;
        barangay: string | null;
        isAssigned: boolean;
        isOperational: boolean;
    };
    summary: {
        completedDeliveries: number;
        completedToday: number;
    };
    isOnline: boolean;
    trips: Array<{
        id: number;
        trackingNumber: string;
        orderNumber: string | null;
        recipientName: string;
        deliveryAddress: string;
        paymentMethod: string;
        destinationHub: string | null;
        deliveredAt: string | null;
    }>;
}

export default function CourierCompletedTrips({ scope, summary, trips, isOnline }: Props) {
    const [searchQuery, setSearchQuery] = useState('');
    const [paymentFilter, setPaymentFilter] = useState<'all' | 'cod' | 'prepaid'>('all');
    const [copiedTracking, setCopiedTracking] = useState<string | null>(null);

    const copyTracking = (trackingNumber: string) => {
        if (!navigator.clipboard) return;
        navigator.clipboard.writeText(trackingNumber);
        setCopiedTracking(trackingNumber);
        setTimeout(() => setCopiedTracking(null), 2000);
    };

    const formatDate = (value: string | null) => {
        if (!value) return 'Not recorded';
        return new Intl.DateTimeFormat('en-PH', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        }).format(new Date(value));
    };

    const filteredTrips = useMemo(() => {
        return trips.filter((trip) => {
            const matchesSearch =
                searchQuery.trim() === '' ||
                trip.trackingNumber.toLowerCase().includes(searchQuery.toLowerCase()) ||
                trip.recipientName.toLowerCase().includes(searchQuery.toLowerCase()) ||
                (trip.orderNumber && trip.orderNumber.toLowerCase().includes(searchQuery.toLowerCase())) ||
                trip.deliveryAddress.toLowerCase().includes(searchQuery.toLowerCase());

            const isCod = trip.paymentMethod.toUpperCase().includes('COD');
            const matchesPayment =
                paymentFilter === 'all' ||
                (paymentFilter === 'cod' && isCod) ||
                (paymentFilter === 'prepaid' && !isCod);

            return matchesSearch && matchesPayment;
        });
    }, [trips, searchQuery, paymentFilter]);

    return (
        <CourierLayout
            title="Completed Trips"
            subtitle={
                scope.isAssigned
                    ? `${scope.hub} · ${scope.company}`
                    : 'Final-mile verified delivery records'
            }
            isOnline={isOnline}
        >
            <Head title="Completed Trips — BagooPH" />

            <div className="space-y-4 sm:space-y-6 font-sans">
                {/* 1. TOP BENTO KPI CARDS (4 TILES) */}
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-2.5 sm:gap-4">
                    {/* 1: Completed Deliveries */}
                    <div className="bg-white rounded-xl p-3.5 sm:p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 text-[11px] sm:text-xs">
                                <span className="font-semibold truncate">All Completed</span>
                                <div className="w-6 h-6 sm:w-7 sm:h-7 rounded-lg bg-rose-50 flex items-center justify-center shrink-0">
                                    <PackageCheck className="w-3.5 h-3.5 sm:w-4 sm:h-4 text-[#E00D42]" />
                                </div>
                            </div>
                            <div className="mt-1 sm:mt-2">
                                <p className="text-xl sm:text-3xl font-black text-slate-900 tracking-tight">
                                    {summary.completedDeliveries}{' '}
                                    <span className="text-xs sm:text-sm font-bold text-slate-500">trips</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-2.5 sm:mt-3 pt-2 sm:pt-3 border-t border-slate-100 flex items-center justify-between text-[10px] sm:text-[11px]">
                            <span className="text-slate-500 truncate">Lifetime Volume</span>
                            <span className="font-bold text-[#E00D42] shrink-0 ml-1">Verified</span>
                        </div>
                    </div>

                    {/* 2: Delivered Today */}
                    <div className="bg-white rounded-xl p-3.5 sm:p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 text-[11px] sm:text-xs">
                                <span className="font-semibold truncate">Delivered Today</span>
                                <div className="w-6 h-6 sm:w-7 sm:h-7 rounded-lg bg-emerald-50 flex items-center justify-center shrink-0">
                                    <CheckCircle2 className="w-3.5 h-3.5 sm:w-4 sm:h-4 text-emerald-600" />
                                </div>
                            </div>
                            <div className="mt-1 sm:mt-2">
                                <p className="text-xl sm:text-3xl font-black text-slate-900 tracking-tight">
                                    {summary.completedToday}{' '}
                                    <span className="text-xs sm:text-sm font-bold text-slate-500">parcels</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-2.5 sm:mt-3 pt-2 sm:pt-3 border-t border-slate-100 flex items-center justify-between text-[10px] sm:text-[11px]">
                            <span className="text-slate-500 truncate">Current Shift</span>
                            <span className="font-bold text-emerald-600 shrink-0 ml-1">100% Drop Rate</span>
                        </div>
                    </div>

                    {/* 3: Assigned Hub Station */}
                    <div className="bg-white rounded-xl p-3.5 sm:p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 text-[11px] sm:text-xs">
                                <span className="font-semibold truncate">Working Facility</span>
                                <div className="w-6 h-6 sm:w-7 sm:h-7 rounded-lg bg-indigo-50 flex items-center justify-center shrink-0">
                                    <Building2 className="w-3.5 h-3.5 sm:w-4 sm:h-4 text-indigo-600" />
                                </div>
                            </div>
                            <div className="mt-1 sm:mt-2">
                                <p className="text-sm sm:text-base font-black text-slate-900 truncate tracking-tight">
                                    {scope.hub ?? 'Not Assigned'}
                                </p>
                                <p className="text-[10px] text-slate-500 font-mono mt-0.5">
                                    {scope.hubCode ?? 'No facility code'}
                                </p>
                            </div>
                        </div>
                        <div className="mt-2.5 sm:mt-3 pt-2 sm:pt-3 border-t border-slate-100 flex items-center justify-between text-[10px] sm:text-[11px]">
                            <span className="text-slate-500 truncate">Dispatch Hub</span>
                            <span className="font-mono font-bold text-slate-800 shrink-0 ml-1">
                                {scope.hubCode || 'BH'}
                            </span>
                        </div>
                    </div>

                    {/* 4: Logistics Fleet Partner */}
                    <div className="bg-white rounded-xl p-3.5 sm:p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 text-[11px] sm:text-xs">
                                <span className="font-semibold truncate">Fleet Partner</span>
                                <div className="w-6 h-6 sm:w-7 sm:h-7 rounded-lg bg-slate-100 flex items-center justify-center shrink-0">
                                    <Truck className="w-3.5 h-3.5 sm:w-4 sm:h-4 text-slate-700" />
                                </div>
                            </div>
                            <div className="mt-1 sm:mt-2">
                                <p className="text-sm sm:text-base font-black text-slate-900 truncate tracking-tight">
                                    {scope.company ?? 'Bagoo Logistics'}
                                </p>
                                <p className="text-[10px] text-slate-500 font-medium mt-0.5 truncate">
                                    {scope.barangay ? `Sector: ${scope.barangay}` : 'All Sectors'}
                                </p>
                            </div>
                        </div>
                        <div className="mt-2.5 sm:mt-3 pt-2 sm:pt-3 border-t border-slate-100 flex items-center justify-between text-[10px] sm:text-[11px]">
                            <span className="text-slate-500 truncate">Status</span>
                            <span className="font-bold text-emerald-600 shrink-0 ml-1">Operational</span>
                        </div>
                    </div>
                </div>

                {/* 2. NOTICE BANNER */}
                <div className="rounded-xl border border-blue-200 bg-blue-50/90 p-4 text-xs leading-5 text-blue-950 flex items-start gap-3 shadow-2xs">
                    <ShieldCheck className="w-4 h-4 text-blue-600 shrink-0 mt-0.5" />
                    <div>
                        <span className="font-bold text-blue-900">Custody Audit Ledger: </span>
                        This page records verified final-mile deliveries and drop-off timestamps. Courier payout
                        calculations, COD collection reconciliation, and remittance ledgers will unlock once the financial
                        settlement service is activated.
                    </div>
                </div>

                {/* 3. TRIP HISTORY BENTO CONTAINER WITH SEARCH & FILTERS */}
                <div className="bg-white rounded-2xl border border-slate-200/90 shadow-2xs overflow-hidden">
                    {/* Header + Search/Filter Toolbar */}
                    <div className="p-4 sm:p-5 border-b border-slate-100 space-y-4">
                        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                            <div>
                                <h2 className="text-base sm:text-lg font-black text-slate-900 tracking-tight">
                                    Verified Final-Mile Trip Ledger
                                </h2>
                                <p className="text-xs text-slate-500 mt-0.5">
                                    Historical record of completed drops with recipient details and drop timestamps.
                                </p>
                            </div>
                            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200 self-start sm:self-auto font-mono">
                                Total: {trips.length}
                            </span>
                        </div>

                        {/* Search Bar + Segmented Payment Filter */}
                        <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5">
                            {/* Search Input */}
                            <div className="relative flex-1">
                                <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                <input
                                    type="text"
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    placeholder="Search tracking #, recipient, or order..."
                                    className="w-full pl-9 pr-3.5 py-2 text-xs rounded-xl border border-slate-200 bg-slate-50/50 text-slate-900 placeholder:text-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#E00D42]/20 focus:border-[#E00D42] transition"
                                />
                                {searchQuery && (
                                    <button
                                        type="button"
                                        onClick={() => setSearchQuery('')}
                                        className="absolute right-2.5 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-400 hover:text-slate-600 bg-slate-200/70 hover:bg-slate-200 rounded px-1.5 py-0.5"
                                    >
                                        Clear
                                    </button>
                                )}
                            </div>

                            {/* Payment Segmented Filter */}
                            <div className="flex items-center gap-1 bg-slate-100/90 p-1 rounded-xl border border-slate-200/80 shrink-0">
                                <button
                                    type="button"
                                    onClick={() => setPaymentFilter('all')}
                                    className={`px-3 py-1.5 rounded-lg text-xs font-bold transition cursor-pointer ${
                                        paymentFilter === 'all'
                                            ? 'bg-white text-slate-900 shadow-xs'
                                            : 'text-slate-600 hover:text-slate-900'
                                    }`}
                                >
                                    All
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setPaymentFilter('cod')}
                                    className={`px-3 py-1.5 rounded-lg text-xs font-bold transition cursor-pointer flex items-center gap-1 ${
                                        paymentFilter === 'cod'
                                            ? 'bg-white text-emerald-800 shadow-xs'
                                            : 'text-slate-600 hover:text-slate-900'
                                    }`}
                                >
                                    <Wallet className="w-3.5 h-3.5" />
                                    <span>COD</span>
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setPaymentFilter('prepaid')}
                                    className={`px-3 py-1.5 rounded-lg text-xs font-bold transition cursor-pointer flex items-center gap-1 ${
                                        paymentFilter === 'prepaid'
                                            ? 'bg-white text-indigo-700 shadow-xs'
                                            : 'text-slate-600 hover:text-slate-900'
                                    }`}
                                >
                                    <CreditCard className="w-3.5 h-3.5" />
                                    <span>Prepaid</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* Trip Cards List */}
                    {filteredTrips.length === 0 ? (
                        <div className="p-8 sm:p-12 text-center">
                            <div className="w-12 h-12 rounded-2xl bg-slate-100 flex items-center justify-center mx-auto text-slate-400">
                                <Clock3 className="w-6 h-6 text-slate-400" />
                            </div>
                            <h3 className="mt-3 text-sm font-bold text-slate-800">
                                {searchQuery || paymentFilter !== 'all'
                                    ? 'No trips match your filter'
                                    : 'No completed final-mile trips yet'}
                            </h3>
                            <p className="mt-1 text-xs text-slate-500 max-w-sm mx-auto">
                                {searchQuery || paymentFilter !== 'all'
                                    ? 'Try adjusting your search query or reset the payment filter to view all trips.'
                                    : 'When you deliver assigned parcels and upload proof of delivery, they will appear in this verified ledger.'}
                            </p>
                            {searchQuery || paymentFilter !== 'all' ? (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setSearchQuery('');
                                        setPaymentFilter('all');
                                    }}
                                    className="mt-3.5 inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-700 hover:bg-slate-50 transition cursor-pointer"
                                >
                                    Reset Filters
                                </button>
                            ) : (
                                <Link
                                    href={route('courier.deliveries')}
                                    className="mt-4 inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-[#E00D42] text-white text-xs font-bold hover:bg-[#C20836] transition shadow-xs"
                                >
                                    <span>View Dispatch Queue</span>
                                    <ArrowUpRight className="w-3.5 h-3.5" />
                                </Link>
                            )}
                        </div>
                    ) : (
                        <div className="p-3 sm:p-5 space-y-3">
                            {filteredTrips.map((trip) => {
                                const isCod = trip.paymentMethod.toUpperCase().includes('COD');

                                return (
                                    <article
                                        key={trip.id}
                                        className="bg-white rounded-xl border border-slate-200/90 hover:border-slate-300 p-4 transition shadow-2xs space-y-3"
                                    >
                                        {/* Card Top Row: Monospace Tracking + Delivered Badge */}
                                        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                            <div className="flex items-center gap-2 flex-wrap">
                                                <span className="font-mono text-xs sm:text-sm font-black text-slate-900 bg-slate-100 px-2.5 py-1 rounded-md border border-slate-200">
                                                    {trip.trackingNumber}
                                                </span>
                                                <button
                                                    type="button"
                                                    onClick={() => copyTracking(trip.trackingNumber)}
                                                    className="inline-flex items-center gap-1 text-[11px] font-bold text-slate-500 hover:text-slate-800 transition cursor-pointer"
                                                    title="Copy tracking number"
                                                >
                                                    {copiedTracking === trip.trackingNumber ? (
                                                        <Check className="w-3.5 h-3.5 text-emerald-600" />
                                                    ) : (
                                                        <Copy className="w-3.5 h-3.5 text-slate-400" />
                                                    )}
                                                </button>
                                                {trip.orderNumber && (
                                                    <span className="text-[11px] text-slate-500 font-medium">
                                                        Order #{trip.orderNumber}
                                                    </span>
                                                )}
                                            </div>

                                            {/* Delivered Timestamp Pill */}
                                            <div className="flex items-center gap-2">
                                                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-emerald-50 text-emerald-800 border border-emerald-200">
                                                    <CheckCircle2 className="w-3 h-3 text-emerald-600" />
                                                    Delivered
                                                </span>
                                                <span className="text-[11px] font-semibold text-slate-500">
                                                    {formatDate(trip.deliveredAt)}
                                                </span>
                                            </div>
                                        </div>

                                        {/* Card Middle Grid: Recipient & Address vs Details */}
                                        <div className="grid grid-cols-1 md:grid-cols-3 gap-3 pt-2 border-t border-slate-100 text-xs">
                                            {/* Recipient info */}
                                            <div className="md:col-span-2 space-y-1">
                                                <div className="flex items-center gap-1.5 font-bold text-slate-900">
                                                    <User className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                                                    <span>{trip.recipientName}</span>
                                                </div>
                                                <div className="flex items-start gap-1.5 text-slate-500 leading-relaxed">
                                                    <MapPin className="w-3.5 h-3.5 text-[#E00D42] shrink-0 mt-0.5" />
                                                    <span>{trip.deliveryAddress}</span>
                                                </div>
                                            </div>

                                            {/* Payment & Hub Meta */}
                                            <div className="flex md:flex-col justify-between md:justify-center items-start md:items-end gap-1.5 pt-2 md:pt-0 border-t md:border-t-0 border-slate-100">
                                                {/* Payment Pill */}
                                                <span
                                                    className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-bold ${
                                                        isCod
                                                            ? 'bg-amber-50 text-amber-800 border border-amber-200'
                                                            : 'bg-indigo-50 text-indigo-700 border border-indigo-200'
                                                    }`}
                                                >
                                                    {isCod ? (
                                                        <Wallet className="w-3 h-3 text-amber-600" />
                                                    ) : (
                                                        <CreditCard className="w-3 h-3 text-indigo-600" />
                                                    )}
                                                    {trip.paymentMethod}
                                                </span>

                                                {/* Destination Hub */}
                                                <div className="flex items-center gap-1 text-[11px] text-slate-500">
                                                    <Building2 className="w-3 h-3 text-slate-400" />
                                                    <span className="truncate max-w-[160px]">
                                                        {trip.destinationHub || 'Direct Drop'}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </article>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>
        </CourierLayout>
    );
}

