export function financeMoney(cents: number | string | null | undefined): string {
    if (cents === null || cents === undefined || (typeof cents === 'number' && !Number.isSafeInteger(cents))) return 'Unavailable';
    const value = String(cents);
    if (!/^-?\d+$/.test(value)) return 'Unavailable';
    const amount = BigInt(value);
    const absolute = amount < 0n ? -amount : amount;
    return `${amount < 0n ? '-' : ''}₱${(absolute / 100n).toLocaleString('en-PH')}.${String(absolute % 100n).padStart(2, '0')}`;
}

export function financeDate(value: string): string {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? 'Date unavailable' : date.toLocaleString('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' });
}

export function financeState(value: string): string {
    return value.replaceAll('_', ' ').replace(/^\w/, letter => letter.toUpperCase());
}
