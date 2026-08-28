import React, { FormEventHandler, useRef, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import { 
    ArrowRight, 
    ArrowLeft, 
    Lock, 
    Mail, 
    User, 
    Store, 
    ShieldCheck, 
    Check, 
    Upload, 
    FileText, 
    X, 
    Phone, 
    MapPin, 
    Sparkles,
    Eye,
    EyeOff
} from 'lucide-react';

export default function Register() {
    const [currentStep, setCurrentStep] = useState(1);
    const [stepErrors, setStepErrors] = useState<Record<string, string>>({});
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);

    const fileInputRef = useRef<HTMLInputElement>(null);
    const [fileName, setFileName] = useState<string | null>(null);
    const [fileSize, setFileSize] = useState<string | null>(null);

    const { data, setData, post, processing, errors, reset } = useForm<{
        name: string;
        email: string;
        phone: string;
        address: string;
        city: string;
        role: 'buyer';
        password: string;
        password_confirmation: string;
        id_document: File | null;
    }>({
        name: '',
        email: '',
        phone: '',
        address: '',
        city: '',
        role: 'buyer',
        password: '',
        password_confirmation: '',
        id_document: null,
    });

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            setData('id_document', file);
            setFileName(file.name);
            setFileSize((file.size / (1024 * 1024)).toFixed(2) + ' MB');
        }
    };

    const removeFile = () => {
        setData('id_document', null);
        setFileName(null);
        setFileSize(null);
        if (fileInputRef.current) fileInputRef.current.value = '';
    };

    const validateStep1 = () => {
        const newErrors: Record<string, string> = {};
        if (!data.name.trim()) newErrors.name = 'Full name is required';
        if (!data.email.trim()) {
            newErrors.email = 'Email address is required';
        } else if (!/\S+@\S+\.\S+/.test(data.email)) {
            newErrors.email = 'Valid email address is required';
        }
        if (!data.password) {
            newErrors.password = 'Password is required';
        } else if (data.password.length < 8) {
            newErrors.password = 'Password must be at least 8 characters';
        }
        if (data.password !== data.password_confirmation) {
            newErrors.password_confirmation = 'Passwords do not match';
        }
        setStepErrors(newErrors);
        return Object.keys(newErrors).length === 0;
    };

    const handleNext = () => {
        if (validateStep1()) setCurrentStep(2);
    };

    const handlePrev = () => {
        setStepErrors({});
        setCurrentStep(1);
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
            title="Create Buyer Account" 
            subtitle="Shop across 14 departments with live doorstep telemetry"
            headerBadge="BUYER PORTAL // 01"
            maxWidth="md"
        >
            <Head title="Buyer Registration — BagooPH" />

            {/* NUMBERED STEPS HEADER */}
            <div className="mb-6 pb-2 border-b border-slate-100">
                <div className="flex items-center justify-center gap-3 sm:gap-6 font-mono">
                    
                    {/* Step 1 */}
                    <div className="flex items-center gap-2">
                        <div className={`w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition ${
                            currentStep === 1 
                                ? 'bg-[#E00D42] text-white shadow-xs' 
                                : 'bg-emerald-600 text-white'
                        }`}>
                            {currentStep > 1 ? <Check className="w-3.5 h-3.5" /> : '1'}
                        </div>
                        <span className={`text-[11px] font-bold uppercase ${
                            currentStep === 1 ? 'text-slate-900' : 'text-slate-500'
                        }`}>
                            Credentials
                        </span>
                    </div>

                    <div className={`w-10 sm:w-16 h-px transition-colors ${currentStep > 1 ? 'bg-emerald-600' : 'bg-slate-200'}`}></div>

                    {/* Step 2 */}
                    <div className="flex items-center gap-2">
                        <div className={`w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition ${
                            currentStep === 2 
                                ? 'bg-[#E00D42] text-white shadow-xs' 
                                : 'bg-slate-100 text-slate-400 border border-slate-200'
                        }`}>
                            2
                        </div>
                        <span className={`text-[11px] font-bold uppercase ${
                            currentStep === 2 ? 'text-slate-900' : 'text-slate-400'
                        }`}>
                            Address & Contact
                        </span>
                    </div>

                </div>
            </div>

            <form onSubmit={submit} className="space-y-4 font-mono">
                
                {/* STEP 1: ACCOUNT CREDENTIALS */}
                {currentStep === 1 && (
                    <div className="space-y-4 animate-fade-in">
                        <div>
                            <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                Full Name *
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
                                    autoFocus
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
                                Email Address *
                            </label>
                            <div className="relative">
                                <Mail className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                <input
                                    id="email"
                                    type="email"
                                    name="email"
                                    value={data.email}
                                    className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                    placeholder="name@domain.com"
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

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
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
                                        onChange={(e) => {
                                             setData('password', e.target.value);
                                             if (stepErrors.password) setStepErrors(prev => ({ ...prev, password: '' }));
                                        }}
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
                                {(stepErrors.password || errors.password) && (
                                    <InputError message={stepErrors.password || errors.password} className="mt-1" />
                                )}
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
                                        onChange={(e) => {
                                             setData('password_confirmation', e.target.value);
                                             if (stepErrors.password_confirmation) setStepErrors(prev => ({ ...prev, password_confirmation: '' }));
                                        }}
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
                                {(stepErrors.password_confirmation || errors.password_confirmation) && (
                                    <InputError message={stepErrors.password_confirmation || errors.password_confirmation} className="mt-1" />
                                )}
                            </div>
                        </div>

                        <div className="pt-2">
                            <button
                                type="button"
                                onClick={handleNext}
                                className="w-full py-3 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-sm transition uppercase tracking-wider flex items-center justify-center gap-2"
                            >
                                <span>Continue to Delivery Address</span>
                                <ArrowRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}

                {/* STEP 2: SHIPPING & CONTACT */}
                {currentStep === 2 && (
                    <div className="space-y-4 animate-fade-in">
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                    Mobile Phone Number
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
                                        onChange={(e) => setData('phone', e.target.value)}
                                    />
                                </div>
                                <InputError message={errors.phone} className="mt-1" />
                            </div>

                            <div>
                                <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                    City / Municipality
                                </label>
                                <input
                                    id="city"
                                    type="text"
                                    name="city"
                                    value={data.city}
                                    className="w-full px-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                    placeholder="e.g. Quezon City"
                                    onChange={(e) => setData('city', e.target.value)}
                                />
                                <InputError message={errors.city} className="mt-1" />
                            </div>
                        </div>

                        <div>
                            <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                Default Delivery Address
                            </label>
                            <div className="relative">
                                <MapPin className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                <input
                                    id="address"
                                    type="text"
                                    name="address"
                                    value={data.address}
                                    className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                    placeholder="Unit, House No., Street, Barangay"
                                    onChange={(e) => setData('address', e.target.value)}
                                />
                            </div>
                            <InputError message={errors.address} className="mt-1" />
                        </div>

                        {/* Optional ID Upload */}
                        <div>
                            <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                Valid ID (Optional Fast-Track Verification)
                            </label>
                            <input
                                type="file"
                                ref={fileInputRef}
                                onChange={handleFileChange}
                                accept="image/jpeg,image/png,image/webp,application/pdf"
                                className="hidden"
                            />
                            {!fileName ? (
                                <div 
                                    onClick={() => fileInputRef.current?.click()}
                                    className="border-2 border-dashed border-slate-300 hover:border-[#E00D42] hover:bg-rose-50/40 rounded-lg p-3 text-center cursor-pointer transition flex items-center justify-center gap-2 bg-white"
                                >
                                    <Upload className="w-4 h-4 text-slate-400" />
                                    <span className="text-[11px] text-slate-600">Click to upload valid ID (JPG, PNG max 5MB)</span>
                                </div>
                            ) : (
                                <div className="flex items-center justify-between p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-xs">
                                    <div className="flex items-center gap-2 truncate">
                                        <FileText className="w-4 h-4 text-[#E00D42] shrink-0" />
                                        <span className="font-bold truncate text-[11px] text-slate-900">{fileName}</span>
                                        <span className="text-[10px] text-slate-500 shrink-0">({fileSize})</span>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={removeFile}
                                        className="p-1 hover:bg-slate-200 rounded text-slate-500 hover:text-slate-800 transition"
                                    >
                                        <X className="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            )}
                            <InputError message={errors.id_document} className="mt-1" />
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
                                <span>{processing ? 'Creating Account...' : 'Complete Registration'}</span>
                                <ArrowRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}

                {/* Switchers */}
                <div className="pt-4 border-t border-slate-200 space-y-3 font-sans text-xs">
                    <div className="text-center font-mono text-[11px]">
                        <span className="text-slate-500">Already have an account? </span>
                        <Link 
                            href={route('login')} 
                            className="text-slate-900 font-bold hover:text-[#E00D42] underline underline-offset-2"
                        >
                            Sign In
                        </Link>
                    </div>

                    <div className="p-3 rounded-xl bg-slate-900 text-white flex items-center justify-between gap-3 border border-slate-800">
                        <div className="flex items-center gap-2.5">
                            <div className="w-7 h-7 rounded-lg bg-white/10 flex items-center justify-center text-[#E00D42]">
                                <Store className="w-3.5 h-3.5" />
                            </div>
                            <div>
                                <span className="block font-bold text-[11px]">Want to sell on Bagoo?</span>
                                <span className="block text-[9px] text-slate-400 font-mono">10% Flat Fee & Express Waybills</span>
                            </div>
                        </div>

                        <Link
                            href={route('seller.register')}
                            className="px-2.5 py-1.5 bg-[#E00D42] hover:bg-[#C20836] text-white rounded-lg font-mono text-[10px] font-bold uppercase shrink-0 transition"
                        >
                            Open Shop
                        </Link>
                    </div>
                </div>
            </form>
        </GuestLayout>
    );
}
