export function sessionDate(value: string | null): string {
    if (!value || !Number.isFinite(new Date(value).getTime())) return 'Not recorded';
    return new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(new Date(value));
}

export function sessionRequestToken(): string {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
    if (!globalThis.crypto?.getRandomValues) throw new Error('This browser cannot securely prepare the request. Update your browser and try again.');
    const bytes = new Uint8Array(16);
    globalThis.crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map(value => value.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function confirmedSessionResult(data: unknown): { message: string; affected_count: number; reference: string } {
    if (!data || typeof data !== 'object' || Array.isArray(data)) throw new Error('Sign-out could not be confirmed. Refresh login activity before retrying.');
    const value = data as Record<string, unknown>;
    if (typeof value.reference !== 'string' || !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value.reference)
        || !Number.isSafeInteger(value.affected_count) || Number(value.affected_count) < 0
        || typeof value.message !== 'string' || value.message.trim().length === 0 || value.message.length > 500) {
        throw new Error('Sign-out could not be confirmed. Refresh login activity before retrying.');
    }
    return value as { message: string; affected_count: number; reference: string };
}
