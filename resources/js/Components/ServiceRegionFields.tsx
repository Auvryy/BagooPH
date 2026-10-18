import InputError from '@/Components/InputError';

export default function ServiceRegionFields({ options, values, onChange, error }: {
    options: Record<string, string>;
    values: string[];
    onChange: (values: string[]) => void;
    error?: string;
}) {
    return <fieldset className="space-y-2" aria-describedby="service-regions-help service-regions-error">
        <legend className="text-xs font-semibold text-slate-800">Service regions *</legend>
        <p id="service-regions-help" className="text-xs text-slate-600">Select every region your company serves. Your office address is entered separately.</p>
        <div className="grid max-h-64 grid-cols-1 gap-1 overflow-y-auto rounded-lg border border-slate-300 p-2 sm:grid-cols-2">
            {Object.entries(options).map(([code, label]) => <label key={code} className="flex cursor-pointer items-start gap-2 rounded-lg px-2 py-2 text-xs leading-relaxed text-slate-800 hover:bg-slate-50">
                <input type="checkbox" name="service_areas" value={code} checked={values.includes(code)} aria-invalid={Boolean(error)}
                    onChange={event => onChange(event.target.checked ? [...values, code] : values.filter(value => value !== code))}
                    className="mt-0.5 rounded border-slate-400 text-[#E00D42] focus:ring-[#E00D42]" />
                <span>{label}</span>
            </label>)}
        </div>
        <p className="text-xs text-slate-600">{values.length ? `Selected: ${values.map(code => options[code] ?? code).join(', ')}` : 'No service regions selected.'}</p>
        <InputError id="service-regions-error" message={error} role="status" aria-live="polite" />
    </fieldset>;
}
