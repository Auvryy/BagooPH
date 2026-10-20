import type { BirthDateLimits } from '@/Components/BirthDateInput';
import { mobileNumberError } from '@/Components/PhoneInput';
import { AuthRequestError, authRequest, registrationFormData, validEmailFormat } from '@/utils/authRequests';
import { passwordError } from '@/utils/passwordPolicy';
import { companyInitialsError, companyTinError, documentError, licenseNumberError, plateNumberError, registrationTextError } from '@/utils/registrationFields';

export type PersonalNameField = 'first_name' | 'middle_initial' | 'last_name';
export type PersonalNameValues = Record<PersonalNameField, string>;

export function personalNameError(value: string, label: string, required = true, maximum = 50): string | undefined {
    if (/[\p{C}\u034f\ufe00-\ufe0f]/u.test(value)) return 'Remove hidden characters from your name.';
    const name = value.normalize('NFKC').replace(/^ +| +$/g, '');
    if (!name) return required ? label + ' is required.' : undefined;
    const minimum = required ? 2 : 1;
    if (Array.from(name).length < minimum || Array.from(name).length > maximum) return label + ' must contain ' + minimum + ' to ' + maximum + ' characters.';
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
    return /^\p{Script=Latin}{1,2}\.?$/u.test(initial) ? undefined : 'Enter one or two letters, such as M. or MD.';
}

export function formatMiddleInitial(value: string): string {
    if (middleInitialError(value)) return value;
    const letters = value.normalize('NFKC').trim().replace(/\.$/, '');
    const uppercase = letters.toUpperCase();
    return letters ? (Array.from(uppercase).length <= 2 ? uppercase : letters) + '.' : '';
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

export function registrationInputErrors(data: Record<string, unknown>, limits: BirthDateLimits, step?: number, google = false): Record<string, string> {
    const errors: Record<string, string> = {};
    const value = (field: string) => typeof data[field] === 'string' ? data[field] as string : '';
    const checking = (fieldStep: number) => step === undefined || step === fieldStep;
    const role = String(data.role);
    const add = (field: string, error: string | undefined) => { if (error) errors[field] = error; };
    if (checking(1)) {
        for (const [field, label] of [['first_name', 'First name'], ['middle_initial', 'Middle initial'], ['last_name', 'Last name']] as const) {
            const error = field === 'middle_initial' ? middleInitialError(value(field)) : personalNameError(value(field), label);
            if (error) errors[field] = error;
        }
        if (!Object.keys(errors).length) {
            const error = combinedPersonalNameError({ first_name: value('first_name'), middle_initial: value('middle_initial'), last_name: value('last_name') });
            if (error) errors.name = error;
        }
        if (!value('sex').trim()) errors.sex = 'Sex is required. Choose an option.';
        else if (!['Female', 'Male', 'Other'].includes(value('sex'))) errors.sex = 'Choose an available option.';
        if (!google) {
            if (!value('email').trim()) errors.email = 'Email address is required.';
            else if (!validEmailFormat(value('email'))) errors.email = 'Enter a complete email address, such as name@example.com.';
        }
    }
    const worker = role !== 'buyer';
    if (checking(worker ? 1 : 2)) {
        const error = value('birthday') ? birthDateError(value('birthday'), limits, true) : 'Date of birth is required. You must be at least 18.';
        if (error) errors.birthday = error;
    }
    const locationStep = role === 'courier' ? 1 : role === 'logistics' ? 3 : 2;
    if (checking(locationStep)) {
        if (worker || !google || ['region', 'province', 'city', 'barangay', 'address'].some(field => value(field).trim())) {
            for (const [field, label] of [['region', 'Region'], ['province', 'Province or area'], ['city', 'City or municipality'], ['barangay', 'Barangay']] as const) {
                if (!value(field).trim()) errors[field] = 'Choose a ' + label.toLowerCase() + ' from the list.';
            }
        }
        for (const [field, label] of [['city', 'City or municipality'], ['address', 'Street address']] as const) {
            const required = worker || (role === 'buyer' && !google);
            add(field, !value(field).trim() && required ? label + ' is required.' : registrationTextError(value(field), field === 'address' ? 5 : 2, field === 'address' ? 500 : 255, required));
        }
        for (const field of ['province', 'municipality', 'barangay']) add(field, registrationTextError(value(field), 2, 255));
        if (value('postal_code') && !/^[0-9]{4}$/.test(value('postal_code'))) errors.postal_code = 'Use exactly 4 digits.';
    }
    const phoneStep = role === 'courier' || role === 'logistics' ? 1 : 2;
    if (checking(phoneStep)) {
        add('phone', mobileNumberError(value('phone'), worker));
    }
    const passwordStep = role === 'buyer' || role === 'logistics' ? 1 : 3;
    if (!google && checking(passwordStep)) {
        add('password', passwordError(value('password')));
        if (value('password_confirmation') !== value('password')) errors.password_confirmation = 'The passwords do not match.';
    }
    if (role === 'seller' && checking(1)) {
        add('shop_name', registrationTextError(value('shop_name'), 2, 255, true));
        if (!/^[1-9][0-9]*$/.test(String(data.root_category_id ?? ''))) errors.root_category_id = 'Choose a shop category.';
    }
    if (role === 'courier' && checking(2)) {
        if (!['Motorcycle', 'Scooter', 'Sedan / Van'].includes(value('vehicle_type'))) errors.vehicle_type = 'Choose an available vehicle.';
        add('plate_number', plateNumberError(value('plate_number'), value('vehicle_type')));
        add('license_number', licenseNumberError(value('license_number')));
    }
    if (role === 'logistics') {
        if (checking(1)) {
            add('company_name', registrationTextError(value('company_name'), 2, 255, true));
            add('company_code', companyInitialsError(value('company_code')));
            add('tin', companyTinError(value('tin')));
        }
        if (checking(2)) {
            if (!Array.isArray(data.service_areas) || data.service_areas.length < 1 || data.service_areas.length > 18 || new Set(data.service_areas).size !== data.service_areas.length) errors.service_areas = 'Choose at least one service region, without duplicates.';
            add('franchise_number', registrationTextError(value('franchise_number'), 2, 100, false, true));
            if (value('franchise_number').toUpperCase() === 'PENDING-LTFRB') errors.franchise_number = 'Enter your real franchise number or leave it blank.';
            add('operating_province', registrationTextError(value('operating_province'), 2, 255));
            const size = data.fleet_size;
            if (size !== '' && size !== null && size !== undefined && (!/^[1-9][0-9]{0,4}$/.test(String(size)) || Number(size) > 10000)) errors.fleet_size = 'Use a whole number from 1 to 10,000, or leave it blank.';
            if (!Array.isArray(data.vehicle_types) || data.vehicle_types.length > 3 || new Set(data.vehicle_types).size !== data.vehicle_types.length || data.vehicle_types.some(type => !['motorcycle', 'l300_van', 'wing_truck'].includes(type))) errors.vehicle_types = 'Choose from the available vehicle categories.';
        }
    }
    if (step === undefined) {
        const requiredDocuments = role === 'seller' ? ['id_document', 'business_permit'] : role === 'courier' ? ['id_document', 'driver_license', 'or_cr_document'] : role === 'logistics' ? ['business_permit'] : [];
        const fields = [...new Set(['id_document', ...requiredDocuments, ...(role === 'logistics' ? ['franchise_document'] : [])])];
        for (const field of fields) add(field, documentError(data[field] as File | null, requiredDocuments.includes(field)));
        if (data.policy_accepted !== true) errors.policy_accepted = 'Agree to the Terms of Use and acknowledge the Privacy Notice before creating your account.';
    }
    return errors;
}

export async function checkRegistration(data: Record<string, unknown>, limits: BirthDateLimits, step?: number): Promise<void> {
    const errors = registrationInputErrors(data, limits, step);
    if (Object.keys(errors).length) throw new AuthRequestError('Check the highlighted details before continuing.', 0, errors);
    const reply = await authRequest('/register/validate', registrationFormData(data, step));
    if (reply.valid !== true) throw new AuthRequestError('We could not confirm your details. Please try again.');
}
