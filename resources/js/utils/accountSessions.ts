import type { AccountSession, AccountSessionProps } from '@/types/accountSessions';

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

/** Only the two bounded list cursors can be sent back to the private endpoint. */
export function sessionListUrl(href = '/account/security'): string {
    const url = new URL(href, 'https://account.invalid');
    if (url.origin !== 'https://account.invalid' || url.pathname !== '/account/security' || url.username || url.password) {
        throw new Error('This login activity page is unavailable. Refresh the list and try again.');
    }
    const query = new URLSearchParams();
    for (const key of ['sessions_page', 'history_page']) {
        const value = url.searchParams.get(key);
        if (value === null) continue;
        if (!/^[1-9][0-9]{0,6}$/.test(value) || Number(value) > 1000000) throw new Error('Choose a valid login activity page.');
        query.set(key, value);
    }
    return '/account/security' + (query.size ? '?' + query.toString() : '');
}

/** An expired login can return HTML; never display it as a private account result. */
export function confirmedSessionPage(value: unknown, owner: number): AccountSessionProps {
    const data = value as AccountSessionProps | null;
    const nullableText = (text: unknown) => text === null || typeof text === 'string';
    const entry = (record: AccountSession) => record && typeof record.id === 'string' && typeof record.device === 'string'
        && typeof record.method === 'string' && ['web', 'rider'].includes(record.channel)
        && ['computer', 'phone', 'tablet', 'unknown'].includes(record.device_type)
        && ['active', 'signed_out', 'expired', 'ended'].includes(record.status)
        && [record.network, record.signed_in_at, record.last_seen_at, record.ended_at].every(nullableText)
        && typeof record.active === 'boolean' && typeof record.current === 'boolean';
    const list = (page: AccountSessionProps['sessions']) => page && Array.isArray(page.data) && page.data.length <= 12 && page.data.every(entry)
        && Number.isInteger(page.current_page) && page.current_page > 0 && Number.isInteger(page.last_page) && page.last_page > 0
        && Number.isInteger(page.total) && page.total >= 0 && Array.isArray(page.links)
        && page.links.every(link => (link.url === null || typeof link.url === 'string') && typeof link.label === 'string' && typeof link.active === 'boolean');
    if (!data || data.sessionOwnerId !== owner || !list(data.sessions) || !list(data.signIns)
        || typeof data.currentSessionId !== 'string' || typeof data.canConfirmByEmail !== 'boolean'
        || !Number.isInteger(data.historyDays) || data.historyDays < 1 || (data.lastSignIn !== null && !entry(data.lastSignIn))) {
        throw new Error('We could not load login activity for this account. Refresh the list and try again.');
    }
    return data;
}
