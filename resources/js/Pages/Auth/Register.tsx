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
    Upload, 
    FileText, 
    X, 
    Phone, 
    MapPin, 
    Eye,
    EyeOff,
    Check,
    Calendar,
    Sparkles
} from 'lucide-react';
import { getDomainUrl } from '@/utils/domain';

export default function Register() {
    const [currentStep, setCurrentStep] = useState(1);
    const [stepErrors, setStepErrors] = useState<Record<string, string>>({});
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);

    const fileInputRef = useRef<HTMLInputElement>(null);
    const [fileName, setFileName] = useState<string | null>(null);
    const [fileSize, setFileSize] = useState<string | null>(null);

    const { data, setData, post, processing, errors, reset } = useForm<{
        first_name: string;
        middle_name: string;
        last_name: string;
        email: string;
        password: string;
        password_confirmation: string;
        birthday: string;
        age: string | number;
        sex: string;
        phone: string;
        city: string;
        address: string;
        role: 'buyer';
        id_document: File | null;
    }>({
        first_name: '',
        middle_name: '',
        last_name: '',
        email: '',
        password: '',
        password_confirmation: '',
        birthday: '',
        age: '',
        sex: '',
        phone: '',
        city: '',
        address: '',
        role: 'buyer',
        id_document: null,
    });

    const calculateAge = (birthDateString: string) => {
        if (!birthDateString) return '';
        const today = new Date();
        const birthDate = new Date(birthDateString);
        let age = today.getFullYear() - birthDate.getFullYear();
        const m = today.getMonth() - birthDate.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
            age--;
        }
        return age >= 0 ? age : 0;
    };

    const handleBirthdayChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const value = e.target.value;
        const computedAge = calculateAge(value);
        setData(prev => ({
            ...prev,
            birthday: value,
            age: computedAge,
        }));
        if (stepErrors.birthday) {
            setStepErrors(prev => {
                const updated = { ...prev };
                delete updated.birthday;
                return updated;
            });
        }
    };

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
        if (!data.first_name.trim()) newErrors.first_name = 'First name is required';
        if (!data.last_name.trim()) newErrors.last_name = 'Last name / surname is required';
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
        if (!data.password_confirmation) {
            newErrors.password_confirmation = 'Confirm password is required';
        } else if (data.password !== data.password_confirmation) {
            newErrors.password_confirmation = 'Passwords do not match';
        }

        setStepErrors(newErrors);
        return Object.keys(newErrors).length === 0;
    };

    const handleNext = () => {
        if (validateStep1()) {
            setStepErrors({});
            setCurrentStep(2);
        }
    };

    const handlePrev = () => {
        setStepErrors({});
        setCurrentStep(1);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        const newErrors: Record<string, string> = {};
        if (!data.birthday) newErrors.birthday = 'Date of birth is required';
        if (!data.sex) newErrors.sex = 'Sex is required';
        if (!data.city.trim()) newErrors.city = 'City / municipality is required';
        if (!data.address.trim()) newErrors.address = 'Delivery address is required';

        if (Object.keys(newErrors).length > 0) {
            setStepErrors(newErrors);
            return;
        }

        post(route('register'), {
            forceFormData: true,
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout
            formPosition="right"
            imageSrc="/images/auth/buyer_register.jpg"
            imageAlt="BagooPH ID Verification Visual"
            imageBadge="Buyer Verification"
            imageHeadline="Safe, Verified Accounts"
            imageDescription="Create your verified account for 100% cash on delivery inspection, secure checkouts, and priority buyer protection."
            title="Create an Account"
            subtitle="Join BagooPH in two quick steps to start shopping local Filipino brands"
        >
            <Head title="Create an Account — BagooPH" />

            {/* Step Checkpoint Progress Header */}
            <div className="flex items-center justify-between font-mono mb-6 pb-2 border-b border-slate-100">
                <div className="flex items-center gap-2">
                    <div className={`w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition ${
                        currentStep === 1 
                            ? 'bg-[#E00D42] text-white shadow-xs' 
                            : 'bg-[#E00D42] text-white'
                    }`}>
                        {currentStep > 1 ? <Check className="w-3.5 h-3.5" /> : '1'}
                    </div>
                    <span className={`text-[11px] font-bold uppercase ${currentStep === 1 ? 'text-slate-900' : 'text-slate-600'}`}>
                        Credentials
                    </span>
                </div>

                <div className={`flex-1 mx-3 h-px transition-colors ${currentStep > 1 ? 'bg-[#E00D42]' : 'bg-slate-200'}`}></div>

                <div className="flex items-center gap-2">
                    <div className={`w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition ${
                        currentStep === 2 
                            ? 'bg-[#E00D42] text-white shadow-xs' 
                            : 'bg-slate-100 text-slate-400 border border-slate-200'
                    }`}>
                        2
                    </div>
                    <span className={`text-[11px] font-bold uppercase ${currentStep === 2 ? 'text-slate-900' : 'text-slate-400'}`}>
                        Identity & Address
                    </span>
                </div>
            </div>

            <form onSubmit={submit} className="space-y-3.5 font-sans">
                {/* CHECKPOINT 1: CREDENTIALS & NAME */}
                {currentStep === 1 && (
                    <div className="space-y-3.5">
                        {/* Separated Name: First Name, Middle Name, Last Name */}
                        <div className="grid grid-cols-1 sm:grid-cols-12 gap-3">
                            <div className="sm:col-span-5">
                                <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                                    First Name *
                                </label>
                                <div className="relative">
                                    <User className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                    <input
                                        id="first_name"
                                        type="text"
                                        name="first_name"
                                        value={data.first_name}
                                        className="w-full pl-9 pr-3 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900 placeholder-slate-400"
                                        placeholder="Juan"
                                        autoFocus
                                        onChange={(e) => setData('first_name', e.target.value)}
                                        required
                                    />
                                </div>
                                <InputError message={stepErrors.first_name || errors.first_name} className="mt-1" />
                            </div>

                            <div className="sm:col-span-3">
                                <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                                    M.I. / Middle
                                </label>
                                <input
                                    id="middle_name"
                                    type="text"
                                    name="middle_name"
                                    value={data.middle_name}
                                    className="w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900 placeholder-slate-400"
                                    placeholder="Santos"
                                    onChange={(e) => setData('middle_name', e.target.value)}
                                />
                            </div>

                            <div className="sm:col-span-4">
                                <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                                    Last Name *
                                </label>
                                <input
                                    id="last_name"
                                    type="text"
                                    name="last_name"
                                    value={data.last_name}
                                    className="w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900 placeholder-slate-400"
                                    placeholder="Dela Cruz"
                                    onChange={(e) => setData('last_name', e.target.value)}
                                    required
                                />
                                <InputError message={stepErrors.last_name || errors.last_name} className="mt-1" />
                            </div>
                        </div>

                        {/* Email Address */}
                        <div>
                            <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                                Email Address *
                            </label>
                            <div className="relative">
                                <Mail className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                                <input
                                    id="email"
                                    type="email"
                                    name="email"
                                    value={data.email}
                                    className="w-full pl-10 pr-3.5 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900 placeholder-slate-400"
                                    placeholder="juan.delacruz@example.com"
                                    autoComplete="username"
                                    onChange={(e) => setData('email', e.target.value)}
                                    required
                                />
                            </div>
                            <InputError message={stepErrors.email || errors.email} className="mt-1" />
                        </div>

                        {/* Password Creation */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
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
                                <InputError message={stepErrors.password || errors.password} className="mt-1" />
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
                                <InputError message={stepErrors.password_confirmation || errors.password_confirmation} className="mt-1" />
                            </div>
                        </div>

                        {/* Continue Button */}
                        <div className="pt-2">
                            <button
                                type="button"
                                onClick={handleNext}
                                className="w-full py-3 bg-[#E00D42] hover:bg-[#C20836] active:bg-[#A8002A] text-white font-bold text-sm rounded-lg shadow-xs transition flex items-center justify-center gap-2 cursor-pointer"
                            >
                                <span>Continue to Personal Info</span>
                                <ArrowRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}

                {/* CHECKPOINT 2: IDENTITY, DEMOGRAPHICS & ADDRESS */}
                {currentStep === 2 && (
                    <div className="space-y-3.5">
                        {/* Birthday & Auto-Calculated Age */}
                        <div className="grid grid-cols-1 sm:grid-cols-12 gap-3">
                            <div className="sm:col-span-7">
                                <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                                    Date of Birth *
                                </label>
                                <div className="relative">
                                    <Calendar className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                    <input
                                        id="birthday"
                                        type="date"
                                        name="birthday"
                                        max={new Date().toISOString().split('T')[0]}
                                        value={data.birthday}
                                        className="w-full pl-9 pr-3 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900"
                                        onChange={handleBirthdayChange}
                                        required
                                    />
                                </div>
                                <InputError message={stepErrors.birthday || errors.birthday} className="mt-1" />
                            </div>

                            <div className="sm:col-span-5">
                                <div className="flex items-center justify-between mb-1">
                                    <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider font-mono">
                                        Age
                                    </label>
                                    <span className="text-[10px] text-slate-400 font-mono flex items-center gap-1">
                                        <Sparkles className="w-3 h-3 text-[#E00D42]" />
                                        Auto-calculated
                                    </span>
                                </div>
                                <input
                                    id="age"
                                    type="text"
                                    name="age"
                                    value={data.age ? `${data.age} years old` : 'Select birthday'}
                                    readOnly
                                    className="w-full px-3 py-2 text-sm bg-slate-50 border border-slate-300 rounded-lg text-slate-700 font-mono font-medium outline-hidden select-none cursor-default"
                                />
                            </div>
                        </div>

                        {/* Sex & Phone */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                                    Sex *
                                </label>
                                <select
                                    id="sex"
                                    name="sex"
                                    value={data.sex}
                                    onChange={(e) => setData('sex', e.target.value)}
                                    className="w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900"
                                    required
                                >
                                    <option value="">Select Sex</option>
                                    <option value="Female">Female</option>
                                    <option value="Male">Male</option>
                                    <option value="Other">Other / Prefer not to say</option>
                                </select>
                                <InputError message={stepErrors.sex || errors.sex} className="mt-1" />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                                    Mobile Number
                                </label>
                                <div className="relative">
                                    <Phone className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                    <input
                                        id="phone"
                                        type="tel"
                                        name="phone"
                                        value={data.phone}
                                        className="w-full pl-9 pr-3 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900 placeholder-slate-400"
                                        placeholder="+63 917 123 4567"
                                        onChange={(e) => setData('phone', e.target.value)}
                                    />
                                </div>
                                <InputError message={errors.phone} className="mt-1" />
                            </div>
                        </div>

                        {/* City & Delivery Address */}
                        <div className="grid grid-cols-1 sm:grid-cols-12 gap-3">
                            <div className="sm:col-span-5">
                                <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                                    City / Municipality *
                                </label>
                                <div className="relative">
                                    <MapPin className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                    <input
                                        id="city"
                                        type="text"
                                        name="city"
                                        value={data.city}
                                        className="w-full pl-9 pr-3 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900 placeholder-slate-400"
                                        placeholder="e.g. Quezon City"
                                        onChange={(e) => setData('city', e.target.value)}
                                        required
                                    />
                                </div>
                                <InputError message={stepErrors.city || errors.city} className="mt-1" />
                            </div>

                            <div className="sm:col-span-7">
                                <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                                    Delivery Address *
                                </label>
                                <input
                                    id="address"
                                    type="text"
                                    name="address"
                                    value={data.address}
                                    className="w-full px-3.5 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900 placeholder-slate-400"
                                    placeholder="Unit / Street / Barangay"
                                    onChange={(e) => setData('address', e.target.value)}
                                    required
                                />
                                <InputError message={stepErrors.address || errors.address} className="mt-1" />
                            </div>
                        </div>

                        {/* Optional Government ID Document Upload */}
                        <div>
                            <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                                Government ID <span className="text-slate-400 lowercase font-normal">(optional for verified badge)</span>
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
                                    className="border border-dashed border-slate-300 hover:border-slate-400 rounded-lg p-2.5 text-center cursor-pointer transition flex items-center justify-center gap-2 bg-slate-50/50 hover:bg-slate-50"
                                >
                                    <Upload className="w-4 h-4 text-slate-400" />
                                    <span className="text-xs text-slate-600">Upload Valid ID (JPG, PNG, PDF max 5MB)</span>
                                </div>
                            ) : (
                                <div className="flex items-center justify-between p-2 bg-slate-50 border border-slate-200 rounded-lg text-xs">
                                    <div className="flex items-center gap-2 truncate">
                                        <FileText className="w-4 h-4 text-[#E00D42] shrink-0" />
                                        <span className="font-semibold truncate text-slate-800">{fileName}</span>
                                        <span className="text-[10px] text-slate-400 shrink-0">({fileSize})</span>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={removeFile}
                                        className="p-1 hover:bg-slate-200 rounded text-slate-400 hover:text-slate-700 transition cursor-pointer"
                                    >
                                        <X className="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            )}
                            <InputError message={errors.id_document} className="mt-1" />
                        </div>

                        {/* Back & Submit Buttons */}
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
                                <span>{processing ? 'Creating Account...' : 'Complete Registration'}</span>
                                <ArrowRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}
            </form>

            <div className="mt-6 pt-5 border-t border-slate-200 text-center space-y-2">
                <p className="text-xs text-slate-600">
                    Already have an account?{' '}
                    <Link 
                        href={route('login')} 
                        className="text-[#E00D42] font-semibold hover:underline"
                    >
                        Log In
                    </Link>
                </p>

                <p className="text-xs text-slate-500">
                    Want to become a seller?{' '}
                    <a
                        href={getDomainUrl('seller', '/register')}
                        className="text-slate-800 font-semibold hover:text-[#E00D42] hover:underline"
                    >
                        Register Store →
                    </a>
                </p>
            </div>
        </GuestLayout>
    );
}
