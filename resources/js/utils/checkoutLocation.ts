/** A confirmed delivery pin must be a complete, bounded decimal pair. */
export function validDeliveryPin(latitude: string | number | null, longitude: string | number | null): boolean {
    const valid = (value: string | number | null, maximum: number) => {
        if (value === null || value === '') return false;
        const text = String(value);
        return /^-?[0-9]+(?:\.[0-9]{1,7})?$/.test(text) && Number.isFinite(Number(text)) && Math.abs(Number(text)) <= maximum;
    };
    return valid(latitude, 90) && valid(longitude, 180);
}

export type CheckoutAddressField = 'shipping_address' | 'shipping_region' | 'shipping_city' | 'shipping_province' | 'destination_barangay' | 'shipping_postal_code';

export function defaultLogisticsCompanyId<T extends { id: number | string }>(companies: T[], selectedId: number | string | null | undefined): number | string | '' {
    if (selectedId !== null && selectedId !== undefined && selectedId !== ''
        && companies.some(company => String(company.id) === String(selectedId))) return selectedId;
    return companies[0]?.id ?? '';
}

export interface PickupHubOption {
    id: number | string;
    logistics_company_id: number | string;
    province: string;
    city_municipality: string;
    postal_codes?: string[] | null;
    coverage_barangays?: string[] | null;
}

const normalizePlace = (value: string | null | undefined): string =>
    (value ?? '').trim().toLowerCase().replace(/\s+/g, ' ');

const cityKey = (value: string | null | undefined): string => {
    const normalized = normalizePlace(value).replace(/[.,]/g, '');
    return normalized.replace(/^(?:city|municipality)\s+of\s+|\s+(?:city|municipality)$/g, '').trim();
};

export function matchingPickupHubs<T extends PickupHubOption>(
    hubs: T[],
    companyId: number | string | null | undefined,
    address: { province?: string; city?: string; postal_code?: string; barangay?: string },
): T[] {
    if (companyId === null || companyId === undefined || companyId === '') return [];
    const province = normalizePlace(address.province);
    const city = normalizePlace(address.city);
    const postal = (address.postal_code ?? '').trim();
    const barangay = normalizePlace(address.barangay);
    if (!province || !city || !/^[0-9]{4}$/.test(postal)) return [];

    return hubs.filter(hub => {
        if (String(hub.logistics_company_id) !== String(companyId)) return false;
        if (normalizePlace(hub.province) !== province) return false;
        const postalCodes = hub.postal_codes?.filter(Boolean) ?? [];
        if (postalCodes.length > 0) {
            if (!postalCodes.includes(postal)) return false;
        } else if (cityKey(hub.city_municipality) !== cityKey(city)) {
            return false;
        }
        const coverageBarangays = hub.coverage_barangays?.filter(Boolean) ?? [];
        if (coverageBarangays.length > 0 && barangay) {
            if (!coverageBarangays.some(covered => normalizePlace(covered) === barangay)) return false;
        }
        return true;
    });
}

export function defaultPickupHubId<T extends { id: number | string }>(hubs: T[], selectedHubId: number | string | null | undefined): number | string | '' {
    if (selectedHubId !== null && selectedHubId !== undefined && selectedHubId !== ''
        && hubs.some(hub => String(hub.id) === String(selectedHubId))) return selectedHubId;
    return hubs[0]?.id ?? '';
}

export function estimateSelfPickupFeeFromDoorstep(doorstepFee: number, model = 'dedicated_fleet'): number {
    const cents = Math.max(0, Math.round(doorstepFee * 100));
    const delivery = model === 'standard'
        ? Math.trunc(cents / 2)
        : Math.trunc(cents / 12) * 5 + Math.trunc(((cents % 12) * 5) / 12);
    return (cents - delivery) / 100;
}

export function withDoorstepDelivery<T extends { delivery_type: 'doorstep' | 'hub_self_pickup'; saved_address_id: number | null; save_address: boolean }>(previous: T, canSave: boolean): T {
    return { ...previous, delivery_type: 'doorstep', save_address: previous.saved_address_id === null && canSave ? true : previous.save_address };
}

export function changedDeliveryLocation<T extends Record<CheckoutAddressField, string> & {
    shipping_latitude: string | number; shipping_longitude: string | number; saved_address_id: number | null; save_address: boolean; saved_address_location_token?: string | null;
}>(previous: T, updates: Partial<Pick<T, CheckoutAddressField>>, canSave: boolean): T {
    const entries = (Object.entries(updates) as [CheckoutAddressField, string][]).filter(([field, value]) => previous[field] !== value);
    if (entries.length === 0) return previous;
    return {
        ...previous,
        ...Object.fromEntries(entries),
        shipping_latitude: '',
        shipping_longitude: '',
        save_address: previous.saved_address_id !== null ? canSave : previous.save_address,
        saved_address_id: null,
        saved_address_location_token: null,
    };
}

export function changedDeliveryAddress<T extends Record<CheckoutAddressField, string> & {
    shipping_latitude: string | number; shipping_longitude: string | number; saved_address_id: number | null; save_address: boolean; saved_address_location_token?: string | null;
}>(previous: T, field: CheckoutAddressField, value: string, canSave: boolean): T {
    return changedDeliveryLocation(previous, { [field]: value } as Partial<Pick<T, CheckoutAddressField>>, canSave);
}
