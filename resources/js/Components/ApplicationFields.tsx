import { useState } from 'react';
import InputError from '@/Components/InputError';
import { combinedPersonalNameError, personalNameError } from '@/utils/registrationValidation';
import { businessContactError, companyInitialsError, licenseNumberError, plateNumberError, registrationTextError } from '@/utils/registrationFields';
import { mobileNumberError } from '@/Components/PhoneInput';

export type ApplicationValues = Record<string, string | number | string[] | null>;
export interface ApplicationField {
    key: string;
    label: string;
    required: boolean;
    type: 'text' | 'email' | 'tel' | 'number' | 'select' | 'multiselect';
    options: string[];
    min_length: number | null;
    max_length: number;
    input_mode: 'numeric' | null;
}
export interface ApplicationDetails {
    values: ApplicationValues;
    fields: ApplicationField[];
    errors: Record<string, string[]>;
    can_correct: boolean;
}

export default function ApplicationFields({ application, values, errors, onChange }: {
    application: ApplicationDetails;
    values: ApplicationValues;
    errors: Record<string, string | undefined>;
    onChange: (key: string, value: string | string[]) => void;
}) {
    const [feedback, setFeedback] = useState<Record<string, string | undefined>>({});
    const fieldError = (field: ApplicationField, value: string): string | undefined => {
        if (['company_code', 'plate_number', 'license_number'].includes(field.key) && value === String(application.values[field.key] ?? '') && !application.errors[field.key]?.length) return undefined;
        if (field.key === 'first_name' || field.key === 'last_name') return personalNameError(value, field.label, field.required);
        if (field.key === 'middle_name') return personalNameError(value, field.label, false);
        if (field.key === 'postal_code') return value && !/^[0-9]{4}$/.test(value) ? 'Use exactly 4 digits.' : undefined;
        if (field.key === 'company_code') return companyInitialsError(value);
        if (field.key === 'plate_number') return plateNumberError(value, String(values.vehicle_type ?? ''));
        if (field.key === 'license_number') return !value && !field.required ? undefined : licenseNumberError(value);
        if (field.key === 'fleet_size') return value && (!/^[1-9][0-9]{0,4}$/.test(value) || Number(value) > 10000) ? 'Use a whole number from 1 to 10,000.' : undefined;
        if (field.type === 'tel') return field.key === 'phone' ? mobileNumberError(value, field.required) : businessContactError(value, field.required);
        if (field.type === 'text') return registrationTextError(value, field.min_length ?? 2, field.max_length, field.required, field.key === 'franchise_number');
        return undefined;
    };
    return (
        <details className="rounded-[12px] border border-slate-300 bg-white p-3" open={Object.keys(application.errors).length > 0 || Object.keys(errors).some(key => application.fields.some(field => field.key === key || key.startsWith(field.key + '.')))}>
            <summary className="cursor-pointer font-semibold text-slate-900">Correct your application details</summary>
            <p className="my-3 text-xs text-slate-600">Changes need a fresh admin review. Your original sign-in email stays with your account.</p>
            <p className="mb-3 text-xs text-slate-500">Name parts must match your identity document. First and last names use 2–50 characters; an existing full middle name can use up to 50. The complete name must fit within 100 characters. Sex and a four-digit postal code are optional.</p>
            <div className="grid gap-3 sm:grid-cols-12">
                {application.fields.map(field => {
                    const value = values[field.key] ?? '';
                    const controlClass = 'w-full rounded-[12px] border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42]';
                    const unchanged = JSON.stringify(value) === JSON.stringify(application.values[field.key] ?? '');
                    const storedError = unchanged ? application.errors[field.key]?.[0] || Object.entries(application.errors).find(([key]) => key.startsWith(field.key + '.'))?.[1]?.[0] : undefined;
                    const error = feedback[field.key] || errors[field.key] || Object.entries(errors).find(([key]) => key.startsWith(field.key + '.'))?.[1] || storedError;
                    const change = (next: string) => {
                        const names = { ...values, [field.key]: next };
                        setFeedback(previous => ({ ...previous, [field.key]: fieldError(field, next),
                            ...(field.key === 'vehicle_type' ? { plate_number: plateNumberError(String(values.plate_number ?? ''), next) } : {}),
                            name: combinedPersonalNameError({ first_name: String(names.first_name ?? ''), middle_initial: String(names.middle_name ?? ''), last_name: String(names.last_name ?? '') }) }));
                        onChange(field.key, next);
                    };
                    const width = field.key === 'first_name' ? 'sm:col-span-5' : field.key === 'last_name' ? 'sm:col-span-4' : field.key === 'middle_name' ? 'sm:col-span-3' : 'sm:col-span-6';
                    return (
                        <div key={field.key} className={width}>
                            <label htmlFor={`application-${field.key}`} className="mb-1 block text-xs font-semibold text-slate-800">{field.label}{field.required ? ' *' : ' (optional)'}</label>
                            {field.type === 'multiselect' ? (
                                <select id={`application-${field.key}`} name={field.key} className={controlClass} multiple required={field.required} aria-invalid={Boolean(error)} value={Array.isArray(value) ? value : []} onChange={event => onChange(field.key, Array.from(event.target.selectedOptions, option => option.value))}>
                                    {field.options.map(option => <option key={option} value={option}>{option.replaceAll('_', ' ')}</option>)}
                                </select>
                            ) : field.type === 'select' ? (
                                <select id={`application-${field.key}`} name={field.key} className={controlClass} required={field.required} aria-invalid={Boolean(error)} value={String(value)} onChange={event => change(event.target.value)}>
                                    <option value="">Choose {field.label.toLowerCase()}</option>
                                    {value && !field.options.includes(String(value)) && <option value={String(value)} disabled>Previous value — choose an available option</option>}
                                    {field.options.map(option => <option key={option} value={option}>{option}</option>)}
                                </select>
                            ) : (
                                <input id={`application-${field.key}`} name={field.key} className={controlClass} type={field.type} readOnly={field.key === 'email'} required={field.required} value={String(value)} aria-invalid={Boolean(error)} aria-describedby={`application-${field.key}-error`}
                                    minLength={field.min_length ?? undefined} maxLength={field.max_length} inputMode={field.input_mode ?? undefined}
                                    onChange={event => change(event.target.value)} onBlur={() => setFeedback(previous => ({ ...previous, [field.key]: fieldError(field, String(value)) }))}
                                    onPaste={event => {
                                        const input = event.currentTarget;
                                        const proposed = input.value.slice(0, input.selectionStart ?? input.value.length) + event.clipboardData.getData('text') + input.value.slice(input.selectionEnd ?? input.value.length);
                                        if (proposed.length > field.max_length) {
                                            event.preventDefault();
                                            setFeedback(previous => ({ ...previous, [field.key]: `Use at most ${field.max_length} characters. No pasted text was added.` }));
                                        }
                                    }} />
                            )}
                            {field.type === 'tel' && <p className="mt-1 text-xs text-slate-500">{field.key === 'phone' ? 'Use your Philippine mobile number, such as 09171234567.' : 'Use a Philippine number; business landlines need an area code.'}</p>}
                            <InputError id={`application-${field.key}-error`} role="status" aria-live="polite" message={error} />
                        </div>
                    );
                })}
            </div>
            <InputError role="status" aria-live="polite" message={feedback.name || errors.name || application.errors.name?.[0]} />
        </details>
    );
}
