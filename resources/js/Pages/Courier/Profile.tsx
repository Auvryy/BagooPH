import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    Bike,
    Building2,
    Calendar,
    Check,
    CheckCircle2,
    Clock,
    Compass,
    Copy,
    ExternalLink,
    FileCheck2,
    IdCard,
    Info,
    Mail,
    MapPin,
    PackageCheck,
    Phone,
    Power,
    QrCode,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Truck,
    User,
    UserCheck,
    Zap,
} from 'lucide-react';
import CourierLayout from '@/Layouts/CourierLayout';

interface Props {
    rider: {
        name: string;
        email: string;
        phone: string | null;
        account_status: string;
        kyc_status: string;
    };
    assignment: {
        company: string | null;
        hub: string | null;
        hub_code: string | null;
        barangay: string | null;
    };
    vehicle: {
        type: string | null;
        model: string | null;
        plate_number: string | null;
        fleet_status: string | null;
        license_number: string | null;
        registration_status: string | null;
    };
    isOnline: boolean;
    completedDeliveries: number;
}

type ProfileTab = 'assignment' | 'vehicle' | 'protocols';

export default function CourierProfile({
    rider,
    assignment,
    vehicle,
    isOnline,
    completedDeliveries,
}: Props) {
    const [activeTab, setActiveTab] = useState<ProfileTab>('assignment');
    const [copiedKey, setCopiedKey] = useState<string | null>(null);
    const [dutyLoading, setDutyLoading] = useState(false);

    const copyToClipboard = (text: string, key: string) => {
        if (!navigator.clipboard) return;
        navigator.clipboard.writeText(text);
        setCopiedKey(key);
        setTimeout(() => setCopiedKey(null), 2000);
    };

    const toggleDuty = () => {
        setDutyLoading(true);
        router.post(
            route('courier.toggleDuty'),
            { is_available: !isOnline },
            {
                preserveScroll: true,
                onFinish: () => setDutyLoading(false),
            },
        );
    };

    return (
        <CourierLayout
            title="Courier Profile"
            subtitle="Verified courier credentials, station assignment, and authorized conveyance"
            isOnline={isOnline}
        >
            <Head title="Courier Profile — BagooPH" />

            <div className="space-y-6 font-sans">
                {/* 1. HERO IDENTITY CANVAS */}
                <div className="relative overflow-hidden rounded-2xl bg-white border border-slate-200/90 shadow-2xs">
                    {/* Decorative Top Canvas Gradient */}
                    <div className="h-32 sm:h-36 bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 relative">
                        <div className="absolute inset-0 opacity-10 bg-[radial-gradient(#fff_1px,transparent_1px)] [background-size:16px_16px]" />
                        <div className="absolute top-4 right-4 flex items-center gap-2">
                            <span className="hidden sm:inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-white/10 text-white backdrop-blur-md border border-white/15">
                                <ShieldCheck className="w-3.5 h-3.5 text-emerald-400" />
                                <span>Verified Partner</span>
                            </span>
                            <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-mono font-bold bg-white/10 text-slate-200 backdrop-blur-md border border-white/15">
                                {assignment.hub_code || 'HUB'}
                            </span>
                        </div>
                    </div>

                    {/* Profile Header Details & Avatar Row */}
                    <div className="px-5 sm:px-8 pb-6 pt-0">
                        <div className="flex flex-col md:flex-row md:items-end md:justify-between gap-5 -mt-12 sm:-mt-14">
                            {/* Avatar & Main Titles */}
                            <div className="flex flex-col sm:flex-row sm:items-end gap-4 sm:gap-5 min-w-0">
                                <div className="relative w-24 h-24 sm:w-28 sm:h-28 rounded-2xl bg-gradient-to-tr from-slate-900 via-slate-800 to-slate-700 text-white font-black text-3xl sm:text-4xl flex items-center justify-center shrink-0 shadow-lg ring-4 ring-white border border-slate-700">
                                    {rider.name.charAt(0).toUpperCase()}
                                    <div
                                        className={`absolute -bottom-1 -right-1 w-6 h-6 rounded-full border-2 border-white flex items-center justify-center shadow-xs ${
                                            isOnline ? 'bg-emerald-500' : 'bg-slate-400'
                                        }`}
                                        title={isOnline ? 'On Duty' : 'Off Duty'}
                                    >
                                        <div className="w-2 h-2 rounded-full bg-white" />
                                    </div>
                                </div>

                                <div className="min-w-0 pb-1">
                                    <div className="flex items-center gap-2.5 flex-wrap">
                                        <h1 className="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">
                                            {rider.name}
                                        </h1>
                                    </div>

                                    <div className="mt-1 flex items-center gap-2 text-xs sm:text-sm text-slate-600 flex-wrap">
                                        <span className="font-semibold text-slate-800">
                                            {assignment.company || 'Logistics Partner'}
                                        </span>
                                        <span className="text-slate-300">•</span>
                                        <span className="inline-flex items-center gap-1 text-slate-600">
                                            <MapPin className="w-3.5 h-3.5 text-[#E00D42]" />
                                            {assignment.hub || 'Station Not Assigned'}
                                        </span>
                                        {assignment.barangay && (
                                            <>
                                                <span className="text-slate-300">•</span>
                                                <span className="text-slate-500">{assignment.barangay}</span>
                                            </>
                                        )}
                                    </div>

                                    {/* Status Pills */}
                                    <div className="mt-3 flex items-center gap-2 flex-wrap">
                                        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-bold uppercase tracking-wider bg-slate-100 text-slate-800 border border-slate-200">
                                            <span
                                                className={`w-1.5 h-1.5 rounded-full ${
                                                    rider.account_status === 'active'
                                                        ? 'bg-emerald-500'
                                                        : 'bg-slate-400'
                                                }`}
                                            />
                                            {rider.account_status.replaceAll('_', ' ')}
                                        </span>

                                        <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-bold uppercase tracking-wider bg-slate-100 text-slate-800 border border-slate-200">
                                            <ShieldCheck className="w-3.5 h-3.5 text-indigo-600" />
                                            KYC {rider.kyc_status.replaceAll('_', ' ')}
                                        </span>

                                        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-bold uppercase tracking-wider bg-slate-100 text-slate-800 border border-slate-200">
                                            <span
                                                className={`w-1.5 h-1.5 rounded-full ${
                                                    isOnline ? 'bg-emerald-500 animate-pulse' : 'bg-slate-400'
                                                }`}
                                            />
                                            {isOnline ? 'On Duty' : 'Off Duty'}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            {/* Top Right Actions & Quick Metric */}
                            <div className="flex items-center gap-3 pt-3 md:pt-0 self-start md:self-end">
                                <div className="px-4 py-2 rounded-xl bg-slate-50 border border-slate-200/90 text-right">
                                    <p className="text-[11px] font-semibold text-slate-500">Delivered Final-Mile</p>
                                    <p className="text-lg font-black text-slate-900 tracking-tight">
                                        {completedDeliveries}{' '}
                                        <span className="text-xs font-medium text-slate-500">parcels</span>
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    onClick={toggleDuty}
                                    disabled={dutyLoading}
                                    className={`px-4 py-2.5 rounded-xl text-xs font-bold transition flex items-center gap-2 cursor-pointer shadow-xs disabled:opacity-60 ${
                                        isOnline
                                            ? 'bg-slate-100 text-slate-800 hover:bg-slate-200 border border-slate-200'
                                            : 'bg-[#E00D42] text-white hover:bg-[#C20836]'
                                    }`}
                                >
                                    <Power className="w-3.5 h-3.5" />
                                    <span>{dutyLoading ? 'Updating...' : isOnline ? 'Go Off Duty' : 'Go On Duty'}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                {/* 2. TWO-COLUMN ASYMMETRIC MODERN LAYOUT */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                    {/* LEFT COLUMN: DIGITAL COURIER PASS & IDENTITY (4 COLS) */}
                    <div className="lg:col-span-4 space-y-6">
                        {/* Authentic Digital Courier Pass */}
                        <div className="relative overflow-hidden rounded-2xl bg-gradient-to-b from-slate-900 via-slate-900 to-slate-950 p-6 text-white border border-slate-800 shadow-xl">
                            {/* Holographic Watermark Accent */}
                            <div className="absolute -top-12 -right-12 w-36 h-36 rounded-full bg-[#E00D42]/15 blur-2xl pointer-events-none" />
                            <div className="absolute bottom-0 right-0 p-4 opacity-5 pointer-events-none">
                                <QrCode className="w-32 h-32 text-white" />
                            </div>

                            {/* Pass Header */}
                            <div className="flex items-center justify-between pb-4 border-b border-slate-800/80">
                                <div className="flex items-center gap-2">
                                    <div className="w-7 h-7 rounded-lg bg-[#E00D42] flex items-center justify-center font-black text-xs text-white shadow-xs">
                                        B
                                    </div>
                                    <span className="font-black text-sm tracking-tight text-white">BagooPH</span>
                                </div>
                                <span className="text-[10px] font-mono uppercase tracking-widest text-slate-400 bg-slate-800/90 px-2 py-0.5 rounded border border-slate-700/60">
                                    OFFICIAL COURIER ID
                                </span>
                            </div>

                            {/* Pass Body */}
                            <div className="mt-5 space-y-4">
                                <div>
                                    <p className="text-[10px] uppercase font-bold tracking-wider text-slate-400">
                                        Courier Partner Name
                                    </p>
                                    <p className="text-base font-black text-white tracking-tight mt-0.5">
                                        {rider.name}
                                    </p>
                                </div>

                                <div className="grid grid-cols-2 gap-3 text-xs">
                                    <div>
                                        <p className="text-[10px] uppercase font-bold tracking-wider text-slate-400">
                                            Facility Station
                                        </p>
                                        <p className="font-mono font-bold text-slate-200 mt-0.5">
                                            {assignment.hub_code || 'HUB'}
                                        </p>
                                        <p className="text-[10px] text-slate-400 truncate mt-0.5">
                                            {assignment.hub || 'Unassigned'}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-[10px] uppercase font-bold tracking-wider text-slate-400">
                                            Authorized Vehicle
                                        </p>
                                        <p className="font-mono font-bold text-slate-200 mt-0.5">
                                            {vehicle.plate_number || 'PENDING'}
                                        </p>
                                        <p className="text-[10px] text-slate-400 truncate mt-0.5">
                                            {vehicle.type || 'Standard'}
                                        </p>
                                    </div>
                                </div>

                                <div className="pt-3 border-t border-slate-800/80 flex items-center justify-between text-xs">
                                    <div>
                                        <p className="text-[10px] uppercase font-bold tracking-wider text-slate-400">
                                            LTO License Ref
                                        </p>
                                        <p className="font-mono text-slate-300 font-semibold mt-0.5">
                                            {vehicle.license_number || 'N/A'}
                                        </p>
                                    </div>
                                    <div className="text-right">
                                        <p className="text-[10px] uppercase font-bold tracking-wider text-slate-400">
                                            Clearance
                                        </p>
                                        <p className="font-bold text-emerald-400 mt-0.5">TIER-3 ACTIVE</p>
                                    </div>
                                </div>
                            </div>

                            {/* Pass Footer Barcode Simulation */}
                            <div className="mt-5 pt-4 border-t border-slate-800/80 flex items-center justify-between">
                                <div className="space-y-1">
                                    <div className="h-6 w-36 bg-[repeating-linear-gradient(90deg,#fff,#fff_2px,transparent_2px,transparent_4px)] opacity-60" />
                                    <p className="font-mono text-[9px] text-slate-400 tracking-widest">
                                        BAGOO-COU-{rider.name.slice(0, 3).toUpperCase()}-2026
                                    </p>
                                </div>
                                <ShieldCheck className="w-6 h-6 text-slate-600" />
                            </div>
                        </div>

                        {/* Contact & Dispatch Endpoints */}
                        <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs space-y-4">
                            <h3 className="text-xs font-black uppercase tracking-wider text-slate-400">
                                Contact & Dispatch Endpoints
                            </h3>

                            <div className="space-y-3">
                                {/* Email */}
                                <div className="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-200/80 text-xs">
                                    <div className="flex items-center gap-2.5 min-w-0">
                                        <Mail className="w-4 h-4 text-slate-500 shrink-0" />
                                        <div className="min-w-0">
                                            <p className="text-[10px] text-slate-500 font-medium">Account Email</p>
                                            <p className="font-bold text-slate-900 truncate">{rider.email}</p>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => copyToClipboard(rider.email, 'email')}
                                        className="p-1.5 rounded-lg hover:bg-white text-slate-500 hover:text-slate-900 transition cursor-pointer shrink-0 ml-2"
                                        title="Copy email"
                                    >
                                        {copiedKey === 'email' ? (
                                            <Check className="w-4 h-4 text-emerald-600" />
                                        ) : (
                                            <Copy className="w-4 h-4" />
                                        )}
                                    </button>
                                </div>

                                {/* Phone */}
                                <div className="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-200/80 text-xs">
                                    <div className="flex items-center gap-2.5 min-w-0">
                                        <Phone className="w-4 h-4 text-slate-500 shrink-0" />
                                        <div className="min-w-0">
                                            <p className="text-[10px] text-slate-500 font-medium">Contact Phone</p>
                                            <p className="font-bold text-slate-900 font-mono">
                                                {rider.phone || 'Not recorded'}
                                            </p>
                                        </div>
                                    </div>
                                    {rider.phone && (
                                        <a
                                            href={`tel:${rider.phone}`}
                                            className="px-2.5 py-1 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 font-bold text-xs hover:bg-emerald-100 transition shrink-0 ml-2"
                                        >
                                            Call
                                        </a>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* RIGHT COLUMN: INTERACTIVE TABBED WORKSPACE (8 COLS) */}
                    <div className="lg:col-span-8 space-y-5">
                        {/* Modern Segmented Navigation Tabs */}
                        <div className="bg-slate-100/90 p-1.5 rounded-xl border border-slate-200/80 flex gap-1.5 overflow-x-auto scrollbar-none">
                            <button
                                type="button"
                                onClick={() => setActiveTab('assignment')}
                                className={`flex-1 min-w-[150px] py-2.5 px-4 rounded-lg text-xs font-bold transition cursor-pointer flex items-center justify-center gap-2 shrink-0 ${
                                    activeTab === 'assignment'
                                        ? 'bg-white text-slate-900 shadow-xs'
                                        : 'text-slate-600 hover:text-slate-900'
                                }`}
                            >
                                <Building2
                                    className={`w-4 h-4 shrink-0 ${
                                        activeTab === 'assignment' ? 'text-slate-900' : 'text-slate-400'
                                    }`}
                                />
                                <span>Work Assignment & Hub</span>
                            </button>

                            <button
                                type="button"
                                onClick={() => setActiveTab('vehicle')}
                                className={`flex-1 min-w-[150px] py-2.5 px-4 rounded-lg text-xs font-bold transition cursor-pointer flex items-center justify-center gap-2 shrink-0 ${
                                    activeTab === 'vehicle'
                                        ? 'bg-white text-slate-900 shadow-xs'
                                        : 'text-slate-600 hover:text-slate-900'
                                }`}
                            >
                                <Bike
                                    className={`w-4 h-4 shrink-0 ${
                                        activeTab === 'vehicle' ? 'text-slate-900' : 'text-slate-400'
                                    }`}
                                />
                                <span>Vehicle & Credentials</span>
                            </button>

                            <button
                                type="button"
                                onClick={() => setActiveTab('protocols')}
                                className={`flex-1 min-w-[150px] py-2.5 px-4 rounded-lg text-xs font-bold transition cursor-pointer flex items-center justify-center gap-2 shrink-0 ${
                                    activeTab === 'protocols'
                                        ? 'bg-white text-slate-900 shadow-xs'
                                        : 'text-slate-600 hover:text-slate-900'
                                }`}
                            >
                                <ShieldCheck
                                    className={`w-4 h-4 shrink-0 ${
                                        activeTab === 'protocols' ? 'text-slate-900' : 'text-slate-400'
                                    }`}
                                />
                                <span>Custody & Protocols</span>
                            </button>
                        </div>

                        {/* TAB 1: WORK ASSIGNMENT & HUB */}
                        {activeTab === 'assignment' && (
                            <div className="space-y-4">
                                {/* Primary Bayan Hub Showcase Card */}
                                <div className="bg-white rounded-2xl p-6 border border-slate-200/90 shadow-2xs">
                                    <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                                        <div className="space-y-1">
                                            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                                Assigned Working Station
                                            </span>
                                            <h2 className="text-xl font-black text-slate-900 tracking-tight">
                                                {assignment.hub || 'No Bayan Hub Assigned'}
                                            </h2>
                                            <p className="text-xs text-slate-500 leading-relaxed max-w-lg">
                                                Designated logistics facility for daily parcel intake, merchant drop-offs,
                                                and final-mile route dispatch operations.
                                            </p>
                                        </div>

                                        <div className="flex items-center gap-2 self-start">
                                            <span className="font-mono text-xs font-black text-slate-900 bg-slate-100 px-3 py-1.5 rounded-lg border border-slate-200">
                                                {assignment.hub_code || 'BH-LBN-01'}
                                            </span>
                                        </div>
                                    </div>

                                    {/* Operational Station Features */}
                                    <div className="mt-5 grid grid-cols-1 sm:grid-cols-3 gap-3 pt-5 border-t border-slate-100 text-xs">
                                        <div className="p-3 rounded-xl bg-slate-50/70 border border-slate-200/80">
                                            <p className="text-slate-500 text-[11px] font-medium">Logistics Carrier</p>
                                            <p className="font-bold text-slate-900 mt-0.5 truncate">
                                                {assignment.company || 'Direct Fleet Partner'}
                                            </p>
                                        </div>
                                        <div className="p-3 rounded-xl bg-slate-50/70 border border-slate-200/80">
                                            <p className="text-slate-500 text-[11px] font-medium">Territory Sector</p>
                                            <p className="font-bold text-slate-900 mt-0.5 truncate">
                                                {assignment.barangay || 'All Assigned Sectors'}
                                            </p>
                                        </div>
                                        <div className="p-3 rounded-xl bg-slate-50/70 border border-slate-200/80">
                                            <p className="text-slate-500 text-[11px] font-medium">Operating Window</p>
                                            <p className="font-bold text-slate-900 mt-0.5">07:00 AM – 07:00 PM</p>
                                        </div>
                                    </div>
                                </div>

                                {/* Operational Scope & Territory Cards */}
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs space-y-2">
                                        <div className="flex items-center gap-2 text-slate-900 font-bold text-sm">
                                            <Compass className="w-4 h-4 text-slate-500" />
                                            <span>Delivery Boundary & Dispatch Scope</span>
                                        </div>
                                        <p className="text-xs text-slate-500 leading-relaxed">
                                            Authorized for final-mile direct drops in {assignment.barangay || 'the station sector'}.
                                            Parcels originating from local merchants are consolidated at {assignment.hub_code || 'Bayan Hub'}.
                                        </p>
                                        <div className="pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                                            <span className="text-slate-500">Routing Boundary</span>
                                            <span className="font-semibold text-slate-800">
                                                {assignment.barangay ? 'Sector Locked' : 'Full Hub Scope'}
                                            </span>
                                        </div>
                                    </div>

                                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs space-y-2">
                                        <div className="flex items-center gap-2 text-slate-900 font-bold text-sm">
                                            <Truck className="w-4 h-4 text-slate-500" />
                                            <span>Fleet Carrier Agreement</span>
                                        </div>
                                        <p className="text-xs text-slate-500 leading-relaxed">
                                            Under active agreement with {assignment.company || 'Logistics Partner'}. Standard insurance,
                                            remittance compliance, and rider safety equipment guidelines apply.
                                        </p>
                                        <div className="pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                                            <span className="text-slate-500">Carrier Standing</span>
                                            <span className="font-semibold text-slate-800">Good Standing</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* TAB 2: VEHICLE & CREDENTIALS */}
                        {activeTab === 'vehicle' && (
                            <div className="space-y-4">
                                {/* Authentic Philippine Official License Plate Card */}
                                <div className="bg-white rounded-2xl p-6 border border-slate-200/90 shadow-2xs">
                                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                                        <div>
                                            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                                Official Vehicle Registration
                                            </span>
                                            <h2 className="text-lg font-black text-slate-900 tracking-tight">
                                                Registered Fleet Plate
                                            </h2>
                                            <p className="text-xs text-slate-500 mt-0.5">
                                                Official Land Transportation Office (LTO) motor vehicle registry record.
                                            </p>
                                        </div>

                                        {vehicle.plate_number && (
                                            <button
                                                type="button"
                                                onClick={() => copyToClipboard(vehicle.plate_number!, 'plate')}
                                                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-700 hover:bg-slate-50 transition cursor-pointer self-start sm:self-auto"
                                            >
                                                {copiedKey === 'plate' ? (
                                                    <>
                                                        <Check className="w-3.5 h-3.5 text-emerald-600" />
                                                        <span className="text-emerald-700">Copied</span>
                                                    </>
                                                ) : (
                                                    <>
                                                        <Copy className="w-3.5 h-3.5 text-slate-400" />
                                                        <span>Copy Plate</span>
                                                    </>
                                                )}
                                            </button>
                                        )}
                                    </div>

                                    {/* Realistic Philippine License Plate Component */}
                                    <div className="mt-5 flex justify-center">
                                        <div className="w-full max-w-sm rounded-xl border-4 border-slate-700 bg-gradient-to-b from-white via-slate-50 to-slate-100 p-3 shadow-md">
                                            {/* Plate Header */}
                                            <div className="flex items-center justify-between px-2 text-[10px] font-black uppercase tracking-widest text-slate-500 border-b border-slate-300 pb-1">
                                                <span>PILIPINAS</span>
                                                <div className="flex items-center gap-1 text-[9px] text-slate-400">
                                                    <span>LTO</span>
                                                    <span>•</span>
                                                    <span>NCR / R-IV</span>
                                                </div>
                                            </div>

                                            {/* Embossed Bold Plate Number */}
                                            <div className="py-3 text-center">
                                                <span className="font-mono text-3xl sm:text-4xl font-black tracking-widest text-slate-950 drop-shadow-xs">
                                                    {vehicle.plate_number || 'NOT RECORDED'}
                                                </span>
                                            </div>

                                            {/* Plate Footer */}
                                            <div className="flex items-center justify-between px-2 pt-1 border-t border-slate-200 text-[9px] font-mono text-slate-500 font-semibold">
                                                <span>REGION IV-A (CALABARZON)</span>
                                                <span className="uppercase text-slate-700 font-bold">
                                                    {vehicle.type || 'MC'}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {/* Vehicle Specs & LTO License Grid */}
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    {/* Asset Specification */}
                                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs space-y-3">
                                        <div className="flex items-center gap-2 text-slate-900 font-bold text-sm">
                                            <Bike className="w-4 h-4 text-slate-500" />
                                            <span>Conveyance Specification</span>
                                        </div>
                                        <div className="space-y-2 text-xs">
                                            <div className="flex items-center justify-between p-2 rounded-lg bg-slate-50">
                                                <span className="text-slate-500">Model & Make</span>
                                                <span className="font-bold text-slate-900">
                                                    {vehicle.model || vehicle.type || 'Standard Delivery Vehicle'}
                                                </span>
                                            </div>
                                            <div className="flex items-center justify-between p-2 rounded-lg bg-slate-50">
                                                <span className="text-slate-500">Asset Category</span>
                                                <span className="font-bold text-slate-900">
                                                    {vehicle.type || 'Motorcycle'}
                                                </span>
                                            </div>
                                            <div className="flex items-center justify-between p-2 rounded-lg bg-slate-50">
                                                <span className="text-slate-500">Fleet Allocation</span>
                                                <span className="font-semibold text-slate-800">
                                                    {vehicle.fleet_status || 'Operational'}
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    {/* LTO License & Compliance */}
                                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs space-y-3">
                                        <div className="flex items-center gap-2 text-slate-900 font-bold text-sm">
                                            <IdCard className="w-4 h-4 text-slate-500" />
                                            <span>LTO Professional Driver License</span>
                                        </div>
                                        <div className="space-y-2 text-xs">
                                            <div className="flex items-center justify-between p-2 rounded-lg bg-slate-50">
                                                <span className="text-slate-500">License Number</span>
                                                <span className="font-mono font-bold text-slate-900">
                                                    {vehicle.license_number || 'On File'}
                                                </span>
                                            </div>
                                            <div className="flex items-center justify-between p-2 rounded-lg bg-slate-50">
                                                <span className="text-slate-500">OR/CR Registration</span>
                                                <span className="font-semibold text-slate-800">
                                                    {vehicle.registration_status || 'Verified'}
                                                </span>
                                            </div>
                                            <div className="flex items-center justify-between p-2 rounded-lg bg-slate-50">
                                                <span className="text-slate-500">Restriction Codes</span>
                                                <span className="font-semibold text-slate-800">RC 1, 2 (Pro)</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* TAB 3: CUSTODY & PROTOCOLS */}
                        {activeTab === 'protocols' && (
                            <div className="space-y-4">
                                <div className="bg-white rounded-2xl p-6 border border-slate-200/90 shadow-2xs space-y-5">
                                    <div>
                                        <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                            Operating Guidelines
                                        </span>
                                        <h2 className="text-lg font-black text-slate-900 tracking-tight">
                                            Chain of Custody Standard Operating Procedure
                                        </h2>
                                        <p className="text-xs text-slate-500 mt-0.5">
                                            Verified checkpoints required for all merchant pickups and final-mile deliveries.
                                        </p>
                                    </div>

                                    {/* 3-Step Visual Process */}
                                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
                                        <div className="p-4 rounded-xl bg-slate-50 border border-slate-200/80 space-y-2">
                                            <div className="w-6 h-6 rounded-full bg-slate-900 text-white font-bold flex items-center justify-center text-xs">
                                                1
                                            </div>
                                            <p className="font-bold text-slate-900">Merchant Pickup</p>
                                            <p className="text-slate-500 text-[11px] leading-relaxed">
                                                Confirm physical package count and scan merchant waybill before departure.
                                            </p>
                                        </div>

                                        <div className="p-4 rounded-xl bg-slate-50 border border-slate-200/80 space-y-2">
                                            <div className="w-6 h-6 rounded-full bg-slate-900 text-white font-bold flex items-center justify-center text-xs">
                                                2
                                            </div>
                                            <p className="font-bold text-slate-900">Bayan Hub Sorting</p>
                                            <p className="text-slate-500 text-[11px] leading-relaxed">
                                                Deposit inbound parcels at the designated facility staging bay for intake scan.
                                            </p>
                                        </div>

                                        <div className="p-4 rounded-xl bg-slate-50 border border-slate-200/80 space-y-2">
                                            <div className="w-6 h-6 rounded-full bg-slate-900 text-white font-bold flex items-center justify-center text-xs">
                                                3
                                            </div>
                                            <p className="font-bold text-slate-900">Proof of Delivery</p>
                                            <p className="text-slate-500 text-[11px] leading-relaxed">
                                                Capture photographic proof showing recipient or residential drop point.
                                            </p>
                                        </div>
                                    </div>

                                    {/* Security & COD Guidelines */}
                                    <div className="pt-4 border-t border-slate-100 grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                        <div className="flex items-start gap-2.5">
                                            <CheckCircle2 className="w-4 h-4 text-slate-500 shrink-0 mt-0.5" />
                                            <div>
                                                <p className="font-bold text-slate-900">COD Cash Custody</p>
                                                <p className="text-slate-500 text-[11px]">
                                                    Collected cash remains in courier custody until shift remittance at hub.
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex items-start gap-2.5">
                                            <CheckCircle2 className="w-4 h-4 text-slate-500 shrink-0 mt-0.5" />
                                            <div>
                                                <p className="font-bold text-slate-900">Tamper-Evident Packaging</p>
                                                <p className="text-slate-500 text-[11px]">
                                                    Refuse pickup if vendor seal is broken or liquid leakage is detected.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Administrative Read-Only Advisory Banner */}
                        <div className="rounded-xl border border-slate-200/90 bg-slate-50/80 p-4 text-xs leading-relaxed text-slate-600 flex items-start gap-3 shadow-2xs">
                            <Info className="w-4 h-4 text-slate-400 shrink-0 mt-0.5" />
                            <div>
                                <span className="font-bold text-slate-800">Administrative Notice: </span>
                                Verified rider identity, driver license, vehicle plate number, and hub assignments are
                                centrally managed for regulatory compliance. If your station, vehicle, or personal details
                                require updating, please coordinate with your Bayan Hub logistics operations manager.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </CourierLayout>
    );
}


