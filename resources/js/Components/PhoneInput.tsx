import React, { ChangeEvent } from 'react';
import InputError from '@/Components/InputError';

interface PhoneInputProps {
    id?: string;
    name?: string;
    value: string;
    onChange: (value: string) => void;
    label?: string;
    required?: boolean;
    error?: string;
    disabled?: boolean;
    placeholder?: string;
    accentColor?: 'primary' | 'emerald';
    className?: string;
    autoFocus?: boolean;
    helperText?: string;
}

/**
 * Format national mobile digits without removing or truncating invalid input.
 */
export function formatPhNationalNumber(digits: string): string {
    const clean = digits;
    if (!/^[0-9]*$/.test(clean) || clean.length > 10) return clean;
    if (!clean) return '';
    if (clean.length <= 3) return clean;
    if (clean.length <= 6) return `${clean.slice(0, 3)} ${clean.slice(3)}`;
    return `${clean.slice(0, 3)} ${clean.slice(3, 6)} ${clean.slice(6)}`;
}

/**
 * Remove one approved prefix and separators while keeping every entered digit.
 * Unsupported input remains visible for server-side validation.
 */
export function extractNationalDigits(value: string): string {
    if (!/^\+?[0-9 ()-]*$/.test(value)) return value;
    let clean = value.replace(/[ ()-]/g, '');
    if (clean.startsWith('+63')) clean = clean.slice(3);
    else if (clean.startsWith('+')) return value;
    else if (clean.startsWith('63') && clean.length > 2) clean = clean.slice(2);
    else if (clean.startsWith('0') && clean.length > 1) clean = clean.slice(1);
    return clean;
}

export default function PhoneInput({
    id = 'phone',
    name = 'phone',
    value,
    onChange,
    label,
    required = false,
    error,
    disabled = false,
    placeholder = '917 123 4567',
    accentColor = 'primary',
    className = '',
    autoFocus = false,
    helperText,
}: PhoneInputProps) {
    const nationalDigits = extractNationalDigits(value);
    const displayValue = formatPhNationalNumber(nationalDigits);

    const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
        const raw = e.target.value;
        const national = extractNationalDigits(raw);
        if (!/^[0-9]*$/.test(national)) {
            onChange(raw);
            return;
        }
        onChange(!national ? '' : national === '0' ? '0' : '+63 ' + formatPhNationalNumber(national));
    };

    const focusBorderClass =
        accentColor === 'emerald'
            ? 'focus-within:border-emerald-600 focus-within:ring-1 focus-within:ring-emerald-600'
            : 'focus-within:border-[#E00D42] focus-within:ring-1 focus-within:ring-[#E00D42]';

    return (
        <div className={className}>
            {label && (
                <div className="h-5 flex items-center mb-1">
                    <label
                        htmlFor={id}
                        className="text-xs font-semibold text-slate-800 uppercase tracking-wider font-sans truncate"
                    >
                        {label} {required && <span className="text-[#E00D42]">*</span>}
                    </label>
                </div>
            )}

            <div
                className={`relative flex items-stretch rounded-lg border border-slate-300 bg-white transition shadow-2xs ${focusBorderClass} ${
                    disabled ? 'opacity-60 cursor-not-allowed bg-slate-50' : ''
                }`}
            >
                {/* Fixed +63 Prefix Badge */}
                <div className="flex items-center gap-1.5 px-3 bg-slate-50 border-r border-slate-200 rounded-l-lg text-xs font-sans font-bold text-slate-700 select-none shrink-0">
                    <span className="text-slate-400 font-normal">PH</span>
                    <span className="text-slate-300">|</span>
                    <span className="text-slate-900">+63</span>
                </div>

                {/* Input Container */}
                <div className="relative flex-1 flex items-center">
                    <input
                        id={id}
                        type="tel"
                        inputMode="numeric"
                        name={name}
                        value={displayValue}
                        onChange={handleChange}
                        required={required}
                        disabled={disabled}
                        autoFocus={autoFocus}
                        placeholder={placeholder}
                        aria-invalid={Boolean(error)}
                        className="w-full pl-3 pr-3.5 py-2.5 text-sm bg-transparent border-0 outline-hidden font-sans text-slate-900 placeholder-slate-400 tracking-wider focus:ring-0"
                    />
                </div>
            </div>

            {helperText && !error && (
                <p className="text-[11px] text-slate-500 mt-1 font-sans">{helperText}</p>
            )}

            {error && <InputError message={error} className="mt-1" />}
        </div>
    );
}
