import type { BirthDateLimits } from '@/Components/BirthDateInput';
import { extractNationalDigits } from '@/Components/PhoneInput';
import { AuthRequestError, authRequest, registrationFormData, validEmailFormat } from '@/utils/authRequests';

export type PersonalNameField = 'first_name' | 'middle_initial' | 'last_name';
export type PersonalNameValues = Record<PersonalNameField, string>;

export function personalNameError(value: string, label: string, required = true): string | undefined {
    if (/[\p{C}\u034f\ufe00-\ufe0f]/u.test(value)) return 'Remove hidden characters from your name.';
    const name = value.normalize('NFKC').replace(/^ +| +$/g, '');
    if (!name) return required ? label + ' is required.' : undefined;
    const minimum = required ? 2 : 1;
    if (Array.from(name).length < minimum || Array.from(name).length > 50) return label + ' must contain ' + minimum + ' to 50 characters.';
    if (!/^[\p{Script=Latin}\p{M} .’'\-]+$/u.test(name) || !/\p{Script=Latin}/u.test(name)) {
        return 'Use letters, spaces, apostrophes, hyphens or periods. Numbers and other symbols are not allowed.';
    }
    return undefined;
}

export function combinedPersonalNameError(values: PersonalNameValues): string | undefined {
    const name = ['first_name', 'middle_initial', 'last_name'].map(field => values[field as PersonalNameField].normalize('NFKC').replace(/^ +| +$/g, '')).filter(Boolean).join(' ');
    return Array.from(name).length > 100 ? 'Keep your first, middle and last names within 100 characters in total.' : undefined;
}

export function middleInitialError(value: string): string | undefined {
    if (/\p{C}/u.test(value)) return 'Remove hidden characters from your middle initial.';
    const initial = value.normalize('NFKC').replace(/^ +| +$/g, '');
    if (!initial) return undefined;
    return /^\p{Script=Latin}\.?$/u.test(initial) ? undefined : 'Enter one letter, with an optional period, such as M or M.';
}

function birthDateError(value: string, limits: BirthDateLimits, adult: boolean): string | undefined {
    const match = /^([0-9]{4})-([0-9]{2})-([0-9]{2})$/.exec(value);
    if (!match || match[0] !== value) return 'Enter a real birth date in YYYY-MM-DD format.';
    const [, yearText, monthText, dayText] = match;
    const year = Number(yearText), month = Number(monthText), day = Number(dayText);
    const leap = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
    const days = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    if (year < 1 || month < 1 || month > 12 || day < 1 || day > days[month - 1]) return 'Enter a real birth date in YYYY-MM-DD format.';
    if (value >= limits.today) return 'Birth date must be before today.';
    if (adult && value > limits.adult_maximum) return 'You must be at least 18 years old for this account role.';
    return undefined;
}

export function registrationInputErrors(data: Record<string, unknown>, limits: BirthDateLimits, step?: number): Record<string, string> {
    const errors: Record<string, string> = {};
    const value = (field: string) => typeof data[field] === 'string' ? data[field] as string : '';
    const checking = (fieldStep: number) => step === undefined || step === fieldStep;
    const role = String(data.role);
    if (checking(1)) {
        for (const [field, label] of [['first_name', 'First name'], ['middle_initial', 'Middle initial'], ['last_name', 'Last name']] as const) {
            const error = field === 'middle_initial' ? middleInitialError(value(field)) : personalNameError(value(field), label);
            if (error) errors[field] = error;
        }
        if (!Object.keys(errors).length) {
            const error = combinedPersonalNameError({ first_name: value('first_name'), middle_initial: value('middle_initial'), last_name: value('last_name') });
            if (error) errors.name = error;
        }
        if (!value('email').trim()) errors.email = 'Email address is required.';
        else if (!validEmailFormat(value('email'))) errors.email = 'Enter a complete email address, such as name@example.com.';
    }
    const worker = role !== 'buyer';
    if (checking(worker ? 1 : 2) && (worker || value('birthday'))) {
        const error = birthDateError(value('birthday'), limits, worker);
        if (error) errors.birthday = error;
    }
    const locationStep = role === 'courier' ? 1 : role === 'logistics' ? 3 : 2;
    if (checking(locationStep)) {
        for (const [field, label] of [['city', 'City or municipality'], ['address', 'Street address']] as const) {
            if (!value(field).trim()) errors[field] = label + ' is required.';
        }
        if (role === 'buyer' && !value('sex')) errors.sex = 'Choose your sex.';
    }
    const phoneStep = role === 'courier' || role === 'logistics' ? 1 : 2;
    if (checking(phoneStep)) {
        if (worker && !value('phone').trim()) errors.phone = 'Contact number is required.';
        else if ((role === 'courier' || role === 'buyer') && value('phone')) {
            const national = extractNationalDigits(value('phone'));
            if (national.length !== 10 || !/^9[0-9]{9}$/.test(national)) errors.phone = 'Enter a valid 10-digit Philippine mobile number, starting with 9 after +63.';
        }
    }
    return errors;
}

export async function checkRegistration(data: Record<string, unknown>, limits: BirthDateLimits, step?: number): Promise<void> {
    const errors = registrationInputErrors(data, limits, step);
    if (Object.keys(errors).length) throw new AuthRequestError('Check the highlighted details before continuing.', 0, errors);
    const reply = await authRequest('/register/validate', registrationFormData(data, step));
    if (reply.valid !== true) throw new AuthRequestError('We could not confirm your details. Please try again.');
}
