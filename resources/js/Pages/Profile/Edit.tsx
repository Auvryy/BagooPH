import React from 'react';
import { Head, usePage, Link } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { PageProps } from '@/types';
import AccountEmailSettings from '@/Components/AccountEmailSettings';
import AccountIdentitySettings from '@/Components/AccountIdentitySettings';
import AccountSessionSettings from '@/Components/AccountSessionSettings';
import useSettingsSections from '@/hooks/useSettingsSections';
import DeleteUserForm from './Partials/DeleteUserForm';
import { AccountClosureReview } from '@/types/accountClosure';
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
    Truck,
    Building2,
} from 'lucide-react';

export default function Edit({
    mustVerifyEmail,
    status,
    closure,
    settingsOnly = false,
}: PageProps<{ mustVerifyEmail: boolean; status?: string; closure: AccountClosureReview | null; settingsOnly?: boolean }>) {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user;
    const role = user?.role || 'buyer';
    useSettingsSections(true, ['login-activity']);

    const getBackRoute = () => {
        switch (role) {
            case 'admin':
                return { href: route('admin.dashboard'), label: 'Back to Overview' };
            case 'seller':
                return { href: route('seller.dashboard'), label: 'Back to Cockpit' };
            case 'courier':
                return { href: route('courier.deliveries'), label: 'Back to Deliveries' };
            case 'logistics':
                return { href: route('hub.dashboard'), label: 'Back to Hub' };
            default:
                return { href: route('buyer.index'), label: 'Return to Shop' };
        }
    };

    const backLink = getBackRoute();

    const content = settingsOnly ? (
        <div className="mx-auto max-w-4xl space-y-5">
            <h1 className="text-2xl font-semibold">Account settings</h1>
            <p className="rounded-xl border border-slate-300 bg-white p-4 text-sm text-slate-600">Your account cannot start new work. You can request a correction to reviewed identity here; approval does not clear separate restrictions.</p>
            <AccountSessionSettings />
            <AccountIdentitySettings theme={role === 'seller' ? 'seller' : role === 'buyer' ? 'buyer' : role === 'courier' ? 'courier' : 'default'} />
        </div>
    ) : (
        <div className="mx-auto max-w-5xl space-y-6 font-sans">
            {/* Top Navigation Breadcrumb / Back Link */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-300">
                <div className="flex items-center gap-3">
                    <div className={`w-10 h-10 rounded-lg border flex items-center justify-center font-bold shadow-2xs shrink-0 ${
                        role === 'seller' ? 'bg-purple-50 text-purple-700 border-purple-200' :
                        role === 'courier' ? 'bg-blue-50 text-blue-700 border-blue-200' :
                        'bg-rose-50 text-[#E00D42] border-rose-200'
                    }`}>
                        {role === 'admin' && <Shield className="w-5 h-5" />}
                        {role === 'seller' && <Store className="w-5 h-5" />}
                        {role === 'courier' && <Truck className="w-5 h-5" />}
                        {role === 'logistics' && <Building2 className="w-5 h-5" />}
                        {role === 'buyer' && <User className="w-5 h-5" />}
                    </div>
                    <div>
                        <h1 className="text-xl sm:text-2xl font-bold text-[#121316] tracking-tight">
                            {role === 'admin' ? 'Admin Profile & Security' :
                             role === 'seller' ? 'Merchant Account Settings' :
                             role === 'courier' ? 'Courier Fleet Settings' :
                             role === 'logistics' ? 'Account & security' : 'Account Settings'}
                        </h1>
                        <p className="text-xs text-slate-600 font-medium">
                            {role === 'admin' ? 'Manage root credentials, platform governance keys, and account authentication' :
                             'Manage your personal profile, contact information, and security credentials'}
                        </p>
                    </div>
                </div>

                <Link
                    href={backLink.href}
                    className="inline-flex items-center gap-1.5 self-start sm:self-auto text-xs font-semibold text-slate-700 hover:text-[#E00D42] hover:border-[#E00D42] transition bg-white px-3.5 py-2 rounded-lg border border-slate-300 shadow-2xs"
                >
                    <ArrowLeft className="w-4 h-4" />
                    <span>{backLink.label}</span>
                </Link>
            </div>

            {/* 1. Profile Identity Banner */}
            {user && (
                <div className="bg-white rounded-xl p-6 border border-slate-300 shadow-xs flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                    <div className="flex items-center gap-4 min-w-0">
                        <div className="w-14 h-14 rounded-xl bg-[#E00D42] text-white font-bold text-xl flex items-center justify-center border border-[#C20836] shadow-xs shrink-0">
                            {user.name.charAt(0).toUpperCase()}
                        </div>
                        <div className="space-y-1 min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="text-lg font-bold text-[#121316] truncate">{user.name}</h2>
                                <span className={`px-2.5 py-0.5 rounded border text-xs font-semibold flex items-center gap-1 ${
                                    role === 'admin'
                                        ? 'bg-rose-50 text-[#C20836] border-rose-300'
                                        : 'bg-emerald-50 text-emerald-800 border-emerald-300'
                                }`}>
                                    <CheckCircle2 className={`w-3.5 h-3.5 ${role === 'admin' ? 'text-[#E00D42]' : 'text-emerald-600'}`} />
                                    <span>{role === 'admin' ? 'Root Governance' : 'KYC Verified'}</span>
                                </span>
                            </div>
                            <p className="text-xs text-slate-600 break-all">{user.email}</p>
                            <p className="text-xs text-[#C20836] uppercase font-bold tracking-wide">
                                {role === 'admin' ? 'Platform Super Administrator' :
                                 role === 'seller' ? 'Verified Merchant' :
                                 role === 'courier' ? 'Fleet Logistics Rider' :
                                 role === 'logistics' ? 'Logistics hub account' : 'Registered Buyer'}
                            </p>
                        </div>
                    </div>

                    <div className="sm:text-right text-xs text-slate-500 space-y-0.5 shrink-0 border-t border-slate-200 pt-3 w-full sm:w-auto sm:border-t-0 sm:pt-0">
                        <span className="block text-[11px] text-slate-500 uppercase font-semibold tracking-wider">Account Status</span>
                        <strong className="text-emerald-700 font-bold text-sm block">Active &amp; Verified</strong>
                    </div>
                </div>
            )}

            {/* 2 & 3. Personal Information + Security & Password (Balanced 2-column grid on desktop) */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2 items-stretch">
                <section className="bg-white rounded-xl p-6 border border-slate-300 shadow-xs flex flex-col">
                    <div className="flex items-start gap-3 pb-4 mb-5 border-b border-slate-200">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-rose-200 bg-rose-50 text-[#E00D42]">
                            <User className="w-4 h-4" />
                        </span>
                        <div>
                            <h2 className="text-sm font-bold text-[#121316]">Personal Information</h2>
                            <p className="mt-0.5 text-xs text-slate-600">Reviewed identity details and contact phone number.</p>
                        </div>
                    </div>

                    <UpdateProfileInformationForm
                        mustVerifyEmail={mustVerifyEmail}
                        status={status}
                        showHeader={false}
                        className="w-full flex-1"
                    />
                </section>

                <section className="bg-white rounded-xl p-6 border border-slate-300 shadow-xs flex flex-col">
                    <div className="flex items-start gap-3 pb-4 mb-5 border-b border-slate-200">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-rose-200 bg-rose-50 text-[#E00D42]">
                            <Lock className="w-4 h-4" />
                        </span>
                        <div>
                            <h2 className="text-sm font-bold text-[#121316]">Security &amp; Password</h2>
                            <p className="mt-0.5 text-xs text-slate-600">Use 8–128 characters with mixed case, a number and a symbol.</p>
                        </div>
                    </div>

                    <UpdatePasswordForm showHeader={false} className="w-full flex-1" />
                </section>
            </div>

            <AccountEmailSettings theme={role === 'buyer' ? 'buyer' : role === 'courier' ? 'courier' : 'seller'} />

            <AccountSessionSettings />

            <AccountIdentitySettings theme={role === 'buyer' ? 'buyer' : role === 'courier' ? 'courier' : 'seller'} />

            {/* 4. Danger Zone: Delete Account */}
            <section className="bg-white rounded-xl p-6 border border-rose-300 shadow-xs space-y-4">
                <div className="flex items-center gap-2.5 pb-4 border-b border-rose-200 text-xs font-bold text-[#C20836] uppercase tracking-wider">
                    <Trash2 className="w-4 h-4" />
                    <span>Danger Zone</span>
                </div>

                <DeleteUserForm className="w-full" closure={closure!} />
            </section>
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
                title={role === 'logistics' ? 'Account & security' : 'Courier Account Settings'}
                subtitle={role === 'logistics' ? 'Manage your profile, identity and sign-in security' : 'Manage rider credentials and account security'}
            >
                <Head title={role === 'logistics' ? 'Account & security — BagooPH Logistics' : 'Courier Settings — BagooPH Logistics'} />
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
