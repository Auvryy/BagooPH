import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import {
    Bike,
    Building2,
    Check,
    CheckCircle2,
    Compass,
    Copy,
    FileCheck2,
    IdCard,
    Mail,
    MapPin,
    PackageCheck,
    Phone,
    ShieldAlert,
    ShieldCheck,
    Truck,
    UserCheck,
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

type ProfileTab = 'assignment' | 'vehicle' | 'account';

export default function CourierProfile({
    rider,
    assignment,
    vehicle,
    isOnline,
    completedDeliveries,
}: Props) {
    const [activeTab, setActiveTab] = useState<ProfileTab>('assignment');
    const [copiedKey, setCopiedKey] = useState<string | null>(null);

    const copyToClipboard = (text: string, key: string) => {
        if (!navigator.clipboard) return;
        navigator.clipboard.writeText(text);
        setCopiedKey(key);
        setTimeout(() => setCopiedKey(null), 2000);
    };

    return (
        <CourierLayout
            title="Rider Profile"
            subtitle="Verified identity, active hub assignment, and authorized fleet vehicle"
            isOnline={isOnline}
        >
            <Head title="Rider Profile — BagooPH" />

            <div className="space-y-4 sm:space-y-6 font-sans">
                {/* 1. BENTO HERO PROFILE HEADER */}
                <div className="bg-white rounded-2xl p-4 sm:p-6 border border-slate-200/90 shadow-2xs">
                    <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">
                        {/* Avatar & Rider Identity */}
                        <div className="flex items-start sm:items-center gap-4 min-w-0">
                            <div className="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-gradient-to-br from-slate-900 to-slate-800 text-white font-black text-2xl flex items-center justify-center shrink-0 shadow-md border border-slate-700/50">
                                {rider.name.charAt(0).toUpperCase()}
                            </div>
                            <div className="min-w-0">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <h2 className="text-lg sm:text-2xl font-black text-slate-900 tracking-tight truncate">
                                        {rider.name}
                                    </h2>
                                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-[#E00D42] border border-rose-200">
                                        <UserCheck className="w-3 h-3 text-[#E00D42]" />
                                        Courier Partner
                                    </span>
                                </div>

                                <div className="mt-2 flex flex-wrap items-center gap-2">
                                    {/* Account Status Badge */}
                                    <span
                                        className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md text-[11px] font-bold uppercase tracking-wider ${
                                            rider.account_status === 'active'
                                                ? 'bg-emerald-50 text-emerald-800 border border-emerald-200'
                                                : 'bg-slate-100 text-slate-700 border border-slate-200'
                                        }`}
                                    >
                                        <span
                                            className={`w-1.5 h-1.5 rounded-full ${
                                                rider.account_status === 'active'
                                                    ? 'bg-emerald-500'
                                                    : 'bg-slate-400'
                                            }`}
                                        />
                                        {rider.account_status.replaceAll('_', ' ')}
                                    </span>

                                    {/* KYC Badge */}
                                    <span
                                        className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-bold uppercase tracking-wider ${
                                            rider.kyc_status === 'approved' || rider.kyc_status === 'verified'
                                                ? 'bg-indigo-50 text-indigo-700 border border-indigo-200'
                                                : 'bg-amber-50 text-amber-800 border border-amber-200'
                                        }`}
                                    >
                                        <ShieldCheck className="w-3 h-3 text-indigo-600" />
                                        KYC {rider.kyc_status.replaceAll('_', ' ')}
                                    </span>

                                    {/* Duty Status */}
                                    <span
                                        className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md text-[11px] font-bold uppercase tracking-wider ${
                                            isOnline
                                                ? 'bg-emerald-50 text-emerald-800 border border-emerald-200'
                                                : 'bg-slate-100 text-slate-600 border border-slate-200'
                                        }`}
                                    >
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

                        {/* Top Bento Mini-Stats */}
                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-2.5 pt-4 lg:pt-0 border-t lg:border-t-0 border-slate-100">
                            {/* Completed Deliveries */}
                            <div className="bg-slate-50/80 rounded-xl p-3 border border-slate-200/80">
                                <div className="flex items-center justify-between text-slate-500 text-[11px]">
                                    <span className="font-semibold">Final-Mile</span>
                                    <PackageCheck className="w-3.5 h-3.5 text-[#E00D42]" />
                                </div>
                                <p className="mt-1 text-xl sm:text-2xl font-black text-slate-900 tracking-tight">
                                    {completedDeliveries}
                                </p>
                                <p className="text-[10px] text-slate-500 font-medium">Delivered Parcels</p>
                            </div>

                            {/* Working Hub */}
                            <div className="bg-slate-50/80 rounded-xl p-3 border border-slate-200/80">
                                <div className="flex items-center justify-between text-slate-500 text-[11px]">
                                    <span className="font-semibold">Station</span>
                                    <Building2 className="w-3.5 h-3.5 text-indigo-600" />
                                </div>
                                <p className="mt-1 text-sm sm:text-base font-black text-slate-900 truncate tracking-tight">
                                    {assignment.hub_code || 'HUB'}
                                </p>
                                <p className="text-[10px] text-slate-500 font-medium truncate">
                                    {assignment.hub || 'Unassigned'}
                                </p>
                            </div>

                            {/* Carrier */}
                            <div className="col-span-2 sm:col-span-1 bg-slate-50/80 rounded-xl p-3 border border-slate-200/80">
                                <div className="flex items-center justify-between text-slate-500 text-[11px]">
                                    <span className="font-semibold">Carrier</span>
                                    <Truck className="w-3.5 h-3.5 text-slate-600" />
                                </div>
                                <p className="mt-1 text-sm sm:text-base font-black text-slate-900 truncate tracking-tight">
                                    {assignment.company || 'Logistics'}
                                </p>
                                <p className="text-[10px] text-slate-500 font-medium truncate">Fleet Member</p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* 2. SEGMENTED TAB NAVIGATION (INSIDE PROFILE SO INFO DOES NOT STACK UP) */}
                <div className="bg-slate-100/90 p-1.5 rounded-xl border border-slate-200/80 flex gap-1.5 overflow-x-auto scrollbar-none">
                    <button
                        type="button"
                        onClick={() => setActiveTab('assignment')}
                        className={`flex-1 min-w-[140px] py-2.5 px-3 rounded-lg text-xs font-bold transition cursor-pointer flex items-center justify-center gap-2 shrink-0 ${
                            activeTab === 'assignment'
                                ? 'bg-white text-slate-900 shadow-xs'
                                : 'text-slate-600 hover:text-slate-900'
                        }`}
                    >
                        <Building2
                            className={`w-4 h-4 shrink-0 ${
                                activeTab === 'assignment' ? 'text-[#E00D42]' : 'text-slate-400'
                            }`}
                        />
                        <span>Work Assignment</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => setActiveTab('vehicle')}
                        className={`flex-1 min-w-[140px] py-2.5 px-3 rounded-lg text-xs font-bold transition cursor-pointer flex items-center justify-center gap-2 shrink-0 ${
                            activeTab === 'vehicle'
                                ? 'bg-white text-slate-900 shadow-xs'
                                : 'text-slate-600 hover:text-slate-900'
                        }`}
                    >
                        <Bike
                            className={`w-4 h-4 shrink-0 ${
                                activeTab === 'vehicle' ? 'text-[#E00D42]' : 'text-slate-400'
                            }`}
                        />
                        <span>Vehicle & License</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => setActiveTab('account')}
                        className={`flex-1 min-w-[140px] py-2.5 px-3 rounded-lg text-xs font-bold transition cursor-pointer flex items-center justify-center gap-2 shrink-0 ${
                            activeTab === 'account'
                                ? 'bg-white text-slate-900 shadow-xs'
                                : 'text-slate-600 hover:text-slate-900'
                        }`}
                    >
                        <IdCard
                            className={`w-4 h-4 shrink-0 ${
                                activeTab === 'account' ? 'text-[#E00D42]' : 'text-slate-400'
                            }`}
                        />
                        <span>Account & Contact</span>
                    </button>
                </div>

                {/* 3. TAB CONTENT PANELS (BENTO CARD GRIDS) */}
                {activeTab === 'assignment' && (
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {/* Company Card */}
                        <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                        Carrier Partner
                                    </span>
                                    <h3 className="mt-1 text-base sm:text-lg font-black text-slate-900 tracking-tight">
                                        {assignment.company || 'Direct Logistics Partner'}
                                    </h3>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Authorized carrier company handling regional sorting and parcel fulfillment.
                                    </p>
                                </div>
                                <div className="w-10 h-10 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-center shrink-0">
                                    <Building2 className="w-5 h-5 text-slate-700" />
                                </div>
                            </div>
                            <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                                <span className="text-slate-500">Fleet Status</span>
                                <span className="font-bold text-emerald-600">Active Carrier</span>
                            </div>
                        </div>

                        {/* Working Facility Card */}
                        <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                        Assigned Station
                                    </span>
                                    <h3 className="mt-1 text-base sm:text-lg font-black text-slate-900 tracking-tight">
                                        {assignment.hub || 'No hub assigned'}
                                    </h3>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Designated staging facility for merchant pickup deposits and delivery departures.
                                    </p>
                                </div>
                                <div className="w-10 h-10 rounded-xl bg-rose-50 border border-rose-100 flex items-center justify-center shrink-0">
                                    <MapPin className="w-5 h-5 text-[#E00D42]" />
                                </div>
                            </div>
                            <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                                <span className="text-slate-500">Facility Station Code</span>
                                <span className="font-mono font-bold text-slate-900 bg-slate-100 px-2 py-0.5 rounded border border-slate-200">
                                    {assignment.hub_code || 'N/A'}
                                </span>
                            </div>
                        </div>

                        {/* Assigned Barangay Sector */}
                        <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                        Territory Sector
                                    </span>
                                    <h3 className="mt-1 text-base sm:text-lg font-black text-slate-900 tracking-tight">
                                        {assignment.barangay || 'All Assigned Sectors'}
                                    </h3>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Primary final-mile boundary allocated for residential and business drops.
                                    </p>
                                </div>
                                <div className="w-10 h-10 rounded-xl bg-indigo-50 border border-indigo-100 flex items-center justify-center shrink-0">
                                    <Compass className="w-5 h-5 text-indigo-600" />
                                </div>
                            </div>
                            <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                                <span className="text-slate-500">Route Assignment</span>
                                <span className="font-bold text-slate-800">
                                    {assignment.barangay ? 'Sector Locked' : 'Flexible Hub Scope'}
                                </span>
                            </div>
                        </div>

                        {/* Chain of Custody & Protocols */}
                        <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                        Custody Protocols
                                    </span>
                                    <h3 className="mt-1 text-base sm:text-lg font-black text-slate-900 tracking-tight">
                                        Standard Operating Standards
                                    </h3>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Pickup handoffs require merchant scan or manual intake confirmation before bay transit.
                                    </p>
                                </div>
                                <div className="w-10 h-10 rounded-xl bg-emerald-50 border border-emerald-100 flex items-center justify-center shrink-0">
                                    <ShieldCheck className="w-5 h-5 text-emerald-600" />
                                </div>
                            </div>
                            <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                                <span className="text-slate-500">Delivery Confirmation</span>
                                <span className="font-bold text-emerald-600">Photo Proof Required</span>
                            </div>
                        </div>
                    </div>
                )}

                {activeTab === 'vehicle' && (
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {/* Vehicle Model & Type */}
                        <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                        Assigned Asset
                                    </span>
                                    <h3 className="mt-1 text-base sm:text-lg font-black text-slate-900 tracking-tight">
                                        {vehicle.model
                                            ? `${vehicle.type ?? 'Vehicle'} · ${vehicle.model}`
                                            : vehicle.type || 'Standard Delivery Vehicle'}
                                    </h3>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Inspected conveyance for authorized courier operations and parcel transport.
                                    </p>
                                </div>
                                <div className="w-10 h-10 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-center shrink-0">
                                    <Bike className="w-5 h-5 text-slate-700" />
                                </div>
                            </div>
                            <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                                <span className="text-slate-500">Vehicle Type</span>
                                <span className="font-bold text-slate-900">{vehicle.type ?? 'Motorcycle'}</span>
                            </div>
                        </div>

                        {/* License Plate Card with Stylized Embossed Plate */}
                        <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                        Official Plate Number
                                    </span>
                                    <div className="mt-2 inline-flex items-center gap-2 bg-slate-100 border-2 border-slate-300 rounded-lg px-3 py-1 shadow-inner">
                                        <span className="text-[10px] font-black text-slate-400 uppercase tracking-widest">
                                            PH
                                        </span>
                                        <span className="font-mono text-base font-black text-slate-900 tracking-wider">
                                            {vehicle.plate_number || 'NOT RECORDED'}
                                        </span>
                                    </div>
                                </div>
                                {vehicle.plate_number && (
                                    <button
                                        type="button"
                                        onClick={() => copyToClipboard(vehicle.plate_number!, 'plate')}
                                        className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition cursor-pointer"
                                    >
                                        {copiedKey === 'plate' ? (
                                            <>
                                                <Check className="w-3.5 h-3.5 text-emerald-600" />
                                                <span className="text-emerald-700">Copied</span>
                                            </>
                                        ) : (
                                            <>
                                                <Copy className="w-3.5 h-3.5 text-slate-400" />
                                                <span>Copy</span>
                                            </>
                                        )}
                                    </button>
                                )}
                            </div>
                            <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                                <span className="text-slate-500">LTO Verification</span>
                                <span className="font-bold text-emerald-600">Active Registration</span>
                            </div>
                        </div>

                        {/* Driver's License Number */}
                        <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                        Driver License (LTO)
                                    </span>
                                    <h3 className="mt-1 text-base sm:text-lg font-mono font-black text-slate-900 tracking-tight">
                                        {vehicle.license_number || 'Pending Record'}
                                    </h3>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Official Professional Driver License logged during identity onboarding.
                                    </p>
                                </div>
                                <div className="w-10 h-10 rounded-xl bg-blue-50 border border-blue-100 flex items-center justify-center shrink-0">
                                    <IdCard className="w-5 h-5 text-blue-600" />
                                </div>
                            </div>
                            <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                                <span className="text-slate-500">Authorization</span>
                                <span className="font-bold text-slate-800">Professional RC 1,2</span>
                            </div>
                        </div>

                        {/* Registration & Fleet Status */}
                        <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                        Fleet Compliance & Status
                                    </span>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-xs font-bold uppercase bg-emerald-50 text-emerald-800 border border-emerald-200">
                                            <FileCheck2 className="w-3.5 h-3.5 text-emerald-600" />
                                            OR/CR {vehicle.registration_status || 'Verified'}
                                        </span>
                                        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-xs font-bold uppercase bg-indigo-50 text-indigo-800 border border-indigo-200">
                                            <CheckCircle2 className="w-3.5 h-3.5 text-indigo-600" />
                                            {vehicle.fleet_status || 'Operational'}
                                        </span>
                                    </div>
                                    <p className="mt-2 text-xs text-slate-500">
                                        All statutory roadworthiness documentation is kept on file with logistics compliance.
                                    </p>
                                </div>
                                <div className="w-10 h-10 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-center shrink-0">
                                    <FileCheck2 className="w-5 h-5 text-slate-700" />
                                </div>
                            </div>
                            <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                                <span className="text-slate-500">Safety Inspection</span>
                                <span className="font-bold text-emerald-600">Passed</span>
                            </div>
                        </div>
                    </div>
                )}

                {activeTab === 'account' && (
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {/* Email Address */}
                        <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                        Account Email
                                    </span>
                                    <h3 className="mt-1 text-base sm:text-lg font-bold text-slate-900 truncate">
                                        {rider.email}
                                    </h3>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Used for official dispatch notifications, system alerts, and security logins.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => copyToClipboard(rider.email, 'email')}
                                    className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition cursor-pointer shrink-0"
                                >
                                    {copiedKey === 'email' ? (
                                        <>
                                            <Check className="w-3.5 h-3.5 text-emerald-600" />
                                            <span className="text-emerald-700">Copied</span>
                                        </>
                                    ) : (
                                        <>
                                            <Copy className="w-3.5 h-3.5 text-slate-400" />
                                            <span>Copy</span>
                                        </>
                                    )}
                                </button>
                            </div>
                            <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                                <span className="text-slate-500">Verification</span>
                                <span className="font-bold text-emerald-600">Verified Email</span>
                            </div>
                        </div>

                        {/* Phone Number */}
                        <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                        Contact Phone
                                    </span>
                                    <h3 className="mt-1 text-base sm:text-lg font-mono font-bold text-slate-900">
                                        {rider.phone || 'No phone recorded'}
                                    </h3>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Direct hotline for hub dispatchers and customer delivery confirmations.
                                    </p>
                                </div>
                                {rider.phone && (
                                    <a
                                        href={`tel:${rider.phone}`}
                                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-bold hover:bg-emerald-100 transition shrink-0"
                                    >
                                        <Phone className="w-3.5 h-3.5 text-emerald-600" />
                                        <span>Call</span>
                                    </a>
                                )}
                            </div>
                            <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                                <span className="text-slate-500">SMS Dispatch Alerts</span>
                                <span className="font-bold text-emerald-600">Enabled</span>
                            </div>
                        </div>

                        {/* Government KYC Profile */}
                        <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                        Identity Verification
                                    </span>
                                    <h3 className="mt-1 text-base sm:text-lg font-black text-slate-900 tracking-tight">
                                        Government KYC Record
                                    </h3>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Biometric and official government ID records approved by compliance officer.
                                    </p>
                                </div>
                                <div className="w-10 h-10 rounded-xl bg-emerald-50 border border-emerald-100 flex items-center justify-center shrink-0">
                                    <ShieldCheck className="w-5 h-5 text-emerald-600" />
                                </div>
                            </div>
                            <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                                <span className="text-slate-500">Verification Level</span>
                                <span className="font-bold text-emerald-600 uppercase">
                                    Tier 3 · Fully Verified
                                </span>
                            </div>
                        </div>

                        {/* Security & Access Level */}
                        <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                        Security Clearance
                                    </span>
                                    <h3 className="mt-1 text-base sm:text-lg font-black text-slate-900 tracking-tight">
                                        Courier Field Access
                                    </h3>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Authorized for high-value parcel custody, Bayan Hub intake, and COD collection.
                                    </p>
                                </div>
                                <div className="w-10 h-10 rounded-xl bg-indigo-50 border border-indigo-100 flex items-center justify-center shrink-0">
                                    <CheckCircle2 className="w-5 h-5 text-indigo-600" />
                                </div>
                            </div>
                            <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                                <span className="text-slate-500">System Role</span>
                                <span className="font-bold text-slate-900">Courier / Field Delivery</span>
                            </div>
                        </div>
                    </div>
                )}

                {/* 4. ADMINISTRATIVE READ-ONLY NOTICE BANNER */}
                <div className="rounded-xl border border-slate-200/90 bg-slate-50/80 p-4 text-xs leading-5 text-slate-600 flex items-start gap-3 shadow-2xs">
                    <ShieldAlert className="w-4 h-4 text-slate-500 shrink-0 mt-0.5" />
                    <div>
                        <span className="font-bold text-slate-800">Administrative Notice: </span>
                        Verified identity, driver license, vehicle plate number, and hub assignments are centrally
                        governed for regulatory compliance and insurance coverage. If any credential or station
                        details need modification, please contact your Bayan Hub operations supervisor.
                    </div>
                </div>
            </div>
        </CourierLayout>
    );
}

