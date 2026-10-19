/** Printed tracking codes and older Bagoo tracking-page QR labels identify the same parcel. */
export function waybillCode(value: string, origin: string): string | undefined {
    const text = value.replace(/^ +| +$/g, '');
    if (!text || text.length > 255 || /[^\x20-\x7e]/.test(text)) return;
    if (/^[A-Za-z0-9][A-Za-z0-9_-]*$/.test(text)) return text.toUpperCase();
    try {
        const current = new URL(origin);
        const url = new URL(text);
        const base = (host: string) => host.replace(/^(seller|courier|hub|admin)\./, '');
        const root = base(current.hostname);
        if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.search || url.hash
            || url.port !== current.port || ![root, ...['seller', 'courier', 'hub', 'admin'].map(prefix => `${prefix}.${root}`)].includes(url.hostname)) return;
        return url.pathname.match(/^\/track\/([A-Za-z0-9][A-Za-z0-9_-]{0,254})\/?$/)?.[1].toUpperCase();
    } catch { return; }
}

export function selectWaybill(values: Array<{ rawValue: string }>, origin: string): { code?: string; error?: string } {
    const codes = new Set(values.map(value => waybillCode(value.rawValue, origin)).filter((value): value is string => Boolean(value)));
    if (codes.size > 1) return { error: 'Several parcel codes are visible. Move closer and point at one code.' };
    if (codes.size === 1) return { code: [...codes][0] };
    return values.length ? { error: 'Point at the parcel tracking barcode or QR code, or enter its tracking number.' } : {};
}

export class WaybillRequestError extends Error {
    constructor(message: string, public status = 0) { super(message); }
}

/** Read the current cookie for every request, including after an Inertia sign-in. */
export function waybillCsrfHeaders(cookie: string, renderedToken: string): Record<string, string> {
    const encoded = cookie.split(';').map(part => part.trim()).find(part => part.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
    try {
        if (encoded) return { 'X-XSRF-TOKEN': decodeURIComponent(encoded) };
    } catch { /* A malformed cookie may fall back to the current rendered token. */ }
    return { 'X-CSRF-TOKEN': renderedToken };
}

export async function requestWaybill<T>(path: string, body: Record<string, unknown>, signal: AbortSignal): Promise<T> {
    if (!path.startsWith('/') || path.startsWith('//')) throw new WaybillRequestError('Open the scanner in the current portal.');
    let response: Response;
    try {
        response = await fetch(path, {
            method: 'POST', credentials: 'same-origin', mode: 'same-origin', cache: 'no-store', signal,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                ...waybillCsrfHeaders(document.cookie, document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '') },
            body: JSON.stringify(body),
        });
    } catch (error) {
        if (signal.aborted) throw error;
        throw new WaybillRequestError('We could not reach the scanner. Check your connection and try again.');
    }
    if (response.status === 419) throw new WaybillRequestError('Your session expired. Reload this scanner and sign in again if asked.', 419);
    if (response.status === 401 || response.redirected) throw new WaybillRequestError('Sign in again to continue scanning.', 401);
    let data;
    try { data = await response.json(); } catch { throw new WaybillRequestError('The scanner could not read the response. Try again shortly.', response.status); }
    if (!response.ok || data?.success !== true) {
        const fieldError = Object.values(data?.errors ?? {}).flat().find(value => typeof value === 'string');
        const message = response.status >= 500 ? 'The scanner is temporarily unavailable. Try again shortly.'
            : fieldError ?? data?.error ?? data?.message ?? 'The waybill could not be checked. Try again.';
        throw new WaybillRequestError(String(message), response.status);
    }
    if (data.return_case) {
        const item = data.return_case;
        if (data.confirmed !== false || typeof item.reference !== 'string' || item.reference.length > 40
            || typeof item.barcode !== 'string' || !/^BRT-[A-Z0-9]{20}$/.test(item.barcode)
            || typeof item.href !== 'string' || !/^\/buyer-returns\/[1-9][0-9]{0,17}$/.test(item.href)
            || typeof item.status !== 'string' || item.status.length > 40) {
            throw new WaybillRequestError('The return details were incomplete. Reload the return queue before continuing.');
        }
        return data as T;
    }
    if (!data.delivery?.guidance || !data.prompt || typeof data.delivery.tracking_number !== 'string') {
        throw new WaybillRequestError('The parcel details were incomplete. Scan the waybill again.');
    }
    return data as T;
}
