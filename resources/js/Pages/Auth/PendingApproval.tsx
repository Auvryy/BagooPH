import React, { FormEventHandler, useRef, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import { CourierProfile, Shop, User } from '@/types';
import { 
    Clock, 
    AlertTriangle, 
    CheckCircle2, 
    RefreshCw, 
    LogOut, 
    Upload, 
    FileText, 
    ShieldAlert, 
    ShieldCheck, 
    Store, 
    Truck, 
    UserCheck, 
    X,
    ArrowRight,
    Sparkles
} from 'lucide-react';

interface PendingApprovalProps {
    user: User;
    shop?: Shop | null;
    courierProfile?: CourierProfile | null;
}

export default function PendingApproval({ user, shop, courierProfile }: PendingApprovalProps) {
    const isRejected = user.kyc_status === 'rejected';

    const idInputRef = useRef<HTMLInputElement>(null);
    const permitInputRef = useRef<HTMLInputElement>(null);
    const licenseInputRef = useRef<HTMLInputElement>(null);
    const orCrInputRef = useRef<HTMLInputElement>(null);

    const [idFileName, setIdFileName] = useState<string | null>(null);
    const [permitFileName, setPermitFileName] = useState<string | null>(null);
    const [licenseFileName, setLicenseFileName] = useState<string | null>(null);
    const [orCrFileName, setOrCrFileName] = useState<string | null>(null);

    const { data, setData, post, processing, errors, reset } = useForm<{
        id_document: File | null;
        business_permit: File | null;
        driver_license: File | null;
        or_cr_document: File | null;
    }>({
        id_document: null,
        business_permit: null,
        driver_license: null,
        or_cr_document: null,
    });

    const handleResubmit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('kyc.resubmit'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setIdFileName(null);
                setPermitFileName(null);
                setLicenseFileName(null);
                setOrCrFileName(null);
                reset();
            },
        });
    };

    const handleLogout = () => {
        router.post(route('logout'));
    };

    return (
        <GuestLayout
            title={isRejected ? "KYC Verification Action Required" : "Account Verification Pending"}
            subtitle={
                isRejected 
                    ? "Your verification documents were flagged by compliance. Please review feedback and resubmit." 
                    : "Your application is currently under review by the Bagoo Platform Governance Team."
            }
            headerBadge="GOVERNANCE // KYC GATE"
        >
            <Head title="Account Verification Status — BagooPH" />

            <div className="space-y-6 font-mono text-xs">
                {/* Status Hero Card */}
                {isRejected ? (
                    <div className="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-900 space-y-3">
                        <div className="flex items-center gap-2.5">
                            <div className="w-8 h-8 rounded-lg bg-rose-600 text-white flex items-center justify-center shrink-0">
                                <ShieldAlert className="w-4 h-4" />
                            </div>
                            <div>
                                <span className="font-bold text-sm block">Application Status: REJECTED</span>
                                <span className="text-[10px] text-rose-700">Action Required: Resubmit Corrected Documents</span>
                            </div>
                        </div>

                        {user.kyc_feedback && (
                            <div className="p-3 bg-white border border-rose-200 rounded-lg text-xs space-y-1">
                                <span className="font-bold uppercase tracking-wider text-[10px] text-rose-800 block">
                                    Compliance Officer Feedback:
                                </span>
                                <p className="text-black font-sans leading-relaxed">
                                    {user.kyc_feedback}
                                </p>
                            </div>
                        )}
                    </div>
                ) : (
                    <div className="p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 space-y-3">
                        <div className="flex items-center justify-between gap-3">
                            <div className="flex items-center gap-2.5">
                                <div className="w-8 h-8 rounded-lg bg-amber-500 text-white flex items-center justify-center shrink-0 animate-pulse">
                                    <Clock className="w-4 h-4" />
                                </div>
                                <div>
                                    <span className="font-bold text-sm block">Application Status: IN REVIEW</span>
                                    <span className="text-[10px] text-amber-700">Estimated turnaround: 2–24 business hours</span>
                                </div>
                            </div>

                            <button
                                onClick={() => router.reload()}
                                className="px-2.5 py-1.5 bg-amber-100 hover:bg-amber-200 text-amber-900 rounded-md font-bold text-[10px] uppercase flex items-center gap-1.5 transition cursor-pointer"
                            >
                                <RefreshCw className="w-3 h-3" />
                                <span>Check Status</span>
                            </button>
                        </div>

                        <p className="text-[11px] text-amber-800/90 font-sans">
                            Thank you for registering with BagooPH. Your submitted identity and business credentials are being inspected by our trust and safety team.
                        </p>
                    </div>
                )}

                {/* Applicant Summary */}
                <div className="p-3.5 bg-white border border-black/10 rounded-xl space-y-2">
                    <div className="flex items-center justify-between border-b border-black/10 pb-2">
                        <span className="text-[10px] font-bold uppercase tracking-wider text-black/50">Applicant Profile</span>
                        <span className="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-black text-white">
                            {user.role}
                        </span>
                    </div>

                    <div className="grid grid-cols-2 gap-2 text-[11px]">
                        <div>
                            <span className="text-black/50 block text-[9px] uppercase">Applicant Name</span>
                            <span className="font-bold">{user.name}</span>
                        </div>
                        <div>
                            <span className="text-black/50 block text-[9px] uppercase">Account Email</span>
                            <span className="font-bold truncate block">{user.email}</span>
                        </div>
                        {shop && (
                            <div className="col-span-2 pt-1 border-t border-black/5 flex items-center gap-2">
                                <Store className="w-3.5 h-3.5 text-[#E00D42]" />
                                <div>
                                    <span className="text-black/50 block text-[9px] uppercase">Registered Store</span>
                                    <span className="font-bold">{shop.name} ({shop.city || 'Metro Manila'})</span>
                                </div>
                            </div>
                        )}
                        {courierProfile && (
                            <div className="col-span-2 pt-1 border-t border-black/5 flex items-center gap-2">
                                <Truck className="w-3.5 h-3.5 text-emerald-600" />
                                <div>
                                    <span className="text-black/50 block text-[9px] uppercase">Assigned Fleet Specs</span>
                                    <span className="font-bold">{courierProfile.vehicle_type} — Plate: {courierProfile.plate_number || 'N/A'}</span>
                                </div>
                            </div>
                        )}
                    </div>
                </div>

                {/* Document Verification Checklist (Pending Mode) */}
                {!isRejected && (
                    <div className="p-3.5 bg-[#F4F2EC] border border-black/10 rounded-xl space-y-2.5">
                        <span className="text-[10px] font-bold uppercase tracking-wider text-black/70 block">
                            Compliance Intake Checklist:
                        </span>
                        <div className="space-y-1.5 text-[11px]">
                            <div className="flex items-center gap-2 text-emerald-700">
                                <CheckCircle2 className="w-4 h-4 shrink-0" />
                                <span>Government Identification Verification</span>
                            </div>
                            {user.role === 'seller' && (
                                <div className="flex items-center gap-2 text-emerald-700">
                                    <CheckCircle2 className="w-4 h-4 shrink-0" />
                                    <span>Merchant Business Registration (DTI / SEC)</span>
                                </div>
                            )}
                            {user.role === 'courier' && (
                                <>
                                    <div className="flex items-center gap-2 text-emerald-700">
                                        <CheckCircle2 className="w-4 h-4 shrink-0" />
                                        <span>LTO Driver's License & Vehicle OR/CR</span>
                                    </div>
                                    <div className="flex items-center gap-2 text-emerald-700">
                                        <CheckCircle2 className="w-4 h-4 shrink-0" />
                                        <span>Courier Route Zone Allocation</span>
                                    </div>
                                </>
                            )}
                            <div className="flex items-center gap-2 text-black/40">
                                <div className="w-4 h-4 rounded-full border-2 border-black/30 flex items-center justify-center shrink-0 text-[9px] font-bold">
                                    •
                                </div>
                                <span>Platform Admin One-Click Final Signature</span>
                            </div>
                        </div>
                    </div>
                )}

                {/* Resubmission Form (Rejected Mode) */}
                {isRejected && (
                    <form onSubmit={handleResubmit} className="p-4 bg-white border-2 border-[#E00D42]/30 rounded-xl space-y-4">
                        <div className="flex items-center gap-2 border-b border-black/10 pb-2">
                            <Upload className="w-4 h-4 text-[#E00D42]" />
                            <span className="font-bold text-xs uppercase tracking-wider">
                                Upload Corrected Documents
                            </span>
                        </div>

                        {/* Government ID Re-upload */}
                        <div>
                            <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                                Government ID (Passport / UMID / Driver's)
                            </label>
                            <input
                                type="file"
                                ref={idInputRef}
                                onChange={(e) => {
                                    const file = e.target.files?.[0];
                                    if (file) {
                                        setData('id_document', file);
                                        setIdFileName(file.name);
                                    }
                                }}
                                accept="image/jpeg,image/png,image/webp,application/pdf"
                                className="hidden"
                            />
                            {!idFileName ? (
                                <div 
                                    onClick={() => idInputRef.current?.click()}
                                    className="border-2 border-dashed border-black/20 hover:border-[#E00D42] rounded-lg p-2.5 text-center cursor-pointer transition flex items-center justify-center gap-2 bg-[#FBFBFA]"
                                >
                                    <Upload className="w-3.5 h-3.5 text-black/40" />
                                    <span className="text-[10px] text-black/70">Select New Gov ID File (Max 5MB)</span>
                                </div>
                            ) : (
                                <div className="flex items-center justify-between p-2 bg-[#F4F2EC] border border-black/15 rounded-lg text-xs">
                                    <div className="flex items-center gap-2 truncate">
                                        <FileText className="w-4 h-4 text-[#E00D42] shrink-0" />
                                        <span className="font-bold truncate text-[11px]">{idFileName}</span>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setData('id_document', null);
                                            setIdFileName(null);
                                            if (idInputRef.current) idInputRef.current.value = '';
                                        }}
                                        className="p-1 hover:bg-black/10 rounded"
                                    >
                                        <X className="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            )}
                            <InputError message={errors.id_document} className="mt-1" />
                        </div>

                        {/* Seller Permit Re-upload */}
                        {user.role === 'seller' && (
                            <div>
                                <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                                    Updated Business Permit / DTI Certificate
                                </label>
                                <input
                                    type="file"
                                    ref={permitInputRef}
                                    onChange={(e) => {
                                        const file = e.target.files?.[0];
                                        if (file) {
                                            setData('business_permit', file);
                                            setPermitFileName(file.name);
                                        }
                                    }}
                                    accept="image/jpeg,image/png,image/webp,application/pdf"
                                    className="hidden"
                                />
                                {!permitFileName ? (
                                    <div 
                                        onClick={() => permitInputRef.current?.click()}
                                        className="border-2 border-dashed border-black/20 hover:border-[#E00D42] rounded-lg p-2.5 text-center cursor-pointer transition flex items-center justify-center gap-2 bg-[#FBFBFA]"
                                    >
                                        <Upload className="w-3.5 h-3.5 text-black/40" />
                                        <span className="text-[10px] text-black/70">Select New Business Permit File (Max 5MB)</span>
                                    </div>
                                ) : (
                                    <div className="flex items-center justify-between p-2 bg-[#F4F2EC] border border-black/15 rounded-lg text-xs">
                                        <div className="flex items-center gap-2 truncate">
                                            <FileText className="w-4 h-4 text-emerald-600 shrink-0" />
                                            <span className="font-bold truncate text-[11px]">{permitFileName}</span>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setData('business_permit', null);
                                                setPermitFileName(null);
                                                if (permitInputRef.current) permitInputRef.current.value = '';
                                            }}
                                            className="p-1 hover:bg-black/10 rounded"
                                        >
                                            <X className="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                )}
                                <InputError message={errors.business_permit} className="mt-1" />
                            </div>
                        )}

                        {/* Courier License & OR/CR Re-upload */}
                        {user.role === 'courier' && (
                            <>
                                <div>
                                    <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                                        Driver's License
                                    </label>
                                    <input
                                        type="file"
                                        ref={licenseInputRef}
                                        onChange={(e) => {
                                            const file = e.target.files?.[0];
                                            if (file) {
                                                setData('driver_license', file);
                                                setLicenseFileName(file.name);
                                            }
                                        }}
                                        accept="image/jpeg,image/png,image/webp,application/pdf"
                                        className="hidden"
                                    />
                                    {!licenseFileName ? (
                                        <div 
                                            onClick={() => licenseInputRef.current?.click()}
                                            className="border-2 border-dashed border-black/20 hover:border-[#E00D42] rounded-lg p-2.5 text-center cursor-pointer transition flex items-center justify-center gap-2 bg-[#FBFBFA]"
                                        >
                                            <Upload className="w-3.5 h-3.5 text-black/40" />
                                            <span className="text-[10px] text-black/70">Select New Driver's License File</span>
                                        </div>
                                    ) : (
                                        <div className="flex items-center justify-between p-2 bg-[#F4F2EC] border border-black/15 rounded-lg text-xs">
                                            <div className="flex items-center gap-2 truncate">
                                                <FileText className="w-4 h-4 text-blue-600 shrink-0" />
                                                <span className="font-bold truncate text-[11px]">{licenseFileName}</span>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setData('driver_license', null);
                                                    setLicenseFileName(null);
                                                    if (licenseInputRef.current) licenseInputRef.current.value = '';
                                                }}
                                                className="p-1 hover:bg-black/10 rounded"
                                            >
                                                <X className="w-3.5 h-3.5" />
                                            </button>
                                        </div>
                                    )}
                                    <InputError message={errors.driver_license} className="mt-1" />
                                </div>

                                <div>
                                    <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                                        Vehicle Registration (OR / CR)
                                    </label>
                                    <input
                                        type="file"
                                        ref={orCrInputRef}
                                        onChange={(e) => {
                                            const file = e.target.files?.[0];
                                            if (file) {
                                                setData('or_cr_document', file);
                                                setOrCrFileName(file.name);
                                            }
                                        }}
                                        accept="image/jpeg,image/png,image/webp,application/pdf"
                                        className="hidden"
                                    />
                                    {!orCrFileName ? (
                                        <div 
                                            onClick={() => orCrInputRef.current?.click()}
                                            className="border-2 border-dashed border-black/20 hover:border-[#E00D42] rounded-lg p-2.5 text-center cursor-pointer transition flex items-center justify-center gap-2 bg-[#FBFBFA]"
                                        >
                                            <Upload className="w-3.5 h-3.5 text-black/40" />
                                            <span className="text-[10px] text-black/70">Select New OR/CR File</span>
                                        </div>
                                    ) : (
                                        <div className="flex items-center justify-between p-2 bg-[#F4F2EC] border border-black/15 rounded-lg text-xs">
                                            <div className="flex items-center gap-2 truncate">
                                                <FileText className="w-4 h-4 text-emerald-600 shrink-0" />
                                                <span className="font-bold truncate text-[11px]">{orCrFileName}</span>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setData('or_cr_document', null);
                                                    setOrCrFileName(null);
                                                    if (orCrInputRef.current) orCrInputRef.current.value = '';
                                                }}
                                                className="p-1 hover:bg-black/10 rounded"
                                            >
                                                <X className="w-3.5 h-3.5" />
                                            </button>
                                        </div>
                                    )}
                                    <InputError message={errors.or_cr_document} className="mt-1" />
                                </div>
                            </>
                        )}

                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full py-2.5 bg-[#E00D42] hover:bg-[#C20836] text-white font-bold rounded-lg uppercase tracking-wider flex items-center justify-center gap-2 transition cursor-pointer disabled:opacity-50"
                        >
                            <span>{processing ? 'Submitting Documents...' : 'Resubmit KYC Documents'}</span>
                            <ArrowRight className="w-4 h-4" />
                        </button>
                    </form>
                )}

                {/* Footer Controls: Refresh & Logout */}
                <div className="pt-2 flex items-center justify-between gap-3 border-t border-black/10">
                    <button
                        type="button"
                        onClick={() => router.reload()}
                        className="px-3 py-2 bg-white border border-black/20 hover:border-black text-black font-bold rounded-lg text-[11px] uppercase flex items-center gap-1.5 transition cursor-pointer"
                    >
                        <RefreshCw className="w-3.5 h-3.5" />
                        <span>Refresh Status</span>
                    </button>

                    <button
                        type="button"
                        onClick={handleLogout}
                        className="px-3 py-2 bg-black hover:bg-rose-700 text-white font-bold rounded-lg text-[11px] uppercase flex items-center gap-1.5 transition cursor-pointer"
                    >
                        <LogOut className="w-3.5 h-3.5" />
                        <span>Sign Out</span>
                    </button>
                </div>
            </div>
        </GuestLayout>
    );
}
