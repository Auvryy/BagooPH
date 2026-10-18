export function saleCountdown(endsAt: string, now: number): string {
    const remaining = Math.max(0, Math.ceil((new Date(endsAt).getTime() - now) / 1000));
    if (!Number.isFinite(remaining) || remaining === 0) return 'Updating sale';
    const days = Math.floor(remaining / 86400);
    const hours = Math.floor((remaining % 86400) / 3600);
    const minutes = Math.floor((remaining % 3600) / 60);
    const seconds = remaining % 60;
    return `${days ? `${days}d ` : ''}${[hours, minutes, seconds].map(value => String(value).padStart(2, '0')).join(':')}`;
}
