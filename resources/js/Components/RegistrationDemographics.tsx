import { useState } from 'react';
import InputError from '@/Components/InputError';

type FieldProps = {
    value: string;
    onChange: (value: string) => void;
    error?: string;
    courier?: boolean;
};

function controlClass(courier: boolean) {
    return `w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 ${courier ? 'focus:border-emerald-600 focus:ring-emerald-600' : 'focus:border-[#E00D42] focus:ring-[#E00D42]'} focus:ring-1`;
}

export function RegistrationSexField({ value, onChange, error, courier = false }: FieldProps) {
    return <div className="space-y-1">
            <label htmlFor="sex" className="flex h-5 items-center text-xs font-semibold uppercase tracking-wider text-slate-800">Sex (optional)</label>
            <select id="sex" name="sex" value={value} onChange={event => onChange(event.target.value)} className={controlClass(courier)} aria-invalid={Boolean(error)} aria-describedby={error ? 'sex-error' : undefined}>
                <option value="">Choose an option</option>
                <option value="Female">Female</option><option value="Male">Male</option><option value="Other">Other</option>
            </select>
            <InputError id="sex-error" message={error} />
        </div>;
}

export function RegistrationPostalCodeField({ value, onChange, error, courier = false }: FieldProps) {
    const [postalFeedback, setPostalFeedback] = useState<string>();
    return <div className="space-y-1">
            <label htmlFor="postal-code" className="flex h-5 items-center text-xs font-semibold uppercase tracking-wider text-slate-800">Postal code (optional)</label>
            <input id="postal-code" name="postal_code" type="text" inputMode="numeric" autoComplete="postal-code" value={value} minLength={4} maxLength={4} pattern="[0-9]{4}" placeholder="4 digits"
                onChange={event => {
                    const value = event.target.value;
                    if (!/^[0-9]{0,4}$/.test(value)) { setPostalFeedback('Use exactly four digits, without letters or symbols.'); return; }
                    setPostalFeedback(value && value.length !== 4 ? 'Use exactly four digits.' : undefined);
                    onChange(value);
                }}
                onPaste={event => {
                    const input = event.currentTarget;
                    const value = input.value.slice(0, input.selectionStart ?? input.value.length) + event.clipboardData.getData('text') + input.value.slice(input.selectionEnd ?? input.value.length);
                    if (!/^[0-9]{0,4}$/.test(value)) { event.preventDefault(); setPostalFeedback('Use four digits. No pasted text was added.'); }
                }} className={controlClass(courier)} aria-invalid={Boolean(postalFeedback || error)} aria-describedby="postal-code-help postal-code-error" />
            <p id="postal-code-help" className="text-xs text-slate-500">Use four digits for the address in this application.</p>
            <InputError id="postal-code-error" role="status" aria-live="polite" message={postalFeedback || error} />
        </div>;
}
