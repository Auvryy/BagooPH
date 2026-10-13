import UploadHint from '@/Components/UploadHint';
import { RegistrationSexField, RegistrationPostalCodeField } from '@/Components/RegistrationDemographics';
import RegistrationPolicyAcknowledgement, { RegistrationPolicies } from '@/Components/RegistrationPolicyAcknowledgement';
import PasswordRequirements from '@/Components/PasswordRequirements';
import { documentError, rejectOverlongRegistrationPaste, uppercaseOperationalCode } from '@/utils/registrationFields';
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
    Truck, 
    Upload, 
    FileText, 
    X, 
    Phone, 
    MapPin, 
    Building2, 
    FileCheck2,
    Eye,
    EyeOff,
    Check,
    Shield,
    Boxes,
    Hash,
    BadgeCheck,
    Compass
} from 'lucide-react';
import { getDomainUrl } from '@/utils/domain';
import PhoneInput from '@/Components/PhoneInput';
import PhilippineAddressSelector from '@/Components/PhilippineAddressSelector';
import OtpModal from '@/Components/OtpModal';
import { useRegistrationValidation } from '@/hooks/useRegistrationValidation';
import { useRegistrationEmailAvailability } from '@/hooks/useRegistrationEmailAvailability';
import RegistrationEmailFeedback from '@/Components/RegistrationEmailFeedback';
import { normalizeRegistrationEmail } from '@/utils/authRequests';
import BirthDateInput, { BirthDateLimits } from '@/Components/BirthDateInput';

export default function LogisticsRegister({ birthDateLimits, policies }: { birthDateLimits: BirthDateLimits; policies: RegistrationPolicies }) {
    const [currentStep, setCurrentStep] = useState(1);
    const [stepErrors, setStepErrors] = useState<Record<string, string>>({});
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);
    const [showOtpModal, setShowOtpModal] = useState(false);

    const permitInputRef = useRef<HTMLInputElement>(null);
    const franchiseInputRef = useRef<HTMLInputElement>(null);

    const [permitFileName, setPermitFileName] = useState<string | null>(null);
    const [permitFileSize, setPermitFileSize] = useState<string | null>(null);
    const [franchiseFileName, setFranchiseFileName] = useState<string | null>(null);
    const [franchiseFileSize, setFranchiseFileSize] = useState<string | null>(null);

    const { data, setData, post, processing, errors, clearErrors, reset, transform } = useForm<{
        first_name: string;
        middle_initial: string;
        last_name: string;
        birthday: string;
        sex: string;
        company_name: string;
        company_code: string;
        email: string;
        phone: string;
        address: string;
        city: string;
        province: string;
        municipality: string;
        barangay: string;
        postal_code: string;
        franchise_number: string;
        fleet_size: string;
        vehicle_types: string[];
        role: 'logistics';
        password: string;
        password_confirmation: string;
        business_permit: File | null;
        franchise_document: File | null;
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
        company_name: '',
        company_code: '',
        email: '',
        phone: '',
        address: '',
        city: '',
        province: 'Laguna',
        municipality: '',
        barangay: '',
        postal_code: '',
        franchise_number: '',
        fleet_size: '25',
        vehicle_types: ['motorcycle', 'l300_van', 'wing_truck'],
        role: 'logistics',
        password: '',
        password_confirmation: '',
        business_permit: null,
        franchise_document: null,
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
    const emailAvailability = useRegistrationEmailAvailability(data.email, currentStep === 1 && !processing && !validating && !showOtpModal);

    useEffect(() => {
        if (data.otp_token) setData('otp_token', '');
        transform(values => values);
    }, [data.email]);

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

    const handleFranchiseChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            const error = documentError(file);
            if (error) {
                removeFranchiseFile();
                setStepErrors(previous => ({ ...previous, franchise_document: error }));
                return;
            }
            clearErrors('franchise_document');
            setStepErrors(previous => ({ ...previous, franchise_document: '' }));
            setData('franchise_document', file);
            setFranchiseFileName(file.name);
            setFranchiseFileSize((file.size / (1024 * 1024)).toFixed(2) + ' MB');
            setStepErrors(prev => {
                const next = { ...prev };
                delete next.franchise_document;
                return next;
            });
        }
    };

    const removeFranchiseFile = () => {
        setData('franchise_document', null);
        setFranchiseFileName(null);
        setFranchiseFileSize(null);
        if (franchiseInputRef.current) franchiseInputRef.current.value = '';
    };

    const toggleVehicleType = (type: string) => {
        if (data.vehicle_types.includes(type)) {
            if (data.vehicle_types.length > 1) {
                setData('vehicle_types', data.vehicle_types.filter(t => t !== type));
            }
        } else {
            setData('vehicle_types', [...data.vehicle_types, type]);
        }
    };

    const validateForm = async (step?: number) => {
        if ((step === 2 || step === undefined) && data.vehicle_types.length === 0) {
            applyErrors({ vehicle_types: 'Choose at least one vehicle category.' });
            return false;
        }
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
        <GuestLayout guestOnly
            formPosition="right"
            imageSrc="/images/auth/hub_register.jpg?v=20260919"
            imageAlt="BagooPH Logistics Sorting Hub Visual"
            imageBadge="Logistics Hub"
            imageHeadline="Register Your Logistics Hub"
            imageDescription="Accredit your transport fleet, Mother Hub, and Bayan Hub network into BagooPH. Orchestrate regional line-hauls, local counter collection, and automated rider dispatch."
            title="Register as Logistics Partner"
            subtitle="Register your logistics company to manage sorting hubs and fleets on BagooPH"
            alternatePortal={{
                label: 'Logistics Hub Sign In',
                subtext: 'Already registered?',
                href: getDomainUrl('hub', '/login'),
                buttonText: 'Hub Sign In →',
            }}
        >
            <Head title="Logistics Partner Registration — BagooPH" />

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
                            currentStep === 1 ? 'text-slate-900 font-bold' : 'text-slate-400'
                        }`}>
                            Entity & Officer
                        </span>
                    </div>

                    <div className="flex-1 h-0.5 mx-2 bg-slate-200">
                        <div className={`h-full bg-emerald-600 transition-all duration-300 ${
                            currentStep > 1 ? 'w-full' : 'w-0'
                        }`} />
                    </div>

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
                            currentStep === 2 ? 'text-slate-900 font-bold' : 'text-slate-400'
                        }`}>
                            Accreditation & Fleet
                        </span>
                    </div>

                    <div className="flex-1 h-0.5 mx-2 bg-slate-200">
                        <div className={`h-full bg-emerald-600 transition-all duration-300 ${
                            currentStep > 2 ? 'w-full' : 'w-0'
                        }`} />
                    </div>

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
                            currentStep === 3 ? 'text-slate-900 font-bold' : 'text-slate-400'
                        }`}>
                            Depot & Compliance
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
                {/* STEP 1: Corporate Entity & Representative */}
                {currentStep === 1 && (
                    <div className="space-y-3.5 animate-in fade-in duration-200">
                        <div className="p-3 bg-amber-50/70 border border-amber-200 rounded-xl text-amber-900 text-xs">
                            <div className="flex items-center gap-2 font-bold mb-1">
                                <Building2 className="w-4 h-4 text-amber-700" />
                                <span>Corporate & Dispatch Accreditation</span>
                            </div>
                            <p className="text-[11px] text-amber-800 leading-relaxed font-sans">
                                Register your transportation company or sortation hub facility. Your dispatch code will identify all manifests, line-haul waybills, and delivery checkpoints.
                            </p>
                        </div>

                        {/* Company Name & Code */}
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div className="sm:col-span-2 space-y-1">
                                <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 font-sans">
                                    Company / Fleet Name <span className="text-[#E00D42]">*</span>
                                </label>
                                <div className="relative">
                                    <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                        <Building2 className="w-4 h-4" />
                                    </div>
                                    <input name="company_name"
                                        type="text"
                                        minLength={2} maxLength={255}
                                        value={data.company_name}
                                        onChange={e => setData('company_name', e.target.value)}
                                        placeholder="e.g. Tamaraw Freight Express"
                                        className="w-full pl-9 pr-3 py-2 text-sm bg-white border border-slate-200 rounded-lg focus:ring-1 focus:ring-[#E00D42] focus:border-[#E00D42] transition"
                                    />
                                </div>
                                {(stepErrors.company_name || errors.company_name) && (
                                    <p className="text-xs text-[#E00D42]">{stepErrors.company_name || errors.company_name}</p>
                                )}
                            </div>

                            <div className="space-y-1">
                                <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 font-sans">
                                    Company Initials *
                                </label>
                                <div className="relative">
                                    <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                        <Hash className="w-4 h-4" />
                                    </div>
                                    <input name="company_code"
                                        type="text"
                                        minLength={2} maxLength={10}
                                        value={data.company_code}
                                        onChange={e => setData('company_code', uppercaseOperationalCode(e.target.value))}
                                        placeholder="TFX"
                                        className="w-full pl-9 pr-3 py-2 text-sm font-sans uppercase bg-white border border-slate-200 rounded-lg focus:ring-1 focus:ring-[#E00D42] focus:border-[#E00D42] transition"
                                    />
                                </div>
                                {(stepErrors.company_code || errors.company_code) && (
                                    <p className="text-xs text-[#E00D42]">{stepErrors.company_code || errors.company_code}</p>
                                )}
                            </div>
                        </div>

                        <PersonalNameFields values={data} errors={{ ...errors, ...stepErrors }} context="Authorised representative" onChange={(field, value) => {
                            setData(field, value);
                            clearErrors(field, 'name' as keyof typeof data);
                            setStepErrors(previous => { const next = { ...previous }; delete next[field]; delete next.name; delete next.request; return next; });
                        }} />
                        <RegistrationSexField value={data.sex} onChange={value => setData('sex', value)} error={stepErrors.sex || errors.sex} />

                        <BirthDateInput value={data.birthday} maximum={birthDateLimits.adult_maximum} onChange={value => { setData('birthday', value); setStepErrors(prev => ({ ...prev, birthday: '' })); }} error={stepErrors.birthday || errors.birthday} />

                        {/* Email & Phone */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 font-sans">
                                    Corporate Email <span className="text-[#E00D42]">*</span>
                                </label>
                                <div className="relative">
                                    <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                        <Mail className="w-4 h-4" />
                                    </div>
                                    <input
                                        id="email"
                                        onBlur={emailAvailability.checkNow}
                                        aria-describedby="registration-email-feedback"
                                        aria-invalid={Boolean(stepErrors.email || errors.email) || emailAvailability.invalid}
                                        name="email"
                                        type="email"
                                        maxLength={255}
                                        autoCapitalize="none"
                                        spellCheck={false}
                                        autoComplete="username"
                                        required
                                        value={data.email}
                                        onChange={e => {
                                            setData('email', normalizeRegistrationEmail(e.target.value));
                                            clearErrors('email');
                                            setStepErrors(previous => ({ ...previous, email: '' }));
                                        }}
                                        placeholder="dispatch@tamarawexpress.ph"
                                        className="w-full pl-9 pr-3 py-2 text-sm bg-white border border-slate-200 rounded-lg focus:ring-1 focus:ring-[#E00D42] focus:border-[#E00D42] transition"
                                    />
                                </div>
                                <RegistrationEmailFeedback state={emailAvailability.state} error={stepErrors.email || errors.email} onRetry={emailAvailability.checkNow} />
                            </div>

                            <div className="space-y-1">
                                <PhoneInput
                                    value={data.phone}
                                    onChange={val => setData('phone', val)}
                                    label="Account mobile number"
                                    required
                                    error={stepErrors.phone || errors.phone}
                                />
                            </div>
                        </div>

                        {/* Password & Confirmation */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 font-sans">
                                    Account Password <span className="text-[#E00D42]">*</span>
                                </label>
                                <div className="relative">
                                    <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                        <Lock className="w-4 h-4" />
                                    </div>
                                    <input name="password"
                                        type={showPassword ? 'text' : 'password'}
                                        minLength={8} maxLength={128}
                                        value={data.password}
                                        onChange={e => setData('password', e.target.value)}
                                        placeholder="8–128 characters"
                                        className="w-full pl-9 pr-9 py-2 text-sm bg-white border border-slate-200 rounded-lg focus:ring-1 focus:ring-[#E00D42] focus:border-[#E00D42] transition"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPassword(!showPassword)}
                                        className="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600"
                                    >
                                        {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                                    </button>
                                </div>
                                <PasswordRequirements />
                                {(stepErrors.password || errors.password) && (
                                    <p className="text-xs text-[#E00D42]">{stepErrors.password || errors.password}</p>
                                )}
                            </div>

                            <div className="space-y-1">
                                <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 font-sans">
                                    Confirm Password <span className="text-[#E00D42]">*</span>
                                </label>
                                <div className="relative">
                                    <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                        <Lock className="w-4 h-4" />
                                    </div>
                                    <input name="password_confirmation"
                                        type={showConfirmPassword ? 'text' : 'password'}
                                        minLength={8} maxLength={128}
                                        value={data.password_confirmation}
                                        onChange={e => setData('password_confirmation', e.target.value)}
                                        placeholder="Re-enter password"
                                        className="w-full pl-9 pr-9 py-2 text-sm bg-white border border-slate-200 rounded-lg focus:ring-1 focus:ring-[#E00D42] focus:border-[#E00D42] transition"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                                        className="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600"
                                    >
                                        {showConfirmPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                                    </button>
                                </div>
                                {(stepErrors.password_confirmation || errors.password_confirmation) && (
                                    <p className="text-xs text-[#E00D42]">{stepErrors.password_confirmation || errors.password_confirmation}</p>
                                )}
                            </div>
                        </div>

                        <div className="pt-2">
                            <button
                                type="button"
                                onClick={handleNext}
                                className="w-full flex items-center justify-center gap-2 py-2.5 px-4 rounded-xl bg-slate-900 hover:bg-black text-white text-xs font-bold uppercase tracking-wider transition shadow-md cursor-pointer"
                            >
                                <span>Continue to Fleet Accreditation</span>
                                <ArrowRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}

                {/* STEP 2: Accreditation & Fleet Hierarchy */}
                {currentStep === 2 && (
                    <div className="space-y-3.5 animate-in fade-in duration-200">
                        <div className="p-3 bg-blue-50/70 border border-blue-200 rounded-xl text-blue-900 text-xs">
                            <div className="flex items-center gap-2 font-bold mb-1">
                                <BadgeCheck className="w-4 h-4 text-blue-700" />
                                <span>Regulatory Accreditation & Fleet Profile</span>
                            </div>
                            <p className="text-[11px] text-blue-800 leading-relaxed font-sans">
                                Declare your active LTFRB Certificate of Public Convenience (CPC) or franchise details, primary dispatch province, and vehicle capabilities.
                            </p>
                        </div>

                        {/* LTFRB Franchise # */}
                        <div className="space-y-1">
                            <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 font-sans">
                                LTFRB Franchise / CPC Accreditation Number (optional)
                            </label>
                            <div className="relative">
                                <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                    <FileCheck2 className="w-4 h-4" />
                                </div>
                                <input name="franchise_number"
                                    type="text"
                                    minLength={2} maxLength={100}
                                        value={data.franchise_number}
                                    onChange={e => setData('franchise_number', uppercaseOperationalCode(e.target.value))}
                                    placeholder="e.g. LTFRB-2026-TFX-4412"
                                    className="w-full pl-9 pr-3 py-2 text-sm font-sans bg-white border border-slate-200 rounded-lg focus:ring-1 focus:ring-[#E00D42] focus:border-[#E00D42] transition"
                                />
                            </div>
                            {(stepErrors.franchise_number || errors.franchise_number) && (
                                <p className="text-xs text-[#E00D42]">{stepErrors.franchise_number || errors.franchise_number}</p>
                            )}
                        </div>

                        {/* Fleet Size & Primary Operating Province */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 font-sans">
                                    Declared Active Fleet Units
                                </label>
                                <div className="relative">
                                    <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                        <Truck className="w-4 h-4" />
                                    </div>
                                    <input name="fleet_size"
                                        type="text" inputMode="numeric" maxLength={5}
                                        min={1}
                                        max={10000}
                                        value={data.fleet_size}
                                        onChange={e => setData('fleet_size', e.target.value)}
                                        className="w-full pl-9 pr-3 py-2 text-sm font-sans bg-white border border-slate-200 rounded-lg focus:ring-1 focus:ring-[#E00D42] focus:border-[#E00D42] transition"
                                    />
                                </div>
                                {(stepErrors.fleet_size || errors.fleet_size) && <p className="text-xs text-[#E00D42]">{stepErrors.fleet_size || errors.fleet_size}</p>}
                            </div>

                            <div className="space-y-1">
                                <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 font-sans">
                                    Primary Operating Region
                                </label>
                                <div className="relative">
                                    <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                        <Compass className="w-4 h-4" />
                                    </div>
                                    <select
                                        value={data.province}
                                        onChange={e => setData('province', e.target.value)}
                                        className="w-full pl-9 pr-3 py-2 text-sm bg-white border border-slate-200 rounded-lg focus:ring-1 focus:ring-[#E00D42] focus:border-[#E00D42] transition"
                                    >
                                        <option value="Laguna">Laguna (CALABARZON Corridor)</option>
                                        <option value="Metro Manila">Metro Manila (NCR Sortation)</option>
                                        <option value="Batangas">Batangas (Southern Gateway)</option>
                                        <option value="Cavite">Cavite (Mid-Luzon Express)</option>
                                        <option value="Rizal">Rizal (East Freight Sector)</option>
                                        <option value="Pampanga">Pampanga (North Luzon Hub)</option>
                                        <option value="Cebu">Cebu (Visayas Central Hub)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        {/* Vehicle Categories Checkboxes */}
                        <div className="space-y-2">
                            <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 font-sans">
                                Operating Vehicle Tiers <span className="text-[#E00D42]">*</span>
                            </label>
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                                <button
                                    type="button"
                                    onClick={() => toggleVehicleType('motorcycle')}
                                    className={`p-2.5 rounded-xl border text-left transition flex items-center gap-2.5 cursor-pointer ${
                                        data.vehicle_types.includes('motorcycle')
                                            ? 'bg-slate-900 border-slate-900 text-white shadow-xs'
                                            : 'bg-white border-slate-200 text-slate-700 hover:border-slate-300'
                                    }`}
                                >
                                    <div className={`w-6 h-6 rounded-lg flex items-center justify-center shrink-0 ${
                                        data.vehicle_types.includes('motorcycle') ? 'bg-white/20' : 'bg-slate-100'
                                    }`}>
                                        <Check className={`w-3.5 h-3.5 ${data.vehicle_types.includes('motorcycle') ? 'text-white' : 'text-transparent'}`} />
                                    </div>
                                    <div className="min-w-0">
                                        <p className="text-xs font-bold leading-tight">Tier 1: Last-Mile</p>
                                        <p className="text-[10px] opacity-75 font-sans">Motorcycle</p>
                                    </div>
                                </button>

                                <button
                                    type="button"
                                    onClick={() => toggleVehicleType('l300_van')}
                                    className={`p-2.5 rounded-xl border text-left transition flex items-center gap-2.5 cursor-pointer ${
                                        data.vehicle_types.includes('l300_van')
                                            ? 'bg-slate-900 border-slate-900 text-white shadow-xs'
                                            : 'bg-white border-slate-200 text-slate-700 hover:border-slate-300'
                                    }`}
                                >
                                    <div className={`w-6 h-6 rounded-lg flex items-center justify-center shrink-0 ${
                                        data.vehicle_types.includes('l300_van') ? 'bg-white/20' : 'bg-slate-100'
                                    }`}>
                                        <Check className={`w-3.5 h-3.5 ${data.vehicle_types.includes('l300_van') ? 'text-white' : 'text-transparent'}`} />
                                    </div>
                                    <div className="min-w-0">
                                        <p className="text-xs font-bold leading-tight">Tier 2: Feeder</p>
                                        <p className="text-[10px] opacity-75 font-sans">L300 / Urvan</p>
                                    </div>
                                </button>

                                <button
                                    type="button"
                                    onClick={() => toggleVehicleType('wing_truck')}
                                    className={`p-2.5 rounded-xl border text-left transition flex items-center gap-2.5 cursor-pointer ${
                                        data.vehicle_types.includes('wing_truck')
                                            ? 'bg-slate-900 border-slate-900 text-white shadow-xs'
                                            : 'bg-white border-slate-200 text-slate-700 hover:border-slate-300'
                                    }`}
                                >
                                    <div className={`w-6 h-6 rounded-lg flex items-center justify-center shrink-0 ${
                                        data.vehicle_types.includes('wing_truck') ? 'bg-white/20' : 'bg-slate-100'
                                    }`}>
                                        <Check className={`w-3.5 h-3.5 ${data.vehicle_types.includes('wing_truck') ? 'text-white' : 'text-transparent'}`} />
                                    </div>
                                    <div className="min-w-0">
                                        <p className="text-xs font-bold leading-tight">Tier 3: Line-Haul</p>
                                        <p className="text-[10px] opacity-75 font-sans">10W Wing Van</p>
                                    </div>
                                </button>
                            </div>
                            <InputError message={stepErrors.vehicle_types || errors.vehicle_types || Object.entries(errors).find(([key]) => key.startsWith('vehicle_types.'))?.[1]} />
                        </div>

                        {/* Navigation Buttons */}
                        <div className="flex items-center gap-3 pt-2">
                            <button
                                type="button"
                                onClick={handlePrev}
                                className="flex items-center justify-center gap-1.5 py-2.5 px-4 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold uppercase tracking-wider transition cursor-pointer"
                            >
                                <ArrowLeft className="w-4 h-4" />
                                <span>Back</span>
                            </button>
                            <button
                                type="button"
                                onClick={handleNext}
                                className="flex-1 flex items-center justify-center gap-2 py-2.5 px-4 rounded-xl bg-slate-900 hover:bg-black text-white text-xs font-bold uppercase tracking-wider transition shadow-md cursor-pointer"
                            >
                                <span>Depot & Compliance Docs</span>
                                <ArrowRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}

                {/* STEP 3: Depot Address & Compliance Documents */}
                {currentStep === 3 && (
                    <div className="space-y-3.5 animate-in fade-in duration-200">
                        <div className="p-3 bg-emerald-50/70 border border-emerald-200 rounded-xl text-emerald-900 text-xs">
                            <div className="flex items-center gap-2 font-bold mb-1">
                                <Shield className="w-4 h-4 text-emerald-700" />
                                <span>Central Depot Location & KYC Verification</span>
                            </div>
                            <p className="text-[11px] text-emerald-800 leading-relaxed font-sans">
                                Upload your legal registration documents. Once approved by the Bagoo Compliance Team, your dispatch dashboard and sorting stations will be unlocked immediately.
                            </p>
                        </div>

                        {/* Philippine Address Hierarchy & Street */}
                        <PhilippineAddressSelector
                            postalCodeField={<RegistrationPostalCodeField value={data.postal_code} onChange={value => setData('postal_code', value)} error={stepErrors.postal_code || errors.postal_code} />}
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
                                    city: addr.city || addr.municipality,
                                    municipality: addr.municipality,
                                    barangay: addr.barangay,
                                    address: addr.address,
                                }));
                            }}
                            errors={{
                                province: stepErrors.province || errors.province,
                                municipality: stepErrors.municipality || errors.municipality,
                                barangay: stepErrors.barangay || errors.barangay,
                                city: stepErrors.city || errors.city,
                                address: stepErrors.address || errors.address,
                            }}
                            showStreetAddress={true}
                            streetLabel="Central Depot / Headquarters Street Address"
                            streetPlaceholder="e.g. KM 54 National Highway, Real"
                        />

                        {/* Document Upload 1: DTI / SEC Business Permit */}
                        <div className="space-y-1">
                            <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 font-sans">
                                DTI / SEC Registration or Mayor's Business Permit <span className="text-[#E00D42]">*</span>
                            </label>
                            <div className="border border-dashed border-slate-200 rounded-xl p-3 bg-slate-50/50 hover:bg-slate-50 transition">
                                <input
                                    type="file"
                                    ref={permitInputRef}
                                    onChange={handlePermitChange}
                                    accept=".jpg,.jpeg,.png,.pdf,.webp"
                                    className="hidden"
                                />
                                <UploadHint />
                                {permitFileName ? (
                                    <div className="flex items-center justify-between p-2 rounded-lg bg-white border border-slate-200">
                                        <div className="flex items-center gap-2 min-w-0">
                                            <FileText className="w-4 h-4 text-emerald-600 shrink-0" />
                                            <div className="min-w-0">
                                                <p className="text-xs font-semibold text-slate-800 truncate">{permitFileName}</p>
                                                <p className="text-[10px] text-slate-400 font-sans">{permitFileSize}</p>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={removePermitFile}
                                            className="p-1 text-slate-400 hover:text-[#E00D42] transition cursor-pointer"
                                        >
                                            <X className="w-4 h-4" />
                                        </button>
                                    </div>
                                ) : (
                                    <div
                                        onClick={() => permitInputRef.current?.click()}
                                        className="text-center py-2 cursor-pointer"
                                    >
                                        <Upload className="w-5 h-5 mx-auto text-slate-400 mb-1" />
                                        <p className="text-xs font-semibold text-slate-700">Click to upload permit document</p>
                                        <p className="text-[10px] text-slate-400 font-sans mt-0.5">PDF, PNG, or JPG (max 5MB)</p>
                                    </div>
                                )}
                            </div>
                            {(stepErrors.business_permit || errors.business_permit) && (
                                <p className="text-xs text-[#E00D42]">{stepErrors.business_permit || errors.business_permit}</p>
                            )}
                        </div>

                        {/* Document Upload 2: LTFRB Franchise Document (Optional / Recommended) */}
                        <div className="space-y-1">
                            <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 font-sans">
                                LTFRB Franchise / CPC Certification Certificate (Optional)
                            </label>
                            <div className="border border-dashed border-slate-200 rounded-xl p-3 bg-slate-50/50 hover:bg-slate-50 transition">
                                <input
                                    type="file"
                                    ref={franchiseInputRef}
                                    onChange={handleFranchiseChange}
                                    accept=".jpg,.jpeg,.png,.pdf,.webp"
                                    className="hidden"
                                />
                                <UploadHint />
                                {franchiseFileName ? (
                                    <div className="flex items-center justify-between p-2 rounded-lg bg-white border border-slate-200">
                                        <div className="flex items-center gap-2 min-w-0">
                                            <FileText className="w-4 h-4 text-emerald-600 shrink-0" />
                                            <div className="min-w-0">
                                                <p className="text-xs font-semibold text-slate-800 truncate">{franchiseFileName}</p>
                                                <p className="text-[10px] text-slate-400 font-sans">{franchiseFileSize}</p>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={removeFranchiseFile}
                                            className="p-1 text-slate-400 hover:text-[#E00D42] transition cursor-pointer"
                                        >
                                            <X className="w-4 h-4" />
                                        </button>
                                    </div>
                                ) : (
                                    <div
                                        onClick={() => franchiseInputRef.current?.click()}
                                        className="text-center py-2 cursor-pointer"
                                    >
                                        <Upload className="w-5 h-5 mx-auto text-slate-400 mb-1" />
                                        <p className="text-xs font-semibold text-slate-700">Click to upload LTFRB Certificate</p>
                                        <p className="text-[10px] text-slate-400 font-sans mt-0.5">PDF, PNG, or JPG (max 5MB)</p>
                                    </div>
                                )}
                            </div>
                        </div>

                        <InputError message={stepErrors.franchise_document || errors.franchise_document} />

                        <RegistrationPolicyAcknowledgement policies={policies} accepted={data.policy_accepted}
                            onChange={accepted => { setData('policy_accepted', accepted); clearErrors('policy_accepted'); setStepErrors(prev => ({ ...prev, policy_accepted: '' })); }}
                            error={stepErrors.policy_accepted || errors.policy_accepted || errors.terms_version || errors.privacy_version} />

                        {/* Navigation & Submit */}
                        <div className="flex items-center gap-3 pt-2">
                            <button
                                type="button"
                                onClick={handlePrev}
                                className="flex items-center justify-center gap-1.5 py-2.5 px-4 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold uppercase tracking-wider transition cursor-pointer"
                            >
                                <ArrowLeft className="w-4 h-4" />
                                <span>Back</span>
                            </button>
                            <button
                                type="submit"
                                disabled={processing || validating}
                                className="flex-1 flex items-center justify-center gap-2 py-2.5 px-4 rounded-xl bg-[#E00D42] hover:bg-[#b00a34] text-white text-xs font-bold uppercase tracking-wider transition shadow-md disabled:opacity-50 cursor-pointer"
                            >
                                <span>Submit for Accreditation Review</span>
                                <ArrowRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}
            </fieldset>
            </form>

            {/* Email OTP Verification Modal */}
            <OtpModal
                isOpen={showOtpModal}
                onClose={() => setShowOtpModal(false)}
                email={data.email}
                onSuccess={(token: string) => handleOtpSuccess(token)}
                purpose="registration"
            />
        </GuestLayout>
    );
}
