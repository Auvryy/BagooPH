/** A confirmed delivery pin must be a complete, bounded decimal pair. */
export function validDeliveryPin(latitude: string | number | null, longitude: string | number | null): boolean {
    const valid = (value: string | number | null, maximum: number) => {
        if (value === null || value === '') return false;
        const text = String(value);
        return /^-?[0-9]+(?:\.[0-9]{1,7})?$/.test(text) && Number.isFinite(Number(text)) && Math.abs(Number(text)) <= maximum;
    };
    return valid(latitude, 90) && valid(longitude, 180);
}

export type CheckoutAddressField = 'shipping_address' | 'shipping_city' | 'shipping_province' | 'destination_barangay' | 'shipping_postal_code';

export function changedDeliveryAddress<T extends Record<CheckoutAddressField, string> & {
    shipping_latitude: string | number; shipping_longitude: string | number; saved_address_id: number | null; save_address: boolean; saved_address_location_token?: string | null;
}>(previous: T, field: CheckoutAddressField, value: string, canSave: boolean): T {
    if (previous[field] === value) return previous;
    return { ...previous, [field]: value, shipping_latitude: '', shipping_longitude: '',
        save_address: previous.saved_address_id !== null ? canSave : previous.save_address,
        saved_address_id: null, saved_address_location_token: null };
}
