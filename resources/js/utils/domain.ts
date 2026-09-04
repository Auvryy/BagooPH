/**
 * resources/js/utils/domain.ts
 * Resolves full cross-domain URLs for BagooPH portals.
 */
export function getDomainUrl(
    portal?: 'buyer' | 'seller' | 'courier' | 'hub' | 'admin' | null,
    path: string = '/'
): string {
    if (typeof window === 'undefined') {
        return path;
    }

    const { protocol, hostname, port } = window.location;
    const portSuffix = port ? `:${port}` : '';

    // Strip known subdomain prefixes to obtain the base domain
    let baseHostname = hostname;
    const prefixes = ['seller.', 'courier.', 'hub.', 'admin.', 'www.'];
    for (const prefix of prefixes) {
        if (baseHostname.startsWith(prefix)) {
            baseHostname = baseHostname.substring(prefix.length);
            break;
        }
    }

    const normalizedPath = path.startsWith('/') ? path : `/${path}`;

    if (!portal || portal === 'buyer') {
        return `${protocol}//${baseHostname}${portSuffix}${normalizedPath}`;
    }

    return `${protocol}//${portal}.${baseHostname}${portSuffix}${normalizedPath}`;
}
