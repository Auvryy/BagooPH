import React from 'react';
import { Head, usePage, Link } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import DashboardLayout from '@/Layouts/DashboardLayout';
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
    Phone,
    Shield,
    Store,
    Truck
} from 'lucide-react';

export default function Edit({
    mustVerifyEmail,
    status,
}: PageProps<{ mustVerifyEmail: boolean; status?: string }>) {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user;
    const role = user?.role || 'buyer';

    const getBackRoute = () => {
        switch (role) {
            case 'admin':
                return { href: route('admin.dashboard'), label: 'Back to Overview' };
            case 'seller':
                return { href: route('seller.dashboard'), label: 'Back to Cockpit' };
            case 'courier':
            case 'logistics':
                return { href: route('courier.deliveries'), label: 'Back to Deliveries' };
            default:
                return { href: route('buyer.index'), label: 'Return to Shop' };
        }
    };

    const backLink = getBackRoute();

    const content = (
        <div className="max-w-4xl mx-auto space-y-6 font-sans">
            {/* Top Navigation Breadcrumb / Back Link */}
            <div className="flex items-center justify-between pb-4 border-b border-slate-200">
                <div className="flex items-center gap-3">
                    <div className={`w-10 h-10 rounded-2xl flex items-center justify-center font-bold shadow-2xs ${
                        role === 'admin' ? 'bg-slate-900 text-white' :
                        role === 'seller' ? 'bg-purple-100 text-purple-700' :
                        role === 'courier' ? 'bg-blue-100 text-blue-700' :
                        'bg-rose-50 text-[#E00D42]'
                    }`}>
                        {role === 'admin' && <Shield className="w-5 h-5 text-emerald-400" />}
                        {role === 'seller' && <Store className="w-5 h-5" />}
                        {role === 'courier' && <Truck className="w-5 h-5" />}
                        {role === 'buyer' && <User className="w-5 h-5" />}
                    </div>
                    <div>
                        <h1 className="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">
                            {role === 'admin' ? 'Admin Profile & Security' :
                             role === 'seller' ? 'Merchant Account Settings' :
                             role === 'courier' ? 'Courier Fleet Settings' :
                             'Account Settings'}
                        </h1>
                        <p className="text-xs text-slate-500 font-medium">
                            {role === 'admin' ? 'Manage root credentials, platform governance keys, and account authentication' :
                             'Manage your personal profile, contact information, and security credentials'}
                        </p>
                    </div>
                </div>

                <Link
                    href={backLink.href}
                    className="text-xs font-bold text-slate-700 hover:text-[#E00D42] flex items-center gap-1.5 transition bg-white px-3 py-1.5 rounded-xl border border-slate-200 shadow-2xs"
                >
                    <ArrowLeft className="w-4 h-4" />
                    <span>{backLink.label}</span>
                </Link>
            </div>

            {/* 1. Profile Identity Banner */}
            {user && (
                <div className="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-2xs flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <div className="w-16 h-16 rounded-2xl bg-slate-900 text-white font-black text-2xl flex items-center justify-center border-2 border-[#E00D42] shadow-sm shrink-0">
                            {user.name.charAt(0).toUpperCase()}
                        </div>
                        <div className="space-y-1">
                            <div className="flex items-center gap-2">
                                <h2 className="text-lg font-bold text-slate-900">{user.name}</h2>
                                <span className={`px-2.5 py-0.5 rounded-full text-xs font-bold flex items-center gap-1 ${
                                    role === 'admin' 
                                        ? 'bg-slate-900 text-emerald-400 border border-slate-700' 
                                        : 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                                }`}>
                                    <CheckCircle2 className="w-3.5 h-3.5 text-emerald-500" />
                                    <span>{role === 'admin' ? 'ROOT GOVERNANCE' : 'KYC VERIFIED'}</span>
                                </span>
                            </div>
                            <p className="text-xs text-slate-500">{user.email}</p>
                            <p className="text-xs text-[#E00D42] uppercase font-bold">
                                {role === 'admin' ? 'Platform Super Administrator' :
                                 role === 'seller' ? 'Verified Merchant' :
                                 role === 'courier' ? 'Fleet Logistics Rider' :
                                 'Registered Buyer'}
                            </p>
                        </div>
                    </div>

                    <div className="sm:text-right text-xs text-slate-500 space-y-0.5">
                        <span className="block text-[11px] text-slate-400 uppercase font-semibold">Account Status</span>
                        <strong className="text-emerald-600 font-bold text-sm block">Active & Verified</strong>
                    </div>
                </div>
            )}

            {/* 2. Personal Information Card */}
            <div className="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-2xs space-y-4">
                <div className="flex items-center gap-2 pb-3 border-b border-slate-100 text-xs font-bold text-slate-900 uppercase">
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
            <div className="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-2xs space-y-4">
                <div className="flex items-center gap-2 pb-3 border-b border-slate-100 text-xs font-bold text-slate-900 uppercase">
                    <Lock className="w-4 h-4 text-[#E00D42]" />
                    <span>Security & Password</span>
                </div>

                <UpdatePasswordForm className="max-w-xl" />
            </div>

            {/* 4. Danger Zone: Delete Account */}
            <div className="bg-white rounded-3xl p-6 sm:p-8 border border-rose-100 shadow-2xs space-y-4">
                <div className="flex items-center gap-2 pb-3 border-b border-rose-100 text-xs font-bold text-rose-600 uppercase">
                    <Trash2 className="w-4 h-4" />
                    <span>Danger Zone</span>
                </div>

                <DeleteUserForm className="max-w-xl" />
            </div>
        </div>
    );

    if (role === 'admin') {
        return (
            <DashboardLayout
                title="Platform Admin Settings"
                subtitle="Root credentials, governance keys, and account authentication"
            >
                <Head title="Admin Profile & Security — BagooPH Admin" />
                {content}
            </DashboardLayout>
        );
    }

    if (role === 'seller') {
        return (
            <DashboardLayout
                title="Merchant Account Settings"
                subtitle="Manage store owner credentials and security settings"
            >
                <Head title="Merchant Settings — BagooPH Seller" />
                {content}
            </DashboardLayout>
        );
    }

    if (role === 'courier' || role === 'logistics') {
        return (
            <DashboardLayout
                title="Courier Account Settings"
                subtitle="Manage rider credentials and account security"
            >
                <Head title="Courier Settings — BagooPH Logistics" />
                {content}
            </DashboardLayout>
        );
    }

    return (
        <BuyerLayout>
            <Head title="Account Settings & Profile — BagooPH" />
            {content}
        </BuyerLayout>
    );
}
