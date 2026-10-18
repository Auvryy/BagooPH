import { redirectAuthenticatedSession } from './authSession';

export class AuthRequestError extends Error {
    constructor(message: string, public status = 0, public fields: Record<string, string> = {}, public cooldown = 0, public redirect?: string) {
        super(message);
    }
}

function isObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export async function authRequest(path: string, body: FormData | Record<string, unknown>, signal?: AbortSignal): Promise<Record<string, unknown>> {
    const csrf = typeof document === 'undefined' ? '' : document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    let response: Response;
    try {
        response = await fetch(path, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...(body instanceof FormData ? {} : { 'Content-Type': 'application/json' }) },
            body: body instanceof FormData ? body : JSON.stringify(body),
            signal,
        });
    } catch (error) {
        if (signal?.aborted) throw error;
        throw new AuthRequestError('We could not connect. Check your connection and try again.');
    }
    if (response.status === 419) {
        throw new AuthRequestError('Your session expired. Close this window, reload the page, and try again.', 419);
    }
    let data: unknown;
    try { data = await response.json(); }
    catch {
        throw new AuthRequestError('We could not read the response. Please try again shortly.', response.status);
    }
    if (!isObject(data)) throw new AuthRequestError('We could not read the response. Please try again shortly.', response.status);
    const destination = redirectAuthenticatedSession(data, response.status);
    if (destination) throw new AuthRequestError('Returning to your account.', response.status, {}, 0, destination);
    const fields: Record<string, string> = {};
    if (isObject(data.errors)) {
        for (const [key, messages] of Object.entries(data.errors)) {
            if (Array.isArray(messages) && typeof messages[0] === 'string') fields[key] = messages[0];
        }
    }
    if (!response.ok || data.success === false) {
        const retryAfter = Number(response.headers.get('Retry-After'));
        const cooldown = typeof data.cooldown === 'number' && Number.isFinite(data.cooldown) ? Math.max(0, Math.ceil(data.cooldown))
            : response.status === 429 && Number.isFinite(retryAfter) ? Math.max(0, Math.ceil(retryAfter)) : 0;
        const message = response.status >= 500
            ? 'We could not complete the request. Please try again shortly.'
            : Object.values(fields)[0] ?? (typeof data.message === 'string' ? data.message : 'We could not complete the request. Please try again.');
        throw new AuthRequestError(message, response.status, fields, cooldown);
    }
    return data;
}

export class AuthRequestScope {
    private active: AbortController | null = null;

    begin(): AbortController | null {
        if (this.active) return null;
        return this.active = new AbortController();
    }

    current(request: AbortController): boolean {
        return this.active === request && !request.signal.aborted;
    }

    finish(request: AbortController): void {
        if (this.active === request) this.active = null;
    }

    cancel(): void {
        this.active?.abort();
        this.active = null;
    }
}

export async function requestOtp(action: 'send' | 'verify', data: Record<string, unknown>, signal?: AbortSignal) {
    const reply = await authRequest('/api/otp/' + action, data, signal);
    if (reply.success !== true || (action === 'verify' && (typeof reply.token !== 'string' || !/^[A-Za-z0-9]{40}$/.test(reply.token)))) {
        throw new AuthRequestError('Verification could not be confirmed. Please try again.');
    }
    return reply;
}

export function normalizeRegistrationEmail(value: string): string {
    if (/\p{C}/u.test(value)) return value;
    return value.normalize('NFKC').replace(/^ +| +$/g, '').toLowerCase();
}

export function validEmailFormat(value: string): boolean {
    if (/[\p{C}]/u.test(value)) return false;
    const email = value.normalize('NFKC').replace(/^ +| +$/g, '');
    return /^[^\s@()<>[\]\\,;:"]+@[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?(?:\.[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?)+$/u.test(email) && Array.from(email).length <= 255;
}

export function registrationFormData(data: Record<string, unknown>, step?: number): FormData {
    const form = new FormData();
    const append = (key: string, value: unknown) => {
        if (value instanceof Blob) form.append(key, value, value instanceof File ? value.name : 'document');
        else if (Array.isArray(value)) value.forEach((item, index) => append(key + '[' + index + ']', item));
        else form.append(key, value === null || value === undefined ? '' : String(value));
    };
    for (const [key, value] of Object.entries(data)) {
        // Step checks do not upload evidence; the complete check validates it before OTP.
        if (step !== undefined && value instanceof Blob) continue;
        append(key, value);
    }
    if (step !== undefined) form.set('step', String(step));
    return form;
}

export function registrationErrors(_role: string, fields: Record<string, string>): Record<string, string> {
    const aliases: Record<string, string> = {
        middle_name: 'middle_initial',
        terms_version: 'policy_accepted', privacy_version: 'policy_accepted',
        shop_phone: 'phone', shop_address: 'address', shop_city: 'city',
        company_email: 'email', company_phone: 'phone', company_address: 'address',
        otp_token: 'request',
    };
    const errors: Record<string, string> = {};
    for (const [field, message] of Object.entries(fields)) {
        const target = aliases[field] ?? field.split('.')[0];
        errors[target] ??= message;
    }
    return errors;
}

export function registrationErrorStep(role: string, fields: Record<string, string>): number {
    const keys = Object.keys(registrationErrors(role, fields));
    if (keys.some(key => ['name', 'first_name', 'middle_initial', 'last_name', 'sex'].includes(key))) return 1;
    const identity = role === 'buyer' ? ['email', 'password', 'password_confirmation']
        : role === 'seller' ? ['birthday', 'shop_name', 'root_category_id', 'email']
        : role === 'courier' ? ['birthday', 'email', 'phone', 'address', 'city', 'province', 'municipality', 'barangay', 'postal_code']
        : ['birthday', 'company_name', 'company_code', 'tin', 'email', 'phone', 'password', 'password_confirmation'];
    if (keys.some(key => identity.includes(key))) return 1;
    if (role === 'buyer') return 2;
    const middle = role === 'seller' ? ['phone', 'address', 'city', 'province', 'municipality', 'barangay', 'postal_code']
        : role === 'courier' ? ['vehicle_type', 'plate_number', 'license_number']
        : ['operating_province', 'service_areas', 'franchise_number', 'fleet_size', 'vehicle_types'];
    return keys.some(key => middle.includes(key)) ? 2 : 3;
}
