import InputError from '@/Components/InputError';
import { useState } from 'react';
import { businessContactError } from '@/utils/registrationFields';

export default function BusinessContactInput({ value, onChange, label, required, error }: {
    value: string;
    onChange: (value: string) => void;
    label: string;
    required?: boolean;
    error?: string;
}) {
    const [touched, setTouched] = useState(false);
    const [editError, setEditError] = useState<string>();
    const message = editError || (touched ? businessContactError(value, required) : undefined) || error;
    return <div className="font-sans">
        <label className="mb-1 block text-xs font-semibold text-slate-800" htmlFor="business-contact">{label}{required ? ' *' : ''}</label>
        <input id="business-contact" name="phone" type="tel" maxLength={24} value={value} aria-invalid={Boolean(message)} aria-describedby="business-contact-help business-contact-error"
            onChange={event => { setTouched(true); setEditError(undefined); onChange(event.target.value); }}
            onBlur={() => setTouched(true)} onPaste={event => {
                const pasted = event.clipboardData.getData('text');
                const input = event.currentTarget;
                if (value.length - ((input.selectionEnd ?? value.length) - (input.selectionStart ?? value.length)) + pasted.length > 24) {
                    event.preventDefault(); setEditError('Use at most 24 characters, including number formatting.');
                }
            }} required={required} placeholder="09171234567 or (02) 8123 4567" className="w-full rounded-[12px] border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42]" />
        <p id="business-contact-help" className="mt-1 text-xs text-slate-500">Mobile or landline; include the area code for a landline.</p>
        <InputError id="business-contact-error" message={message} />
    </div>;
}
