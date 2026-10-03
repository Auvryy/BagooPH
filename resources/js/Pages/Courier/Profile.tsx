import { useRef, useState, type FormEvent } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { Eye, EyeOff, Save } from 'lucide-react';
import EmailVerificationStatus from '@/Components/EmailVerificationStatus';
import { CourierBadge, CourierFieldError, CourierPanel, courierButton, courierClasses, courierInput, courierPrimary } from '@/Components/CourierUI';
import CourierLayout from '@/Layouts/CourierLayout';
import { courierPath, type CourierScope } from '@/utils/courier';
import { PageProps } from '@/types';
import useCourierRequestError from '@/hooks/useCourierRequestError';

interface Props {
    rider: { name: string; email: string; email_verified_at: string | null; phone: string | null; account_status: string; kyc_status: string };
    assignment: { company: string | null; hub: string | null; hub_code: string | null; barangay: string | null };
    vehicle: { type: string | null; model: string | null; plate_number: string | null; fleet_status: string | null; license_number: string | null; registration_status: string | null };
    scope?: CourierScope;
    isOnline: boolean;
}

const readable = (value?: string | null) => value?.replaceAll('_', ' ') || 'Not provided';

function ManagedDetails({ items }: { items: Array<[string, string | null]> }) {
    return <dl className="mt-5 divide-y divide-slate-300">{items.map(([label, value]) => <div key={label} className="py-4"><dt className="text-sm font-semibold text-slate-600">{label}</dt><dd className="mt-1 break-words text-base text-slate-900">{value || 'Not provided'}</dd></div>)}</dl>;
}

export default function CourierProfile({ rider, assignment, vehicle, scope, isOnline }: Props) {
    const [tab, setTab] = useState<'account' | 'assignment' | 'vehicle'>('account');
    const [visible, setVisible] = useState<Record<string, boolean>>({});
    const [contactResult, setContactResult] = useState('');
    const [passwordResult, setPasswordResult] = useState('');
    const contactPending = useRef(false);
    const passwordPending = useRef(false);
    const contact = useForm({ name: rider.name, phone: rider.phone ?? '' });
    const password = useForm({ current_password: '', password: '', password_confirmation: '' });
    useCourierRequestError(contactPending, (message) => contact.setError('name', message));
    useCourierRequestError(passwordPending, (message) => password.setError('current_password', message));
    const saveContact = (event: FormEvent) => {
        event.preventDefault();
        if (contactPending.current) return;
        contactPending.current = true;
        setContactResult('');
        contact.patch(courierPath('/profile/account'), {
            preserveScroll: true,
            onSuccess: (page) => {
                const error = (page.props as PageProps).flash?.error;
                if (error) contact.setError('name', error);
                else setContactResult('Contact details saved.');
            },
            onFinish: () => { contactPending.current = false; },
        });
    };
    const savePassword = (event: FormEvent) => {
        event.preventDefault();
        if (passwordPending.current || !rider.email_verified_at) return;
        passwordPending.current = true;
        setPasswordResult('');
        password.put(courierPath('/profile/password'), {
            preserveScroll: true,
            onSuccess: (page) => {
                const error = (page.props as PageProps).flash?.error;
                if (error) password.setError('current_password', error);
                else { password.reset(); setVisible({}); setPasswordResult('Password updated.'); }
            },
            onFinish: () => { passwordPending.current = false; },
        });
    };
    const passwordFields = [
        { key: 'current_password', label: 'Current password', autocomplete: 'current-password' },
        { key: 'password', label: 'New password', autocomplete: 'new-password' },
        { key: 'password_confirmation', label: 'Confirm new password', autocomplete: 'new-password' },
    ] as const;

    return (
        <CourierLayout title="Your profile" subtitle="Update your contact details and security settings." scope={scope ?? assignment} isOnline={isOnline}>
            <Head title="Your profile — BagooPH" />
            <div className="space-y-5">
                <CourierPanel className="p-4 sm:p-5">
                    <h2 className="break-words text-xl font-bold">{rider.name}</h2>
                    <p className="mt-2 break-all text-base text-slate-700">{rider.email}</p>
                    <div className="mt-3 flex flex-wrap gap-2"><CourierBadge>Account: {readable(rider.account_status)}</CourierBadge><CourierBadge tone={['approved', 'verified'].includes(rider.kyc_status) ? 'success' : 'neutral'}>Review: {readable(rider.kyc_status)}</CourierBadge></div>
                </CourierPanel>
                <div role="group" aria-label="Profile sections" className="flex flex-wrap gap-2">
                    {([{ key: 'account', label: 'Account' }, { key: 'assignment', label: 'Assignment' }, { key: 'vehicle', label: 'Vehicle' }] as const).map((item) => <button key={item.key} type="button" aria-pressed={tab === item.key} onClick={() => setTab(item.key)} className={courierClasses(courierButton, tab === item.key && 'border-[#C20836] bg-[#FDF2F4] text-[#C20836]')}>{item.label}</button>)}
                </div>
                {tab === 'account' && <div className="grid items-start gap-5 xl:grid-cols-2">
                    <CourierPanel className="p-4 sm:p-5">
                        <h2 className="text-xl font-bold">Contact details</h2>
                        <p className="mt-2 text-base leading-relaxed text-slate-600">Your company, hub, and vehicle assignment are managed by logistics.</p>
                        <form onSubmit={saveContact} className="mt-5 space-y-4">
                            <div><label htmlFor="rider-name" className="text-sm font-semibold">Full name</label><input id="rider-name" type="text" autoComplete="name" required minLength={2} maxLength={100} value={contact.data.name} disabled={contact.processing} onChange={(event) => contact.setData('name', event.target.value)} aria-invalid={Boolean(contact.errors.name)} aria-describedby="rider-name-error" className={courierInput} /><CourierFieldError id="rider-name-error" message={contact.errors.name} /></div>
                            <div><label htmlFor="rider-phone" className="text-sm font-semibold">Mobile number (optional)</label><input id="rider-phone" type="tel" autoComplete="tel" maxLength={30} value={contact.data.phone} disabled={contact.processing} onChange={(event) => contact.setData('phone', event.target.value)} aria-invalid={Boolean(contact.errors.phone)} aria-describedby="rider-phone-help rider-phone-error" className={courierInput} /><p id="rider-phone-help" className="mt-2 text-sm text-slate-600">Use a Philippine mobile number, such as 09XXXXXXXXX.</p><CourierFieldError id="rider-phone-error" message={contact.errors.phone} /></div>
                            <button type="submit" disabled={contact.processing} className={`${courierPrimary} w-full`}><Save className="h-5 w-5" aria-hidden="true" />{contact.processing ? 'Saving…' : 'Save contact details'}</button>
                            <p role="status" className="text-sm font-semibold text-[#047857]">{contactResult}</p>
                        </form>
                    </CourierPanel>
                    <CourierPanel className="p-4 sm:p-5">
                        <h2 className="text-xl font-bold">Password and email</h2>
                        <EmailVerificationStatus email={rider.email} verifiedAt={rider.email_verified_at} comfortable className="mt-4" />
                        <form onSubmit={savePassword} className="mt-5 space-y-4">
                            <p id="rider-password-help" className="text-base leading-relaxed text-slate-600">Use 12–128 characters for your new password. Verify your email before making this change.</p>
                            <CourierFieldError message={(password.errors as Record<string, string>).email} />
                            {passwordFields.map(({ key, label, autocomplete }) => <div key={key}>
                                <label htmlFor={`rider-${key}`} className="text-sm font-semibold">{label}</label>
                                <div className="mt-2 flex items-start gap-2"><input id={`rider-${key}`} type={visible[key] ? 'text' : 'password'} autoComplete={autocomplete} required minLength={key === 'current_password' ? undefined : 12} maxLength={key === 'current_password' ? undefined : 128} value={password.data[key]} disabled={password.processing || !rider.email_verified_at} onChange={(event) => password.setData(key, event.target.value)} aria-invalid={Boolean(password.errors[key])} aria-describedby={`rider-password-help rider-${key}-error`} className={`${courierInput} mt-0 min-w-0 flex-1`} /><button type="button" disabled={password.processing || !rider.email_verified_at} onClick={() => setVisible((current) => ({ ...current, [key]: !current[key] }))} aria-label={`${visible[key] ? 'Hide' : 'Show'} ${label.toLowerCase()}`} aria-pressed={Boolean(visible[key])} className={`${courierButton} h-12 w-12 shrink-0 p-0`}>{visible[key] ? <EyeOff className="h-5 w-5" aria-hidden="true" /> : <Eye className="h-5 w-5" aria-hidden="true" />}</button></div>
                                <CourierFieldError id={`rider-${key}-error`} message={password.errors[key]} />
                            </div>)}
                            <button type="submit" disabled={password.processing || !rider.email_verified_at} className={`${courierPrimary} w-full`}>{password.processing ? 'Updating…' : 'Update password'}</button>
                            <p role="status" className="text-sm font-semibold text-[#047857]">{passwordResult}</p>
                        </form>
                    </CourierPanel>
                </div>}
                {tab === 'assignment' && <CourierPanel className="p-4 sm:p-5"><h2 className="text-xl font-bold">Working assignment</h2><p className="mt-2 text-base leading-relaxed text-slate-600">Your logistics team manages this assignment. Contact them if any details need correcting.</p><ManagedDetails items={[["Company", assignment.company || 'Not assigned'], ["Bayan Hub", assignment.hub || 'Not assigned'], ["Hub code", assignment.hub_code], ["Barangay", assignment.barangay]]} /><p className="mt-4 rounded-[18px] border border-amber-300 bg-[#FFF4DF] p-4 text-base leading-relaxed text-[#92400E]">Going off duty stops new work. Keep handling assigned parcels through their pickup, hub intake, or final-mile delivery.</p></CourierPanel>}
                {tab === 'vehicle' && <CourierPanel className="p-4 sm:p-5"><h2 className="text-xl font-bold">Managed vehicle and credentials</h2><p className="mt-2 text-base leading-relaxed text-slate-600">These are your stored records. Your logistics team handles vehicle and credential updates.</p><ManagedDetails items={[["Vehicle type", vehicle.type], ["Model", vehicle.model], ["Plate number", vehicle.plate_number], ["Fleet status", vehicle.fleet_status], ["License number", vehicle.license_number], ["Registration status", vehicle.registration_status]]} /></CourierPanel>}
            </div>
        </CourierLayout>
    );
}
