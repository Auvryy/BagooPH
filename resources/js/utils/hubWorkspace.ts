import type { HubPlace, HubStep } from '@/Components/Logistics/Workspace';

export function nextTransferHubs<T extends HubPlace>(hubs: T[], source: HubPlace | undefined): T[] {
    if (!source) return [];
    return hubs.filter(hub => hub.id !== source.id && (!source.logistics_company_id || !hub.logistics_company_id || hub.logistics_company_id === source.logistics_company_id) && (source.tier === 'local_bayan_hub'
        ? hub.tier === 'regional_mother_hub' && (!source.parent_hub_id || source.parent_hub_id === hub.id)
        : hub.tier === 'regional_mother_hub' || !hub.parent_hub_id || hub.parent_hub_id === source.id));
}

export function transferSteps(status: string, loaded = false): HubStep[] {
    const position = ({ draft: loaded ? 1 : 0, sealed: 2, dispatched: 3, received: 4, closed: 5 } as Record<string, number>)[status] ?? 0;
    return ['Load parcels', 'Seal list', 'Depart', 'Receive', 'Close'].map((title, index) => ({ title,
        detail: ['Sending hub', 'Lock contents', 'Actual handover', 'Arriving hub', 'Retain receipt'][index],
        state: position >= 5 || index < position ? 'done' : index === position ? 'current' : 'waiting' }));
}

export function bagSteps(status: string, loaded: boolean): HubStep[] {
    const position = status === 'received' ? 6 : status === 'opened' ? 5 : status === 'in_transit' ? 4 : loaded ? 3 : status === 'sealed' ? 2 : 0;
    return ['Pack parcels', 'Seal bag', 'Load truck', 'Depart', 'Verify seal', 'Receive'].map((title, index) => ({ title,
        state: position >= 6 || index < position ? 'done' : index === position ? 'current' : 'waiting' }));
}

export function hubPoint(hub: HubPlace): [number, number] | null {
    if (hub.latitude == null || hub.longitude == null) return null;
    const lat = Number(hub.latitude), lng = Number(hub.longitude);
    return Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180 && (lat !== 0 || lng !== 0) ? [lat, lng] : null;
}
