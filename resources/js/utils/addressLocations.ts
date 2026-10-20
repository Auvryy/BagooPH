import axios from 'axios';

export type AddressLocation = { region: string; province: string; city: string; barangay: string };
export type LocationOption = { value: string; label: string };
export type AddressLocationOptions = { regions: LocationOption[]; provinces: LocationOption[]; cities: LocationOption[]; barangays: LocationOption[]; resolved: Omit<AddressLocation, 'barangay'> };
export const emptyLocationOptions: AddressLocationOptions = { regions: [], provinces: [], cities: [], barangays: [], resolved: { region: '', province: '', city: '' } };
const cachedOptions = new Map<string, AddressLocationOptions>();

export function changeAddressLocation(values: AddressLocation, field: keyof AddressLocation, value: string): AddressLocation {
    if (values[field] === value) return values;
    const next = { ...values, [field]: value };
    if (field === 'region') { next.province = ''; next.city = ''; next.barangay = ''; }
    if (field === 'province') { next.city = ''; next.barangay = ''; }
    if (field === 'city') next.barangay = '';
    return next;
}

export async function loadAddressLocations(values: AddressLocation, signal?: AbortSignal): Promise<AddressLocationOptions> {
    const params = { region: values.region || undefined, province: values.province || undefined, city: values.city || undefined };
    const key = JSON.stringify(params);
    const existing = cachedOptions.get(key);
    if (existing) return existing;
    const { data } = await axios.get<AddressLocationOptions>('/address-locations', { params, signal, timeout: 10000 });
    for (const field of ['regions', 'provinces', 'cities', 'barangays'] as const) {
        if (!Array.isArray(data[field]) || !data[field].every(item => typeof item.value === 'string' && typeof item.label === 'string')) throw new Error('Location options are unavailable.');
    }
    if (!data.resolved || !['region', 'province', 'city'].every(field => typeof data.resolved[field as keyof typeof data.resolved] === 'string')) throw new Error('Location options are unavailable.');
    if (cachedOptions.size >= 64) cachedOptions.delete(cachedOptions.keys().next().value!);
    cachedOptions.set(key, data);
    return data;
}
