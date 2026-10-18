import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { changeAddressLocation, emptyLocationOptions, loadAddressLocations, type AddressLocation } from '@/utils/addressLocations';

type Props = {
    values: AddressLocation;
    onChange: (values: AddressLocation, userChange: boolean) => void;
    errors?: Partial<Record<keyof AddressLocation, string>>;
    idPrefix?: string;
    onLoadingChange?: (loading: boolean) => void;
};
const labels = { region: 'Region', province: 'Province / area', city: 'City / municipality', barangay: 'Barangay' };
const groups = { region: 'regions', province: 'provinces', city: 'cities', barangay: 'barangays' } as const;
const prompts = { region: 'Choose a region', province: 'Choose a province or area', city: 'Choose a city or municipality', barangay: 'Choose a barangay' };

export default function BuyerAddressLocationFields({ values, onChange, errors = {}, idPrefix = 'address', onLoadingChange }: Props) {
    const [options, setOptions] = useState(emptyLocationOptions);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    const [touched, setTouched] = useState<Partial<Record<keyof AddressLocation, boolean>>>({});
    const callbacks = useRef({ onChange, onLoadingChange });
    callbacks.current = { onChange, onLoadingChange };
    useEffect(() => {
        const controller = new AbortController();
        let active = true;
        let ready = false;
        setLoading(true); setError(''); setOptions(emptyLocationOptions);
        callbacks.current.onLoadingChange?.(true);
        loadAddressLocations(values, controller.signal).then(next => {
            if (!active) return;
            setOptions(next); ready = true;
            if (Object.entries(next.resolved).some(([field, value]) => values[field as keyof AddressLocation] !== value)) {
                callbacks.current.onChange({ ...values, ...next.resolved }, false);
            }
        }).catch(reason => {
            if (!active || axios.isCancel(reason)) return;
            const status = axios.isAxiosError(reason) ? reason.response?.status : undefined;
            setError(status === 401 || status === 419 ? 'Your session expired. Sign in again and reopen the address form.'
                : status === 403 ? 'Address changes are unavailable for this account. Check Account status before trying again.'
                : status === 422
                ? 'Choose the location again from the lists. Your other address details have been kept.'
                : 'The location lists could not load. Check your connection and try again.');
        }).finally(() => { if (active) { setLoading(false); callbacks.current.onLoadingChange?.(!ready); } });
        return () => { active = false; controller.abort(); };
    }, [values.region, values.province, values.city, retry]);

    return <div className="space-y-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {(Object.keys(labels) as (keyof AddressLocation)[]).map(field => {
                const choices = options[groups[field]];
                const missingParent = field === 'province' ? !values.region : field === 'city' ? !values.province : field === 'barangay' ? !values.city : false;
                const message = errors[field] || (touched[field] && !values[field] ? prompts[field] + '.' : '');
                const id = `${idPrefix}-${field}`;
                return <div key={field}>
                    <label htmlFor={id} className="mb-1 block text-xs font-bold text-slate-700">{labels[field]} <span aria-hidden="true">*</span></label>
                    <select id={id} name={field} value={values[field]} required disabled={loading || missingParent}
                        onChange={event => { setTouched(previous => ({ ...previous, [field]: true })); onChange(changeAddressLocation(values, field, event.target.value), true); }}
                        onBlur={() => setTouched(previous => ({ ...previous, [field]: true }))}
                        aria-invalid={Boolean(message)} aria-describedby={message ? id + '-error' : undefined}
                        className="w-full rounded-xl border border-slate-300 bg-slate-50 p-2.5 text-sm text-slate-900 focus:border-[#E00D42] focus:bg-white focus:ring-[#E00D42] disabled:cursor-not-allowed disabled:opacity-60">
                        <option value="">{loading ? 'Loading locations...' : prompts[field]}</option>
                        {choices.map((choice, index) => <option key={choice.value + index} value={choice.value}>{choice.label}</option>)}
                    </select>
                    {message && <p id={id + '-error'} role="alert" className="mt-1 text-xs text-rose-700">{message}</p>}
                </div>;
            })}
        </div>
        {loading && <p role="status" className="text-xs text-slate-500">Loading location choices...</p>}
        {error && <div role="alert" className="rounded-xl border border-rose-300 bg-rose-50 p-3 text-xs text-rose-800"><p>{error}</p><button type="button" onClick={() => setRetry(value => value + 1)} className="mt-2 font-semibold underline underline-offset-2">Try again</button><button type="button" onClick={() => onChange({ region: '', province: '', city: '', barangay: '' }, true)} className="ml-3 font-semibold underline underline-offset-2">Choose locations again</button></div>}
    </div>;
}
