export function authenticatedSessionTarget(data: unknown, status: number, origin: string): string | undefined {
    if (![200, 409].includes(status) || !data || typeof data !== 'object' || Array.isArray(data)) return;
    const reply = data as Record<string, unknown>;
    if (!['ALREADY_AUTHENTICATED', 'SESSION_UNAVAILABLE'].includes(String(reply.code)) || typeof reply.redirect !== 'string') return;
    const path = reply.redirect;
    if (!path || /[\s\p{C}\\]/u.test(path) || path.startsWith('//')) return;
    if (!path.startsWith('/') && !/^https?:\/\//.test(path)) return;
    try {
        const current = new URL(origin);
        const target = new URL(path, current);
        const base = (host: string) => host.replace(/^(seller|courier|hub|admin)\./, '');
        if (target.protocol !== current.protocol || target.port !== current.port || target.username || target.password) return;
        if (base(target.hostname) !== base(current.hostname)) return;
        // Only the known portal siblings of this origin can receive a session redirect.
        const host = base(current.hostname);
        if (![host, ...['seller', 'courier', 'hub', 'admin'].map(prefix => prefix + '.' + host)].includes(target.hostname)) return;
        return path;
    } catch { return; }
}

export function redirectAuthenticatedSession(data: unknown, status: number): string | undefined {
    if (typeof window === 'undefined') return;
    const target = authenticatedSessionTarget(data, status, window.location.origin);
    if (target) window.location.assign(target);
    return target;
}
