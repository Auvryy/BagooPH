import React, { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import { User } from '@/types';
import { 
    User as UserIcon, 
    ShieldCheck, 
    Wallet, 
    MapPin, 
    Plus, 
    Phone, 
    Mail, 
    Calendar, 
    CreditCard, 
    Check, 
    ArrowUpRight, 
    ArrowDownLeft, 
    Clock, 
    Sparkles, 
    AlertCircle 
} from 'lucide-react';

interface SavedAddress {
    id: number;
    is_default: boolean;
    recipient_name: string;
    phone: string;
    province: string;
    city: string;
    barangay: string;
    street: string;
    type: string;
}

interface WalletData {
    balance: number;
    currency: string;
    status: string;
    account_number: string;
    recent_transactions: {
        id: string;
        title: string;
        amount: number;
        type: 'credit' | 'debit';
        date: string;
    }[];
}

interface Props {
    user: User;
    addresses: SavedAddress[];
    wallet: WalletData;
    ordersCount: number;
}

interface ProfileForm {
    name: string;
    phone: string;
    birthday: string;
    gender: string;
}

export default function BuyerProfile({ user, addresses, wallet, ordersCount }: Props) {
    const [topupAmount, setTopupAmount] = useState('1000');
    const [simulatedBalance, setSimulatedBalance] = useState(wallet.balance);
    const [topupSuccess, setTopupSuccess] = useState(false);

    const { data, setData, post, processing, errors, recentlySuccessful } = useForm<ProfileForm>({
        name: user.name || '',
        phone: user.phone || '',
        birthday: (user as any).birthday || '',
        gender: (user as any).gender || 'other',
    });

    const handleProfileSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('buyer.profile.update'), {
            preserveScroll: true,
        });
    };

    const handleSimulatedTopup = (e: React.FormEvent) => {
        e.preventDefault();
        const amt = parseFloat(topupAmount) || 0;
        if (amt > 0) {
            setSimulatedBalance((prev) => prev + amt);
            setTopupSuccess(true);
            setTimeout(() => setTopupSuccess(false), 3000);
        }
    };

    const formatPrice = (val: number) => {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2,
        }).format(val);
    };

    return (
        <BuyerLayout>
            <Head title="Buyer Account Hub & Wallet — BagooPH" />

            <div className="space-y-6">
                
                {/* 1. HEADER HERO BANNER */}
                <div className="bg-slate-900 rounded-3xl p-6 sm:p-8 text-white relative overflow-hidden shadow-xl border border-slate-800">
                    <div className="absolute -right-10 -bottom-10 w-72 h-72 bg-[#E00D42]/10 rounded-full blur-3xl pointer-events-none"></div>

                    <div className="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
                        <div className="flex items-center gap-4">
                            <div className="w-16 h-16 rounded-2xl bg-[#E00D42] text-white flex items-center justify-center font-black text-2xl shadow-lg shrink-0">
                                {user.name.charAt(0).toUpperCase()}
                            </div>
                            <div>
                                <div className="flex items-center gap-2">
                                    <h1 className="text-xl sm:text-2xl font-black">{user.name}</h1>
                                    <span className="px-2.5 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 font-mono text-[10px] font-bold flex items-center gap-1 border border-emerald-500/30">
                                        <ShieldCheck className="w-3 h-3" /> VERIFIED BUYER (KYC PASS)
                                    </span>
                                </div>
                                <p className="text-xs text-slate-400 font-mono mt-1">
                                    {user.email} • {user.phone || 'No phone set'}
                                </p>
                            </div>
                        </div>

                        {/* Quick Stats */}
                        <div className="flex items-center gap-3 font-mono">
                            <div className="bg-slate-800/80 border border-slate-700/60 rounded-xl px-4 py-2.5 text-center min-w-[100px]">
                                <span className="text-[10px] text-slate-400 uppercase block">Total Orders</span>
                                <span className="text-lg font-black text-white">{ordersCount}</span>
                            </div>
                            <div className="bg-slate-800/80 border border-slate-700/60 rounded-xl px-4 py-2.5 text-center min-w-[130px]">
                                <span className="text-[10px] text-slate-400 uppercase block">Digital Wallet</span>
                                <span className="text-lg font-black text-emerald-400">{formatPrice(simulatedBalance)}</span>
                            </div>
                        </div>
                    </div>
                </div>

                {/* 2. TWO-COLUMN DETAILS GRID */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    
                    {/* LEFT COLUMN: PROFILE INFORMATION & ADDRESS BOOK */}
                    <div className="lg:col-span-7 space-y-6">
                        
                        {/* A. Personal Details Form */}
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4">
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100 font-mono">
                                <div className="flex items-center gap-2">
                                    <UserIcon className="w-4 h-4 text-[#E00D42]" />
                                    <h3 className="font-bold text-slate-900 text-sm uppercase">Personal Profile Details</h3>
                                </div>
                                {recentlySuccessful && (
                                    <span className="text-[11px] text-emerald-600 font-bold flex items-center gap-1">
                                        <Check className="w-3.5 h-3.5" /> Saved
                                    </span>
                                )}
                            </div>

                            <form onSubmit={handleProfileSubmit} className="space-y-4 font-sans text-xs">
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label className="block font-bold text-slate-700 mb-1">Full Name</label>
                                        <input
                                            type="text"
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            className="w-full rounded-xl bg-slate-50 border border-slate-200 text-xs py-2 px-3 focus:ring-[#E00D42] focus:border-[#E00D42]"
                                        />
                                        {errors.name && <p className="text-rose-500 mt-1">{errors.name}</p>}
                                    </div>

                                    <div>
                                        <label className="block font-bold text-slate-700 mb-1">Phone / Mobile No.</label>
                                        <input
                                            type="text"
                                            value={data.phone}
                                            onChange={(e) => setData('phone', e.target.value)}
                                            placeholder="+63 9XX XXX XXXX"
                                            className="w-full rounded-xl bg-slate-50 border border-slate-200 text-xs py-2 px-3 focus:ring-[#E00D42] focus:border-[#E00D42]"
                                        />
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label className="block font-bold text-slate-700 mb-1">Date of Birth</label>
                                        <input
                                            type="date"
                                            value={data.birthday}
                                            onChange={(e) => setData('birthday', e.target.value)}
                                            className="w-full rounded-xl bg-slate-50 border border-slate-200 text-xs py-2 px-3 focus:ring-[#E00D42] focus:border-[#E00D42]"
                                        />
                                    </div>

                                    <div>
                                        <label className="block font-bold text-slate-700 mb-1">Sex / Gender</label>
                                        <select
                                            value={data.gender}
                                            onChange={(e) => setData('gender', e.target.value)}
                                            className="w-full rounded-xl bg-slate-50 border border-slate-200 text-xs py-2 px-3 focus:ring-[#E00D42] focus:border-[#E00D42]"
                                        >
                                            <option value="male">Male</option>
                                            <option value="female">Female</option>
                                            <option value="other">Prefer not to say</option>
                                        </select>
                                    </div>
                                </div>

                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="px-5 py-2.5 rounded-xl bg-[#E00D42] hover:bg-[#C20836] text-white font-mono font-bold uppercase transition text-xs shadow-xs"
                                >
                                    {processing ? 'Saving...' : 'Update Information'}
                                </button>
                            </form>
                        </div>

                        {/* B. Saved PSGC Delivery Addresses */}
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4">
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100 font-mono">
                                <div className="flex items-center gap-2">
                                    <MapPin className="w-4 h-4 text-[#E00D42]" />
                                    <h3 className="font-bold text-slate-900 text-sm uppercase">Saved Delivery Addresses</h3>
                                </div>
                                <button className="text-[11px] font-bold text-[#E00D42] hover:underline flex items-center gap-1">
                                    <Plus className="w-3.5 h-3.5" /> Add New
                                </button>
                            </div>

                            <div className="space-y-3">
                                {addresses.map((addr) => (
                                    <div 
                                        key={addr.id}
                                        className={`p-4 rounded-xl border transition ${
                                            addr.is_default 
                                                ? 'bg-rose-50/40 border-[#E00D42]/40 shadow-xs' 
                                                : 'bg-slate-50 border-slate-200'
                                        }`}
                                    >
                                        <div className="flex items-center justify-between font-mono text-xs mb-1.5">
                                            <div className="flex items-center gap-2">
                                                <span className="font-bold text-slate-900">{addr.recipient_name}</span>
                                                <span className="text-slate-500">({addr.phone})</span>
                                                <span className="px-2 py-0.5 rounded bg-slate-200 text-slate-700 text-[10px] uppercase font-bold">
                                                    {addr.type}
                                                </span>
                                            </div>
                                            {addr.is_default && (
                                                <span className="px-2 py-0.5 rounded bg-[#E00D42] text-white text-[9px] font-black uppercase">
                                                    DEFAULT
                                                </span>
                                            )}
                                        </div>

                                        <p className="text-xs text-slate-600 font-sans">
                                            {addr.street}, {addr.barangay}, {addr.city}, {addr.province}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </div>

                    </div>

                    {/* RIGHT COLUMN: SIMULATED DIGITAL WALLET & KYC BADGE */}
                    <div className="lg:col-span-5 space-y-6">
                        
                        {/* Digital Wallet Card */}
                        <div className="bg-linear-to-br from-slate-900 via-slate-800 to-slate-950 rounded-2xl p-6 text-white border border-slate-700 shadow-xl space-y-5">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <Wallet className="w-5 h-5 text-emerald-400" />
                                    <span className="font-mono font-bold text-xs uppercase tracking-wider text-slate-300">Bagoo Simulated Wallet</span>
                                </div>
                                <span className="px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-300 text-[10px] font-mono font-bold border border-emerald-500/30">
                                    SANDBOX ACTIVE
                                </span>
                            </div>

                            <div>
                                <span className="text-[10px] font-mono text-slate-400 uppercase">Available Balance</span>
                                <div className="text-3xl font-black font-mono text-emerald-400 mt-0.5">
                                    {formatPrice(simulatedBalance)}
                                </div>
                                <span className="text-[10px] font-mono text-slate-400 mt-1 block">
                                    Account No: {wallet.account_number}
                                </span>
                            </div>

                            {/* Simulated Top-Up Form */}
                            <form onSubmit={handleSimulatedTopup} className="pt-3 border-t border-slate-700/60 space-y-2 font-mono text-xs">
                                <label className="block text-[10px] text-slate-300 uppercase font-bold">Simulated Quick Top-Up</label>
                                <div className="flex items-center gap-2">
                                    <select
                                        value={topupAmount}
                                        onChange={(e) => setTopupAmount(e.target.value)}
                                        className="bg-slate-800 border border-slate-600 rounded-xl text-xs text-white py-2 px-3 focus:ring-emerald-400 focus:border-emerald-400 w-full"
                                    >
                                        <option value="500">+ ₱500.00</option>
                                        <option value="1000">+ ₱1,000.00</option>
                                        <option value="2500">+ ₱2,500.00</option>
                                        <option value="5000">+ ₱5,000.00</option>
                                    </select>
                                    <button
                                        type="submit"
                                        className="px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-slate-950 font-bold rounded-xl transition uppercase shrink-0 font-mono shadow-xs"
                                    >
                                        Top Up
                                    </button>
                                </div>
                                {topupSuccess && (
                                    <p className="text-[11px] text-emerald-400 font-bold flex items-center gap-1 pt-1">
                                        <Sparkles className="w-3.5 h-3.5" /> Simulated wallet credited instantly!
                                    </p>
                                )}
                            </form>
                        </div>

                        {/* KYC Verification Overview */}
                        <div className="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs space-y-3 font-mono text-xs">
                            <div className="flex items-center gap-2 pb-2 border-b border-slate-100">
                                <ShieldCheck className="w-4 h-4 text-emerald-600" />
                                <h4 className="font-bold text-slate-900 text-xs uppercase">KYC Identity Verification</h4>
                            </div>
                            <div className="space-y-2 text-slate-600 font-sans">
                                <div className="flex items-center justify-between text-xs">
                                    <span>Government ID:</span>
                                    <span className="font-mono font-bold text-emerald-600 flex items-center gap-1">
                                        <Check className="w-3.5 h-3.5" /> Verified
                                    </span>
                                </div>
                                <div className="flex items-center justify-between text-xs">
                                    <span>Address Verification:</span>
                                    <span className="font-mono font-bold text-emerald-600 flex items-center gap-1">
                                        <Check className="w-3.5 h-3.5" /> Complete (PSGC)
                                    </span>
                                </div>
                                <div className="flex items-center justify-between text-xs">
                                    <span>Account Status:</span>
                                    <span className="font-mono font-bold text-slate-900 uppercase">ACTIVE_BUYER</span>
                                </div>
                            </div>
                        </div>

                    </div>

                </div>

            </div>
        </BuyerLayout>
    );
}
