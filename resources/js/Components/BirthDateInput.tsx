import InputError from '@/Components/InputError';

export interface BirthDateLimits {
    today: string;
    past_maximum: string;
    adult_maximum: string;
}

interface BirthDateInputProps {
    value: string;
    maximum: string;
    onChange: (value: string) => void;
    error?: string;
    adult?: boolean;
}

export default function BirthDateInput({ value, maximum, onChange, error, adult = true }: BirthDateInputProps) {
    return (
        <div className="space-y-1 font-sans">
            <label htmlFor="birthday" className="block text-xs font-semibold uppercase tracking-wider text-slate-800">Date of birth *</label>
            <input
                id="birthday"
                name="birthday"
                type="date"
                autoComplete="bday"
                value={value}
                max={maximum}
                required
                aria-invalid={Boolean(error)}
                aria-describedby={error ? 'birthday-help birthday-error' : 'birthday-help'}
                onChange={event => onChange(event.target.value)}
                className="w-full rounded-[12px] border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42]"
            />
            <p id="birthday-help" className="text-xs text-slate-500">Use the birth date on your identity document.{adult ? ' You must be at least 18 for this role.' : ''}</p>
            <InputError id="birthday-error" message={error} />
        </div>
    );
}
