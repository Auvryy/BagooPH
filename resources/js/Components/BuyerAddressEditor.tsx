import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { useForm } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import { X } from 'lucide-react';
import PhoneInput from '@/Components/PhoneInput';
import BuyerAddressLocationFields from '@/Components/BuyerAddressLocationFields';
import { buyerAddressErrors } from '@/utils/buyerAddresses';
import type { Address, User } from '@/types';

export default function BuyerAddressEditor({ user, address, count, limit, onClose }: { user: User; address?: Address; count: number; limit: number; onClose: () => void }) {
    const form = useForm({
        recipient_name: address?.recipient_name ?? user.name, phone: address?.phone ?? user.phone ?? '',
        region: address?.region ?? '', province: address?.province ?? '', city: address?.city ?? '', barangay: address?.barangay ?? '',
        street: address?.street ?? '', postal_code: address?.postal_code ?? '', type: address?.type ?? 'Home',
        is_default: address?.is_default ?? count === 0, latitude: address?.latitude ?? null, longitude: address?.longitude ?? null, landmark: address?.landmark ?? '',
    });
    const [locationsLoading, setLocationsLoading] = useState(true);
    const pending = useRef(false);
    const limited = !address && count >= limit;
    const update = (field: 'recipient_name' | 'phone' | 'street' | 'postal_code', value: string) => {
        const next = { ...form.data, [field]: value };
        form.setData(field === 'street' ? { ...next, latitude: null, longitude: null } : next);
        const error = buyerAddressErrors(next)[field];
        if (error) form.setError(field, error); else form.clearErrors(field);
    };
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (pending.current || locationsLoading || limited) return;
        const errors = buyerAddressErrors(form.data);
        form.clearErrors();
        if (Object.keys(errors).length) { form.setError(errors); return; }
        pending.current = true;
        const options = { preserveScroll: true, onSuccess: onClose, onFinish: () => { pending.current = false; } };
        if (address) form.patch(route('buyer.addresses.update', address.id), options);
        else form.post(route('buyer.addresses.store'), options);
    };
    const generalError = (form.errors as Record<string, string>).address_book;
    const inputClass = 'w-full rounded-xl border border-slate-300 bg-slate-50 p-2.5 text-sm text-slate-900 focus:border-[#E00D42] focus:bg-white focus:ring-[#E00D42]';
    return <Dialog open onClose={() => { if (!form.processing) onClose(); }} className="relative z-50 font-sans">
        <div className="fixed inset-0 bg-black/60 backdrop-blur-xs" aria-hidden="true" />
        <div className="fixed inset-0 flex items-center justify-center overflow-y-auto p-4">
            <DialogPanel className="max-h-[92dvh] w-full max-w-lg space-y-4 overflow-y-auto rounded-3xl border border-slate-200 bg-white p-6 shadow-2xl sm:p-8">
                <div className="flex items-center justify-between gap-3 border-b border-slate-100 pb-3">
                    <DialogTitle className="text-base font-bold text-slate-900">{address ? 'Edit delivery address' : 'Add delivery address'}</DialogTitle>
                    <button type="button" onClick={onClose} disabled={form.processing} aria-label="Close address form" className="rounded-lg p-1 text-slate-500 hover:bg-slate-100 focus:ring-2 focus:ring-[#E00D42]"><X className="h-5 w-5" aria-hidden="true" /></button>
                </div>
                <p className="text-xs leading-relaxed text-slate-600">These details are for future deliveries. Existing orders keep their original address. Changing the recipient does not change your account name.</p>
                <form onSubmit={submit} noValidate className="space-y-4 text-xs">
                    {(generalError || limited) && <p role="alert" className="rounded-xl border border-rose-300 bg-rose-50 p-3 text-sm text-rose-800">{generalError || `You can save up to ${limit} addresses. Remove a non-default address before adding another.`}</p>}
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label htmlFor="address-recipient-name" className="mb-1 block font-bold text-slate-700">Recipient name *</label>
                            <input id="address-recipient-name" name="recipient_name" autoComplete="name" autoFocus required minLength={2} maxLength={100} value={form.data.recipient_name}
                                onChange={event => update('recipient_name', event.target.value)} aria-invalid={Boolean(form.errors.recipient_name)} aria-describedby={form.errors.recipient_name ? 'address-name-error' : undefined} className={inputClass} />
                            {form.errors.recipient_name && <p id="address-name-error" role="alert" className="mt-1 text-rose-700">{form.errors.recipient_name}</p>}
                        </div>
                        <div><label htmlFor="address-phone" className="mb-1 block font-bold text-slate-700">Mobile number *</label><PhoneInput id="address-phone" value={form.data.phone} onChange={value => update('phone', value)} error={form.errors.phone} required helperText="Philippine mobile number, such as 09171234567." /></div>
                    </div>
                    <BuyerAddressLocationFields values={form.data} onLoadingChange={setLocationsLoading}
                        onChange={(next, userChange) => {
                            form.setData({ ...form.data, ...next, ...(userChange ? { postal_code: '', latitude: null, longitude: null } : {}) });
                            form.clearErrors('region', 'province', 'city', 'barangay');
                        }} errors={form.errors} />
                    <div>
                        <label htmlFor="address-street" className="mb-1 block font-bold text-slate-700">Street / house / unit number *</label>
                        <textarea id="address-street" name="street" autoComplete="street-address" required minLength={5} maxLength={500} rows={3} value={form.data.street}
                            onChange={event => update('street', event.target.value)} aria-invalid={Boolean(form.errors.street)} aria-describedby={form.errors.street ? 'address-street-error' : undefined} className={inputClass} />
                        {form.errors.street && <p id="address-street-error" role="alert" className="mt-1 text-rose-700">{form.errors.street}</p>}
                    </div>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div><label htmlFor="address-postal-code" className="mb-1 block font-bold text-slate-700">Postal code (optional)</label><input id="address-postal-code" name="postal_code" autoComplete="postal-code" inputMode="numeric" maxLength={4} pattern="[0-9]{4}" value={form.data.postal_code} onChange={event => update('postal_code', event.target.value)} className={inputClass} aria-invalid={Boolean(form.errors.postal_code)} />{form.errors.postal_code && <p role="alert" className="mt-1 text-rose-700">{form.errors.postal_code}</p>}</div>
                        <div><label htmlFor="address-type" className="mb-1 block font-bold text-slate-700">Address label</label><select id="address-type" name="type" value={form.data.type} onChange={event => form.setData('type', event.target.value)} className={inputClass}><option>Home</option><option>Office</option><option>Other</option></select>{form.errors.type && <p role="alert" className="mt-1 text-rose-700">{form.errors.type}</p>}</div>
                    </div>
                    <div className="space-y-3 border-t border-slate-100 pt-3">
                        <label className="flex items-start gap-2 text-xs text-slate-700"><input type="checkbox" checked={form.data.is_default} disabled={Boolean(address?.is_default) || count === 0} onChange={event => form.setData('is_default', event.target.checked)} className="mt-0.5 rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" />Use as my default delivery address</label>
                        {(address?.is_default || count === 0) && <p className="text-xs text-slate-500">Your address book needs one default. To change it, choose another saved address as default.</p>}
                        <div className="flex justify-end gap-3"><button type="button" disabled={form.processing} onClick={onClose} className="rounded-xl border border-slate-300 px-4 py-2.5 font-semibold text-slate-700">Cancel</button><button type="submit" disabled={form.processing || locationsLoading || limited} className="rounded-xl bg-[#E00D42] px-5 py-2.5 font-bold text-white hover:bg-[#C20836] disabled:opacity-50">{form.processing ? 'Saving...' : address ? 'Save changes' : 'Save address'}</button></div>
                    </div>
                </form>
            </DialogPanel>
        </div>
    </Dialog>;
}
