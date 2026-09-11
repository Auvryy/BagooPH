import React, { ChangeEvent, KeyboardEvent } from 'react';
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
}

/**
 * Format raw 10 digits into standard PH mobile format: 9XX XXX XXXX
 */
export function formatPhNationalNumber(digits: string): string {
    const clean = digits.replace(/\D/g, '').slice(0, 10);
    if (!clean) return '';
    if (clean.length <= 3) return clean;
    if (clean.length <= 6) return `${clean.slice(0, 3)} ${clean.slice(3)}`;
    return `${clean.slice(0, 3)} ${clean.slice(3, 6)} ${clean.slice(6)}`;
}

/**
 * Extract 10-digit national number from any string representation (e.g. "+63 917 123 4567" -> "9171234567")
 */
export function extractNationalDigits(value: string): string {
    let clean = (value || '').replace(/\D/g, '');
    // If prefixed with 63, strip 63
    if (clean.startsWith('63') && clean.length > 2) {
        clean = clean.slice(2);
    }
    // If starts with leading 0 (e.g. 0917...), strip leading 0
    if (clean.startsWith('0')) {
        clean = clean.slice(1);
    }
    return clean.slice(0, 10);
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
}: PhoneInputProps) {
    const nationalDigits = extractNationalDigits(value);
    const displayValue = formatPhNationalNumber(nationalDigits);

    const handleKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
        // Allow navigation and edit keys
        if (
            e.key === 'Backspace' ||
            e.key === 'Delete' ||
            e.key === 'Tab' ||
            e.key === 'Escape' ||
            e.key === 'Enter' ||
            e.key === 'ArrowLeft' ||
            e.key === 'ArrowRight' ||
            e.key === 'ArrowUp' ||
            e.key === 'ArrowDown' ||
            e.key === 'Home' ||
            e.key === 'End' ||
            (e.ctrlKey || e.metaKey)
        ) {
            return;
        }

        // Strictly reject any key that is not a numeric digit 0-9
        if (!/^[0-9]$/.test(e.key)) {
            e.preventDefault();
        }
    };

    const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
        const raw = e.target.value;
        const digits = extractNationalDigits(raw);

        if (!digits) {
            onChange('');
            return;
        }

        // Format to "+63 9XX XXX XXXX"
        const formattedNational = formatPhNationalNumber(digits);
        onChange(`+63 ${formattedNational}`);
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
                        className="text-xs font-semibold text-slate-800 uppercase tracking-wider font-mono truncate"
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
                <div className="flex items-center gap-1.5 px-3 bg-slate-50 border-r border-slate-200 rounded-l-lg text-xs font-mono font-bold text-slate-700 select-none shrink-0">
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
                        pattern="[0-9]*"
                        name={name}
                        value={displayValue}
                        onChange={handleChange}
                        onKeyDown={handleKeyDown}
                        disabled={disabled}
                        autoFocus={autoFocus}
                        placeholder={placeholder}
                        maxLength={12} // 10 digits + 2 space separators
                        className="w-full pl-3 pr-3.5 py-2.5 text-sm bg-transparent border-0 outline-hidden font-mono text-slate-900 placeholder-slate-400 tracking-wider focus:ring-0"
                    />
                </div>
            </div>

            {error && <InputError message={error} className="mt-1" />}
        </div>
    );
}
