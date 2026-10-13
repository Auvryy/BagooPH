export interface ClaimWindow {
    status: string;
    expires_at: string;
    locked_until: string | null;
    code_issued_at: string | null;
}

export function claimWindow(claim: ClaimWindow, now: number): 'ready' | 'expired' | 'locked' | 'unavailable' {
    const expiry = Date.parse(claim.expires_at);
    const unlock = claim.locked_until ? Date.parse(claim.locked_until) : null;
    if (claim.status === 'expired') return 'expired';
    if (claim.status !== 'ready') return 'unavailable';
    if (!Number.isFinite(expiry) || now >= expiry) return 'expired';
    if (unlock !== null && (!Number.isFinite(unlock) || now < unlock)) return 'locked';
    return 'ready';
}

export function nextClaimBoundary(claim: ClaimWindow | null, now: number): number | null {
    if (!claim || claim.status !== 'ready') return null;
    const boundaries = [claim.expires_at, claim.locked_until].filter((value): value is string => Boolean(value))
        .map(value => Date.parse(value)).filter(time => Number.isFinite(time) && time > now);
    return boundaries.length ? Math.min(Math.min(...boundaries) - now + 1, 2_147_483_647) : null;
}

export function currentOrderSelection<T extends { id: number }>(rows: T[], selected: { id: number } | null): T | null {
    return selected ? rows.find(row => row.id === selected.id) ?? null : null;
}

export function currentCourierAction(target: { id: number; status: string }, pickups: Array<{ id: number; nextAction: string | null; tracking?: { notice: string | null } | null }>, deliveries: Array<{ id: number; nextAction: string | null; tracking?: { notice: string | null } | null }>): boolean {
    const task = (target.status === 'picked_up' ? pickups : deliveries).find(task => task.id === target.id);
    if (!task || task.tracking?.notice) return false;
    return target.status === 'picked_up' ? task.nextAction === 'confirm_pickup'
        : target.status === 'out_for_delivery' ? task.nextAction === 'start_delivery'
            : ['delivered', 'delivery_failed'].includes(target.status) && task.nextAction === 'complete_delivery';
}
