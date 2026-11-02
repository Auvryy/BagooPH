import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEventHandler } from 'react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import PersonalNameFields from '@/Components/PersonalNameFields';
import BirthDateInput, { BirthDateLimits } from '@/Components/BirthDateInput';
import PhoneInput from '@/Components/PhoneInput';
import PhilippineAddressSelector from '@/Components/PhilippineAddressSelector';
import LocationPinPicker from '@/Components/LocationPinPicker';
import { RegistrationSexField, RegistrationPostalCodeField } from '@/Components/RegistrationDemographics';
import RegistrationPolicyAcknowledgement, { RegistrationPolicies } from '@/Components/RegistrationPolicyAcknowledgement';
import { rejectOverlongRegistrationPaste } from '@/utils/registrationFields';
import { registrationInputErrors } from '@/utils/registrationValidation';

export default function GoogleRegistration({ identity, registrationNonce, policies, birthDateLimits }: {
    identity: { name: string; email: string }; registrationNonce: string; policies: RegistrationPolicies; birthDateLimits: BirthDateLimits;
}) {
    const [addressLoading, setAddressLoading] = useState(false);
    const [feedback, setFeedback] = useState<Record<string, string>>({});
    const { data, setData, post, errors, processing, clearErrors } = useForm({
        registration_nonce: registrationNonce, policy_accepted: false,
        terms_version: policies.terms_version, privacy_version: policies.privacy_version,
        first_name: '', middle_initial: '', last_name: '', birthday: '', phone: '', sex: '', postal_code: '',
        region: '', province: '', city: '', municipality: '', barangay: '', address: '',
        delivery_latitude: null as number | null, delivery_longitude: null as number | null,
    });
    const messages: Record<string, string | undefined> = { ...errors, ...feedback };
    const submit: FormEventHandler = event => {
        event.preventDefault();
        if (addressLoading && [data.region, data.province, data.city, data.barangay, data.address].some(value => value.trim())) {
            setFeedback(previous => ({ ...previous, address: 'Wait for the location lists to load, or use Try again below.' })); return;
        }
        const invalid = registrationInputErrors({ ...data, role: 'buyer' }, birthDateLimits, undefined, true);
        setFeedback(invalid);
        if (Object.keys(invalid).length) return;
        post('/auth/google/register');
    };
    return <GuestLayout guestOnly title="Finish creating your account" subtitle="Confirm your application details and review the account policies.">
        <Head title="Complete registration — BagooPH" />
        <div className="mb-5 rounded-lg border border-slate-300 bg-slate-50 p-3 text-sm">
            <p className="font-semibold text-slate-900">{identity.name}</p>
            <p className="break-all text-slate-600">{identity.email}</p>
            <p className="mt-2 text-xs text-slate-500">A buyer account will be created. Identity review is still required before purchases.</p>
        </div>
        <form onSubmit={submit} className="space-y-4" noValidate aria-busy={processing} onPasteCapture={event => rejectOverlongRegistrationPaste(event, (field, message) => setFeedback(previous => ({ ...previous, [field]: message })))} onChange={event => {
            const input = event.target as HTMLInputElement;
            const key = input.name as keyof typeof data;
            if (!(key in data)) return;
            const value = input.type === 'checkbox' ? input.checked : input.value;
            const error = registrationInputErrors({ ...data, [key]: value, role: 'buyer' }, birthDateLimits, undefined, true)[key];
            clearErrors(key);
            setFeedback(previous => ({ ...previous, [key]: error ?? '' }));
        }}>
            <fieldset disabled={processing} className="space-y-4">
                <PersonalNameFields values={data} onChange={(field, value) => setData(field, value)} errors={messages} context="Name on your identity document" />
                <RegistrationSexField value={data.sex} onChange={value => setData('sex', value)} error={messages.sex} />
                <BirthDateInput value={data.birthday} maximum={birthDateLimits.adult_maximum} onChange={value => setData('birthday', value)} error={messages.birthday} />
                <div className="space-y-1"><label htmlFor="google-phone" className="text-xs font-semibold text-slate-800">Mobile number (optional)</label>
                    <PhoneInput id="google-phone" value={data.phone} onChange={value => setData('phone', value)} error={messages.phone} />
                </div>
                <PhilippineAddressSelector onLoadingChange={setAddressLoading} postalCodeField={<RegistrationPostalCodeField value={data.postal_code} onChange={value => setData(previous => previous.postal_code === value ? previous : { ...previous, postal_code: value, delivery_latitude: null, delivery_longitude: null })} error={messages.postal_code} />} values={data} onChange={address => setData(previous => {
                    const changed = previous.region !== address.region || previous.province !== address.province || previous.city !== address.city || previous.barangay !== address.barangay || previous.address !== address.address;
                    return { ...previous, ...address, delivery_latitude: changed ? null : previous.delivery_latitude, delivery_longitude: changed ? null : previous.delivery_longitude };
                })} errors={messages} required={false} />
                <div className="space-y-2">
                    <p className="text-sm font-semibold text-slate-800">Delivery map pin (optional)</p>
                    <p className="text-xs text-slate-600">Add your complete address and mobile number to save this pin for future deliveries. You can choose a pin at checkout instead.</p>
                    <LocationPinPicker label="Registration delivery location" value={data.delivery_latitude !== null && data.delivery_longitude !== null ? { latitude: data.delivery_latitude, longitude: data.delivery_longitude } : null}
                        onChange={point => { setData(previous => ({ ...previous, delivery_latitude: point.latitude, delivery_longitude: point.longitude })); clearErrors('delivery_latitude', 'delivery_longitude'); setFeedback(previous => ({ ...previous, delivery_latitude: '', delivery_longitude: '' })); }} />
                    {(data.delivery_latitude !== null || data.delivery_longitude !== null) && <button type="button" onClick={() => { setData(previous => ({ ...previous, delivery_latitude: null, delivery_longitude: null })); clearErrors('delivery_latitude', 'delivery_longitude'); if (!data.phone.trim()) clearErrors('phone'); setFeedback(previous => ({ ...previous, delivery_latitude: '', delivery_longitude: '', ...(!data.phone.trim() ? { phone: '' } : {}) })); }} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold">Clear pin</button>}
                    {(messages.delivery_latitude || messages.delivery_longitude) && <p role="alert" className="text-xs text-rose-700">{messages.delivery_latitude || messages.delivery_longitude}</p>}
                </div>
                <RegistrationPolicyAcknowledgement policies={policies} accepted={data.policy_accepted} onChange={accepted => { setData('policy_accepted', accepted); clearErrors('policy_accepted'); }} error={messages.policy_accepted} />
                <InputError message={errors.terms_version || errors.privacy_version || errors.registration_nonce || (errors as Record<string, string>).email} role="alert" />
                <button className="w-full rounded-lg bg-[#E00D42] px-4 py-3 text-sm font-bold text-white disabled:opacity-50" type="submit">{processing ? 'Creating account…' : 'Create buyer account'}</button>
                <a href="/login" className="block text-center text-sm font-semibold text-slate-600 underline">Return to sign in</a>
            </fieldset>
        </form>
    </GuestLayout>;
}
