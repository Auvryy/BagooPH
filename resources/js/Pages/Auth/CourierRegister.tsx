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
import OtpModal from '@/Components/OtpModal';
import { useRegistrationValidation } from '@/hooks/useRegistrationValidation';
import { useRegistrationEmailAvailability } from '@/hooks/useRegistrationEmailAvailability';
import RegistrationEmailFeedback from '@/Components/RegistrationEmailFeedback';
import { normalizeRegistrationEmail } from '@/utils/authRequests';
import BirthDateInput, { BirthDateLimits } from '@/Components/BirthDateInput';

export default function CourierRegister({ birthDateLimits, policies }: { birthDateLimits: BirthDateLimits; policies: RegistrationPolicies }) {
    const [currentStep, setCurrentStep] = useState(1);
    const [stepErrors, setStepErrors] = useState<Record<string, string>>({});
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);
    const [showOtpModal, setShowOtpModal] = useState(false);

    const idInputRef = useRef<HTMLInputElement>(null);
    const licenseInputRef = useRef<HTMLInputElement>(null);
    const orCrInputRef = useRef<HTMLInputElement>(null);

    const [idFileName, setIdFileName] = useState<string | null>(null);
    const [idFileSize, setIdFileSize] = useState<string | null>(null);

    const [licenseFileName, setLicenseFileName] = useState<string | null>(null);
    const [licenseFileSize, setLicenseFileSize] = useState<string | null>(null);

    const [orCrFileName, setOrCrFileName] = useState<string | null>(null);
    const [orCrFileSize, setOrCrFileSize] = useState<string | null>(null);

    const { data, setData, post, processing, errors, clearErrors, reset, transform } = useForm<{
        first_name: string;
        middle_initial: string;
        last_name: string;
        birthday: string;
        sex: string;
        email: string;
        phone: string;
        address: string;
        postal_code: string;
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
        email: '',
        phone: '',
        address: '',
        postal_code: '',
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

    const handleLicenseChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            const error = documentError(file);
            if (error) {
                removeLicenseFile();
                setStepErrors(previous => ({ ...previous, driver_license: error }));
                return;
            }
            clearErrors('driver_license');
            setStepErrors(previous => ({ ...previous, driver_license: '' }));
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
            const error = documentError(file);
            if (error) {
                removeOrCrFile();
                setStepErrors(previous => ({ ...previous, or_cr_document: error }));
                return;
            }
            clearErrors('or_cr_document');
            setStepErrors(previous => ({ ...previous, or_cr_document: '' }));
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

    const validateForm = async (step?: number) => {
        if ((step === 2 || step === undefined) && !data.license_number.trim()) {
            applyErrors({ license_number: 'Driver licence number is required.' });
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
                <div className="flex items-center justify-between font-sans">
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

            <form onPasteCapture={event => rejectOverlongRegistrationPaste(event, (field, message) => setStepErrors(previous => ({ ...previous, [field]: message })))} onChange={fieldFeedback} onSubmit={submit} className="space-y-4" noValidate aria-busy={processing || validating}>
                {Object.keys(stepErrors).length > 0 && (
                    <div ref={feedbackRef} role="alert" tabIndex={-1} className="mb-3 rounded-lg focus:outline-none focus:ring-2 focus:ring-[#E00D42]/20">
                        <InputError message={stepErrors.request ?? Object.values(stepErrors)[0]} />
                    </div>
                )}
                <fieldset disabled={processing || validating || showOtpModal} className="contents">
                {/* STEP 1: RIDER INFO */}
                {currentStep === 1 && (
                    <div className="space-y-4">
                        <PersonalNameFields values={data} errors={{ ...errors, ...stepErrors }} accent="emerald" autoFocus onChange={(field, value) => {
                            setData(field, value);
                            clearErrors(field, 'name' as keyof typeof data);
                            setStepErrors(previous => { const next = { ...previous }; delete next[field]; delete next.name; delete next.request; return next; });
                        }} />
                        <RegistrationSexField value={data.sex} onChange={value => setData('sex', value)} error={stepErrors.sex || errors.sex} courier />

                        <BirthDateInput value={data.birthday} maximum={birthDateLimits.adult_maximum} onChange={value => { setData('birthday', value); setStepErrors(prev => ({ ...prev, birthday: '' })); }} error={stepErrors.birthday || errors.birthday} />

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-sans">
                                    Email Address *
                                </label>
                                <div className="relative">
                                    <Mail className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                                    <input
                                        id="email"
                                        onBlur={emailAvailability.checkNow}
                                        aria-describedby="registration-email-feedback"
                                        aria-invalid={Boolean(stepErrors.email || errors.email) || emailAvailability.invalid}
                                        type="email"
                                        maxLength={255}
                                        autoCapitalize="none"
                                        spellCheck={false}
                                        name="email"
                                        value={data.email}
                                        className="w-full pl-10 pr-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-hidden transition text-slate-900 placeholder-slate-400"
                                        placeholder="rider@domain.com"
                                        autoComplete="username"
                                        onChange={(e) => {
                                            setData('email', normalizeRegistrationEmail(e.target.value));
                                            clearErrors('email');
                                            if (stepErrors.email) setStepErrors(prev => ({ ...prev, email: '' }));
                                        }}
                                        required
                                    />
                                </div>
                                <RegistrationEmailFeedback state={emailAvailability.state} error={stepErrors.email || errors.email} onRetry={emailAvailability.checkNow} />
                            </div>

                            <PhoneInput
                                id="phone"
                                name="phone"
                                label="Mobile Phone Number"
                                value={data.phone}
                                onChange={(val) => {
                                    setData('phone', val);
                                    clearErrors('phone');
                                    if (stepErrors.phone) setStepErrors(prev => ({ ...prev, phone: '' }));
                                }}
                                required
                                accentColor="emerald"
                                helperText="10-digit mobile number (e.g. 917 123 4567)"
                                error={stepErrors.phone || errors.phone}
                            />
                        </div>

                        <PhilippineAddressSelector
                            postalCodeField={<RegistrationPostalCodeField value={data.postal_code} onChange={value => setData('postal_code', value)} error={stepErrors.postal_code || errors.postal_code} courier />}
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
                                province: stepErrors.province || errors.province,
                                municipality: stepErrors.municipality || errors.municipality,
                                barangay: stepErrors.barangay || errors.barangay,
                                city: stepErrors.city || errors.city,
                                address: stepErrors.address || errors.address,
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
                            <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-sans">
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
                            <InputError message={stepErrors.vehicle_type || errors.vehicle_type} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 items-start">
                            <div>
                                <div className="h-5 flex items-center mb-1">
                                    <label htmlFor="plate_number" className="text-xs font-semibold text-slate-800 uppercase tracking-wider font-sans truncate">
                                        Plate / MV File No. <span className="text-emerald-700">*</span>
                                    </label>
                                </div>
                                <div className="relative">
                                    <Hash className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                                    <input
                                        id="plate_number"
                                        type="text"
                                        name="plate_number"
                                        minLength={5} maxLength={8}
                                        value={data.plate_number}
                                        className="w-full pl-10 pr-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-hidden transition text-slate-900 placeholder-slate-400 uppercase font-sans"
                                        placeholder="e.g. 123-ABC"
                                        onChange={(e) => {
                                            setData('plate_number', uppercaseOperationalCode(e.target.value));
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
                                    <label htmlFor="license_number" className="text-xs font-semibold text-slate-800 uppercase tracking-wider font-sans truncate">
                                        Driver's License No. <span className="text-emerald-700">*</span>
                                    </label>
                                </div>
                                <div className="relative">
                                    <FileText className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                                    <input
                                        id="license_number"
                                        type="text"
                                        name="license_number"
                                        minLength={11} maxLength={13}
                                        value={data.license_number}
                                        className="w-full pl-10 pr-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-hidden transition text-slate-900 placeholder-slate-400 uppercase font-sans"
                                        placeholder="e.g. N01-12-345678"
                                        onChange={(e) => {
                                            setData('license_number', uppercaseOperationalCode(e.target.value));
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
                            <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-sans">
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
                            <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-sans">
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
                            <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-sans">
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
                                <InputError message={stepErrors.password_confirmation || errors.password_confirmation} className="mt-1" />
                            </div>
                        </div>

                        <RegistrationPolicyAcknowledgement policies={policies} accepted={data.policy_accepted}
                            onChange={accepted => { setData('policy_accepted', accepted); clearErrors('policy_accepted'); setStepErrors(prev => ({ ...prev, policy_accepted: '' })); }}
                            error={stepErrors.policy_accepted || errors.policy_accepted || errors.terms_version || errors.privacy_version} courier />

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
