import { useState } from 'react';
import InputError from '@/Components/InputError';
import { combinedPersonalNameError, formatMiddleInitial, middleInitialError, personalNameError } from '@/utils/registrationValidation';
import type { PersonalNameField, PersonalNameValues } from '@/utils/registrationValidation';

export default function PersonalNameFields({ values, onChange, errors = {}, accent = 'primary', context, autoFocus = false }: {
    values: PersonalNameValues;
    onChange: (field: PersonalNameField, value: string) => void;
    errors?: Record<string, string | undefined>;
    accent?: 'primary' | 'emerald';
    context?: string;
    autoFocus?: boolean;
}) {
    const [touched, setTouched] = useState<Partial<Record<PersonalNameField, boolean>>>({});
    const [inputErrors, setInputErrors] = useState<Partial<Record<PersonalNameField, string>>>({});
    const combinedError = combinedPersonalNameError(values) ?? errors.name;
    const focus = accent === 'emerald' ? 'focus:border-emerald-600 focus:ring-emerald-600' : 'focus:border-[#E00D42] focus:ring-[#E00D42]';
    return <div className="space-y-2 font-sans">
        {context && <p className="text-xs font-semibold text-slate-800">{context}</p>}
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-12">
            {([
                ['first_name', 'First name', 'given-name', true, 'sm:col-span-5'],
                ['middle_initial', 'Middle initial', 'additional-name', false, 'sm:col-span-3'],
                ['last_name', 'Last name', 'family-name', true, 'sm:col-span-4'],
            ] as const).map(([field, label, autoComplete, required, width]) => {
                const maximum = field === 'middle_initial' ? 3 : 50;
                const error = inputErrors[field] ?? (values[field] || touched[field] ? field === 'middle_initial' ? middleInitialError(values[field]) : personalNameError(values[field], label, required) : undefined) ?? errors[field];
                return <div key={field} className={width}>
                    <label htmlFor={field} className="mb-1 block text-xs font-semibold uppercase tracking-wider text-slate-800 sm:min-h-9">
                        {label} {required ? '*' : '(optional)'}
                    </label>
                    <input id={field} name={field} type="text" value={values[field]} autoComplete={autoComplete} required={required} minLength={required ? 2 : 1} maxLength={maximum}
                        autoFocus={autoFocus && field === 'first_name'} aria-invalid={Boolean(error || combinedError)}
                        onFocus={() => { if (field === 'middle_initial' && !middleInitialError(values[field]) && values[field].endsWith('.')) onChange(field, values[field].slice(0, -1)); }}
                        aria-describedby={[error && field + '-error', combinedError && 'personal-name-error'].filter(Boolean).join(' ') || undefined}
                        onChange={event => {
                            setTouched(previous => ({ ...previous, [field]: true }));
                            if (event.target.value.length > maximum) {
                                setInputErrors(previous => ({ ...previous, [field]: 'Use at most ' + maximum + ' characters. No extra text was added.' }));
                                return;
                            }
                            setInputErrors(previous => ({ ...previous, [field]: undefined }));
                            onChange(field, event.target.value);
                        }}
                        onPaste={event => {
                            const input = event.currentTarget;
                            const proposed = input.value.slice(0, input.selectionStart ?? input.value.length) + event.clipboardData.getData('text') + input.value.slice(input.selectionEnd ?? input.value.length);
                            if (proposed.length > maximum) {
                                event.preventDefault();
                                setInputErrors(previous => ({ ...previous, [field]: 'Use at most ' + maximum + ' characters. No pasted text was added.' }));
                            }
                        }}
                        onBlur={() => {
                            setTouched(previous => ({ ...previous, [field]: true }));
                            const trimmed = field === 'middle_initial' ? formatMiddleInitial(values[field]) : values[field].replace(/^ +| +$/g, '');
                            if (trimmed !== values[field]) onChange(field, trimmed);
                        }}
                        className={`w-full rounded-lg border bg-white px-3 py-2.5 text-sm text-slate-900 outline-hidden transition focus:ring-1 ${focus} ${error || combinedError ? 'border-red-600' : 'border-slate-300'}`} />
                    <InputError id={field + '-error'} role="status" aria-live="polite" message={error} className="mt-1 text-xs" />
                    {field === 'middle_initial' && <p className="mt-1 text-xs text-slate-500">1–2 letters. A period is added when you leave this field.</p>}
                </div>;
            })}
        </div>
        <InputError id="personal-name-error" role="status" aria-live="polite" message={combinedError} className="text-xs" />
    </div>;
}
