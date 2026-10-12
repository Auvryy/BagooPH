import RegistrationDemographics from '@/Components/RegistrationDemographics';
import RegistrationPolicyAcknowledgement, { RegistrationPolicies } from '@/Components/RegistrationPolicyAcknowledgement';
import PasswordRequirements from '@/Components/PasswordRequirements';
import { documentError, rejectOverlongRegistrationPaste } from '@/utils/registrationFields';
import { registrationInputErrors } from '@/utils/registrationValidation';
import React, { FormEventHandler, useEffect, useRef, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import PersonalNameFields from '@/Components/PersonalNameFields';
import { 
    ArrowRight, 
    ArrowLeft, 
    Lock, 
    Mail, 
    Store, 
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
import BusinessContactInput from '@/Components/BusinessContactInput';
import PhilippineAddressSelector from '@/Components/PhilippineAddressSelector';
import OtpModal from '@/Components/OtpModal';
import { useRegistrationValidation } from '@/hooks/useRegistrationValidation';
import { normalizeRegistrationEmail } from '@/utils/authRequests';
import BirthDateInput, { BirthDateLimits } from '@/Components/BirthDateInput';
import MasterCategorySelect, { MasterCategoryChoice } from '@/Components/MasterCategorySelect';

export default function SellerRegister({ birthDateLimits, masterCategories, policies }: { birthDateLimits: BirthDateLimits; policies: RegistrationPolicies; masterCategories: MasterCategoryChoice[] }) {
    const [currentStep, setCurrentStep] = useState(1);
    const [stepErrors, setStepErrors] = useState<Record<string, string>>({});
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);
    const [showOtpModal, setShowOtpModal] = useState(false);

    const idInputRef = useRef<HTMLInputElement>(null);
    const permitInputRef = useRef<HTMLInputElement>(null);

    const [idFileName, setIdFileName] = useState<string | null>(null);
    const [idFileSize, setIdFileSize] = useState<string | null>(null);
    const [permitFileName, setPermitFileName] = useState<string | null>(null);
    const [permitFileSize, setPermitFileSize] = useState<string | null>(null);

    const { data, setData, post, processing, errors, clearErrors, reset, transform } = useForm<{
        first_name: string;
        middle_initial: string;
        last_name: string;
        birthday: string;
        sex: string;
        shop_name: string;
        root_category_id: string;
        email: string;
        phone: string;
        address: string;
        postal_code: string;
        city: string;
        province: string;
        municipality: string;
        barangay: string;
        role: 'seller';
        password: string;
        password_confirmation: string;
        id_document: File | null;
        business_permit: File | null;
        otp_token: string;
        policy_accepted: boolean;
        terms_version: string;
        privacy_version: string;
    }>({
        first_name: '',
        middle_initial: '',
        last_name: '',
        birthday: '',
        sex: '',
        shop_name: '',
        root_category_id: '',
        email: '',
        phone: '',
        address: '',
        postal_code: '',
        city: '',
        province: 'Metro Manila',
        municipality: '',
        barangay: '',
        role: 'seller',
        password: '',
        password_confirmation: '',
        id_document: null,
        business_permit: null,
        otp_token: '',
        policy_accepted: false,
        terms_version: policies.terms_version,
        privacy_version: policies.privacy_version,
    });

    const fieldFeedback = (event: React.FormEvent<HTMLFormElement>) => {
        const input = event.target as HTMLInputElement;
        const field = input.name as keyof typeof data;
        if (!field || !(field in data) || input.type === 'file') return;
        const value = input.type === 'checkbox' ? input.checked : input.value;
        const message = registrationInputErrors({ ...data, [field]: value }, birthDateLimits)[field];
        clearErrors(field);
        setStepErrors(previous => ({ ...previous, [field]: message ?? '', request: '' }));
    };

    const { validate, validating, applyErrors, feedbackRef } = useRegistrationValidation(data, (fieldErrors, step) => {
        setStepErrors(fieldErrors);
        if (Object.keys(fieldErrors).length && !fieldErrors.request) setCurrentStep(step);
    }, birthDateLimits);

    useEffect(() => {
        if (data.otp_token) setData('otp_token', '');
        transform(values => values);
    }, [data.email]);

    const handleIdChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            const error = documentError(file);
            if (error) {
                removeIdFile();
                setStepErrors(previous => ({ ...previous, id_document: error }));
                return;
            }
            clearErrors('id_document');
            setStepErrors(previous => ({ ...previous, id_document: '' }));
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
            const error = documentError(file);
            if (error) {
                removePermitFile();
                setStepErrors(previous => ({ ...previous, business_permit: error }));
                return;
            }
            clearErrors('business_permit');
            setStepErrors(previous => ({ ...previous, business_permit: '' }));
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

    const validateForm = async (step?: number) => {
        return validate(step);
    };

    const handleNext = async () => {
        if (processing || validating) return;
        if (await validateForm(currentStep)) {
            setStepErrors({});
            setCurrentStep(step => step + 1);
        }
    };

    const handlePrev = () => {
        setStepErrors({});
        setCurrentStep(prev => Math.max(1, prev - 1));
    };

    const handleRegistrationErrors = (fieldErrors: Record<string, string>) => {
        applyErrors(fieldErrors);
        if (fieldErrors.otp_token) setData('otp_token', '');
    };

    const createAccount = (token: string) => {
        transform(values => ({ ...values, otp_token: token }));
        post(route('register'), {
            forceFormData: true,
            onError: handleRegistrationErrors,
            onSuccess: () => reset('password', 'password_confirmation'),
            onFinish: () => transform(values => values),
        });
    };

    const submit: FormEventHandler = async (event) => {
        event.preventDefault();
        if (currentStep < 3) { await handleNext(); return; }
        if (processing || validating || !await validateForm()) return;
        if (!data.otp_token) {
            setShowOtpModal(true);
            return;
        }
        createAccount(data.otp_token);
    };

    const handleOtpSuccess = (token: string) => {
        setShowOtpModal(false);
        setData('otp_token', token);
        createAccount(token);
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
                <div className="flex items-center justify-between font-sans">
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

            <form onPasteCapture={event => rejectOverlongRegistrationPaste(event, (field, message) => setStepErrors(previous => ({ ...previous, [field]: message })))} onChange={fieldFeedback} onSubmit={submit} className="space-y-4" noValidate aria-busy={processing || validating}>
                {Object.keys(stepErrors).length > 0 && (
                    <div ref={feedbackRef} role="alert" tabIndex={-1} className="mb-3 rounded-lg focus:outline-none focus:ring-2 focus:ring-[#E00D42]/20">
                        <InputError message={stepErrors.request ?? Object.values(stepErrors)[0]} />
                    </div>
                )}
                <fieldset disabled={processing || validating || showOtpModal} className="contents">
                {/* STEP 1: STORE BASICS */}
                {currentStep === 1 && (
                    <div className="space-y-4">
                        <div>
                            <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-sans">
                                Store / Brand Name *
                            </label>
                            <div className="relative">
                                <Store className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                                <input
                                    id="shop_name"
                                    type="text"
                                    name="shop_name"
                                    minLength={2} maxLength={255}
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

                        <MasterCategorySelect choices={masterCategories} value={data.root_category_id} onChange={value => { setData('root_category_id', value); setStepErrors(previous => ({ ...previous, root_category_id: '' })); }} error={stepErrors.root_category_id || errors.root_category_id} />

                        <PersonalNameFields values={data} errors={{ ...errors, ...stepErrors }} context="Shop owner" onChange={(field, value) => {
                            setData(field, value);
                            clearErrors(field, 'name' as keyof typeof data);
                            setStepErrors(previous => { const next = { ...previous }; delete next[field]; delete next.name; delete next.request; return next; });
                        }} />

                        <BirthDateInput value={data.birthday} maximum={birthDateLimits.adult_maximum} onChange={value => { setData('birthday', value); setStepErrors(prev => ({ ...prev, birthday: '' })); }} error={stepErrors.birthday || errors.birthday} />

                        <div>
                            <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-sans">
                                Merchant Login Email *
                            </label>
                            <div className="relative">
                                <Mail className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                                <input
                                    id="email"
                                    type="email"
                                    maxLength={255}
                                    autoCapitalize="none"
                                    spellCheck={false}
                                    name="email"
                                    value={data.email}
                                    className="w-full pl-10 pr-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900 placeholder-slate-400"
                                    placeholder="merchant@domain.com"
                                    autoComplete="username"
                                    onChange={(e) => {
                                        setData('email', normalizeRegistrationEmail(e.target.value));
                                        clearErrors('email');
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
                            <BusinessContactInput
                                label="Merchant contact number"
                                value={data.phone}
                                onChange={(val) => {
                                    setData('phone', val);
                                    if (stepErrors.phone) setStepErrors(prev => ({ ...prev, phone: '' }));
                                }}
                                required
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
                                province: stepErrors.province || errors.province,
                                municipality: stepErrors.municipality || errors.municipality,
                                barangay: stepErrors.barangay || errors.barangay,
                                city: stepErrors.city || errors.city,
                                address: stepErrors.address || errors.address,
                            }}
                        />
                        <RegistrationDemographics sex={data.sex} postalCode={data.postal_code}
                            onChange={(field, value) => setData(field, value)}
                            errors={{ sex: stepErrors.sex || errors.sex, postal_code: stepErrors.postal_code || errors.postal_code }} />

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
                            <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-sans">
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
                            <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-sans">
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
                                <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-sans">
                                    Password *
                                </label>
                                <div className="relative">
                                    <Lock className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                                    <input
                                        id="password"
                                        type={showPassword ? 'text' : 'password'}
                                        name="password"
                                        minLength={12} maxLength={128}
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
                                <PasswordRequirements />
                                <InputError message={stepErrors.password || errors.password} className="mt-1" />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-sans">
                                    Confirm Password *
                                </label>
                                <div className="relative">
                                    <Lock className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                                    <input
                                        id="password_confirmation"
                                        type={showConfirmPassword ? 'text' : 'password'}
                                        name="password_confirmation"
                                        minLength={12} maxLength={128}
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

                        <RegistrationPolicyAcknowledgement policies={policies} accepted={data.policy_accepted}
                            onChange={accepted => { setData('policy_accepted', accepted); clearErrors('policy_accepted'); setStepErrors(prev => ({ ...prev, policy_accepted: '' })); }}
                            error={stepErrors.policy_accepted || errors.policy_accepted || errors.terms_version || errors.privacy_version} />

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
                                disabled={processing || validating}
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
            </fieldset>
            </form>

            <OtpModal
                isOpen={showOtpModal}
                email={data.email}
                purpose="registration"
                onSuccess={handleOtpSuccess}
                onClose={() => setShowOtpModal(false)}
            />
        </GuestLayout>
    );
}
