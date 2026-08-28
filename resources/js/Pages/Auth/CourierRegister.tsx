import React, { FormEventHandler } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import { ArrowRight, Lock, Mail, Truck, User, ShieldCheck, Check, DollarSign } from 'lucide-react';

export default function CourierRegister() {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        vehicle_type: 'motorcycle',
        plate_number: '',
        email: '',
        role: 'courier' as const,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout 
            title="Join Courier Fleet" 
            subtitle="Claim delivery tasks in the first-come pool with guaranteed ₱50/₱80 fare payouts"
            headerBadge="COURIER DISPATCH // 04"
        >
            <Head title="Courier Registration — BagooPH" />

            <form onSubmit={submit} className="space-y-4 font-mono">
                <div>
                    <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                        Rider Full Name
                    </label>
                    <div className="relative">
                        <User className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                        <input
                            id="name"
                            type="text"
                            name="name"
                            value={data.name}
                            className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
                            placeholder="e.g. Roberto Gomez"
                            autoComplete="name"
                            autoFocus
                            onChange={(e) => setData('name', e.target.value)}
                            required
                        />
                    </div>
                    <InputError message={errors.name} className="mt-1" />
                </div>

                <div>
                    <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                        Vehicle Type
                    </label>
                    <div className="relative">
                        <Truck className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                        <select
                            id="vehicle_type"
                            name="vehicle_type"
                            value={data.vehicle_type}
                            className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
                            onChange={(e) => setData('vehicle_type', e.target.value)}
                            required
                        >
                            <option value="motorcycle">Motorcycle (Express Courier)</option>
                            <option value="van">Delivery Van (Bulk Dispatch)</option>
                            <option value="bicycle">Bicycle (Eco Courier)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                        Vehicle Plate Number
                    </label>
                    <div className="relative">
                        <ShieldCheck className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                        <input
                            id="plate_number"
                            type="text"
                            name="plate_number"
                            value={data.plate_number}
                            className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition uppercase"
                            placeholder="e.g. NAL 8924"
                            onChange={(e) => setData('plate_number', e.target.value)}
                        />
                    </div>
                </div>

                <div>
                    <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                        Email Address
                    </label>
                    <div className="relative">
                        <Mail className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                        <input
                            id="email"
                            type="email"
                            name="email"
                            value={data.email}
                            className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
                            placeholder="rider@example.com"
                            autoComplete="username"
                            onChange={(e) => setData('email', e.target.value)}
                            required
                        />
                    </div>
                    <InputError message={errors.email} className="mt-1" />
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                            Password
                        </label>
                        <div className="relative">
                            <Lock className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                            <input
                                id="password"
                                type="password"
                                name="password"
                                value={data.password}
                                className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
                                autoComplete="new-password"
                                onChange={(e) => setData('password', e.target.value)}
                                required
                            />
                        </div>
                        <InputError message={errors.password} className="mt-1" />
                    </div>

                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                            Confirm Password
                        </label>
                        <div className="relative">
                            <Lock className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                            <input
                                id="password_confirmation"
                                type="password"
                                name="password_confirmation"
                                value={data.password_confirmation}
                                className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
                                autoComplete="new-password"
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                required
                            />
                        </div>
                        <InputError message={errors.password_confirmation} className="mt-1" />
                    </div>
                </div>

                <div className="p-3 bg-[#ECEAE5] border border-black/10 rounded-lg text-[10px] space-y-1 text-black/70">
                    <p className="font-bold text-black flex items-center gap-1">
                        <DollarSign className="w-3 h-3 text-emerald-600" />
                        <span>Instant FCFS Dispatch & 100% Retained Fare</span>
                    </p>
                    <p>All delivery fares (₱50 standard, ₱80 express) are credited directly to your rider earnings ledger.</p>
                </div>

                <button
                    type="submit"
                    disabled={processing}
                    className="w-full py-3 bg-emerald-600 hover:bg-emerald-700 active:scale-[0.98] text-white text-xs font-bold rounded-lg shadow-sm transition uppercase tracking-wider flex items-center justify-center gap-2 cursor-pointer mt-2"
                >
                    <span>{processing ? 'Creating Account...' : 'Complete Driver Registration'}</span>
                    <ArrowRight className="w-4 h-4" />
                </button>

                <div className="pt-3 border-t border-black/10 text-center text-xs">
                    <span className="text-black/60">Already registered? </span>
                    <Link
                        href={route('login')}
                        className="text-[#E00D42] font-bold hover:underline"
                    >
                        Sign in here
                    </Link>
                </div>
            </form>
        </GuestLayout>
    );
}
