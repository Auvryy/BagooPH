import InputError from '@/Components/InputError';

export default function BusinessContactInput({ value, onChange, label, required, error }: {
    value: string;
    onChange: (value: string) => void;
    label: string;
    required?: boolean;
    error?: string;
}) {
    return <div className="font-sans">
        <label className="mb-1 block text-xs font-semibold text-slate-800" htmlFor="business-contact">{label}{required ? ' *' : ''}</label>
        <input id="business-contact" type="tel" value={value} onChange={event => onChange(event.target.value)} required={required} placeholder="09171234567 or (02) 8123 4567" className="w-full rounded-[12px] border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42]" />
        <p className="mt-1 text-xs text-slate-500">Mobile or landline; include the area code for a landline.</p>
        <InputError message={error} />
    </div>;
}
