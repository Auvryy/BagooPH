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
    Building2, 
    FileCheck2,
    Eye,
    EyeOff,
    Check
} from 'lucide-react';
import { getDomainUrl } from '@/utils/domain';
import PhoneInput from '@/Components/PhoneInput';
import PhilippineAddressSelector from '@/Components/PhilippineAddressSelector';

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
        province: string;
        municipality: string;
        barangay: string;
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
        province: 'Metro Manila',
        municipality: '',
        barangay: '',
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
        if (!data.address.trim()) newErrors.address = 'Warehouse or pickup address is required';
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
            formPosition="right"
            imageSrc="/images/auth/seller_register.jpg"
            imageAlt="BagooPH Merchant Verification Visual"
            imageBadge="Merchant Verification"
            imageHeadline="Verified Seller Registration"
            imageDescription="Register your brand or boutique to reach thousands of buyers across Metro Manila with automated door-to-door courier dispatch."
            title="Register as Seller"
            subtitle="Open your store on BagooPH with 10% flat platform commission"
            alternatePortal={{
                label: 'Buyer Marketplace',
                subtext: 'Looking to shop?',
                href: getDomainUrl('buyer', '/login'),
                buttonText: 'Buyer Storefront →',
            }}
        >
            <Head title="Seller Registration — BagooPH" />

            {/* Step Progress Indicators */}
            <div className="mb-6 pb-2 border-b border-slate-100">
                <div className="flex items-center justify-between font-mono">
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
                            Store Basics
                        </span>
                    </div>

                    <div className={`flex-1 mx-3 h-px transition-colors ${currentStep > 1 ? 'bg-emerald-600' : 'bg-slate-200'}`}></div>

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
                            Pickup Address
                        </span>
                    </div>

                    <div className={`flex-1 mx-3 h-px transition-colors ${currentStep > 2 ? 'bg-emerald-600' : 'bg-slate-200'}`}></div>

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
                            Permits & Key
                        </span>
                    </div>
                </div>
            </div>

            <form onSubmit={submit} className="space-y-4">
                {/* STEP 1: STORE BASICS */}
                {currentStep === 1 && (
                    <div className="space-y-4">
                        <div>
                            <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                                Store / Brand Name *
                            </label>
                            <div className="relative">
                                <Store className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                                <input
                                    id="shop_name"
                                    type="text"
                                    name="shop_name"
                                    value={data.shop_name}
                                    className="w-full pl-10 pr-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900 placeholder-slate-400"
                                    placeholder="e.g. Apex Apparel Manila"
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
                            <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                                Merchant Contact Name *
                            </label>
                            <div className="relative">
                                <User className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                                <input
                                    id="name"
                                    type="text"
                                    name="name"
                                    value={data.name}
                                    className="w-full pl-10 pr-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900 placeholder-slate-400"
                                    placeholder="e.g. Maria Santos"
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
                            <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                                Merchant Login Email *
                            </label>
                            <div className="relative">
                                <Mail className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                                <input
                                    id="email"
                                    type="email"
                                    name="email"
                                    value={data.email}
                                    className="w-full pl-10 pr-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900 placeholder-slate-400"
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

                        <div className="pt-2">
                            <button
                                type="button"
                                onClick={handleNext}
                                className="w-full py-3 bg-[#E00D42] hover:bg-[#C20836] active:bg-[#A8002A] text-white font-bold text-sm rounded-lg shadow-xs transition flex items-center justify-center gap-2 cursor-pointer"
                            >
                                <span>Continue to Pickup Address</span>
                                <ArrowRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}

                {/* STEP 2: PICKUP & DISPATCH ADDRESS */}
                {currentStep === 2 && (
                    <div className="space-y-4">
                        <div>
                            <PhoneInput
                                id="phone"
                                name="phone"
                                label="Merchant Mobile Phone Number"
                                value={data.phone}
                                onChange={(val) => {
                                    setData('phone', val);
                                    if (stepErrors.phone) setStepErrors(prev => ({ ...prev, phone: '' }));
                                }}
                                required
                                accentColor="primary"
                                error={stepErrors.phone || errors.phone}
                            />
                        </div>

                        <PhilippineAddressSelector
                            values={{
                                province: data.province,
                                city: data.city,
                                municipality: data.municipality,
                                barangay: data.barangay,
                                address: data.address,
                            }}
                            onChange={(addr) => {
                                setData(prev => ({
                                    ...prev,
                                    province: addr.province,
                                    city: addr.city,
                                    municipality: addr.municipality,
                                    barangay: addr.barangay,
                                    address: addr.address,
                                }));
                                if (stepErrors.city) setStepErrors(prev => ({ ...prev, city: '' }));
                                if (stepErrors.address) setStepErrors(prev => ({ ...prev, address: '' }));
                            }}
                            accentColor="primary"
                            required={true}
                            streetLabel="Warehouse / Store Street Address"
                            streetPlaceholder="Unit No., Building, Street Name"
                            errors={{
                                city: stepErrors.city || errors.city,
                                address: stepErrors.address || errors.address,
                            }}
                        />
                        <p className="text-xs text-slate-500 mt-1">
                            Bagoo Express dispatch riders will collect packaged parcels directly from this pickup location.
                        </p>

                        <div className="flex items-center gap-3 pt-2">
                            <button
                                type="button"
                                onClick={handlePrev}
                                className="w-1/3 py-3 bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold text-sm rounded-lg transition flex items-center justify-center gap-1.5 cursor-pointer"
                            >
                                <ArrowLeft className="w-4 h-4" />
                                <span>Back</span>
                            </button>
                            <button
                                type="button"
                                onClick={handleNext}
                                className="w-2/3 py-3 bg-[#E00D42] hover:bg-[#C20836] active:bg-[#A8002A] text-white font-bold text-sm rounded-lg shadow-xs transition flex items-center justify-center gap-2 cursor-pointer"
                            >
                                <span>Continue to Verification</span>
                                <ArrowRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}

                {/* STEP 3: PERMITS & SECURITY */}
                {currentStep === 3 && (
                    <div className="space-y-4">
                        {/* 1. Business Permit / DTI Upload */}
                        <div>
                            <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                                Business Permit / DTI / Student ID *
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
                                    className="border border-dashed border-slate-300 hover:border-slate-400 rounded-lg p-3 text-center cursor-pointer transition flex items-center justify-center gap-2 bg-slate-50/50 hover:bg-slate-50"
                                >
                                    <Upload className="w-4 h-4 text-slate-500" />
                                    <span className="text-xs text-slate-700 font-medium">Upload DTI, Mayor's Permit, or Student ID</span>
                                </div>
                            ) : (
                                <div className="flex items-center justify-between p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-xs">
                                    <div className="flex items-center gap-2 truncate">
                                        <FileCheck2 className="w-4 h-4 text-emerald-600 shrink-0" />
                                        <span className="font-semibold truncate text-slate-900">{permitFileName}</span>
                                        <span className="text-[10px] text-slate-400 shrink-0">({permitFileSize})</span>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={removePermitFile}
                                        className="p-1 hover:bg-slate-200 rounded text-slate-400 hover:text-slate-700 transition cursor-pointer"
                                    >
                                        <X className="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            )}
                            {(stepErrors.business_permit || errors.business_permit) && (
                                <InputError message={stepErrors.business_permit || errors.business_permit} className="mt-1" />
                            )}
                        </div>

                        {/* 2. Valid Government ID Upload */}
                        <div>
                            <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                                Valid Government / Student ID *
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
                                    className="border border-dashed border-slate-300 hover:border-slate-400 rounded-lg p-3 text-center cursor-pointer transition flex items-center justify-center gap-2 bg-slate-50/50 hover:bg-slate-50"
                                >
                                    <Upload className="w-4 h-4 text-slate-500" />
                                    <span className="text-xs text-slate-700 font-medium">Upload Valid Government ID</span>
                                </div>
                            ) : (
                                <div className="flex items-center justify-between p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-xs">
                                    <div className="flex items-center gap-2 truncate">
                                        <FileText className="w-4 h-4 text-slate-700 shrink-0" />
                                        <span className="font-semibold truncate text-slate-900">{idFileName}</span>
                                        <span className="text-[10px] text-slate-400 shrink-0">({idFileSize})</span>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={removeIdFile}
                                        className="p-1 hover:bg-slate-200 rounded text-slate-400 hover:text-slate-700 transition cursor-pointer"
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
                                <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                                    Password *
                                </label>
                                <div className="relative">
                                    <Lock className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                                    <input
                                        id="password"
                                        type={showPassword ? 'text' : 'password'}
                                        name="password"
                                        value={data.password}
                                        className="w-full pl-10 pr-9 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900 placeholder-slate-400"
                                        placeholder="••••••••••••"
                                        autoComplete="new-password"
                                        onChange={(e) => setData('password', e.target.value)}
                                        required
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPassword(!showPassword)}
                                        className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 transition cursor-pointer"
                                        title={showPassword ? 'Hide password' : 'Show password'}
                                    >
                                        {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                                    </button>
                                </div>
                                <InputError message={errors.password} className="mt-1" />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                                    Confirm Password *
                                </label>
                                <div className="relative">
                                    <Lock className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                                    <input
                                        id="password_confirmation"
                                        type={showConfirmPassword ? 'text' : 'password'}
                                        name="password_confirmation"
                                        value={data.password_confirmation}
                                        className="w-full pl-10 pr-9 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900 placeholder-slate-400"
                                        placeholder="••••••••••••"
                                        autoComplete="new-password"
                                        onChange={(e) => setData('password_confirmation', e.target.value)}
                                        required
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                                        className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 transition cursor-pointer"
                                        title={showConfirmPassword ? 'Hide password' : 'Show password'}
                                    >
                                        {showConfirmPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                                    </button>
                                </div>
                                <InputError message={errors.password_confirmation} className="mt-1" />
                            </div>
                        </div>

                        <div className="flex items-center gap-3 pt-2">
                            <button
                                type="button"
                                onClick={handlePrev}
                                className="w-1/3 py-3 bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold text-sm rounded-lg transition flex items-center justify-center gap-1.5 cursor-pointer"
                            >
                                <ArrowLeft className="w-4 h-4" />
                                <span>Back</span>
                            </button>
                            <button
                                type="submit"
                                disabled={processing}
                                className="w-2/3 py-3 bg-[#E00D42] hover:bg-[#C20836] active:bg-[#A8002A] text-white font-bold text-sm rounded-lg shadow-xs transition flex items-center justify-center gap-2 disabled:opacity-50 cursor-pointer"
                            >
                                <span>{processing ? 'Registering...' : 'Complete Registration'}</span>
                                <ArrowRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}

                {/* Footer Switcher */}
                <div className="mt-6 pt-5 border-t border-slate-200 text-center">
                    <p className="text-xs text-slate-600">
                        Already have a seller account?{' '}
                        <Link 
                            href={route('seller.login')} 
                            className="text-[#E00D42] font-semibold hover:underline"
                        >
                            Sign In to Seller Centre
                        </Link>
                    </p>
                </div>
            </form>
        </GuestLayout>
    );
}
