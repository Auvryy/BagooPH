import InputError from '@/Components/InputError';

export type ApplicationValues = Record<string, string | number | string[] | null>;
export interface ApplicationField {
    key: string;
    label: string;
    required: boolean;
    type: 'text' | 'email' | 'tel' | 'number' | 'select' | 'multiselect';
    options: string[];
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
    return (
        <details className="rounded-[12px] border border-slate-300 bg-white p-3" open={Object.keys(application.errors).length > 0 || Object.keys(errors).some(key => application.fields.some(field => field.key === key || key.startsWith(field.key + '.')))}>
            <summary className="cursor-pointer font-semibold text-slate-900">Correct your application details</summary>
            <p className="my-3 text-xs text-slate-600">Changes need a fresh admin review. Your original sign-in email stays with your account.</p>
            <div className="grid gap-3 sm:grid-cols-2">
                {application.fields.map(field => {
                    const value = values[field.key] ?? '';
                    const controlClass = 'w-full rounded-[12px] border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42]';
                    return (
                        <div key={field.key}>
                            <label htmlFor={`application-${field.key}`} className="mb-1 block text-xs font-semibold text-slate-800">{field.label}{field.required ? ' *' : ''}</label>
                            {field.type === 'multiselect' ? (
                                <select id={`application-${field.key}`} className={controlClass} multiple value={Array.isArray(value) ? value : []} onChange={event => onChange(field.key, Array.from(event.target.selectedOptions, option => option.value))}>
                                    {field.options.map(option => <option key={option} value={option}>{option.replaceAll('_', ' ')}</option>)}
                                </select>
                            ) : field.type === 'select' ? (
                                <select id={`application-${field.key}`} className={controlClass} value={String(value)} onChange={event => onChange(field.key, event.target.value)}>
                                    <option value="">Choose {field.label.toLowerCase()}</option>
                                    {value && !field.options.includes(String(value)) && <option value={String(value)} disabled>Previous value — choose an available option</option>}
                                    {field.options.map(option => <option key={option} value={option}>{option}</option>)}
                                </select>
                            ) : (
                                <input id={`application-${field.key}`} className={controlClass} type={field.type} readOnly={field.key === 'email'} value={String(value)} onChange={event => onChange(field.key, event.target.value)} maxLength={field.key.includes('address') ? 500 : field.key === 'name' || field.key.endsWith('_name') && !['shop_name', 'company_name'].includes(field.key) ? 100 : field.key === 'postal_code' ? 4 : 255} inputMode={field.key === 'postal_code' ? 'numeric' : undefined} />
                            )}
                            {field.type === 'tel' && <p className="mt-1 text-xs text-slate-500">Use a Philippine number; business landlines need an area code.</p>}
                            <InputError message={errors[field.key] || Object.entries(errors).find(([key]) => key.startsWith(field.key + '.'))?.[1] || application.errors[field.key]?.[0] || Object.entries(application.errors).find(([key]) => key.startsWith(field.key + '.'))?.[1]?.[0]} />
                        </div>
                    );
                })}
            </div>
        </details>
    );
}
