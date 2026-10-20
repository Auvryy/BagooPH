export type ListingField = 'name' | 'description' | 'price' | 'stock';

export const listingHints: Record<ListingField, string> = {
    name: '5–120 characters.',
    description: '20–3,000 characters.',
    price: '₱1.00–₱1,000,000.00, with up to 2 decimal places.',
    stock: '0–99,999 whole units. Zero stock appears as sold out.',
};

export function sellerListingError(field: ListingField, value: string): string | undefined {
    if (value === '') return;
    const length = Array.from(value.trim()).length;
    if (field === 'name' && (length < 5 || length > 120)) return 'Use 5–120 characters for the product title.';
    if (field === 'description' && (length < 20 || length > 3000)) return 'Use 20–3,000 characters for the description.';
    if (field === 'price' && (!/^[0-9]+(?:\.[0-9]{1,2})?$/.test(value) || Number(value) < 1 || Number(value) > 1000000)) {
        return 'Enter ₱1.00–₱1,000,000.00 with up to 2 decimal places.';
    }
    if (field === 'stock' && (!/^[0-9]+$/.test(value) || Number(value) > 99999)) return 'Enter a whole number from 0 to 99,999.';
}
