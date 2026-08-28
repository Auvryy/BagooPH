import React, { FormEventHandler, useRef, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import { 
    ArrowRight, 
    ArrowLeft, 
    Lock, 
    Mail, 
    Store, 
    User, 
    Upload, 
    FileText, 
    X, 
    Phone, 
    MapPin, 
    Sparkles, 
    ShieldCheck, 
    Check, 
    Building2, 
    FileCheck2,
    Eye,
    EyeOff
} from 'lucide-react';

export default function SellerRegister() {
    const [currentStep, setCurrentStep] = useState(1);
    const [stepErrors, setStepErrors] = useState<Record<string, string>>({});
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);

    const idInputRef = useRef<HTMLInputElement>(null);
    const permitInputRef = useRef<HTMLInputElement>(null);

    const [idFileName, setIdFileName] = useState<string | null>(null);
    const [idFileSize, setIdFileSize] = useState<string | null>(null);
    const [permitFileName, setPermitFileName] = useState<string | null>(null);
    const [permitFileSize, setPermitFileSize] = useState<string | null>(null);

    const { data, setData, post, processing, errors, reset } = useForm<{
        name: string;
        shop_name: string;
        email: string;
        phone: string;
        address: string;
        city: string;
        role: 'seller';
        password: string;
        password_confirmation: string;
        id_document: File | null;
        business_permit: File | null;
    }>({
        name: '',
        shop_name: '',
        email: '',
        phone: '',
        address: '',
        city: '',
        role: 'seller',
        password: '',
        password_confirmation: '',
        id_document: null,
        business_permit: null,
    });

    const handleIdChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            setData('id_document', file);
            setIdFileName(file.name);
            setIdFileSize((file.size / (1024 * 1024)).toFixed(2) + ' MB');
            setStepErrors(prev => {
                const next = { ...prev };
                delete next.id_document;
                return next;
            });
        }
    };

    const removeIdFile = () => {
        setData('id_document', null);
        setIdFileName(null);
        setIdFileSize(null);
        if (idInputRef.current) idInputRef.current.value = '';
    };

    const handlePermitChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            setData('business_permit', file);
            setPermitFileName(file.name);
            setPermitFileSize((file.size / (1024 * 1024)).toFixed(2) + ' MB');
            setStepErrors(prev => {
                const next = { ...prev };
                delete next.business_permit;
                return next;
            });
        }
    };

    const removePermitFile = () => {
        setData('business_permit', null);
        setPermitFileName(null);
        setPermitFileSize(null);
        if (permitInputRef.current) permitInputRef.current.value = '';
    };

    const validateStep1 = () => {
        const newErrors: Record<string, string> = {};
        if (!data.shop_name.trim()) newErrors.shop_name = 'Store name is required';
        if (!data.name.trim()) newErrors.name = 'Merchant contact name is required';
        if (!data.email.trim()) {
            newErrors.email = 'Email address is required';
        } else if (!/\S+@\S+\.\S+/.test(data.email)) {
            newErrors.email = 'Valid email address is required';
        }
        setStepErrors(newErrors);
        return Object.keys(newErrors).length === 0;
    };

    const validateStep2 = () => {
        const newErrors: Record<string, string> = {};
        if (!data.phone.trim()) newErrors.phone = 'Mobile phone number is required';
        if (!data.city.trim()) newErrors.city = 'City or municipality is required';
        if (!data.address.trim()) newErrors.address = 'Warehouse/pickup address is required';
        setStepErrors(newErrors);
        return Object.keys(newErrors).length === 0;
    };

    const handleNext = () => {
        if (currentStep === 1) {
            if (validateStep1()) setCurrentStep(2);
        } else if (currentStep === 2) {
            if (validateStep2()) setCurrentStep(3);
        }
    };

    const handlePrev = () => {
        setStepErrors({});
        setCurrentStep(prev => Math.max(1, prev - 1));
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
            title="Open Verified Store" 
            subtitle="Sell across 14 departments with 10% flat fees & automated waybills"
            headerBadge="MERCHANT STUDIO // 02"
            maxWidth="lg"
        >
            <Head title="Seller Registration — BagooPH" />

            {/* NUMBERED STEPS HEADER */}
            <div className="mb-6 pb-2 border-b border-slate-100">
                <div className="flex items-center justify-center gap-2 sm:gap-4 font-mono">
                    
                    {/* Step 1 */}
                    <div className="flex items-center gap-2">
                        <div className={`w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition ${
                            currentStep === 1 
                                ? 'bg-[#E00D42] text-white shadow-xs' 
                                : currentStep > 1 
                                ? 'bg-emerald-600 text-white' 
                                : 'bg-slate-100 text-slate-400 border border-slate-200'
                        }`}>
                            {currentStep > 1 ? <Check className="w-3.5 h-3.5" /> : '1'}
                        </div>
                        <span className={`text-[11px] font-bold uppercase hidden sm:inline ${
                            currentStep === 1 ? 'text-slate-900' : currentStep > 1 ? 'text-slate-600' : 'text-slate-400'
                        }`}>
                            Store Info
                        </span>
                    </div>

                    <div className={`w-8 sm:w-12 h-px transition-colors ${currentStep > 1 ? 'bg-emerald-600' : 'bg-slate-200'}`}></div>

                    {/* Step 2 */}
                    <div className="flex items-center gap-2">
                        <div className={`w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition ${
                            currentStep === 2 
                                ? 'bg-[#E00D42] text-white shadow-xs' 
                                : currentStep > 2 
                                ? 'bg-emerald-600 text-white' 
                                : 'bg-slate-100 text-slate-400 border border-slate-200'
                        }`}>
                            {currentStep > 2 ? <Check className="w-3.5 h-3.5" /> : '2'}
                        </div>
                        <span className={`text-[11px] font-bold uppercase hidden sm:inline ${
                            currentStep === 2 ? 'text-slate-900' : currentStep > 2 ? 'text-slate-600' : 'text-slate-400'
                        }`}>
                            Location
                        </span>
                    </div>

                    <div className={`w-8 sm:w-12 h-px transition-colors ${currentStep > 2 ? 'bg-emerald-600' : 'bg-slate-200'}`}></div>

                    {/* Step 3 */}
                    <div className="flex items-center gap-2">
                        <div className={`w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition ${
                            currentStep === 3 
                                ? 'bg-[#E00D42] text-white shadow-xs' 
                                : 'bg-slate-100 text-slate-400 border border-slate-200'
                        }`}>
                            3
                        </div>
                        <span className={`text-[11px] font-bold uppercase hidden sm:inline ${
                            currentStep === 3 ? 'text-slate-900' : 'text-slate-400'
                        }`}>
                            KYC & Security
                        </span>
                    </div>

                </div>
            </div>

            <form onSubmit={submit} className="space-y-4 font-mono">
                
                {/* STEP 1: STORE & IDENTITY */}
                {currentStep === 1 && (
                    <div className="space-y-4 animate-fade-in">
                        <div>
                            <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                Store / Merchant Business Name *
                            </label>
                            <div className="relative">
                                <Store className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                <input
                                    id="shop_name"
                                    type="text"
                                    name="shop_name"
                                    value={data.shop_name}
                                    className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                    placeholder="e.g. Apex Apparel Studio"
                                    autoFocus
                                    onChange={(e) => {
                                        setData('shop_name', e.target.value);
                                        if (stepErrors.shop_name) setStepErrors(prev => ({ ...prev, shop_name: '' }));
                                    }}
                                    required
                                />
                            </div>
                            {(stepErrors.shop_name || errors.shop_name) && (
                                <InputError message={stepErrors.shop_name || errors.shop_name} className="mt-1" />
                            )}
                        </div>

                        <div>
                            <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                Owner / Authorized Representative Name *
                            </label>
                            <div className="relative">
                                <User className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                <input
                                    id="name"
                                    type="text"
                                    name="name"
                                    value={data.name}
                                    className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                    placeholder="e.g. Juan Dela Cruz"
                                    autoComplete="name"
                                    onChange={(e) => {
                                        setData('name', e.target.value);
                                        if (stepErrors.name) setStepErrors(prev => ({ ...prev, name: '' }));
                                    }}
                                    required
                                />
                            </div>
                            {(stepErrors.name || errors.name) && (
                                <InputError message={stepErrors.name || errors.name} className="mt-1" />
                            )}
                        </div>

                        <div>
                            <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                Merchant Email Address *
                            </label>
                            <div className="relative">
                                <Mail className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                <input
                                    id="email"
                                    type="email"
                                    name="email"
                                    value={data.email}
                                    className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                    placeholder="merchant@domain.com"
                                    autoComplete="username"
                                    onChange={(e) => {
                                        setData('email', e.target.value);
                                        if (stepErrors.email) setStepErrors(prev => ({ ...prev, email: '' }));
                                    }}
                                    required
                                />
                            </div>
                            {(stepErrors.email || errors.email) && (
                                <InputError message={stepErrors.email || errors.email} className="mt-1" />
                            )}
                        </div>

                        <div className="p-3 rounded-lg bg-slate-100 border border-slate-200 text-xs font-sans text-slate-600 flex items-center gap-2">
                            <Sparkles className="w-4 h-4 text-[#E00D42] shrink-0" />
                            <span>Your store will receive a <strong>Verified Merchant Badge</strong> upon completing business KYC.</span>
                        </div>

                        <div className="pt-2">
                            <button
                                type="button"
                                onClick={handleNext}
                                className="w-full py-3 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-sm transition uppercase tracking-wider flex items-center justify-center gap-2"
                            >
                                <span>Continue to Location & Contact</span>
                                <ArrowRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}

                {/* STEP 2: LOCATION & CONTACT */}
                {currentStep === 2 && (
                    <div className="space-y-4 animate-fade-in">
                        <div>
                            <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                Mobile Contact Phone *
                            </label>
                            <div className="relative">
                                <Phone className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                <input
                                    id="phone"
                                    type="tel"
                                    name="phone"
                                    value={data.phone}
                                    className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                    placeholder="+63 917 123 4567"
                                    autoFocus
                                    onChange={(e) => {
                                        setData('phone', e.target.value);
                                        if (stepErrors.phone) setStepErrors(prev => ({ ...prev, phone: '' }));
                                    }}
                                    required
                                />
                            </div>
                            {(stepErrors.phone || errors.phone) && (
                                <InputError message={stepErrors.phone || errors.phone} className="mt-1" />
                            )}
                        </div>

                        <div>
                            <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                City / Municipality *
                            </label>
                            <div className="relative">
                                <Building2 className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                <input
                                    id="city"
                                    type="text"
                                    name="city"
                                    value={data.city}
                                    className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                    placeholder="e.g. Quezon City, Manila"
                                    onChange={(e) => {
                                        setData('city', e.target.value);
                                        if (stepErrors.city) setStepErrors(prev => ({ ...prev, city: '' }));
                                    }}
                                    required
                                />
                            </div>
                            {(stepErrors.city || errors.city) && (
                                <InputError message={stepErrors.city || errors.city} className="mt-1" />
                            )}
                        </div>

                        <div>
                            <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                Complete Warehouse / Store Pickup Address *
                            </label>
                            <div className="relative">
                                <MapPin className="w-4 h-4 text-slate-400 absolute left-3 top-3" />
                                <textarea
                                    id="address"
                                    name="address"
                                    rows={2}
                                    value={data.address}
                                    className="w-full pl-9 pr-3 py-2 text-xs bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                    placeholder="Building, Unit No., Street, Barangay"
                                    onChange={(e) => {
                                        setData('address', e.target.value);
                                        if (stepErrors.address) setStepErrors(prev => ({ ...prev, address: '' }));
                                    }}
                                    required
                                />
                            </div>
                            {(stepErrors.address || errors.address) && (
                                <InputError message={stepErrors.address || errors.address} className="mt-1" />
                            )}
                            <p className="text-[10px] text-slate-500 font-sans mt-1">
                                This address will be used by Bagoo Express couriers for 45-minute doorstep order pickup.
                            </p>
                        </div>

                        <div className="flex items-center gap-3 pt-2">
                            <button
                                type="button"
                                onClick={handlePrev}
                                className="w-1/3 py-3 bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold text-xs rounded-lg transition uppercase tracking-wider flex items-center justify-center gap-1.5"
                            >
                                <ArrowLeft className="w-3.5 h-3.5" />
                                <span>Back</span>
                            </button>
                            <button
                                type="button"
                                onClick={handleNext}
                                className="w-2/3 py-3 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-sm transition uppercase tracking-wider flex items-center justify-center gap-2"
                            >
                                <span>Continue to KYC & Security</span>
                                <ArrowRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}

                {/* STEP 3: KYC DOCUMENTS & SECURITY */}
                {currentStep === 3 && (
                    <div className="space-y-4 animate-fade-in">
                        
                        {/* 1. DTI / Mayor's Permit Upload */}
                        <div>
                            <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                Business Permit / DTI / SEC Certificate *
                            </label>
                            <input
                                type="file"
                                ref={permitInputRef}
                                onChange={handlePermitChange}
                                accept="image/jpeg,image/png,image/webp,application/pdf"
                                className="hidden"
                            />
                            {!permitFileName ? (
                                <div 
                                    onClick={() => permitInputRef.current?.click()}
                                    className="border-2 border-dashed border-slate-300 hover:border-[#E00D42] hover:bg-rose-50/40 rounded-lg p-3.5 text-center cursor-pointer transition flex items-center justify-center gap-2 bg-white"
                                >
                                    <Upload className="w-4 h-4 text-[#E00D42]" />
                                    <span className="text-[11px] text-slate-700 font-bold">Upload DTI/SEC/Mayor's Permit (JPG, PNG, PDF max 10MB)</span>
                                </div>
                            ) : (
                                <div className="flex items-center justify-between p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-xs">
                                    <div className="flex items-center gap-2 truncate">
                                        <FileCheck2 className="w-4 h-4 text-emerald-600 shrink-0" />
                                        <span className="font-bold truncate text-[11px] text-slate-900">{permitFileName}</span>
                                        <span className="text-[10px] text-slate-500 shrink-0">({permitFileSize})</span>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={removePermitFile}
                                        className="p-1 hover:bg-slate-200 rounded text-slate-500 hover:text-slate-800 transition"
                                    >
                                        <X className="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            )}
                            {(stepErrors.business_permit || errors.business_permit) && (
                                <InputError message={stepErrors.business_permit || errors.business_permit} className="mt-1" />
                            )}
                        </div>

                        {/* 2. Government ID Upload */}
                        <div>
                            <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                Valid Government ID of Representative *
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
                                    className="border-2 border-dashed border-slate-300 hover:border-[#E00D42] hover:bg-rose-50/40 rounded-lg p-3.5 text-center cursor-pointer transition flex items-center justify-center gap-2 bg-white"
                                >
                                    <Upload className="w-4 h-4 text-slate-400" />
                                    <span className="text-[11px] text-slate-700">Upload Valid ID (Passport, UMID, Driver's License)</span>
                                </div>
                            ) : (
                                <div className="flex items-center justify-between p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-xs">
                                    <div className="flex items-center gap-2 truncate">
                                        <FileText className="w-4 h-4 text-indigo-600 shrink-0" />
                                        <span className="font-bold truncate text-[11px] text-slate-900">{idFileName}</span>
                                        <span className="text-[10px] text-slate-500 shrink-0">({idFileSize})</span>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={removeIdFile}
                                        className="p-1 hover:bg-slate-200 rounded text-slate-500 hover:text-slate-800 transition"
                                    >
                                        <X className="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            )}
                            {(stepErrors.id_document || errors.id_document) && (
                                <InputError message={stepErrors.id_document || errors.id_document} className="mt-1" />
                            )}
                        </div>

                        {/* Password & Confirm Password */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
                            <div>
                                <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                    Password *
                                </label>
                                <div className="relative">
                                    <Lock className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                    <input
                                        id="password"
                                        type={showPassword ? 'text' : 'password'}
                                        name="password"
                                        value={data.password}
                                        className="w-full pl-9 pr-10 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                        placeholder="••••••••••••"
                                        autoComplete="new-password"
                                        onChange={(e) => setData('password', e.target.value)}
                                        required
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPassword(!showPassword)}
                                        className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 transition"
                                        title={showPassword ? 'Hide password' : 'Show password'}
                                    >
                                        {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                                    </button>
                                </div>
                                <InputError message={errors.password} className="mt-1" />
                            </div>

                            <div>
                                <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                    Confirm Password *
                                </label>
                                <div className="relative">
                                    <Lock className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                    <input
                                        id="password_confirmation"
                                        type={showConfirmPassword ? 'text' : 'password'}
                                        name="password_confirmation"
                                        value={data.password_confirmation}
                                        className="w-full pl-9 pr-10 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                        placeholder="••••••••••••"
                                        autoComplete="new-password"
                                        onChange={(e) => setData('password_confirmation', e.target.value)}
                                        required
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                                        className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 transition"
                                        title={showConfirmPassword ? 'Hide password' : 'Show password'}
                                    >
                                        {showConfirmPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                                    </button>
                                </div>
                                <InputError message={errors.password_confirmation} className="mt-1" />
                            </div>
                        </div>

                        <div className="p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-xs font-sans text-emerald-900 space-y-1">
                            <div className="flex items-center gap-1.5 font-bold font-mono text-[11px]">
                                <ShieldCheck className="w-4 h-4 text-emerald-600" />
                                <span>BagooPH Merchant Guarantee</span>
                            </div>
                            <p className="text-[11px]">10% flat commission on settled orders. No monthly subscription or listing fees.</p>
                        </div>

                        <div className="flex items-center gap-3 pt-2">
                            <button
                                type="button"
                                onClick={handlePrev}
                                className="w-1/3 py-3 bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold text-xs rounded-lg transition uppercase tracking-wider flex items-center justify-center gap-1.5"
                            >
                                <ArrowLeft className="w-3.5 h-3.5" />
                                <span>Back</span>
                            </button>
                            <button
                                type="submit"
                                disabled={processing}
                                className="w-2/3 py-3 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-sm transition uppercase tracking-wider flex items-center justify-center gap-2 disabled:opacity-50"
                            >
                                <span>{processing ? 'Submitting Application...' : 'Complete & Open Store'}</span>
                                <ArrowRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}

                {/* Switcher & Portal Link */}
                <div className="pt-4 border-t border-slate-200 text-center font-mono text-[11px] space-y-1">
                    <span className="text-slate-500">Already have a merchant account? </span>
                    <Link 
                        href={route('seller.login')} 
                        className="text-slate-900 font-bold hover:text-[#E00D42] underline underline-offset-2"
                    >
                        Sign In to Seller Centre
                    </Link>
                </div>
            </form>
        </GuestLayout>
    );
}
