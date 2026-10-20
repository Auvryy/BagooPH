import React from 'react';
import AddressLocationFields from '@/Components/AddressLocationFields';
import InputError from '@/Components/InputError';
import type { AddressLocation } from '@/utils/addressLocations';

type AddressValues = AddressLocation & { municipality: string; address: string };
type Props = {
    values: AddressValues;
    onChange: (values: AddressValues) => void;
    errors?: Partial<Record<keyof AddressValues, string>>;
    accentColor?: 'primary' | 'emerald';
    required?: boolean;
    showStreetAddress?: boolean;
    streetLabel?: string;
    streetPlaceholder?: string;
    postalCodeField?: React.ReactNode;
    onLoadingChange?: (loading: boolean) => void;
};

export default function PhilippineAddressSelector({ values, onChange, errors = {}, accentColor = 'primary', required = true,
    showStreetAddress = true, streetLabel = 'Street / House / Unit No.', streetPlaceholder = 'e.g. Unit 401, 123 Mabini Street',
    postalCodeField, onLoadingChange }: Props) {
    const focus = accentColor === 'emerald' ? 'focus:border-emerald-600 focus:ring-emerald-600' : 'focus:border-[#E00D42] focus:ring-[#E00D42]';
    return <div className="space-y-3">
        <AddressLocationFields values={values} required={required} appearance="registration" accent={accentColor}
            idPrefix="registration" errors={errors} onLoadingChange={onLoadingChange}
            onChange={location => onChange({ ...values, ...location, municipality: location.city })} />
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {postalCodeField}
            {showStreetAddress && <div className={postalCodeField ? '' : 'sm:col-span-2'}>
                <label htmlFor="registration-street" className="mb-1 block text-xs font-semibold text-slate-800">{streetLabel} {required && '*'}</label>
                <input id="registration-street" name="address" type="text" value={values.address} required={required} minLength={5} maxLength={500}
                    autoComplete="street-address" placeholder={streetPlaceholder} onChange={event => onChange({ ...values, address: event.target.value })}
                    aria-invalid={Boolean(errors.address)} aria-describedby={errors.address ? 'registration-street-error' : undefined}
                    className={`w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 ${focus}`} />
                <InputError id="registration-street-error" message={errors.address} className="mt-1" />
            </div>}
        </div>
    </div>;
}
