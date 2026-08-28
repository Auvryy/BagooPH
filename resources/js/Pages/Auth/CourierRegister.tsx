import React, { FormEventHandler, useRef, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import { 
    ArrowRight, 
    ArrowLeft, 
    Lock, 
    Mail, 
    Truck, 
    User, 
    ShieldCheck, 
    Check, 
    DollarSign, 
    Upload, 
    FileText, 
    X, 
    Phone, 
    MapPin, 
    Hash, 
    Bike, 
    FileCheck2, 
    Car 
} from 'lucide-react';

export default function CourierRegister() {
    const [currentStep, setCurrentStep] = useState(1);
    const [stepErrors, setStepErrors] = useState<Record<string, string>>({});

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
            setStepErrors(prev => {
                const next = { ...prev };
                delete next.driver_license;
                return next;
            });
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
            setStepErrors(prev => {
                const next = { ...prev };
                delete next.or_cr_document;
                return next;
            });
        }
    };

    const removeOrCrFile = () => {
        setData('or_cr_document', null);
        setOrCrFileName(null);
        setOrCrFileSize(null);
        if (orCrInputRef.current) orCrInputRef.current.value = '';
    };

    const validateStep1 = () => {
        const newErrors: Record<string, string> = {};
        if (!data.name.trim()) newErrors.name = 'Full legal name is required';
        if (!data.email.trim()) {
            newErrors.email = 'Email address is required';
        } else if (!/\S+@\S+\.\S+/.test(data.email)) {
            newErrors.email = 'Valid email is required';
        }
        if (!data.phone.trim()) newErrors.phone = 'Contact number is required';
        if (!data.city.trim()) newErrors.city = 'Operating city is required';
        setStepErrors(newErrors);
        return Object.keys(newErrors).length === 0;
    };

    const validateStep2 = () => {
        const newErrors: Record<string, string> = {};
        if (!data.vehicle_type.trim()) newErrors.vehicle_type = 'Vehicle type is required';
        if (!data.plate_number.trim()) newErrors.plate_number = 'Plate / conduction number is required';
        if (!data.license_number.trim()) newErrors.license_number = 'Driver license number is required';
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
            title="Join Courier Fleet" 
            subtitle="Claim delivery tasks in the first-come pool with guaranteed ₱60/trip base payouts"
            headerBadge="COURIER DISPATCH // 03"
            maxWidth="lg"
        >
            <Head title="Courier Registration — BagooPH" />

            {/* NUMBERED STEPS HEADER */}
            <div className="mb-6 pb-2 border-b border-slate-100">
                <div className="flex items-center justify-center gap-2 sm:gap-4 font-mono">
                    
                    {/* Step 1 */}
                    <div className="flex items-center gap-2">
                        <div className={`w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition ${
                            currentStep === 1 
                                ? 'bg-emerald-600 text-white shadow-xs' 
                                : currentStep > 1 
                                ? 'bg-emerald-600 text-white' 
                                : 'bg-slate-100 text-slate-400 border border-slate-200'
                        }`}>
                            {currentStep > 1 ? <Check className="w-3.5 h-3.5" /> : '1'}
                        </div>
                        <span className={`text-[11px] font-bold uppercase hidden sm:inline ${
                            currentStep === 1 ? 'text-slate-900' : currentStep > 1 ? 'text-slate-600' : 'text-slate-400'
                        }`}>
                            Rider Info
                        </span>
                    </div>

                    <div className={`w-8 sm:w-12 h-px transition-colors ${currentStep > 1 ? 'bg-emerald-600' : 'bg-slate-200'}`}></div>

                    {/* Step 2 */}
                    <div className="flex items-center gap-2">
                        <div className={`w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition ${
                            currentStep === 2 
                                ? 'bg-emerald-600 text-white shadow-xs' 
                                : currentStep > 2 
                                ? 'bg-emerald-600 text-white' 
                                : 'bg-slate-100 text-slate-400 border border-slate-200'
                        }`}>
                            {currentStep > 2 ? <Check className="w-3.5 h-3.5" /> : '2'}
                        </div>
                        <span className={`text-[11px] font-bold uppercase hidden sm:inline ${
                            currentStep === 2 ? 'text-slate-900' : currentStep > 2 ? 'text-slate-600' : 'text-slate-400'
                        }`}>
                            Vehicle
                        </span>
                    </div>

                    <div className={`w-8 sm:w-12 h-px transition-colors ${currentStep > 2 ? 'bg-emerald-600' : 'bg-slate-200'}`}></div>

                    {/* Step 3 */}
                    <div className="flex items-center gap-2">
                        <div className={`w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition ${
                            currentStep === 3 
                                ? 'bg-emerald-600 text-white shadow-xs' 
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
                
                {/* STEP 1: RIDER IDENTITY */}
                {currentStep === 1 && (
                    <div className="space-y-4 animate-fade-in">
                        <div>
                            <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                Full Legal Name *
                            </label>
                            <div className="relative">
                                <User className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                <input
                                    id="name"
                                    type="text"
                                    name="name"
                                    value={data.name}
                                    className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                    placeholder="e.g. Juan Dela Cruz"
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

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
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
                                        className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                        placeholder="rider@domain.com"
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

                            <div>
                                <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                    Mobile Phone Number *
                                </label>
                                <div className="relative">
                                    <Phone className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                    <input
                                        id="phone"
                                        type="tel"
                                        name="phone"
                                        value={data.phone}
                                        className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                        placeholder="+63 917 123 4567"
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
                        </div>

                        <div>
                            <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                Base City / Municipality *
                            </label>
                            <div className="relative">
                                <MapPin className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                <input
                                    id="city"
                                    type="text"
                                    name="city"
                                    value={data.city}
                                    className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                    placeholder="e.g. Quezon City, Pasig, Makati"
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

                        <div className="pt-2">
                            <button
                                type="button"
                                onClick={handleNext}
                                className="w-full py-3 bg-emerald-700 hover:bg-emerald-800 active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-sm transition uppercase tracking-wider flex items-center justify-center gap-2"
                            >
                                <span>Continue to Vehicle Specs</span>
                                <ArrowRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}

                {/* STEP 2: VEHICLE & FLEET SPECS */}
                {currentStep === 2 && (
                    <div className="space-y-4 animate-fade-in">
                        <div>
                            <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                Delivery Vehicle Type *
                            </label>
                            <div className="grid grid-cols-3 gap-2 font-mono text-xs">
                                {['Motorcycle', 'Scooter', 'Sedan / Van'].map((type) => (
                                    <button
                                        key={type}
                                        type="button"
                                        onClick={() => setData('vehicle_type', type)}
                                        className={`p-3 rounded-lg border text-center transition flex flex-col items-center gap-1.5 ${
                                            data.vehicle_type === type
                                                ? 'bg-emerald-50 border-emerald-600 text-emerald-800 font-bold'
                                                : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'
                                        }`}
                                    >
                                        {type === 'Sedan / Van' ? <Car className="w-5 h-5 text-indigo-600" /> : <Bike className="w-5 h-5 text-emerald-600" />}
                                        <span className="text-[11px]">{type}</span>
                                    </button>
                                ))}
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                    Vehicle Plate / MV File No. *
                                </label>
                                <div className="relative">
                                    <Hash className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                    <input
                                        id="plate_number"
                                        type="text"
                                        name="plate_number"
                                        value={data.plate_number}
                                        className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                        placeholder="e.g. 123-ABC / N12345"
                                        onChange={(e) => {
                                            setData('plate_number', e.target.value);
                                            if (stepErrors.plate_number) setStepErrors(prev => ({ ...prev, plate_number: '' }));
                                        }}
                                        required
                                    />
                                </div>
                                {(stepErrors.plate_number || errors.plate_number) && (
                                    <InputError message={stepErrors.plate_number || errors.plate_number} className="mt-1" />
                                )}
                            </div>

                            <div>
                                <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                    Driver's License Number *
                                </label>
                                <div className="relative">
                                    <FileText className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                    <input
                                        id="license_number"
                                        type="text"
                                        name="license_number"
                                        value={data.license_number}
                                        className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                        placeholder="e.g. N01-12-345678"
                                        onChange={(e) => {
                                            setData('license_number', e.target.value);
                                            if (stepErrors.license_number) setStepErrors(prev => ({ ...prev, license_number: '' }));
                                        }}
                                        required
                                    />
                                </div>
                                {(stepErrors.license_number || errors.license_number) && (
                                    <InputError message={stepErrors.license_number || errors.license_number} className="mt-1" />
                                )}
                            </div>
                        </div>

                        <div>
                            <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                Residential / Garage Address
                            </label>
                            <input
                                id="address"
                                type="text"
                                name="address"
                                value={data.address}
                                className="w-full px-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                placeholder="Unit / Street / Barangay"
                                onChange={(e) => setData('address', e.target.value)}
                            />
                            <InputError message={errors.address} className="mt-1" />
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
                                className="w-2/3 py-3 bg-emerald-700 hover:bg-emerald-800 active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-sm transition uppercase tracking-wider flex items-center justify-center gap-2"
                            >
                                <span>Continue to Documents</span>
                                <ArrowRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}

                {/* STEP 3: FLEET KYC & PASSWORD */}
                {currentStep === 3 && (
                    <div className="space-y-4 animate-fade-in">
                        {/* Driver's License Document */}
                        <div>
                            <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                Professional / Non-Prof Driver's License *
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
                                    className="border-2 border-dashed border-slate-300 hover:border-emerald-600 hover:bg-emerald-50/40 rounded-lg p-3 text-center cursor-pointer transition flex items-center justify-center gap-2 bg-white"
                                >
                                    <Upload className="w-4 h-4 text-emerald-600" />
                                    <span className="text-[11px] text-slate-700 font-bold">Upload Driver's License (JPG, PNG, PDF max 10MB)</span>
                                </div>
                            ) : (
                                <div className="flex items-center justify-between p-2 bg-slate-50 border border-slate-200 rounded-lg text-xs">
                                    <div className="flex items-center gap-2 truncate">
                                        <FileCheck2 className="w-4 h-4 text-emerald-600 shrink-0" />
                                        <span className="font-bold truncate text-[11px] text-slate-900">{licenseFileName}</span>
                                        <span className="text-[10px] text-slate-500 shrink-0">({licenseFileSize})</span>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={removeLicenseFile}
                                        className="p-1 hover:bg-slate-200 rounded text-slate-500 hover:text-slate-800 transition"
                                    >
                                        <X className="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            )}
                            <InputError message={errors.driver_license} className="mt-1" />
                        </div>

                        {/* Vehicle OR/CR Document */}
                        <div>
                            <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                                Vehicle Official Receipt & Certificate of Registration (OR/CR)
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
                                    className="border-2 border-dashed border-slate-300 hover:border-emerald-600 hover:bg-emerald-50/40 rounded-lg p-3 text-center cursor-pointer transition flex items-center justify-center gap-2 bg-white"
                                >
                                    <Upload className="w-4 h-4 text-slate-400" />
                                    <span className="text-[11px] text-slate-700">Upload OR/CR Document</span>
                                </div>
                            ) : (
                                <div className="flex items-center justify-between p-2 bg-slate-50 border border-slate-200 rounded-lg text-xs">
                                    <div className="flex items-center gap-2 truncate">
                                        <FileText className="w-4 h-4 text-indigo-600 shrink-0" />
                                        <span className="font-bold truncate text-[11px] text-slate-900">{orCrFileName}</span>
                                        <span className="text-[10px] text-slate-500 shrink-0">({orCrFileSize})</span>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={removeOrCrFile}
                                        className="p-1 hover:bg-slate-200 rounded text-slate-500 hover:text-slate-800 transition"
                                    >
                                        <X className="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            )}
                            <InputError message={errors.or_cr_document} className="mt-1" />
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
                                        type="password"
                                        name="password"
                                        value={data.password}
                                        className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                        placeholder="••••••••••••"
                                        autoComplete="new-password"
                                        onChange={(e) => setData('password', e.target.value)}
                                        required
                                    />
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
                                        type="password"
                                        name="password_confirmation"
                                        value={data.password_confirmation}
                                        className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                        placeholder="••••••••••••"
                                        autoComplete="new-password"
                                        onChange={(e) => setData('password_confirmation', e.target.value)}
                                        required
                                    />
                                </div>
                                <InputError message={errors.password_confirmation} className="mt-1" />
                            </div>
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
                                className="w-2/3 py-3 bg-emerald-700 hover:bg-emerald-800 active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-sm transition uppercase tracking-wider flex items-center justify-center gap-2 disabled:opacity-50"
                            >
                                <span>{processing ? 'Registering Rider...' : 'Submit Courier Application'}</span>
                                <ArrowRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}

                {/* Switcher & Portal Link */}
                <div className="pt-4 border-t border-slate-200 text-center font-mono text-[11px] space-y-1">
                    <span className="text-slate-500">Already registered with the fleet? </span>
                    <Link 
                        href={route('courier.login')} 
                        className="text-slate-900 font-bold hover:text-emerald-700 underline underline-offset-2"
                    >
                        Sign In to Courier Dispatch
                    </Link>
                </div>
            </form>
        </GuestLayout>
    );
}
