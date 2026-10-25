import { waybillCsrfHeaders } from '@/utils/waybillScan';
import { courierPath } from '@/utils/courier';

export interface RoadPreview { task: string; waybill: string; mode: string; lines: Array<Array<[number, number]>>; destination: [number, number]; observed_at: string; live_tracking: false }
export function readRoadPreview(value: unknown, task: string, waybill: string): RoadPreview {
    const data = value as RoadPreview | null;
    const point = (p: unknown): p is [number, number] => Array.isArray(p) && p.length === 2 && p.every(n => typeof n === 'number' && Number.isFinite(n)) && p[0] >= 4 && p[0] <= 22 && p[1] >= 116 && p[1] <= 128;
    let count = 0;
    if (!data || data.task !== task || data.waybill !== waybill || data.live_tracking !== false || !['motorcycle', 'scooter', 'drive'].includes(data.mode)
        || !point(data.destination) || !Number.isFinite(Date.parse(data.observed_at)) || !Array.isArray(data.lines) || data.lines.length < 1 || data.lines.length > 100
        || data.lines.some(line => !Array.isArray(line) || line.length < 2 || line.some(p => !point(p) || ++count > 10000))) throw new Error('The road preview could not be verified. Refresh the current stop.');
    return data;
}
export async function requestRoadPreview(task: string, waybill: string, status: string, fix: GeolocationPosition, signal: AbortSignal): Promise<RoadPreview> {
    if (!/^(pickup|final_mile)-[1-9][0-9]*$/.test(task)) throw new Error('Claim or refresh this parcel before requesting a road route.');
    const response = await fetch(courierPath(`/route-preview/${task}`), {
        method: 'POST', mode: 'same-origin', credentials: 'same-origin', cache: 'no-store', signal,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
            ...waybillCsrfHeaders(document.cookie, document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '') },
        body: JSON.stringify({ latitude: fix.coords.latitude, longitude: fix.coords.longitude, accuracy: fix.coords.accuracy,
            recorded_at: new Date(fix.timestamp).toISOString(), expected_status: status }),
    });
    if (response.redirected || response.status === 401 || response.status === 419) throw new Error('Your session changed. Refresh and sign in again before requesting directions.');
    let data;
    try { data = await response.json(); } catch { throw new Error('The road preview is unavailable. Use the stop address or retry.'); }
    if (!response.ok) throw new Error(response.status >= 500 ? 'Road previews are unavailable. Use the stop address or external directions.' : String(data.message ?? 'Refresh this parcel before requesting directions.'));
    return readRoadPreview(data, task, waybill);
}
