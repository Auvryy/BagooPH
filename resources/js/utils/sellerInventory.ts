export interface SellerInventoryFilters {
    search?: string;
    category_id?: string | number | null;
    status?: 'all' | 'active' | 'draft' | 'archived';
    stock?: 'all' | 'low_stock' | 'out_of_stock' | 'in_stock';
}

export function sellerInventoryQuery(filters: SellerInventoryFilters): Record<string, string> {
    const query: Record<string, string> = {};
    const search = filters.search?.trim();
    if (search) query.search = search;
    if (filters.category_id) query.category_id = String(filters.category_id);
    if (filters.status && filters.status !== 'all') query.status = filters.status;
    if (filters.stock && filters.stock !== 'all') query.stock = filters.stock;
    return query;
}

export function sellerVariantStock(stock: number | string | null | undefined, listingStock: number): number {
    return Number(stock ?? listingStock);
}

export function newListingStatus(submitterValue: string | null | undefined): 'active' | 'draft' {
    return submitterValue === 'draft' ? 'draft' : 'active';
}
