import { CatalogueSnapshot } from './cataloguePrefetch';

/** Reviewed code destinations only. Private page data always comes from a visit. */
export function portalPageComponent(href: string, origin: string, page: CatalogueSnapshot & { component?: string }): string | undefined {
    let url: URL;
    try { url = new URL(href, origin); } catch { return; }
    if (url.origin !== origin || url.username || url.password) return;
    const role = page.props.auth?.user?.role;
    const path = url.pathname;
    if (role === 'buyer') {
        if (path === '/buyer/profile') return 'Buyer/Profile';
        if (['/buyer/orders', '/my-orders'].includes(path)) {
            const user = page.props.auth.user;
            return user.status !== 'active' && ['approved', 'verified'].includes(user.kyc_status) ? 'Buyer/Orders' : 'Buyer/Profile';
        }
        if (path === '/buyer/saved-products') return 'Buyer/SavedProducts';
    }
    if (role === 'seller') {
        const section = path.match(/^\/seller\/(dashboard|products|orders|preview)\/?$/)?.[1]
            ?? (url.hostname.startsWith('seller.') ? path.match(/^\/(dashboard|products|orders|preview)\/?$/)?.[1] : undefined);
        return ({ dashboard: 'Seller/Dashboard', products: 'Seller/Products', orders: 'Seller/Orders', preview: 'Marketplace/ShopDetail' } as Record<string, string>)[section ?? ''];
    }
    if (role === 'courier') {
        const section = path.match(/^\/courier\/(deliveries|earnings|profile)\/?$/)?.[1]
            ?? (url.hostname.startsWith('courier.') ? path.match(/^\/(deliveries|earnings|profile)\/?$/)?.[1] : undefined);
        return ({ deliveries: 'Courier/Deliveries', earnings: 'Courier/Earnings', profile: 'Courier/Profile' } as Record<string, string>)[section ?? ''];
    }
    if (role === 'logistics') {
        const section = path.match(/^\/hub\/(dashboard|network|fleet|deliveries|scan|counter|manifests)(?:\/\d+)?\/?$/)?.[1]
            ?? (url.hostname.startsWith('hub.') ? path.match(/^\/(dashboard|network|fleet|deliveries|scan|counter|manifests)(?:\/\d+)?\/?$/)?.[1] : undefined);
        // Only manifest detail has a numeric suffix; never treat action URLs as pages.
        if (section !== 'manifests' && /\/\d+\/?$/.test(path)) return;
        return ({ dashboard: 'Hub/Dashboard', network: 'Hub/Network', fleet: 'Hub/Fleet', deliveries: 'Hub/Deliveries', scan: 'Hub/ScanStation', counter: 'Hub/CounterPickup', manifests: 'Hub/Manifests' } as Record<string, string>)[section ?? ''];
    }
}

export type BuyerProfileTab = 'orders' | 'account' | 'addresses' | 'wallet' | 'vouchers';
export function buyerProfileProps(tab: BuyerProfileTab): string[] {
    const common = ['auth', 'user', 'canUsePortal', 'initialTab', 'flash', 'errors'];
    const sections: Record<BuyerProfileTab, string[]> = {
        orders: ['orders', 'ordersCount', 'orderCounts', 'currentOrderStatus'],
        account: ['identityCorrection', 'emailSettings'],
        addresses: ['addresses'],
        wallet: ['wallet'],
        vouchers: ['availableVouchers', 'availableVoucherCount', 'voucherCollection'],
    };
    return [...common, ...sections[tab]];
}
