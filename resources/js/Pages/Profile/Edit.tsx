import React from 'react';
import { Head, usePage, Link } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import { PageProps } from '@/types';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';
import { 
    User, 
    ShieldCheck, 
    Lock, 
    MapPin, 
    Trash2, 
    ArrowLeft,
    CheckCircle2,
    Calendar,
    Mail,
    Phone
} from 'lucide-react';

export default function Edit({
    mustVerifyEmail,
    status,
}: PageProps<{ mustVerifyEmail: boolean; status?: string }>) {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user;

    return (
        <BuyerLayout>
            <Head title="Account Settings & Profile — BagooPH" />

            <div className="max-w-4xl mx-auto space-y-6">
                
                {/* Top Navigation Back */}
                <div className="flex items-center justify-between pb-4 border-b border-slate-200">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-rose-50 text-[#E00D42] flex items-center justify-center font-bold">
                            <User className="w-5 h-5" />
                        </div>
                        <div>
                            <h1 className="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">Account Settings</h1>
                            <p className="text-xs text-slate-500 font-mono">Manage your personal profile, addresses, and security credentials</p>
                        </div>
                    </div>

                    <Link
                        href={route('buyer.index')}
                        className="text-xs font-bold text-[#E00D42] hover:underline flex items-center gap-1 font-mono uppercase"
                    >
                        <ArrowLeft className="w-4 h-4" />
                        <span>Return to Shop</span>
                    </Link>
                </div>

                {/* 1. Profile Identity Banner */}
                {user && (
                    <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 font-sans">
                        <div className="flex items-center gap-4">
                            <div className="w-16 h-16 rounded-2xl bg-slate-900 text-white font-black text-2xl flex items-center justify-center border-2 border-[#E00D42] shadow-sm">
                                {user.name.charAt(0).toUpperCase()}
                            </div>
                            <div className="space-y-1">
                                <div className="flex items-center gap-2">
                                    <h2 className="text-lg font-bold text-slate-900">{user.name}</h2>
                                    <span className="px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-bold font-mono uppercase flex items-center gap-1">
                                        <CheckCircle2 className="w-3 h-3 text-emerald-600" />
                                        <span>KYC VERIFIED</span>
                                    </span>
                                </div>
                                <p className="text-xs text-slate-500 font-mono">{user.email}</p>
                                <p className="text-[11px] text-[#E00D42] uppercase font-bold font-mono">
                                    Role: {user.role} Member
                                </p>
                            </div>
                        </div>

                        <div className="sm:text-right font-mono text-xs text-slate-500">
                            <span>Account Status: </span>
                            <strong className="text-emerald-600 font-bold">Active & Verified</strong>
                        </div>
                    </div>
                )}

                {/* 2. Personal Information Card */}
                <div className="bg-white rounded-2xl p-6 sm:p-8 border border-slate-200 shadow-xs space-y-4">
                    <div className="flex items-center gap-2 pb-3 border-b border-slate-100 font-mono text-xs font-bold text-slate-900 uppercase">
                        <User className="w-4 h-4 text-[#E00D42]" />
                        <span>Personal Information</span>
                    </div>

                    <UpdateProfileInformationForm
                        mustVerifyEmail={mustVerifyEmail}
                        status={status}
                        className="max-w-xl"
                    />
                </div>

                {/* 3. Security & Password Card */}
                <div className="bg-white rounded-2xl p-6 sm:p-8 border border-slate-200 shadow-xs space-y-4">
                    <div className="flex items-center gap-2 pb-3 border-b border-slate-100 font-mono text-xs font-bold text-slate-900 uppercase">
                        <Lock className="w-4 h-4 text-[#E00D42]" />
                        <span>Security & Password</span>
                    </div>

                    <UpdatePasswordForm className="max-w-xl" />
                </div>

                {/* 4. Danger Zone: Delete Account */}
                <div className="bg-white rounded-2xl p-6 sm:p-8 border border-rose-100 shadow-xs space-y-4">
                    <div className="flex items-center gap-2 pb-3 border-b border-rose-100 font-mono text-xs font-bold text-rose-600 uppercase">
                        <Trash2 className="w-4 h-4" />
                        <span>Danger Zone</span>
                    </div>

                    <DeleteUserForm className="max-w-xl" />
                </div>

            </div>
        </BuyerLayout>
    );
}
