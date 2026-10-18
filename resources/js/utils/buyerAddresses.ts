import { mobileNumberError } from '@/Components/PhoneInput';
import { personalNameError } from '@/utils/registrationValidation';
import { registrationTextError } from '@/utils/registrationFields';

export type BuyerAddressInput = { recipient_name: string; phone: string; region: string; province: string; city: string; barangay: string; street: string; postal_code: string };
export function buyerAddressErrors(data: BuyerAddressInput): Partial<Record<keyof BuyerAddressInput, string>> {
    const errors: Partial<Record<keyof BuyerAddressInput, string>> = {};
    const set = (field: keyof BuyerAddressInput, error?: string) => { if (error) errors[field] = error; };
    set('recipient_name', personalNameError(data.recipient_name, 'Recipient name', true, 100));
    set('phone', mobileNumberError(data.phone, true));
    set('street', registrationTextError(data.street, 5, 500, true));
    for (const field of ['region', 'province', 'city', 'barangay'] as const) if (!data[field].trim()) errors[field] = 'Choose a ' + (field === 'province' ? 'province or area' : field) + '.';
    if (data.postal_code && !/^[0-9]{4}$/.test(data.postal_code)) errors.postal_code = 'Use a four-digit Philippine postal code.';
    return errors;
}
