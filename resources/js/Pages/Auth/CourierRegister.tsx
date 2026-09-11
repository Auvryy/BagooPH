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
    Check, 
    Upload, 
    FileText, 
    X, 
    Phone, 
    MapPin,
    Car,
    Bike,
    Hash,
    FileCheck2,
    Eye,
    EyeOff,
    Building2
} from 'lucide-react';
import { getDomainUrl } from '@/utils/domain';
import PhoneInput from '@/Components/PhoneInput';
import PhilippineAddressSelector from '@/Components/PhilippineAddressSelector';

export default function CourierRegister() {
    const [currentStep, setCurrentStep] = useState(1);
    const [stepErrors, setStepErrors] = useState<Record<string, string>>({});
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);

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
        province: string;
        municipality: string;
        barangay: string;
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
        province: 'Metro Manila',
        municipality: '',
        barangay: '',
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
        if (!data.plate_number.trim()) newErrors.plate_number = 'Plate / MV file number is required';
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
            formPosition="right"
            imageSrc="/images/auth/courier_register.jpg"
            imageAlt="Bagoo Express Courier License & ID Verification Visual"
            imageBadge="Driver Verification"
            imageHeadline="Verified Courier Accreditation"
            imageDescription="Submit your valid driver's license and vehicle registration (OR/CR) to receive active parcel delivery and pickup routes."
            title="Join Courier Fleet"
            subtitle="Apply as a verified dispatch rider across Metro Manila"
            alternatePortal={{
                label: 'Buyer Marketplace',
                subtext: 'Looking to shop?',
                href: getDomainUrl('buyer', '/login'),
                buttonText: 'Buyer Storefront →',
            }}
        >
            <Head title="Courier Registration — BagooPH" />

            {/* Step Progress Indicators */}
            <div className="mb-6 pb-2 border-b border-slate-100">
                <div className="flex items-center justify-between font-mono">
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

                    <div className={`flex-1 mx-3 h-px transition-colors ${currentStep > 1 ? 'bg-emerald-600' : 'bg-slate-200'}`}></div>

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
                            Vehicle Specs
                        </span>
                    </div>

                    <div className={`flex-1 mx-3 h-px transition-colors ${currentStep > 2 ? 'bg-emerald-600' : 'bg-slate-200'}`}></div>

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
                            Licenses & Key
                        </span>
                    </div>
                </div>
            </div>

            <form onSubmit={submit} className="space-y-4">
                {/* STEP 1: RIDER INFO */}
                {currentStep === 1 && (
                    <div className="space-y-4">
                        <div>
                            <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                                Full Legal Name *
                            </label>
                            <div className="relative">
                                <User className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                                <input
                                    id="name"
                                    type="text"
                                    name="name"
                                    value={data.name}
                                    className="w-full pl-10 pr-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-hidden transition text-slate-900 placeholder-slate-400"
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
                                        className="w-full pl-10 pr-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-hidden transition text-slate-900 placeholder-slate-400"
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

                            <PhoneInput
                                id="phone"
                                name="phone"
                                label="Mobile Phone Number"
                                value={data.phone}
                                onChange={(val) => {
                                    setData('phone', val);
                                    if (stepErrors.phone) setStepErrors(prev => ({ ...prev, phone: '' }));
                                }}
                                required
                                accentColor="emerald"
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
                            }}
                            accentColor="emerald"
                            required={true}
                            streetLabel="Garage / Terminal Street Address"
                            streetPlaceholder="Unit / Street / Building"
                            errors={{
                                city: stepErrors.city || errors.city,
                                address: errors.address,
                            }}
                        />

                        <div className="pt-2">
                            <button
                                type="button"
                                onClick={handleNext}
                                className="w-full py-3 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-bold text-sm rounded-lg shadow-xs transition flex items-center justify-center gap-2 cursor-pointer"
                            >
                                <span>Continue to Vehicle Specs</span>
                                <ArrowRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}

                {/* STEP 2: VEHICLE SPECS */}
                {currentStep === 2 && (
                    <div className="space-y-4">
                        <div>
                            <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                                Delivery Vehicle Type *
                            </label>
                            <div className="grid grid-cols-3 gap-2">
                                {['Motorcycle', 'Scooter', 'Sedan / Van'].map((type) => (
                                    <button
                                        key={type}
                                        type="button"
                                        onClick={() => setData('vehicle_type', type)}
                                        className={`p-3 rounded-lg border text-center transition flex flex-col items-center gap-1.5 cursor-pointer ${
                                            data.vehicle_type === type
                                                ? 'bg-emerald-50 border-emerald-600 text-emerald-800 font-bold shadow-xs'
                                                : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'
                                        }`}
                                    >
                                        {type === 'Sedan / Van' ? <Car className="w-5 h-5 text-emerald-700" /> : <Bike className="w-5 h-5 text-emerald-600" />}
                                        <span className="text-xs">{type}</span>
                                    </button>
                                ))}
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 items-start">
                            <div>
                                <div className="h-5 flex items-center mb-1">
                                    <label htmlFor="plate_number" className="text-xs font-semibold text-slate-800 uppercase tracking-wider font-mono truncate">
                                        Plate / MV File No. <span className="text-emerald-700">*</span>
                                    </label>
                                </div>
                                <div className="relative">
                                    <Hash className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                                    <input
                                        id="plate_number"
                                        type="text"
                                        name="plate_number"
                                        value={data.plate_number}
                                        className="w-full pl-10 pr-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-hidden transition text-slate-900 placeholder-slate-400 uppercase font-mono"
                                        placeholder="e.g. 123-ABC"
                                        onChange={(e) => {
                                            setData('plate_number', e.target.value.toUpperCase());
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
                                <div className="h-5 flex items-center mb-1">
                                    <label htmlFor="license_number" className="text-xs font-semibold text-slate-800 uppercase tracking-wider font-mono truncate">
                                        Driver's License No. <span className="text-emerald-700">*</span>
                                    </label>
                                </div>
                                <div className="relative">
                                    <FileText className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                                    <input
                                        id="license_number"
                                        type="text"
                                        name="license_number"
                                        value={data.license_number}
                                        className="w-full pl-10 pr-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-hidden transition text-slate-900 placeholder-slate-400 uppercase font-mono"
                                        placeholder="e.g. N01-12-345678"
                                        onChange={(e) => {
                                            setData('license_number', e.target.value.toUpperCase());
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
                                className="w-2/3 py-3 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-bold text-sm rounded-lg shadow-xs transition flex items-center justify-center gap-2 cursor-pointer"
                            >
                                <span>Continue to Documents</span>
                                <ArrowRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}

                {/* STEP 3: FLEET DOCUMENTS & PASSWORD */}
                {currentStep === 3 && (
                    <div className="space-y-4">
                        {/* Valid Government ID */}
                        <div>
                            <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                                Valid Government ID (Passport / UMID / Postal) *
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
                                    <span className="text-xs text-slate-700 font-medium">Upload Government ID</span>
                                </div>
                            ) : (
                                <div className="flex items-center justify-between p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-xs">
                                    <div className="flex items-center gap-2 truncate">
                                        <FileCheck2 className="w-4 h-4 text-emerald-600 shrink-0" />
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

                        {/* Driver's License Document */}
                        <div>
                            <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
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
                                    className="border border-dashed border-slate-300 hover:border-slate-400 rounded-lg p-3 text-center cursor-pointer transition flex items-center justify-center gap-2 bg-slate-50/50 hover:bg-slate-50"
                                >
                                    <Upload className="w-4 h-4 text-slate-500" />
                                    <span className="text-xs text-slate-700 font-medium">Upload Driver's License</span>
                                </div>
                            ) : (
                                <div className="flex items-center justify-between p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-xs">
                                    <div className="flex items-center gap-2 truncate">
                                        <FileCheck2 className="w-4 h-4 text-emerald-600 shrink-0" />
                                        <span className="font-semibold truncate text-slate-900">{licenseFileName}</span>
                                        <span className="text-[10px] text-slate-400 shrink-0">({licenseFileSize})</span>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={removeLicenseFile}
                                        className="p-1 hover:bg-slate-200 rounded text-slate-400 hover:text-slate-700 transition cursor-pointer"
                                    >
                                        <X className="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            )}
                            {(stepErrors.driver_license || errors.driver_license) && (
                                <InputError message={stepErrors.driver_license || errors.driver_license} className="mt-1" />
                            )}
                        </div>

                        {/* Vehicle OR/CR Document */}
                        <div>
                            <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                                Vehicle Registration (OR/CR) *
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
                                    className="border border-dashed border-slate-300 hover:border-slate-400 rounded-lg p-3 text-center cursor-pointer transition flex items-center justify-center gap-2 bg-slate-50/50 hover:bg-slate-50"
                                >
                                    <Upload className="w-4 h-4 text-slate-500" />
                                    <span className="text-xs text-slate-700 font-medium">Upload Vehicle OR/CR</span>
                                </div>
                            ) : (
                                <div className="flex items-center justify-between p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-xs">
                                    <div className="flex items-center gap-2 truncate">
                                        <FileText className="w-4 h-4 text-emerald-600 shrink-0" />
                                        <span className="font-semibold truncate text-slate-900">{orCrFileName}</span>
                                        <span className="text-[10px] text-slate-400 shrink-0">({orCrFileSize})</span>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={removeOrCrFile}
                                        className="p-1 hover:bg-slate-200 rounded text-slate-400 hover:text-slate-700 transition cursor-pointer"
                                    >
                                        <X className="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            )}
                            {(stepErrors.or_cr_document || errors.or_cr_document) && (
                                <InputError message={stepErrors.or_cr_document || errors.or_cr_document} className="mt-1" />
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
                                        className="w-full pl-10 pr-9 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-hidden transition text-slate-900 placeholder-slate-400"
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
                                        className="w-full pl-10 pr-9 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-hidden transition text-slate-900 placeholder-slate-400"
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
                                className="w-2/3 py-3 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-bold text-sm rounded-lg shadow-xs transition flex items-center justify-center gap-2 disabled:opacity-50 cursor-pointer"
                            >
                                <span>{processing ? 'Submitting...' : 'Complete Application'}</span>
                                <ArrowRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}

                {/* Footer Switcher */}
                <div className="mt-6 pt-5 border-t border-slate-200 text-center">
                    <p className="text-xs text-slate-600">
                        Already registered as a driver?{' '}
                        <Link 
                            href={route('courier.login')} 
                            className="text-emerald-700 font-semibold hover:underline"
                        >
                            Sign In to Courier Dispatch
                        </Link>
                    </p>
                </div>
            </form>
        </GuestLayout>
    );
}
