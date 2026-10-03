export interface CourierScope {
    company?: string | null;
    hub?: string | null;
    hubCode?: string | null;
    hub_code?: string | null;
    barangay?: string | null;
    isAssigned?: boolean;
    isOperational?: boolean;
}

export interface CourierPlace {
    name?: string | null;
    address?: string | null;
    latitude?: number | null;
    longitude?: number | null;
    code?: string | null;
}

export interface CourierMapJob {
    key: string;
    trackingNumber: string;
    stage: string;
    stopLabel: string;
    place: CourierPlace;
    instruction: string;
    preview: boolean;
}

export function courierMapCoordinates(place: CourierPlace): [number, number] | null {
    const { latitude, longitude } = place;
    if (typeof latitude !== 'number' || typeof longitude !== 'number'
        || !Number.isFinite(latitude) || !Number.isFinite(longitude)
        || Math.abs(latitude) > 85.05112878 || Math.abs(longitude) > 180) return null;
    return [latitude, longitude];
}

export function selectCourierMapJob(jobs: CourierMapJob[], selectedKey: string | null): CourierMapJob | null {
    return jobs.find((job) => job.key === selectedKey) ?? jobs[0] ?? null;
}

export function courierPath(path: string, hostname = typeof window === 'undefined' ? '' : window.location.hostname): string {
    const relative = path.startsWith('/') ? path : `/${path}`;
    return hostname.startsWith('courier.') ? relative : `/courier${relative}`;
}

export function directionsUrl(place: CourierPlace): string | null {
    const { latitude, longitude } = place;
    const coordinatesValid = typeof latitude === 'number' && typeof longitude === 'number'
        && Number.isFinite(latitude) && Number.isFinite(longitude)
        && Math.abs(latitude) <= 90 && Math.abs(longitude) <= 180;
    const destination = coordinatesValid ? `${latitude},${longitude}` : place.address?.trim();
    if (!destination) return null;
    const params = new URLSearchParams({ api: '1', destination, dir_action: 'navigate' });
    return `https://www.google.com/maps/dir/?${params.toString()}`;
}

export function telephoneUrl(phone?: string | null): string | null {
    if (!phone) return null;
    const digits = phone.replace(/\D/g, '');
    if (digits.length < 7 || digits.length > 15) return null;
    return `tel:${phone.trim().startsWith('+') ? '+' : ''}${digits}`;
}

export function courierDate(value?: string | null): string {
    if (!value || !Number.isFinite(new Date(value).getTime())) return 'Not recorded';
    return new Intl.DateTimeFormat('en-PH', {
        month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Manila',
    }).format(new Date(value));
}

export function courierMoney(value: number | null): string {
    if (value === null || !Number.isFinite(value)) return 'Amount not provided';
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value);
}
