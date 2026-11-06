import type { Product } from '@/types';

export function percentagePrice(regular: number, percentage: number): number {
    return Math.max(1, Math.floor((Math.round(regular * 100) * (100 - percentage) + 50) / 100)) / 100;
}

export function selectedRegularPrice(product: Product, color: string | null, size: string | null): number {
    const row = product.variants?.combinations?.find(row => row.color === color && row.size === size);
    if (product.variants?.pricing_mode === 'independent' && row) return Number(row.price);
    const option = product.variants?.sizes?.find(option => option.name === size);
    return (Math.round(Number(product.price) * 100) + Math.round(Number(row?.extra_price ?? option?.extra_price ?? 0) * 100)) / 100;
}

export function saleUnitPrice(product: Product, regular: number): number {
    const sale = product.sale_offer?.active;
    if (!sale) return regular;
    const cents = Math.round(regular * 100);
    if (sale.discount_type === 'percentage' && sale.percentage != null) return percentagePrice(regular, sale.percentage);
    // The retained sale summary price is the minimum option price, including its adjustment.
    const minimum = Number(product.price_range?.regular_min ?? product.price);
    const reduction = Math.max(0, Math.round(minimum * 100) - Math.round(Number(sale.price) * 100));
    return Math.max(1, cents - reduction) / 100;
}

export interface VariantSelection { color: string | null; size: string | null }

export function firstAvailableVariantSelection(product: Product): VariantSelection | null {
    const combinations = product.variants?.combinations ?? [];
    const row = combinations.find(combination => Number(combination.stock) > 0) ?? combinations[0];
    if (row) return { color: row.color, size: row.size };

    const colors = product.variants?.colors ?? [];
    const sizes = product.variants?.sizes ?? [];
    if (!colors.length && !sizes.length) return null;
    const color = colors.find(option => option.in_stock !== false) ?? colors[0];
    const size = sizes.find(option => Number(option.stock ?? product.stock) > 0) ?? sizes[0];
    return { color: color?.name ?? null, size: size?.name ?? null };
}

export function defaultVariantRegularPrice(product: Product): number {
    const combinations = product.variants?.combinations ?? [];
    if (!combinations.length) return Number(product.price_range?.regular_min ?? product.price);

    const selection = firstAvailableVariantSelection(product)!;
    const price = selectedRegularPrice(product, selection.color, selection.size);
    return Number.isFinite(price) ? price : Number(product.price_range?.regular_min ?? product.price);
}

export function defaultVariantDisplayPrice(product: Product): number {
    if (!product.variants?.combinations?.length) {
        return Number(product.price_range?.min ?? product.sale_offer?.effective_price ?? product.price);
    }
    return saleUnitPrice(product, defaultVariantRegularPrice(product));
}
