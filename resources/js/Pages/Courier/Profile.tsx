import { useRef, useState, type FormEvent } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Building2, Eye, EyeOff, IdCard, MapPin, PencilLine, Save, ShieldCheck, Truck, UserRound } from 'lucide-react';
import EmailVerificationStatus from '@/Components/EmailVerificationStatus';
import { CourierAvatar, CourierBadge, CourierFieldError, CourierPanel, courierButton, courierClasses, courierInput, courierPrimary } from '@/Components/CourierUI';
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
    return <dl className="mt-5 grid gap-x-6 sm:grid-cols-2">{items.map(([label, value]) => <div key={label} className="border-b border-slate-100/80 py-4"><dt className="text-xs font-medium text-slate-500">{label}</dt><dd className="mt-2 break-words text-sm font-medium leading-relaxed text-slate-900">{value || 'Not provided'}</dd></div>)}</dl>;
}

export default function CourierProfile({ rider, assignment, vehicle, scope, isOnline }: Props) {
    const { auth } = usePage<PageProps>().props;
    const [tab, setTab] = useState<'information' | 'edit' | 'privacy' | 'assignment' | 'vehicle'>('information');
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
        <CourierLayout title="Your profile" hidePageHeading scope={scope ?? assignment} isOnline={isOnline}>
            <Head title="Your profile — BagooPH" />
            <div className="space-y-5">
                <CourierPanel className="overflow-hidden">
                    <div className="relative h-32 overflow-hidden bg-[#FBEAF0] sm:h-40" data-profile-cover="true">
                        <p className="absolute left-5 top-5 text-xs font-medium text-[#9D2444] sm:left-6">Your rider workspace</p>
                        <svg viewBox="0 0 600 160" fill="none" className="absolute right-0 top-0 h-full w-auto max-w-full text-[#E00D42]" aria-hidden="true">
                            <circle cx="510" cy="75" r="105" fill="#FFF6F8" /><circle cx="340" cy="160" r="75" fill="#FDF2F4" />
                            <path d="M240 120H310C345 120 335 55 370 55H510" stroke="currentColor" strokeOpacity=".15" strokeWidth="3" strokeDasharray="5 8" />
                            <rect x="435" y="35" width="60" height="60" rx="8" fill="white" fillOpacity=".75" />
                            <path d="m465 49 17 9v19l-17 9-17-9V58l17-9Zm0 18v19m-17-28 17 9 17-9m-26-4 17 9" stroke="currentColor" strokeOpacity=".55" strokeWidth="2" strokeLinejoin="round" />
                            <circle cx="250" cy="120" r="7" fill="white" stroke="currentColor" strokeOpacity=".3" strokeWidth="2" />
                        </svg>
                    </div>
                    <div className="relative px-4 pb-5 sm:px-6 sm:pb-6">
                        <div className="flex flex-wrap items-end justify-between gap-4">
                            <div className="min-w-0"><CourierAvatar name={rider.name} src={auth.user?.avatar} className="-mt-10 h-24 w-24 bg-[#FBEAF0] text-3xl ring-[6px] ring-white" /><h1 className="mt-4 break-words text-2xl font-semibold tracking-tight">{rider.name}</h1><p className="mt-2 break-all text-sm text-slate-600">{rider.email}</p></div>
                            <button type="button" onClick={() => setTab('edit')} aria-controls="profile-edit" className={courierButton}><PencilLine className="h-4 w-4" aria-hidden="true" />Edit information</button>
                        </div>
                        <div className="mt-4 flex flex-wrap gap-2"><CourierBadge>Account: {readable(rider.account_status)}</CourierBadge><CourierBadge tone={['approved', 'verified'].includes(rider.kyc_status) ? 'success' : 'neutral'}>Review: {readable(rider.kyc_status)}</CourierBadge></div>
                    </div>
                </CourierPanel>
                <div className="grid items-start gap-5 lg:grid-cols-[15rem_minmax(0,1fr)]">
                    <aside className="min-w-0 space-y-4" aria-label="Rider identity and settings">
                        <CourierPanel className="p-4 sm:p-5"><div className="flex items-center gap-2 text-[#C20836]"><IdCard className="h-5 w-5" aria-hidden="true" /><h2 className="text-sm font-semibold">Rider identity</h2></div><p className="mt-4 text-xs text-slate-500">Account ID</p><p className="mt-1 text-lg font-semibold tabular-nums">{auth.user ? `#${auth.user.id}` : 'Not provided'}</p><dl className="mt-4 space-y-4 text-sm"><div><dt className="text-xs text-slate-500">Mobile number</dt><dd className="mt-1 break-words">{rider.phone || 'Not provided'}</dd></div><div><dt className="flex items-center gap-1.5 text-xs text-slate-500"><Building2 className="h-3.5 w-3.5" aria-hidden="true" />Company</dt><dd className="mt-1 break-words">{assignment.company || 'Not assigned'}</dd></div><div><dt className="flex items-center gap-1.5 text-xs text-slate-500"><MapPin className="h-3.5 w-3.5" aria-hidden="true" />Bayan Hub</dt><dd className="mt-1 break-words">{assignment.hub || 'Not assigned'}</dd></div></dl></CourierPanel>
                        <CourierPanel className="p-2"><nav aria-label="Profile sections" className="grid grid-cols-2 gap-1 sm:flex sm:flex-wrap lg:block lg:space-y-1">
                            {([{ key: 'information', label: 'Information', icon: UserRound }, { key: 'edit', label: 'Edit information', icon: PencilLine }, { key: 'privacy', label: 'Privacy and security', icon: ShieldCheck }, { key: 'assignment', label: 'Assignment', icon: MapPin }, { key: 'vehicle', label: 'Vehicle', icon: Truck }] as const).map(({ key, label, icon: Icon }) => <button key={key} type="button" aria-pressed={tab === key} aria-controls={`profile-${key}`} onClick={() => setTab(key)} className={courierClasses(courierButton, 'min-w-0 justify-start border-transparent px-3 text-left lg:w-full', tab === key && 'bg-[#FFF6F8] text-[#C20836]')}><Icon className="h-4 w-4 shrink-0" aria-hidden="true" /><span>{label}</span></button>)}
                        </nav></CourierPanel>
                    </aside>
                    <div className="min-w-0">
                    <section id="profile-information" aria-labelledby="profile-information-title" hidden={tab !== 'information'}>
                        <CourierPanel className="p-4 sm:p-6"><h2 id="profile-information-title" className="text-lg font-semibold">Information</h2><p className="mt-2 text-sm leading-relaxed text-slate-600">Your saved account details and current review status.</p><ManagedDetails items={[["Full name", rider.name], ["Email address", rider.email], ["Mobile number", rider.phone], ["Account status", readable(rider.account_status)], ["Identity review", readable(rider.kyc_status)], ["Email status", rider.email_verified_at ? 'Verified' : 'Verification required']]} /><p className="courier-inset mt-5 rounded-[8px] p-4 text-sm leading-relaxed text-slate-600">Use Edit information to update your name or mobile number. Your logistics team manages assignment and vehicle records.</p></CourierPanel>
                    </section>
                    <section id="profile-edit" aria-labelledby="profile-edit-title" hidden={tab !== 'edit'}>
                    <CourierPanel className="p-4 sm:p-6">
                        <h2 id="profile-edit-title" className="text-lg font-semibold">Contact details</h2>
                        <p className="mt-2 text-sm leading-relaxed text-slate-600">Keep your name and mobile number up to date so your delivery contacts can reach you.</p>
                        <form onSubmit={saveContact} className="mt-5 space-y-4">
                            <div><label htmlFor="rider-name" className="text-sm font-semibold">Full name</label><input id="rider-name" type="text" autoComplete="name" required minLength={2} maxLength={100} value={contact.data.name} disabled={contact.processing} onChange={(event) => contact.setData('name', event.target.value)} aria-invalid={Boolean(contact.errors.name)} aria-describedby="rider-name-error" className={courierInput} /><CourierFieldError id="rider-name-error" message={contact.errors.name} /></div>
                            <div><label htmlFor="rider-phone" className="text-sm font-semibold">Mobile number (optional)</label><input id="rider-phone" type="tel" autoComplete="tel" maxLength={30} value={contact.data.phone} disabled={contact.processing} onChange={(event) => contact.setData('phone', event.target.value)} aria-invalid={Boolean(contact.errors.phone)} aria-describedby="rider-phone-help rider-phone-error" className={courierInput} /><p id="rider-phone-help" className="mt-2 text-sm text-slate-600">Use a Philippine mobile number, such as 09XXXXXXXXX.</p><CourierFieldError id="rider-phone-error" message={contact.errors.phone} /></div>
                            <button type="submit" disabled={contact.processing} className={`${courierPrimary} w-full`}><Save className="h-5 w-5" aria-hidden="true" />{contact.processing ? 'Saving…' : 'Save contact details'}</button>
                            <p role="status" className="text-sm font-semibold text-[#047857]">{contactResult}</p>
                        </form>
                    </CourierPanel>
                    </section>
                    <section id="profile-privacy" aria-labelledby="profile-privacy-title" hidden={tab !== 'privacy'}>
                    <CourierPanel className="p-4 sm:p-6">
                        <h2 id="profile-privacy-title" className="text-lg font-semibold">Privacy and security</h2>
                        <p className="mt-2 text-sm leading-relaxed text-slate-600">Manage email verification and your password.</p>
                        <EmailVerificationStatus email={rider.email} verifiedAt={rider.email_verified_at} comfortable className="mt-4 !border-transparent" />
                        <form onSubmit={savePassword} className="mt-5 space-y-4">
                            <p id="rider-password-help" className="text-sm leading-relaxed text-slate-600">Use 12–128 characters for your new password. Verify your email before making this change.</p>
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
                    </section>
                    <section id="profile-assignment" aria-labelledby="profile-assignment-title" hidden={tab !== 'assignment'}><CourierPanel className="p-4 sm:p-6"><h2 id="profile-assignment-title" className="text-lg font-semibold">Working assignment</h2><p className="mt-2 text-sm leading-relaxed text-slate-600">Your logistics team manages this assignment. Contact them if any details need correcting.</p><ManagedDetails items={[["Company", assignment.company || 'Not assigned'], ["Bayan Hub", assignment.hub || 'Not assigned'], ["Hub code", assignment.hub_code], ["Barangay", assignment.barangay]]} /><p className="mt-5 rounded-[8px] bg-[#FFF4DF] p-4 text-sm leading-relaxed text-[#92400E]">Going off duty stops new work. Keep handling assigned parcels through their pickup, hub intake, or final-mile delivery.</p></CourierPanel></section>
                    <section id="profile-vehicle" aria-labelledby="profile-vehicle-title" hidden={tab !== 'vehicle'}><CourierPanel className="p-4 sm:p-6"><h2 id="profile-vehicle-title" className="text-lg font-semibold">Managed vehicle and credentials</h2><p className="mt-2 text-sm leading-relaxed text-slate-600">These are your stored records. Your logistics team handles vehicle and credential updates.</p><ManagedDetails items={[["Vehicle type", vehicle.type], ["Model", vehicle.model], ["Plate number", vehicle.plate_number], ["Fleet status", readable(vehicle.fleet_status)], ["License number", vehicle.license_number], ["Registration status", readable(vehicle.registration_status)]]} /></CourierPanel></section>
                    </div>
                </div>
            </div>
        </CourierLayout>
    );
}
