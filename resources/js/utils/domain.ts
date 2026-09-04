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

    // Check if hostname is an IPv4 or IPv6 address (e.g., 127.0.0.1 or ::1)
    // Raw IP addresses do not resolve subdomain DNS (seller.127.0.0.1) in browsers.
    const isIpAddress = /^(\d{1,3}\.){3}\d{1,3}$/.test(hostname) || hostname === '::1' || hostname === '[::1]';

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

    if (isIpAddress) {
        if (!portal || portal === 'buyer') {
            return `${protocol}//${baseHostname}${portSuffix}${normalizedPath}`;
        }
        const subPath = normalizedPath === '/' ? `/${portal}` : `/${portal}${normalizedPath}`;
        return `${protocol}//${baseHostname}${portSuffix}${subPath}`;
    }

    if (!portal || portal === 'buyer') {
        return `${protocol}//${baseHostname}${portSuffix}${normalizedPath}`;
    }

    return `${protocol}//${portal}.${baseHostname}${portSuffix}${normalizedPath}`;
}
