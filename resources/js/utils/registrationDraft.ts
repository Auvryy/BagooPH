export type RegistrationDraftRole = 'buyer' | 'seller' | 'courier' | 'logistics';

export interface RegistrationDraft {
    fields: Record<string, unknown>;
    step: number;
    verificationOpen: boolean;
}

const VERSION = 1;
const PREFIX = 'bagoo:registration-draft:v1:';
const MAX_DRAFT_LENGTH = 32_768;

const FIELD_ALLOWLIST: Record<RegistrationDraftRole, readonly string[]> = {
    buyer: [
        'first_name', 'middle_initial', 'last_name', 'email', 'birthday', 'age', 'sex', 'phone',
        'region', 'province', 'city', 'municipality', 'barangay', 'address', 'postal_code',
        'delivery_latitude', 'delivery_longitude',
    ],
    seller: [
        'first_name', 'middle_initial', 'last_name', 'birthday', 'sex', 'shop_name', 'root_category_id',
        'email', 'phone', 'address', 'postal_code', 'city', 'region', 'province', 'municipality',
        'barangay', 'pickup_latitude', 'pickup_longitude',
    ],
    courier: [
        'first_name', 'middle_initial', 'last_name', 'birthday', 'sex', 'email', 'phone', 'address',
        'postal_code', 'city', 'region', 'province', 'municipality', 'barangay', 'vehicle_type',
        'plate_number', 'license_number',
    ],
    logistics: [
        'first_name', 'middle_initial', 'last_name', 'birthday', 'sex', 'company_name', 'company_code',
        'tin', 'service_areas', 'email', 'phone', 'address', 'city', 'region', 'province', 'municipality',
        'barangay', 'postal_code', 'franchise_number', 'fleet_size', 'vehicle_types',
    ],
};

const ARRAY_FIELDS = new Set(['service_areas', 'vehicle_types']);
const NUMBER_FIELDS = new Set(['age', 'delivery_latitude', 'delivery_longitude', 'pickup_latitude', 'pickup_longitude']);
const MAX_STEPS: Record<RegistrationDraftRole, number> = { buyer: 2, seller: 3, courier: 3, logistics: 3 };

function storageForCurrentTab(): Storage | null {
    try {
        return typeof window === 'undefined' ? null : window.sessionStorage;
    } catch {
        return null;
    }
}

function keyFor(role: RegistrationDraftRole): string {
    return PREFIX + role;
}

function safeFields(role: RegistrationDraftRole, source: unknown): Record<string, unknown> {
    if (!source || typeof source !== 'object' || Array.isArray(source)) return {};

    const input = source as Record<string, unknown>;
    const fields: Record<string, unknown> = {};
    for (const field of FIELD_ALLOWLIST[role]) {
        const value = input[field];
        if (ARRAY_FIELDS.has(field)) {
            if (Array.isArray(value) && value.every(item => typeof item === 'string')) fields[field] = value.slice(0, 100);
            continue;
        }
        if (NUMBER_FIELDS.has(field)) {
            if (value === null || (typeof value === 'number' && Number.isFinite(value)) || (typeof value === 'string' && value.length <= 64)) {
                fields[field] = value;
            }
            continue;
        }
        if (typeof value === 'string' && value.length <= 2048) fields[field] = value;
    }
    return fields;
}

export function loadRegistrationDraft(role: RegistrationDraftRole): RegistrationDraft | null {
    const storage = storageForCurrentTab();
    if (!storage) return null;

    try {
        const stored = storage.getItem(keyFor(role));
        if (!stored || stored.length > MAX_DRAFT_LENGTH) return null;
        const parsed: unknown = JSON.parse(stored);
        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return null;

        const draft = parsed as Record<string, unknown>;
        if (draft.version !== VERSION || !Number.isInteger(draft.step)) return null;
        const step = Math.max(1, Math.min(MAX_STEPS[role], draft.step as number));
        const fields = safeFields(role, draft.fields);
        const verificationOpen = draft.verificationOpen === true && typeof fields.email === 'string' && fields.email.length > 0;
        return { fields, step, verificationOpen };
    } catch {
        return null;
    }
}

export function saveRegistrationDraft(
    role: RegistrationDraftRole,
    source: unknown,
    step: number,
    verificationOpen: boolean,
): void {
    const storage = storageForCurrentTab();
    if (!storage) return;

    try {
        const draft = JSON.stringify({
            version: VERSION,
            step: Math.max(1, Math.min(MAX_STEPS[role], Math.trunc(step) || 1)),
            verificationOpen: verificationOpen === true,
            fields: safeFields(role, source),
        });
        if (draft.length <= MAX_DRAFT_LENGTH) storage.setItem(keyFor(role), draft);
    } catch {
        // Storage may be disabled or full; registration should still work without a saved draft.
    }
}

export function clearRegistrationDraft(role: RegistrationDraftRole): void {
    const storage = storageForCurrentTab();
    if (!storage) return;
    try {
        storage.removeItem(keyFor(role));
    } catch {
        // Ignore unavailable storage; the account submission remains authoritative.
    }
}
