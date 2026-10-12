import type { ClipboardEvent } from 'react';

export const documentMaxBytes = 5 * 1024 * 1024;
export const documentAccept = '.jpg,.jpeg,.png,.webp,.pdf';
const documentTypes: Record<string, string> = { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp', pdf: 'application/pdf' };

export function uppercaseOperationalCode(value: string): string {
    return value.replace(/[a-z]/g, character => character.toUpperCase());
}

export function rejectOverlongRegistrationPaste(event: ClipboardEvent<HTMLFormElement>, onError: (field: string, message: string) => void): void {
    const input = event.target as HTMLInputElement;
    // Mobile/business components handle supported whole-number prefixes themselves.
    if (input.tagName !== 'INPUT' || input.type === 'file' || input.name === 'phone' || input.maxLength < 1) return;
    const pasted = event.clipboardData.getData('text');
    let proposed = input.value.slice(0, input.selectionStart ?? input.value.length) + pasted + input.value.slice(input.selectionEnd ?? input.value.length);
    if (input.type === 'email') proposed = proposed.replace(/^ +| +$/g, '');
    if (proposed.length > input.maxLength) {
        event.preventDefault();
        onError(input.name || (input.id === 'street-address' ? 'address' : input.id), `Use at most ${input.maxLength} characters. No pasted text was added.`);
    }
}

export function documentError(file: File | null | undefined, required = false): string | undefined {
    if (!file) return required ? 'Choose a verification document.' : undefined;
    if (file.size < 1 || file.size > documentMaxBytes) return 'Choose a non-empty document no larger than 5 MB (5,242,880 bytes).';
    const extension = file.name.split('.').pop()?.toLowerCase() ?? '';
    if (!documentTypes[extension] || (file.type && file.type !== documentTypes[extension])) return 'Choose a JPG, PNG, WEBP or PDF document.';
    return undefined;
}

export function companyInitialsError(value: string): string | undefined {
    return /^[A-Z]{2,10}$/i.test(value.replace(/^ +| +$/g, '')) ? undefined : 'Use 2–10 letters for your company initials, without numbers or symbols.';
}

export function plateNumberError(value: string, vehicleType: string): string | undefined {
    const raw = value.replace(/^ +| +$/g, '').toUpperCase();
    if (raw.length > 8 || !/^[A-Z0-9]+(?:[- ][A-Z0-9]+)?$/.test(raw)) return 'Use at most 7 letters and digits and one optional space or hyphen.';
    const plate = raw.replace(/[- ]/g, '');
    const pattern = vehicleType === 'Sedan / Van' ? /^[A-Z]{3}[0-9]{2,4}$/ : /^(?:[A-Z]{2}[0-9]{4,5}|[0-9]{3,4}[A-Z]{2}|(?=[A-Z0-9]{6}$)(?=.*[A-Z])(?=.*[0-9])[A-Z0-9]{6})$/;
    return pattern.test(plate) ? undefined : 'Enter the Philippine plate shown on your registration document for this vehicle.';
}

export function licenseNumberError(value: string): string | undefined {
    return /^(?:[A-Z][0-9]{2}-[0-9]{2}-[0-9]{6}|[A-Z][0-9]{10})$/i.test(value.replace(/^ +| +$/g, '')) ? undefined : 'Use the licence number on your card, such as N01-12-345678. Its 11 letters and digits without hyphens are also accepted.';
}

export function businessContactError(value: string, required = false): string | undefined {
    if (!value) return required ? 'Contact number is required.' : undefined;
    if (value.length > 24 || !/^\+?[0-9 ()-]+$/.test(value)) return 'Use a Philippine mobile or landline number with its area code.';
    let digits = value.replace(/[ ()-]/g, '');
    if (digits.startsWith('0')) digits = '+63' + digits.slice(1);
    else if (digits.startsWith('63')) digits = '+' + digits;
    return /^\+63(?:9[0-9]{9}|2[0-9]{8}|(?:3[2-68]|4[2-9]|5[2-6]|6[2-58]|7[24578]|8[2-8])[0-9]{7})$/.test(digits) ? undefined : 'Use a complete Philippine mobile or landline number with its area code.';
}

export function registrationTextError(value: string, minimum: number, maximum: number, required = false, code = false): string | undefined {
    if (/\p{C}|[\u034f\ufe00-\ufe0f]/u.test(value)) return 'Remove hidden characters from this field.';
    const text = value.normalize('NFKC').replace(/^ +| +$/g, '');
    if (!text) return required ? 'This field is required.' : undefined;
    if (Array.from(text).length < minimum || Array.from(text).length > maximum) return `Use ${minimum}–${maximum} characters.`;
    const pattern = code ? /^[A-Z0-9]+(?:-[A-Z0-9]+)*$/i : /^[\p{Script=Latin}\p{M}\p{N} #.,’'\-/()&:]+$/u;
    if (!pattern.test(text) || !/[\p{L}\p{N}]/u.test(text) || /(?:javascript|vbscript|data)\s*:|\bon[a-z]+\s*=/i.test(text)) return 'Use meaningful plain text without markup or unsupported symbols.';
    return undefined;
}
