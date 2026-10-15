export interface AdvertisementMeasurementData { token: string; revision_id: number; endpoint: string }
export type AdvertisementEventType = 'impression' | 'click';
type SendEvent = (data: AdvertisementMeasurementData, type: AdvertisementEventType) => Promise<unknown> | void;

async function sendEvent(data: AdvertisementMeasurementData, type: AdvertisementEventType) {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const cookie = document.cookie?.split('; ').find((value) => value.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
    let xsrf: string | undefined;
    try { xsrf = cookie ? decodeURIComponent(cookie) : undefined; } catch { /* Fall back to the rendered token if the cookie is malformed. */ }
    await fetch(data.endpoint, {
        method: 'POST', credentials: 'same-origin', mode: 'same-origin', keepalive: true, cache: 'no-store',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : { 'X-CSRF-TOKEN': csrf }) },
        body: JSON.stringify({ token: data.token, type }),
    });
}

/** One instance belongs to one mounted public homepage, with no persistent visitor identifier. */
export class AdvertisementMeasurementSession {
    private attempted = new Set<string>();

    constructor(private readonly send: SendEvent = sendEvent) {}

    private event(data: AdvertisementMeasurementData, type: AdvertisementEventType) {
        const key = `${data.revision_id}:${type}`;
        if (this.attempted.has(key)) return;
        this.attempted.add(key);
        try { Promise.resolve(this.send(data, type)).catch(() => {}); } catch { /* Shopping remains available if optional reporting fails. */ }
    }

    click(data?: AdvertisementMeasurementData) {
        if (data) this.event(data, 'click');
    }

    observe(image: HTMLImageElement, data: AdvertisementMeasurementData): () => void {
        const doc = image.ownerDocument;
        const Observer = doc.defaultView?.IntersectionObserver;
        if (!Observer || this.attempted.has(`${data.revision_id}:impression`)) return () => {};
        let loaded = image.complete && image.naturalWidth > 0;
        let visible = false;
        let stopped = false;
        let timer: ReturnType<typeof setTimeout> | null = null;
        const eligible = () => !stopped && loaded && visible && doc.visibilityState === 'visible' && image.isConnected;
        const cancel = () => { if (timer !== null) { clearTimeout(timer); timer = null; } };
        const check = () => {
            if (!eligible()) { cancel(); return; }
            if (timer !== null || this.attempted.has(`${data.revision_id}:impression`)) return;
            timer = setTimeout(() => { timer = null; if (eligible()) this.event(data, 'impression'); }, 1000);
        };
        const observer = new Observer((entries) => {
            for (const entry of entries) if (entry.target === image) {
                visible = entry.isIntersecting && entry.intersectionRatio >= 0.5;
                check();
            }
        }, { threshold: [0, 0.5, 1] });
        const load = () => { loaded = image.naturalWidth > 0; check(); };
        const error = () => { loaded = false; cancel(); };
        image.addEventListener('load', load);
        image.addEventListener('error', error);
        doc.addEventListener('visibilitychange', check);
        observer.observe(image);
        return () => {
            stopped = true; cancel(); observer.disconnect();
            image.removeEventListener('load', load); image.removeEventListener('error', error);
            doc.removeEventListener('visibilitychange', check);
        };
    }
}
