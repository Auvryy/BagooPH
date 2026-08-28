import React, { FormEventHandler, useRef, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import { ArrowRight, Lock, Mail, Truck, User, ShieldCheck, Check, DollarSign, Upload, FileText, X, Phone, MapPin, Hash } from 'lucide-react';

export default function CourierRegister() {
    const idInputRef = useRef<HTMLInputElement>(null);
    const licenseInputRef = useRef<HTMLInputElement>(null);
    const orCrInputRef = useRef<HTMLInputElement>(null);

    const [idFileName, setIdFileName] = useState<string | null>(null);
    const [idFileSize, setIdFileSize] = useState<string | null>(null);

    const [licenseFileName, setLicenseFileName] = useState<string | null>(null);
    const [licenseFileSize, setLicenseFileSize] = useState<string | null>(null);

    const [orCrFileName, setOrCrFileName] = useState<string | null>(null);
    const [orCrFileSize, setOrCrFileSize] = useState<string | null>(null);

    const { data, setData, post, processing, errors, reset } = useForm<{
        name: string;
        email: string;
        phone: string;
        address: string;
        city: string;
        vehicle_type: string;
        plate_number: string;
        license_number: string;
        role: 'courier';
        password: string;
        password_confirmation: string;
        id_document: File | null;
        driver_license: File | null;
        or_cr_document: File | null;
    }>({
        name: '',
        email: '',
        phone: '',
        address: '',
        city: '',
        vehicle_type: 'Motorcycle',
        plate_number: '',
        license_number: '',
        role: 'courier',
        password: '',
        password_confirmation: '',
        id_document: null,
        driver_license: null,
        or_cr_document: null,
    });

    const handleIdChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            setData('id_document', file);
            setIdFileName(file.name);
            setIdFileSize((file.size / (1024 * 1024)).toFixed(2) + ' MB');
        }
    };

    const removeIdFile = () => {
        setData('id_document', null);
        setIdFileName(null);
        setIdFileSize(null);
        if (idInputRef.current) idInputRef.current.value = '';
    };

    const handleLicenseChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            setData('driver_license', file);
            setLicenseFileName(file.name);
            setLicenseFileSize((file.size / (1024 * 1024)).toFixed(2) + ' MB');
        }
    };

    const removeLicenseFile = () => {
        setData('driver_license', null);
        setLicenseFileName(null);
        setLicenseFileSize(null);
        if (licenseInputRef.current) licenseInputRef.current.value = '';
    };

    const handleOrCrChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            setData('or_cr_document', file);
            setOrCrFileName(file.name);
            setOrCrFileSize((file.size / (1024 * 1024)).toFixed(2) + ' MB');
        }
    };

    const removeOrCrFile = () => {
        setData('or_cr_document', null);
        setOrCrFileName(null);
        setOrCrFileSize(null);
        if (orCrInputRef.current) orCrInputRef.current.value = '';
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('register'), {
            forceFormData: true,
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
                        Rider Full Name *
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

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                            Email Address *
                        </label>
                        <div className="relative">
                            <Mail className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                            <input
                                id="email"
                                type="email"
                                name="email"
                                value={data.email}
                                className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
                                placeholder="rider@domain.com"
                                autoComplete="username"
                                onChange={(e) => setData('email', e.target.value)}
                                required
                            />
                        </div>
                        <InputError message={errors.email} className="mt-1" />
                    </div>

                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                            Mobile Contact Phone *
                        </label>
                        <div className="relative">
                            <Phone className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                            <input
                                id="phone"
                                type="tel"
                                name="phone"
                                value={data.phone}
                                className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
                                placeholder="+63 917 000 0000"
                                onChange={(e) => setData('phone', e.target.value)}
                                required
                            />
                        </div>
                        <InputError message={errors.phone} className="mt-1" />
                    </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                            Residential Base Address *
                        </label>
                        <div className="relative">
                            <MapPin className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                            <input
                                id="address"
                                type="text"
                                name="address"
                                value={data.address}
                                className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
                                placeholder="e.g. 12 Dispatcher Ave"
                                onChange={(e) => setData('address', e.target.value)}
                                required
                            />
                        </div>
                        <InputError message={errors.address} className="mt-1" />
                    </div>

                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                            Operating City / Hub Area *
                        </label>
                        <input
                            id="city"
                            type="text"
                            name="city"
                            value={data.city}
                            className="w-full px-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
                            placeholder="e.g. Pasig, Metro Manila"
                            onChange={(e) => setData('city', e.target.value)}
                            required
                        />
                        <InputError message={errors.city} className="mt-1" />
                    </div>
                </div>

                {/* Fleet Specs */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                            Vehicle Type *
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
                                <option value="Motorcycle">Motorcycle (Express)</option>
                                <option value="Van">Delivery Van (Bulk)</option>
                                <option value="Bicycle">Bicycle (Eco)</option>
                                <option value="Truck">Light Truck (Cargo)</option>
                            </select>
                        </div>
                        <InputError message={errors.vehicle_type} className="mt-1" />
                    </div>

                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                            Plate Number *
                        </label>
                        <input
                            id="plate_number"
                            type="text"
                            name="plate_number"
                            value={data.plate_number}
                            className="w-full px-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition uppercase"
                            placeholder="e.g. NCS-8892"
                            onChange={(e) => setData('plate_number', e.target.value)}
                            required
                        />
                        <InputError message={errors.plate_number} className="mt-1" />
                    </div>

                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                            LTO License No.
                        </label>
                        <input
                            id="license_number"
                            type="text"
                            name="license_number"
                            value={data.license_number}
                            className="w-full px-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition uppercase"
                            placeholder="e.g. N02-18-092831"
                            onChange={(e) => setData('license_number', e.target.value)}
                        />
                        <InputError message={errors.license_number} className="mt-1" />
                    </div>
                </div>

                {/* 3 Courier Documents Uploads */}
                <div className="space-y-3 pt-1">
                    {/* 1. Government ID */}
                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1 flex items-center justify-between">
                            <span>1. Valid Government ID *</span>
                            <span className="text-[10px] text-[#E00D42] font-normal">Passport / UMID / Postal</span>
                        </label>
                        <input
                            type="file"
                            ref={idInputRef}
                            onChange={handleIdChange}
                            accept="image/jpeg,image/png,image/webp,application/pdf"
                            className="hidden"
                        />
                        {!idFileName ? (
                            <div 
                                onClick={() => idInputRef.current?.click()}
                                className="border-2 border-dashed border-black/20 hover:border-[#E00D42] hover:bg-[#E00D42]/5 rounded-lg p-2.5 text-center cursor-pointer transition flex items-center justify-center gap-2 bg-white"
                            >
                                <Upload className="w-3.5 h-3.5 text-black/40" />
                                <span className="text-[11px] text-black/70">Upload Gov ID</span>
                            </div>
                        ) : (
                            <div className="flex items-center justify-between p-2 bg-[#F4F2EC] border border-black/15 rounded-lg text-xs">
                                <div className="flex items-center gap-2 truncate">
                                    <FileText className="w-4 h-4 text-[#E00D42] shrink-0" />
                                    <span className="font-bold truncate text-[11px]">{idFileName}</span>
                                    <span className="text-[10px] text-black/50 shrink-0">({idFileSize})</span>
                                </div>
                                <button
                                    type="button"
                                    onClick={removeIdFile}
                                    className="p-1 hover:bg-black/10 rounded text-black/60 hover:text-black transition"
                                >
                                    <X className="w-3.5 h-3.5" />
                                </button>
                            </div>
                        )}
                        <InputError message={errors.id_document} className="mt-1" />
                    </div>

                    {/* 2. Driver's License */}
                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1 flex items-center justify-between">
                            <span>2. LTO Driver's License *</span>
                            <span className="text-[10px] text-[#E00D42] font-normal">Professional or Non-Pro</span>
                        </label>
                        <input
                            type="file"
                            ref={licenseInputRef}
                            onChange={handleLicenseChange}
                            accept="image/jpeg,image/png,image/webp,application/pdf"
                            className="hidden"
                        />
                        {!licenseFileName ? (
                            <div 
                                onClick={() => licenseInputRef.current?.click()}
                                className="border-2 border-dashed border-black/20 hover:border-[#E00D42] hover:bg-[#E00D42]/5 rounded-lg p-2.5 text-center cursor-pointer transition flex items-center justify-center gap-2 bg-white"
                            >
                                <Upload className="w-3.5 h-3.5 text-black/40" />
                                <span className="text-[11px] text-black/70">Upload Driver's License</span>
                            </div>
                        ) : (
                            <div className="flex items-center justify-between p-2 bg-[#F4F2EC] border border-black/15 rounded-lg text-xs">
                                <div className="flex items-center gap-2 truncate">
                                    <FileText className="w-4 h-4 text-blue-600 shrink-0" />
                                    <span className="font-bold truncate text-[11px]">{licenseFileName}</span>
                                    <span className="text-[10px] text-black/50 shrink-0">({licenseFileSize})</span>
                                </div>
                                <button
                                    type="button"
                                    onClick={removeLicenseFile}
                                    className="p-1 hover:bg-black/10 rounded text-black/60 hover:text-black transition"
                                >
                                    <X className="w-3.5 h-3.5" />
                                </button>
                            </div>
                        )}
                        <InputError message={errors.driver_license} className="mt-1" />
                    </div>

                    {/* 3. OR/CR Document */}
                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1 flex items-center justify-between">
                            <span>3. Vehicle Registration (OR / CR) *</span>
                            <span className="text-[10px] text-[#E00D42] font-normal">Official Receipt & Cert of Registration</span>
                        </label>
                        <input
                            type="file"
                            ref={orCrInputRef}
                            onChange={handleOrCrChange}
                            accept="image/jpeg,image/png,image/webp,application/pdf"
                            className="hidden"
                        />
                        {!orCrFileName ? (
                            <div 
                                onClick={() => orCrInputRef.current?.click()}
                                className="border-2 border-dashed border-black/20 hover:border-[#E00D42] hover:bg-[#E00D42]/5 rounded-lg p-2.5 text-center cursor-pointer transition flex items-center justify-center gap-2 bg-white"
                            >
                                <Upload className="w-3.5 h-3.5 text-black/40" />
                                <span className="text-[11px] text-black/70">Upload Vehicle OR / CR Document</span>
                            </div>
                        ) : (
                            <div className="flex items-center justify-between p-2 bg-[#F4F2EC] border border-black/15 rounded-lg text-xs">
                                <div className="flex items-center gap-2 truncate">
                                    <ShieldCheck className="w-4 h-4 text-emerald-600 shrink-0" />
                                    <span className="font-bold truncate text-[11px]">{orCrFileName}</span>
                                    <span className="text-[10px] text-black/50 shrink-0">({orCrFileSize})</span>
                                </div>
                                <button
                                    type="button"
                                    onClick={removeOrCrFile}
                                    className="p-1 hover:bg-black/10 rounded text-black/60 hover:text-black transition"
                                >
                                    <X className="w-3.5 h-3.5" />
                                </button>
                            </div>
                        )}
                        <InputError message={errors.or_cr_document} className="mt-1" />
                    </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                            Password *
                        </label>
                        <div className="relative">
                            <Lock className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                            <input
                                id="password"
                                type="password"
                                name="password"
                                value={data.password}
                                className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
                                placeholder="••••••••"
                                autoComplete="new-password"
                                onChange={(e) => setData('password', e.target.value)}
                                required
                            />
                        </div>
                        <InputError message={errors.password} className="mt-1" />
                    </div>

                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                            Confirm Password *
                        </label>
                        <div className="relative">
                            <Lock className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                            <input
                                id="password_confirmation"
                                type="password"
                                name="password_confirmation"
                                value={data.password_confirmation}
                                className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
                                placeholder="••••••••"
                                autoComplete="new-password"
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                required
                            />
                        </div>
                        <InputError message={errors.password_confirmation} className="mt-1" />
                    </div>
                </div>

                <div className="p-3 bg-[#ECEAE5] border border-black/10 rounded-lg text-[10px] space-y-1 text-black/70 font-mono">
                    <p className="font-bold text-black flex items-center gap-1">
                        <DollarSign className="w-3.5 h-3.5 text-emerald-600" />
                        <span>Instant FCFS Dispatch & Guaranteed Delivery Payout</span>
                    </p>
                    <p>Earn ₱50–₱60 standard delivery fees deposited directly to your Courier Ledger upon confirmed doorstep delivery.</p>
                </div>

                <button
                    type="submit"
                    disabled={processing}
                    className="w-full py-3 bg-emerald-600 hover:bg-emerald-700 active:scale-[0.98] text-white text-xs font-bold rounded-lg shadow-sm transition uppercase tracking-wider flex items-center justify-center gap-2 cursor-pointer mt-2 disabled:opacity-50"
                >
                    <span>{processing ? 'Submitting Application...' : 'Complete Driver Registration'}</span>
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
