import React, { FormEventHandler, useRef, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import { 
    ArrowRight, 
    Lock, 
    Mail, 
    User, 
    Upload, 
    FileText, 
    X, 
    Phone, 
    MapPin, 
    Eye,
    EyeOff
} from 'lucide-react';
import { getDomainUrl } from '@/utils/domain';

export default function Register() {
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
            imageSrc="/images/auth/buyer_register.jpg"
            imageAlt="BagooPH ID Verification Visual"
            imageBadge="Buyer Verification"
            imageHeadline="Safe, Verified Accounts"
            imageDescription="Create your verified account for 100% cash on delivery inspection, secure checkouts, and priority buyer protection."
            title="Create an Account"
            subtitle="Join BagooPH in seconds to start shopping local Filipino brands"
        >
            <Head title="Create an Account — BagooPH" />

            <form onSubmit={submit} className="space-y-3.5">
                <div>
                    <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                        Full Name *
                    </label>
                    <div className="relative">
                        <User className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                        <input
                            id="name"
                            type="text"
                            name="name"
                            value={data.name}
                            className="w-full pl-10 pr-3.5 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900 placeholder-slate-400"
                            placeholder="Juan Dela Cruz"
                            autoFocus
                            onChange={(e) => setData('name', e.target.value)}
                            required
                        />
                    </div>
                    <InputError message={errors.name} className="mt-1" />
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
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
                                className="w-full pl-10 pr-3 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900 placeholder-slate-400"
                                placeholder="name@example.com"
                                autoComplete="username"
                                onChange={(e) => setData('email', e.target.value)}
                                required
                            />
                        </div>
                        <InputError message={errors.email} className="mt-1" />
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                            Mobile Number
                        </label>
                        <div className="relative">
                            <Phone className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                            <input
                                id="phone"
                                type="tel"
                                name="phone"
                                value={data.phone}
                                className="w-full pl-10 pr-3 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900 placeholder-slate-400"
                                placeholder="+63 917 123 4567"
                                onChange={(e) => setData('phone', e.target.value)}
                            />
                        </div>
                        <InputError message={errors.phone} className="mt-1" />
                    </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                            City / Municipality
                        </label>
                        <div className="relative">
                            <MapPin className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                            <input
                                id="city"
                                type="text"
                                name="city"
                                value={data.city}
                                className="w-full pl-10 pr-3 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900 placeholder-slate-400"
                                placeholder="e.g. Quezon City"
                                onChange={(e) => setData('city', e.target.value)}
                            />
                        </div>
                        <InputError message={errors.city} className="mt-1" />
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                            Delivery Address
                        </label>
                        <input
                            id="address"
                            type="text"
                            name="address"
                            value={data.address}
                            className="w-full px-3.5 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900 placeholder-slate-400"
                            placeholder="Unit / Street / Barangay"
                            onChange={(e) => setData('address', e.target.value)}
                        />
                        <InputError message={errors.address} className="mt-1" />
                    </div>
                </div>

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

                {/* Optional ID Document Upload */}
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

                <div className="pt-2">
                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full py-3 bg-[#E00D42] hover:bg-[#C20836] active:bg-[#A8002A] text-white font-bold text-sm rounded-lg shadow-xs transition flex items-center justify-center gap-2 disabled:opacity-50 cursor-pointer"
                    >
                        <span>{processing ? 'Creating Account...' : 'Create Account'}</span>
                        <ArrowRight className="w-4 h-4" />
                    </button>
                </div>
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
