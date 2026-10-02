import React, { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import {
    Bike,
    Building2,
    Check,
    CheckCircle2,
    Compass,
    Copy,
    Eye,
    EyeOff,
    LockKeyhole,
    Mail,
    MapPin,
    Pencil,
    Phone,
    Power,
    QrCode,
    Save,
    ShieldCheck,
    Truck,
} from 'lucide-react';
import { useCourierDutyControl } from '@/Components/CourierDutyControl';
import EmailVerificationStatus from '@/Components/EmailVerificationStatus';
import CourierLayout from '@/Layouts/CourierLayout';

interface Props {
    rider: {
        name: string;
        email: string;
        email_verified_at: string | null;
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
}

type ProfileTab = 'assignment' | 'vehicle' | 'protocols' | 'account';

export default function CourierProfile({
    rider,
    assignment,
    vehicle,
    isOnline,
}: Props) {
    const [activeTab, setActiveTab] = useState<ProfileTab>('assignment');
    const [copiedKey, setCopiedKey] = useState<string | null>(null);
    const [showCurrentPassword, setShowCurrentPassword] = useState(false);
    const [showNewPassword, setShowNewPassword] = useState(false);
    const { confirmationDialog, dutyLoading, requestDutyChange } = useCourierDutyControl(isOnline);
    const contactForm = useForm({
        name: rider.name,
        phone: rider.phone ?? '',
    });
    const passwordForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const copyToClipboard = (text: string, key: string) => {
        if (!navigator.clipboard) return;
        navigator.clipboard.writeText(text);
        setCopiedKey(key);
        setTimeout(() => setCopiedKey(null), 2000);
    };

    const saveContactDetails = (event: React.FormEvent) => {
        event.preventDefault();
        contactForm.patch(route('courier.profile.update'), {
            preserveScroll: true,
        });
    };

    const changePassword = (event: React.FormEvent) => {
        event.preventDefault();
        passwordForm.put(route('courier.profile.password.update'), {
            preserveScroll: true,
            onSuccess: () => passwordForm.reset(),
        });
    };

    return (
        <CourierLayout
            title="Courier Profile"
            subtitle="Verified credentials, working station, and vehicle information"
            isOnline={isOnline}
        >
            <Head title="Courier Profile — BagooPH" />

            <div className="space-y-6 font-sans">
                {/* 1. HERO IDENTITY CARD (LIGHT, CLEAN, NO COLOR-MIXING BUG) */}
                <div className="rounded-2xl bg-white border border-slate-200/90 shadow-2xs overflow-hidden">
                    {/* Subtle Light Cover Banner */}
                    <div className="h-28 sm:h-32 bg-slate-50 border-b border-slate-200/70 px-5 sm:px-8 py-4 flex items-start relative">
                        <div className="flex items-center gap-2">
                            <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-white text-slate-800 border border-slate-200/80 shadow-2xs">
                                <ShieldCheck className="w-3.5 h-3.5 text-emerald-600" />
                                <span>Verified Partner</span>
                            </span>
                        </div>
                    </div>

                    {/* Profile Details Container (Separated cleanly below banner) */}
                    <div className="px-5 sm:px-8 pb-6 pt-0">
                        <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-5">
                            {/* Avatar & Rider Name */}
                            <div className="flex flex-col sm:flex-row sm:items-end gap-4 sm:gap-5 min-w-0">
                                {/* Avatar breaks top edge with white ring */}
                                <div className="relative -mt-10 sm:-mt-12 w-20 h-20 sm:w-24 sm:h-24 rounded-2xl bg-slate-900 text-white font-black text-2xl sm:text-3xl flex items-center justify-center shrink-0 shadow-md ring-4 ring-white border border-slate-700">
                                    {rider.name.charAt(0).toUpperCase()}
                                    <div
                                        className={`absolute -bottom-1 -right-1 w-5 h-5 rounded-full border-2 border-white flex items-center justify-center ${
                                            isOnline ? 'bg-emerald-500' : 'bg-[#E00D42]'
                                        }`}
                                        title={isOnline ? 'On Duty' : 'Off Duty'}
                                    >
                                        <div className="w-1.5 h-1.5 rounded-full bg-white" />
                                    </div>
                                </div>

                                {/* Rider Info: Rendered cleanly on white background */}
                                <div className="min-w-0 pt-1">
                                    <h1 className="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">
                                        {rider.name}
                                    </h1>

                                    <div className="mt-1 flex items-center gap-2 text-xs text-slate-500 flex-wrap">
                                        <span className="font-semibold text-slate-700">
                                            {assignment.company || 'Logistics Partner'}
                                        </span>
                                        <span>•</span>
                                        <span className="inline-flex items-center gap-1 text-slate-600">
                                            <MapPin className="w-3 h-3 text-[#E00D42]" />
                                            {assignment.hub || 'Station Not Assigned'}
                                        </span>
                                        {assignment.barangay && (
                                            <>
                                                <span>•</span>
                                                <span>{assignment.barangay}</span>
                                            </>
                                        )}
                                    </div>

                                    {/* Calm Status Badges (KYC duplicate removed) */}
                                    <div className="mt-2.5 flex items-center gap-2">
                                        <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md text-xs font-semibold uppercase bg-slate-100 text-slate-700 border border-slate-200">
                                            <span
                                                className={`w-1.5 h-1.5 rounded-full ${
                                                    rider.account_status === 'active'
                                                        ? 'bg-emerald-500'
                                                        : 'bg-slate-400'
                                                }`}
                                            />
                                            {rider.account_status.replaceAll('_', ' ')}
                                        </span>

                                        <span className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md text-xs font-semibold uppercase border ${
                                            isOnline
                                                ? 'bg-emerald-50 text-emerald-800 border-emerald-200'
                                                : 'bg-rose-50 text-rose-800 border-rose-200'
                                        }`}>
                                            <span
                                                className={`w-1.5 h-1.5 rounded-full ${
                                                    isOnline ? 'bg-emerald-500 animate-pulse' : 'bg-[#E00D42]'
                                                }`}
                                            />
                                            {isOnline ? 'On Duty' : 'Off Duty'}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center pt-3 sm:pt-0 self-start sm:self-end">
                                <button
                                    type="button"
                                    onClick={requestDutyChange}
                                    disabled={dutyLoading}
                                    className={`px-4 py-2.5 rounded-md text-xs font-bold transition flex items-center gap-2 cursor-pointer shadow-xs disabled:opacity-60 ${
                                        isOnline
                                            ? 'bg-rose-50 text-rose-800 hover:bg-rose-100 border border-rose-300'
                                            : 'bg-emerald-600 text-white hover:bg-emerald-700 border border-emerald-700'
                                    }`}
                                >
                                    <Power className={`w-3.5 h-3.5 ${isOnline ? 'text-[#E00D42]' : 'text-white'}`} />
                                    <span>{dutyLoading ? 'Updating...' : isOnline ? 'Go Off Duty' : 'Go On Duty'}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                {/* 2. TWO-COLUMN ASYMMETRIC MODERN LAYOUT */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                    {/* LEFT COLUMN: LIGHT DIGITAL COURIER PASS & CONTACT (4 COLS) */}
                    <div className="lg:col-span-4 space-y-5">
                        {/* Light Digital Courier Pass Card (NO DARK SHADE) */}
                        <div className="rounded-2xl bg-white p-5 border border-slate-200/90 shadow-2xs space-y-4">
                            {/* Card Header */}
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                                <div className="flex items-center gap-2">
                                    <div className="w-6 h-6 rounded-md bg-[#E00D42] flex items-center justify-center font-black text-xs text-white">
                                        B
                                    </div>
                                    <span className="font-bold text-xs text-slate-900">Courier Pass</span>
                                </div>
                                <span className="text-[10px] font-mono uppercase font-semibold text-slate-500 bg-slate-100 px-2 py-0.5 rounded border border-slate-200">
                                    OFFICIAL ID
                                </span>
                            </div>

                            {/* Card Identity Fields */}
                            <div className="space-y-3 text-xs">
                                <div>
                                    <p className="text-[10px] font-medium text-slate-400 uppercase tracking-wider">
                                        Courier Partner
                                    </p>
                                    <p className="font-black text-slate-900 text-sm mt-0.5">{rider.name}</p>
                                </div>

                                <div className="grid grid-cols-2 gap-3 pt-2 border-t border-slate-100">
                                    <div>
                                        <p className="text-[10px] font-medium text-slate-400 uppercase">Station</p>
                                        <p className="font-mono font-bold text-slate-800 mt-0.5">
                                            {assignment.hub_code || 'HUB'}
                                        </p>
                                        <p className="text-[11px] text-slate-500 truncate mt-0.5">
                                            {assignment.hub || 'Unassigned'}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-[10px] font-medium text-slate-400 uppercase">Vehicle</p>
                                        <p className="font-mono font-bold text-slate-800 mt-0.5">
                                            {vehicle.plate_number || 'PENDING'}
                                        </p>
                                        <p className="text-[11px] text-slate-500 truncate mt-0.5">
                                            {vehicle.type || 'Standard'}
                                        </p>
                                    </div>
                                </div>

                                <div className="pt-2 border-t border-slate-100 flex items-center justify-between text-xs">
                                    <div>
                                        <p className="text-[10px] font-medium text-slate-400 uppercase">License Ref</p>
                                        <p className="font-mono font-semibold text-slate-700 mt-0.5">
                                            {vehicle.license_number || 'On File'}
                                        </p>
                                    </div>
                                    <div className="text-right">
                                        <p className="text-[10px] font-medium text-slate-400 uppercase">Clearance</p>
                                        <span className="inline-flex items-center gap-1 text-[11px] font-semibold text-slate-700 mt-0.5">
                                            <ShieldCheck className="w-3.5 h-3.5 text-emerald-600" />
                                            Active
                                        </span>
                                    </div>
                                </div>
                            </div>

                            {/* Minimal Barcode Pattern */}
                            <div className="pt-3 border-t border-slate-100 flex items-center justify-between">
                                <div className="space-y-1">
                                    <div className="h-5 w-32 bg-[repeating-linear-gradient(90deg,#334155,#334155_2px,transparent_2px,transparent_4px)] opacity-50" />
                                    <p className="font-mono text-[9px] text-slate-400 tracking-wider">
                                        BAGOO-COU-2026
                                    </p>
                                </div>
                                <QrCode className="w-6 h-6 text-slate-300" />
                            </div>
                        </div>

                        {/* Contact Information */}
                        <div className="rounded-2xl bg-white p-5 border border-slate-200/90 shadow-2xs space-y-3">
                            <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400">
                                Contact Endpoints
                            </h3>

                            <div className="space-y-2.5 text-xs">
                                {/* Email */}
                                <div className="flex items-center justify-between p-2.5 rounded-xl bg-slate-50 border border-slate-200/80">
                                    <div className="flex items-center gap-2 min-w-0">
                                        <Mail className="w-3.5 h-3.5 text-slate-500 shrink-0" />
                                        <span className="font-semibold text-slate-800 truncate">{rider.email}</span>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => copyToClipboard(rider.email, 'email')}
                                        className="p-1 rounded text-slate-400 hover:text-slate-700 transition cursor-pointer shrink-0 ml-1"
                                        title="Copy email"
                                    >
                                        {copiedKey === 'email' ? (
                                            <Check className="w-3.5 h-3.5 text-emerald-600" />
                                        ) : (
                                            <Copy className="w-3.5 h-3.5" />
                                        )}
                                    </button>
                                </div>

                                {/* Phone */}
                                <div className="flex items-center justify-between p-2.5 rounded-xl bg-slate-50 border border-slate-200/80">
                                    <div className="flex items-center gap-2 min-w-0">
                                        <Phone className="w-3.5 h-3.5 text-slate-500 shrink-0" />
                                        <span className="font-mono font-semibold text-slate-800">
                                            {rider.phone || 'Not recorded'}
                                        </span>
                                    </div>
                                    {rider.phone && (
                                        <a
                                            href={`tel:${rider.phone}`}
                                            className="px-2 py-0.5 rounded bg-white text-slate-700 border border-slate-200 text-xs font-semibold hover:bg-slate-100 transition shrink-0 ml-1"
                                        >
                                            Call
                                        </a>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* RIGHT COLUMN: REFINED WORKSPACE TABS (8 COLS) */}
                    <div className="lg:col-span-8 space-y-5">
                        {/* Segmented Navigation Tabs */}
                        <div className="bg-slate-100/90 p-1.5 rounded-xl border border-slate-200/80 flex gap-1.5 overflow-x-auto scrollbar-none">
                            <button
                                type="button"
                                onClick={() => setActiveTab('assignment')}
                                className={`flex-1 min-w-[140px] py-2 px-3 rounded-lg text-xs font-bold transition cursor-pointer flex items-center justify-center gap-2 shrink-0 ${
                                    activeTab === 'assignment'
                                        ? 'bg-white text-slate-900 shadow-xs'
                                        : 'text-slate-600 hover:text-slate-900'
                                }`}
                            >
                                <Building2 className="w-3.5 h-3.5 shrink-0" />
                                <span>Work Assignment</span>
                            </button>

                            <button
                                type="button"
                                onClick={() => setActiveTab('vehicle')}
                                className={`flex-1 min-w-[140px] py-2 px-3 rounded-lg text-xs font-bold transition cursor-pointer flex items-center justify-center gap-2 shrink-0 ${
                                    activeTab === 'vehicle'
                                        ? 'bg-white text-slate-900 shadow-xs'
                                        : 'text-slate-600 hover:text-slate-900'
                                }`}
                            >
                                <Bike className="w-3.5 h-3.5 shrink-0" />
                                <span>Vehicle & License</span>
                            </button>

                            <button
                                type="button"
                                onClick={() => setActiveTab('protocols')}
                                className={`flex-1 min-w-[140px] py-2 px-3 rounded-lg text-xs font-bold transition cursor-pointer flex items-center justify-center gap-2 shrink-0 ${
                                    activeTab === 'protocols'
                                        ? 'bg-white text-slate-900 shadow-xs'
                                        : 'text-slate-600 hover:text-slate-900'
                                }`}
                            >
                                <ShieldCheck className="w-3.5 h-3.5 shrink-0" />
                                <span>Custody & Protocols</span>
                            </button>

                            <button
                                type="button"
                                onClick={() => setActiveTab('account')}
                                className={`flex-1 min-w-[140px] py-2 px-3 rounded-lg text-xs font-bold transition cursor-pointer flex items-center justify-center gap-2 shrink-0 ${
                                    activeTab === 'account'
                                        ? 'bg-white text-slate-900 shadow-xs'
                                        : 'text-slate-600 hover:text-slate-900'
                                }`}
                            >
                                <LockKeyhole className="w-3.5 h-3.5 shrink-0" />
                                <span>Account & Security</span>
                            </button>
                        </div>

                        {/* TAB 1: WORK ASSIGNMENT */}
                        {activeTab === 'assignment' && (
                            <div className="space-y-4">
                                {/* Station Card */}
                                <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs space-y-4">
                                    <div>
                                        <div>
                                            <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                                Assigned Working Station
                                            </p>
                                            <h2 className="text-lg font-black text-slate-900 tracking-tight mt-0.5">
                                                {assignment.hub || 'No Bayan Hub Assigned'}
                                            </h2>
                                            <p className="text-xs text-slate-500 mt-0.5">
                                                Primary logistics facility for intake and dispatch.
                                            </p>
                                        </div>
                                    </div>

                                    {/* 3 Metrics */}
                                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-3 border-t border-slate-100 text-xs">
                                        <div className="p-2.5 rounded-xl bg-slate-50 border border-slate-200/70">
                                            <p className="text-slate-500 text-[10px]">Logistics Carrier</p>
                                            <p className="font-bold text-slate-900 truncate mt-0.5">
                                                {assignment.company || 'Direct Partner'}
                                            </p>
                                        </div>
                                        <div className="p-2.5 rounded-xl bg-slate-50 border border-slate-200/70">
                                            <p className="text-slate-500 text-[10px]">Territory Sector</p>
                                            <p className="font-bold text-slate-900 truncate mt-0.5">
                                                {assignment.barangay || 'All Sectors'}
                                            </p>
                                        </div>
                                        <div className="p-2.5 rounded-xl bg-slate-50 border border-slate-200/70">
                                            <p className="text-slate-500 text-[10px]">Operating Hours</p>
                                            <p className="font-bold text-slate-900 mt-0.5">07:00 AM – 07:00 PM</p>
                                        </div>
                                    </div>
                                </div>

                                {/* 2 Compact Info Cards */}
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                                    <div className="bg-white rounded-2xl p-4 border border-slate-200/90 shadow-2xs space-y-1.5">
                                        <div className="flex items-center gap-1.5 font-bold text-slate-900">
                                            <Compass className="w-3.5 h-3.5 text-slate-500" />
                                            <span>Delivery Boundary</span>
                                        </div>
                                        <p className="text-slate-500 leading-relaxed">
                                            Authorized for final-mile drops in {assignment.barangay || 'assigned station sector'}.
                                        </p>
                                    </div>

                                    <div className="bg-white rounded-2xl p-4 border border-slate-200/90 shadow-2xs space-y-1.5">
                                        <div className="flex items-center gap-1.5 font-bold text-slate-900">
                                            <Truck className="w-3.5 h-3.5 text-slate-500" />
                                            <span>Carrier Standing</span>
                                        </div>
                                        <p className="text-slate-500 leading-relaxed">
                                            Active fleet member with {assignment.company || 'Logistics Partner'}.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* TAB 2: VEHICLE & LICENSE */}
                        {activeTab === 'vehicle' && (
                            <div className="space-y-4">
                                {/* Plate Card */}
                                <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs space-y-4">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                                Fleet Plate
                                            </p>
                                            <h2 className="text-base font-black text-slate-900 tracking-tight mt-0.5">
                                                Registered Plate Number
                                            </h2>
                                        </div>
                                        {vehicle.plate_number && (
                                            <button
                                                type="button"
                                                onClick={() => copyToClipboard(vehicle.plate_number!, 'plate')}
                                                className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg border border-slate-200 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition cursor-pointer"
                                            >
                                                {copiedKey === 'plate' ? (
                                                    <Check className="w-3.5 h-3.5 text-emerald-600" />
                                                ) : (
                                                    <Copy className="w-3.5 h-3.5 text-slate-400" />
                                                )}
                                                <span>Copy</span>
                                            </button>
                                        )}
                                    </div>

                                    {/* Realistic Philippine Plate */}
                                    <div className="flex justify-center py-1">
                                        <div className="w-full max-w-xs rounded-xl border-2 border-slate-600 bg-white p-3 shadow-xs">
                                            <div className="flex items-center justify-between px-1 text-[9px] font-black uppercase text-slate-400 border-b border-slate-200 pb-1">
                                                <span>PILIPINAS</span>
                                                <span>LTO • NCR/R-IV</span>
                                            </div>
                                            <div className="py-2 text-center">
                                                <span className="font-mono text-2xl sm:text-3xl font-black tracking-widest text-slate-950">
                                                    {vehicle.plate_number || 'NOT RECORDED'}
                                                </span>
                                            </div>
                                            <div className="flex items-center justify-between px-1 pt-1 border-t border-slate-100 text-[8px] font-mono text-slate-400">
                                                <span>REGION IV-A</span>
                                                <span className="uppercase text-slate-600 font-bold">
                                                    {vehicle.type || 'MC'}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {/* Vehicle Specs Grid */}
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                                    <div className="bg-white rounded-2xl p-4 border border-slate-200/90 shadow-2xs space-y-2">
                                        <p className="font-bold text-slate-900">Vehicle Specification</p>
                                        <div className="space-y-1.5 text-slate-600">
                                            <div className="flex justify-between py-1 border-b border-slate-100">
                                                <span>Model</span>
                                                <span className="font-semibold text-slate-900">
                                                    {vehicle.model || vehicle.type || 'Standard'}
                                                </span>
                                            </div>
                                            <div className="flex justify-between py-1 border-b border-slate-100">
                                                <span>Category</span>
                                                <span className="font-semibold text-slate-900">
                                                    {vehicle.type || 'Motorcycle'}
                                                </span>
                                            </div>
                                            <div className="flex justify-between pt-1">
                                                <span>Status</span>
                                                <span className="font-semibold text-slate-700">
                                                    {vehicle.fleet_status || 'Operational'}
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="bg-white rounded-2xl p-4 border border-slate-200/90 shadow-2xs space-y-2">
                                        <p className="font-bold text-slate-900">LTO Credentials</p>
                                        <div className="space-y-1.5 text-slate-600">
                                            <div className="flex justify-between py-1 border-b border-slate-100">
                                                <span>License No.</span>
                                                <span className="font-mono font-semibold text-slate-900">
                                                    {vehicle.license_number || 'On File'}
                                                </span>
                                            </div>
                                            <div className="flex justify-between py-1 border-b border-slate-100">
                                                <span>OR/CR</span>
                                                <span className="font-semibold text-slate-700">
                                                    {vehicle.registration_status || 'Verified'}
                                                </span>
                                            </div>
                                            <div className="flex justify-between pt-1">
                                                <span>Restriction</span>
                                                <span className="font-semibold text-slate-700">RC 1, 2</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* TAB 3: CUSTODY CHECKPOINTS (UNCLUTTERED, REDUCED TEXT) */}
                        {activeTab === 'protocols' && (
                            <div className="space-y-4">
                                <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs space-y-4">
                                    <div>
                                        <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                            Operating Guidelines
                                        </p>
                                        <h2 className="text-base font-black text-slate-900 tracking-tight mt-0.5">
                                            Custody Checkpoints
                                        </h2>
                                        <p className="text-xs text-slate-500 mt-0.5">
                                            Standard 3-step delivery verification.
                                        </p>
                                    </div>

                                    {/* 3 Punchy Steps */}
                                    <div className="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
                                        <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200/70 space-y-1">
                                            <div className="w-5 h-5 rounded-full bg-slate-900 text-white font-bold flex items-center justify-center text-[10px]">
                                                1
                                            </div>
                                            <p className="font-bold text-slate-900">Merchant Pickup</p>
                                            <p className="text-slate-500 text-[11px] leading-relaxed">
                                                Scan waybill & verify package count.
                                            </p>
                                        </div>

                                        <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200/70 space-y-1">
                                            <div className="w-5 h-5 rounded-full bg-slate-900 text-white font-bold flex items-center justify-center text-[10px]">
                                                2
                                            </div>
                                            <p className="font-bold text-slate-900">Hub Intake</p>
                                            <p className="text-slate-500 text-[11px] leading-relaxed">
                                                Deposit at assigned staging bay.
                                            </p>
                                        </div>

                                        <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200/70 space-y-1">
                                            <div className="w-5 h-5 rounded-full bg-slate-900 text-white font-bold flex items-center justify-center text-[10px]">
                                                3
                                            </div>
                                            <p className="font-bold text-slate-900">Proof of Delivery</p>
                                            <p className="text-slate-500 text-[11px] leading-relaxed">
                                                Capture drop-off photo & handoff.
                                            </p>
                                        </div>
                                    </div>

                                    {/* Concise Policy Note */}
                                    <div className="pt-3 border-t border-slate-100 flex items-center gap-2 text-xs text-slate-600">
                                        <CheckCircle2 className="w-3.5 h-3.5 text-slate-500 shrink-0" />
                                        <span>COD cash remains in courier custody until shift remittance at hub.</span>
                                    </div>
                                </div>
                            </div>
                        )}

                        {activeTab === 'account' && (
                            <div className="space-y-4">
                                <section className="rounded-lg border border-slate-300 bg-white p-5 shadow-2xs">
                                    <div className="flex items-start gap-3 border-b border-slate-200 pb-4">
                                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-slate-50 text-slate-700">
                                            <Pencil className="h-4 w-4" />
                                        </div>
                                        <div>
                                            <h2 className="text-base font-bold text-slate-950">Account contact details</h2>
                                            <p className="mt-1 text-xs leading-5 text-slate-600">
                                                Update the name shown in courier communications and your mobile number. Your working station,
                                                vehicle, licence, and clearance are managed by logistics.
                                            </p>
                                        </div>
                                    </div>

                                    <form onSubmit={saveContactDetails} className="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div>
                                            <label htmlFor="courier-name" className="text-xs font-bold text-slate-800">
                                                Display name
                                            </label>
                                            <input
                                                id="courier-name"
                                                value={contactForm.data.name}
                                                onChange={(event) => contactForm.setData('name', event.target.value)}
                                                autoComplete="name"
                                                className="mt-1.5 w-full rounded-sm border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15"
                                            />
                                            {contactForm.errors.name && (
                                                <p className="mt-1.5 text-xs font-medium text-[#E00D42]">{contactForm.errors.name}</p>
                                            )}
                                        </div>
                                        <div>
                                            <label htmlFor="courier-phone" className="text-xs font-bold text-slate-800">
                                                Mobile number
                                            </label>
                                            <input
                                                id="courier-phone"
                                                value={contactForm.data.phone}
                                                onChange={(event) => contactForm.setData('phone', event.target.value)}
                                                autoComplete="tel"
                                                inputMode="tel"
                                                placeholder="0917 123 4567"
                                                className="mt-1.5 w-full rounded-sm border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15"
                                            />
                                            {contactForm.errors.phone && (
                                                <p className="mt-1.5 text-xs font-medium text-[#E00D42]">{contactForm.errors.phone}</p>
                                            )}
                                        </div>
                                        <div className="sm:col-span-2 flex flex-col gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
                                            <p className="text-xs text-slate-500">
                                                Account email: <span className="font-semibold text-slate-700">{rider.email}</span>
                                            </p>
                                            <button
                                                type="submit"
                                                disabled={contactForm.processing}
                                                className="inline-flex items-center justify-center gap-1.5 rounded-sm bg-[#E00D42] px-3.5 py-2 text-xs font-bold text-white transition hover:bg-[#C20836] disabled:opacity-50"
                                            >
                                                <Save className="h-3.5 w-3.5" />
                                                {contactForm.processing ? 'Saving...' : 'Save details'}
                                            </button>
                                        </div>
                                    </form>
                                </section>

                                <section className="rounded-lg border border-slate-300 bg-white p-5 shadow-2xs">
                                    <div className="flex items-start gap-3 border-b border-slate-200 pb-4">
                                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-slate-50 text-slate-700">
                                            <LockKeyhole className="h-4 w-4" />
                                        </div>
                                        <div>
                                            <h2 className="text-base font-bold text-slate-950">Change password</h2>
                                            <p className="mt-1 text-xs leading-5 text-slate-600">
                                                Confirm your current password, then choose a strong new password.
                                            </p>
                                        </div>
                                    </div>

                                    <EmailVerificationStatus
                                        email={rider.email}
                                        verifiedAt={rider.email_verified_at}
                                        className="mt-5"
                                    />

                                    {rider.email_verified_at && (
                                    <form onSubmit={changePassword} className="mt-5 space-y-4">
                                        <PasswordField
                                            id="courier-current-password"
                                            label="Current password"
                                            value={passwordForm.data.current_password}
                                            error={passwordForm.errors.current_password}
                                            visible={showCurrentPassword}
                                            onToggle={() => setShowCurrentPassword(!showCurrentPassword)}
                                            onChange={(value) => passwordForm.setData('current_password', value)}
                                            autoComplete="current-password"
                                        />
                                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                            <PasswordField
                                                id="courier-new-password"
                                                label="New password"
                                                value={passwordForm.data.password}
                                                error={passwordForm.errors.password}
                                                visible={showNewPassword}
                                                onToggle={() => setShowNewPassword(!showNewPassword)}
                                                onChange={(value) => passwordForm.setData('password', value)}
                                                autoComplete="new-password"
                                            />
                                            <PasswordField
                                                id="courier-confirm-password"
                                                label="Confirm new password"
                                                value={passwordForm.data.password_confirmation}
                                                error={passwordForm.errors.password_confirmation}
                                                visible={showNewPassword}
                                                onToggle={() => setShowNewPassword(!showNewPassword)}
                                                onChange={(value) => passwordForm.setData('password_confirmation', value)}
                                                autoComplete="new-password"
                                            />
                                        </div>
                                        <div className="flex justify-end border-t border-slate-100 pt-4">
                                            <button
                                                type="submit"
                                                disabled={passwordForm.processing}
                                                className="inline-flex items-center justify-center gap-1.5 rounded-sm bg-slate-900 px-3.5 py-2 text-xs font-bold text-white transition hover:bg-slate-700 disabled:opacity-50"
                                            >
                                                <LockKeyhole className="h-3.5 w-3.5" />
                                                {passwordForm.processing ? 'Updating...' : 'Update password'}
                                            </button>
                                        </div>
                                    </form>
                                    )}
                                </section>
                            </div>
                        )}

                        {/* Concise 1-Line Advisory Footer */}
                        <p className="text-center text-xs text-slate-400 py-1">
                            Need to update your station or vehicle? Coordinate with your Bayan Hub supervisor.
                        </p>
                    </div>
                </div>
            </div>
            {confirmationDialog}
        </CourierLayout>
    );
}

function PasswordField({
    id,
    label,
    value,
    error,
    visible,
    onToggle,
    onChange,
    autoComplete,
}: {
    id: string;
    label: string;
    value: string;
    error?: string;
    visible: boolean;
    onToggle: () => void;
    onChange: (value: string) => void;
    autoComplete: string;
}) {
    return (
        <div>
            <label htmlFor={id} className="text-xs font-bold text-slate-800">
                {label}
            </label>
            <div className="relative mt-1.5">
                <input
                    id={id}
                    type={visible ? 'text' : 'password'}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    autoComplete={autoComplete}
                    className="w-full rounded-sm border border-slate-300 px-3 py-2 pr-10 text-sm text-slate-900 outline-none transition focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15"
                />
                <button
                    type="button"
                    onClick={onToggle}
                    className="absolute inset-y-0 right-0 flex items-center px-3 text-slate-400 transition hover:text-slate-700"
                    aria-label={visible ? 'Hide password' : 'Show password'}
                >
                    {visible ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </button>
            </div>
            {error && <p className="mt-1.5 text-xs font-medium text-[#E00D42]">{error}</p>}
        </div>
    );
}
