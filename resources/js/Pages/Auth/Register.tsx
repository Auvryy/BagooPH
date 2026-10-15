import UploadHint from '@/Components/UploadHint';
import { RegistrationSexField, RegistrationPostalCodeField } from '@/Components/RegistrationDemographics';
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
import PhoneInput from '@/Components/PhoneInput';
import PhilippineAddressSelector from '@/Components/PhilippineAddressSelector';
import OtpModal from '@/Components/OtpModal';
import { useRegistrationValidation } from '@/hooks/useRegistrationValidation';
import { useRegistrationEmailAvailability } from '@/hooks/useRegistrationEmailAvailability';
import RegistrationEmailFeedback from '@/Components/RegistrationEmailFeedback';
import { normalizeRegistrationEmail } from '@/utils/authRequests';
import { BirthDateLimits } from '@/Components/BirthDateInput';

export default function Register({ birthDateLimits, policies }: { birthDateLimits: BirthDateLimits; policies: RegistrationPolicies }) {
    const [currentStep, setCurrentStep] = useState(1);
    const [stepErrors, setStepErrors] = useState<Record<string, string>>({});
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);
    const [showOtpModal, setShowOtpModal] = useState(false);

    const fileInputRef = useRef<HTMLInputElement>(null);
    const [fileName, setFileName] = useState<string | null>(null);
    const [fileSize, setFileSize] = useState<string | null>(null);

    const { data, setData, post, processing, errors, clearErrors, reset, transform } = useForm<{
        first_name: string;
        middle_initial: string;
        last_name: string;
        email: string;
        password: string;
        password_confirmation: string;
        birthday: string;
        age: string | number;
        sex: string;
        phone: string;
        province: string;
        city: string;
        municipality: string;
        barangay: string;
        address: string;
        postal_code: string;
        role: 'buyer';
        id_document: File | null;
        otp_token: string;
        policy_accepted: boolean;
        terms_version: string;
        privacy_version: string;
    }>({
        first_name: '',
        middle_initial: '',
        last_name: '',
        email: '',
        password: '',
        password_confirmation: '',
        birthday: '',
        age: '',
        sex: '',
        phone: '',
        province: 'Metro Manila',
        city: '',
        municipality: '',
        barangay: '',
        address: '',
        postal_code: '',
        role: 'buyer',
        id_document: null,
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

    const calculateAge = (birthDateString: string) => {
        if (!birthDateString) return '';
        const [year, month, day] = birthDateString.split('-').map(Number);
        const [todayYear, todayMonth, todayDay] = birthDateLimits.today.split('-').map(Number);
        let age = todayYear - year;
        if (todayMonth < month || (todayMonth === month && todayDay < day)) {
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
            const error = documentError(file);
            if (error) {
                removeFile();
                setStepErrors(previous => ({ ...previous, id_document: error }));
                return;
            }
            clearErrors('id_document');
            setStepErrors(previous => ({ ...previous, id_document: '' }));
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

    const validateForm = (step?: number) => validate(step);

    const handleNext = async () => {
        if (processing || validating) return;
        if (await validateForm(currentStep)) {
            setStepErrors({});
            setCurrentStep(step => step + 1);
        }
    };

    const handlePrev = () => {
        setStepErrors({});
        setCurrentStep(1);
    };

    const handleRegistrationErrors = (fieldErrors: Record<string, string>) => {
        applyErrors(fieldErrors);
        if (fieldErrors.otp_token) setData('otp_token', '');
    };

    const createAccount = (token: string) => {
        transform(values => ({ ...values, otp_token: token }));
        if (typeof window !== 'undefined') sessionStorage.removeItem('bagoo_buyer_id_prompt_dismissed');
        post(route('register'), {
            forceFormData: true,
            onError: handleRegistrationErrors,
            onSuccess: () => reset('password', 'password_confirmation'),
            onFinish: () => transform(values => values),
        });
    };

    const submit: FormEventHandler = async (event) => {
        event.preventDefault();
        if (currentStep < 2) { await handleNext(); return; }
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
            imageSrc="/images/auth/buyer_register.jpg"
            imageAlt="BagooPH ID Verification Visual"
            imageBadge="Buyer Verification"
            imageHeadline="Safe, Verified Accounts"
            imageDescription="Create your verified account for 100% cash on delivery inspection, secure checkouts, and priority buyer protection."
            title="Create an Account"
            subtitle="Join BagooPH in two quick steps to start shopping local Filipino brands"
            alternatePortal={{
                label: 'Merchant Portal',
                subtext: 'Want to become a seller?',
                href: getDomainUrl('seller', '/register'),
                buttonText: 'Register Store →',
            }}
        >
            <Head title="Create an Account — BagooPH" />

            {/* Step Checkpoint Progress Header */}
            <div className="flex items-center justify-between font-sans mb-6 pb-2 border-b border-slate-100">
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

            {/* Quick Google Sign Up Option */}
            {currentStep === 1 && (
                <div className="mb-5">
                    <a
                        href={route('auth.google')}
                        onClick={() => {
                            if (typeof window !== 'undefined') {
                                sessionStorage.removeItem('bagoo_buyer_id_prompt_dismissed');
                            }
                        }}
                        className="w-full h-11 flex items-center justify-center gap-3 px-4 rounded-xl border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 font-semibold text-xs shadow-2xs transition active:scale-[0.99] group cursor-pointer"
                    >
                        <svg className="w-4 h-4 shrink-0" viewBox="0 0 24 24">
                            <path
                                fill="#4285F4"
                                d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.66-5.17 3.66-9.17z"
                            />
                            <path
                                fill="#34A853"
                                d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.1-6.72-4.93H1.25v3.15C3.26 21.36 7.33 24 12 24z"
                            />
                            <path
                                fill="#FBBC05"
                                d="M5.28 14.27c-.25-.72-.38-1.49-.38-2.27s.13-1.55.38-2.27V6.58H1.25C.45 8.16 0 9.94 0 12s.45 3.84 1.25 5.42l4.03-3.15z"
                            />
                            <path
                                fill="#EA4335"
                                d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.33 0 3.26 2.64 1.25 6.58l4.03 3.15c.95-2.83 3.6-4.98 6.72-4.98z"
                            />
                        </svg>
                        <span>Sign up with Google</span>
                    </a>

                    <div className="relative flex items-center justify-center my-4">
                        <div className="border-t border-slate-200 w-full"></div>
                        <span className="bg-white px-3 text-[11px] font-sans uppercase tracking-wider text-slate-400 absolute">
                            Or register with email
                        </span>
                    </div>
                </div>
            )}

            <form onPasteCapture={event => rejectOverlongRegistrationPaste(event, (field, message) => setStepErrors(previous => ({ ...previous, [field]: message })))} onChange={fieldFeedback} onSubmit={submit} className="space-y-3.5 font-sans" noValidate aria-busy={processing || validating}>
                {Object.keys(stepErrors).length > 0 && (
                    <div ref={feedbackRef} role="alert" tabIndex={-1} className="mb-3 rounded-lg focus:outline-none focus:ring-2 focus:ring-[#E00D42]/20">
                        <InputError message={stepErrors.request ?? Object.values(stepErrors)[0]} />
                    </div>
                )}
                <fieldset disabled={processing || validating || showOtpModal} className="contents">
                {/* CHECKPOINT 1: CREDENTIALS & NAME */}
                {currentStep === 1 && (
                    <div className="space-y-3.5">
                        <PersonalNameFields values={data} errors={{ ...errors, ...stepErrors }} autoFocus onChange={(field, value) => {
                            setData(field, value);
                            clearErrors(field, 'name' as keyof typeof data);
                            setStepErrors(previous => { const next = { ...previous }; delete next[field]; delete next.name; delete next.request; return next; });
                        }} />
                        <RegistrationSexField value={data.sex} onChange={value => setData('sex', value)} error={stepErrors.sex || errors.sex} />

                        {/* Email Address */}
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
                                    className="w-full pl-10 pr-3.5 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900 placeholder-slate-400"
                                    placeholder="juan.delacruz@example.com"
                                    autoComplete="username"
                                    onChange={(e) => {
                                        setData('email', normalizeRegistrationEmail(e.target.value));
                                        clearErrors('email');
                                        setStepErrors(previous => ({ ...previous, email: '' }));
                                    }}
                                    required
                                />
                            </div>
                            <RegistrationEmailFeedback state={emailAvailability.state} error={stepErrors.email || errors.email} onRetry={emailAvailability.checkNow} />
                        </div>

                        {/* Password Creation */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
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
                                        minLength={8} maxLength={128}
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
                                        minLength={8} maxLength={128}
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
                                <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-sans">
                                    Date of Birth *
                                </label>
                                <div className="relative">
                                    <Calendar className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                    <input
                                        id="birthday"
                                        type="date"
                                        name="birthday"
                                        max={birthDateLimits.adult_maximum}
                                        required
                                        aria-describedby="buyer-birthday-help"
                                        value={data.birthday}
                                        className="w-full pl-9 pr-3 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition text-slate-900"
                                        onChange={handleBirthdayChange}
                                    />
                                </div>
                                <InputError message={stepErrors.birthday || errors.birthday} className="mt-1" />
                                <p id="buyer-birthday-help" className="mt-1 text-xs text-slate-500">Use the birth date on your identity document. You must be at least 18.</p>
                            </div>

                            <div className="sm:col-span-5">
                                <div className="flex items-center justify-between mb-1">
                                    <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider font-sans">
                                        Age
                                    </label>
                                    <span className="text-[10px] text-slate-400 font-sans flex items-center gap-1">
                                        <Sparkles className="w-3 h-3 text-[#E00D42]" />
                                        Auto-calculated
                                    </span>
                                </div>
                                <input
                                    id="age"
                                    type="text"
                                    name="age"
                                    value={data.age !== '' ? `${data.age} years old` : 'Select birthday'}
                                    readOnly
                                    className="w-full px-3 py-2 text-sm bg-slate-50 border border-slate-300 rounded-lg text-slate-700 font-sans font-medium outline-hidden select-none cursor-default"
                                />
                            </div>
                        </div>

                        {/* Sex & Phone */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <PhoneInput
                                    id="phone"
                                    name="phone"
                                    label="Mobile Number"
                                    value={data.phone}
                                    onChange={(val) => {
                                        setData('phone', val);
                                        clearErrors('phone');
                                        if (stepErrors.phone) setStepErrors(prev => ({ ...prev, phone: '' }));
                                    }}
                                    placeholder="917 123 4567"
                                    accentColor="primary"
                                    helperText="10-digit mobile number (e.g. 917 123 4567)"
                                    error={stepErrors.phone || errors.phone}
                                />
                            </div>
                        </div>

                        {/* Province, City, Barangay & Street Address */}
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
                            streetLabel="Delivery Street Address / House No."
                            streetPlaceholder="Unit / Street / Building"
                            errors={{
                                province: stepErrors.province || errors.province,
                                municipality: stepErrors.municipality || errors.municipality,
                                barangay: stepErrors.barangay || errors.barangay,
                                city: stepErrors.city || errors.city,
                                address: stepErrors.address || errors.address,
                            }}
                        />

                        {/* Optional Government ID Document Upload */}
                        <div>
                            <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-sans">
                                Government ID <span className="text-slate-400 lowercase font-normal">(optional for verified badge)</span>
                            </label>
                            <input
                                type="file"
                                ref={fileInputRef}
                                onChange={handleFileChange}
                                accept="image/jpeg,image/png,image/webp,application/pdf"
                                className="hidden"
                            />
                            <UploadHint />
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
                            <InputError message={stepErrors.id_document || errors.id_document} className="mt-1" />
                        </div>

                        {/* Back & Submit Buttons */}
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
                                <span>{processing ? 'Creating Account...' : 'Complete Registration'}</span>
                                <ArrowRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}
            </fieldset>
            </form>

            <div className="mt-6 pt-5 border-t border-slate-200 text-center">
                <p className="text-xs text-slate-600">
                    Already have an account?{' '}
                    <Link 
                        href={route('login')} 
                        className="text-[#E00D42] font-semibold hover:underline"
                    >
                        Log In
                    </Link>
                </p>
            </div>

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
