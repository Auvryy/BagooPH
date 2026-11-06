export interface SellerListingDraft {
    form: Record<string, unknown>;
    gallery: Array<
        | { id: string; type: 'existing' | 'url'; url: string; name?: string }
        | { id: string; type: 'file'; name: string; size: number; mimeType: string; lastModified: number }
    >;
    variantState: Record<string, unknown>;
}

export interface SellerListingDraftImageRecord {
    key: string;
    scope: string;
    shopId: string;
    draftId: string;
    itemId: string;
    name: string;
    size: number;
    type: string;
    lastModified: number;
    blob: Blob;
}

export interface SellerListingDraftImageStore {
    replaceScope(scope: string, records: SellerListingDraftImageRecord[]): Promise<void>;
    readScope(scope: string): Promise<SellerListingDraftImageRecord[]>;
    clearScope(scope: string): Promise<void>;
}

export interface SellerListingDraftFileItem {
    id: string;
    type: string;
    file?: File;
}

const MAX_DRAFT_IMAGES = 9;
const MAX_DRAFT_IMAGE_BYTES = 5 * 1024 * 1024;
const MAX_DRAFT_IMAGE_TOTAL_BYTES = MAX_DRAFT_IMAGES * MAX_DRAFT_IMAGE_BYTES;
const DRAFT_IMAGE_MIME_TYPES = new Set(['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif']);

function draftImageScope(shopId: string | number, draftId: string): string {
    return `${encodeURIComponent(String(shopId))}:${encodeURIComponent(draftId)}`;
}

function draftImageKey(scope: string, itemId: string): string {
    return `${scope}:${encodeURIComponent(itemId)}`;
}

let draftImageDatabase: Promise<IDBDatabase> | null = null;

function openDraftImageDatabase(): Promise<IDBDatabase> {
    if (draftImageDatabase) return draftImageDatabase;
    if (!globalThis.indexedDB) return Promise.reject(new Error('IndexedDB is unavailable.'));

    const opening = new Promise<IDBDatabase>((resolve, reject) => {
        const request = globalThis.indexedDB.open('bagoo-seller-listing-draft-images', 1);
        request.onupgradeneeded = () => {
            const database = request.result;
            if (!database.objectStoreNames.contains('images')) {
                const images = database.createObjectStore('images', { keyPath: 'key' });
                images.createIndex('scope', 'scope', { unique: false });
            }
        };
        request.onsuccess = () => {
            const database = request.result;
            database.onversionchange = () => {
                database.close();
                draftImageDatabase = null;
            };
            resolve(database);
        };
        request.onerror = () => reject(request.error ?? new Error('Could not open image draft storage.'));
        request.onblocked = () => reject(new Error('Image draft storage is blocked by another tab.'));
    }).catch(error => {
        draftImageDatabase = null;
        throw error;
    });
    draftImageDatabase = opening;
    return opening;
}

function deleteDraftImageScope(transaction: IDBTransaction, scope: string, onDone: () => void): void {
    const images = transaction.objectStore('images');
    const request = images.index('scope').openCursor(IDBKeyRange.only(scope));
    request.onerror = () => transaction.abort();
    request.onsuccess = () => {
        const cursor = request.result;
        if (cursor) {
            cursor.delete();
            cursor.continue();
            return;
        }
        onDone();
    };
}

export const indexedDbSellerListingDraftImageStore: SellerListingDraftImageStore = {
    async replaceScope(scope, records) {
        const database = await openDraftImageDatabase();
        await new Promise<void>((resolve, reject) => {
            const transaction = database.transaction('images', 'readwrite');
            transaction.oncomplete = () => resolve();
            transaction.onerror = () => reject(transaction.error ?? new Error('Could not save image draft.'));
            transaction.onabort = () => reject(transaction.error ?? new Error('Could not save image draft.'));
            deleteDraftImageScope(transaction, scope, () => {
                try {
                    const images = transaction.objectStore('images');
                    records.forEach(record => images.put(record));
                } catch {
                    transaction.abort();
                }
            });
        });
    },
    async readScope(scope) {
        const database = await openDraftImageDatabase();
        return new Promise((resolve, reject) => {
            const transaction = database.transaction('images', 'readonly');
            const request = transaction.objectStore('images').index('scope').getAll(IDBKeyRange.only(scope));
            let records: SellerListingDraftImageRecord[] = [];
            transaction.oncomplete = () => resolve(records);
            transaction.onerror = () => reject(transaction.error ?? new Error('Could not read image draft.'));
            request.onerror = () => reject(request.error ?? new Error('Could not read image draft.'));
            transaction.onabort = () => reject(transaction.error ?? new Error('Could not read image draft.'));
            request.onsuccess = () => { records = request.result as SellerListingDraftImageRecord[]; };
        });
    },
    async clearScope(scope) {
        const database = await openDraftImageDatabase();
        await new Promise<void>((resolve, reject) => {
            const transaction = database.transaction('images', 'readwrite');
            transaction.oncomplete = () => resolve();
            transaction.onerror = () => reject(transaction.error ?? new Error('Could not clear image draft.'));
            transaction.onabort = () => reject(transaction.error ?? new Error('Could not clear image draft.'));
            deleteDraftImageScope(transaction, scope, () => undefined);
        });
    },
};

const imageDraftQueues = new Map<string, Promise<unknown>>();

function queueImageDraftChange<T>(scope: string, operation: () => Promise<T>): Promise<T> {
    const previous = imageDraftQueues.get(scope) ?? Promise.resolve();
    const next = previous.catch(() => undefined).then(operation);
    imageDraftQueues.set(scope, next);
    void next.finally(() => {
        if (imageDraftQueues.get(scope) === next) imageDraftQueues.delete(scope);
    }).catch(() => undefined);
    return next;
}

const FORM_FIELDS = [
    'name', 'category_id', 'price', 'compare_at_price', 'stock', 'purchase_limit', 'sku', 'description', 'status',
    'brand', 'condition', 'weight_kg', 'length_cm', 'width_cm', 'height_cm', 'tag_names', 'tag_draft',
];

const SIMPLE_FIELDS = new Set(FORM_FIELDS.filter(field => field !== 'tag_names'));

function safeForm(value: unknown): Record<string, unknown> {
    if (!value || typeof value !== 'object') return {};
    const source = value as Record<string, unknown>;
    const form: Record<string, unknown> = {};
    for (const field of FORM_FIELDS) {
        if (!Object.hasOwn(source, field)) continue;
        const input = source[field];
        if (field === 'tag_names' && Array.isArray(input)) {
            form[field] = input.filter((tag): tag is string => typeof tag === 'string' && tag.length <= 50).slice(0, 20);
        } else if (SIMPLE_FIELDS.has(field) && (input === null || ['string', 'number', 'boolean'].includes(typeof input))) {
            form[field] = input;
        }
    }
    return form;
}

function safePrimitive(value: unknown): string | number | boolean | null | undefined {
    return value === null || typeof value === 'string' || typeof value === 'number' && Number.isFinite(value) || typeof value === 'boolean'
        ? value as string | number | boolean | null
        : undefined;
}

function safeRows(value: unknown, fields: string[], maximum: number): Record<string, string | number | boolean | null>[] {
    if (!Array.isArray(value)) return [];
    return value.slice(0, maximum).flatMap(row => {
        if (!row || typeof row !== 'object') return [];
        const source = row as Record<string, unknown>;
        return [Object.fromEntries(fields.flatMap(field => {
            const item = safePrimitive(source[field]);
            return item === undefined ? [] : [[field, item]];
        }))];
    });
}

function safeGallery(value: unknown): SellerListingDraft['gallery'] {
    if (!Array.isArray(value)) return [];
    const gallery: SellerListingDraft['gallery'] = [];
    value.slice(0, 9).forEach((item, index) => {
        if (!item || typeof item !== 'object') return;
        const row = item as Record<string, unknown>;
        const id = typeof row.id === 'string' && row.id.length > 0 && row.id.length <= 200 ? row.id : `recovered_${index}`;
        if (row.type === 'file') {
            const file = row.file && typeof row.file === 'object' ? row.file as File : undefined;
            const name = typeof row.name === 'string' ? row.name : file?.name;
            const size = typeof row.size === 'number' ? row.size : file?.size;
            const mimeType = typeof row.mimeType === 'string' ? row.mimeType : file?.type;
            const lastModified = typeof row.lastModified === 'number' ? row.lastModified : file?.lastModified;
            if (typeof name !== 'string' || name.length > 255
                || typeof size !== 'number' || !Number.isInteger(size) || size < 1 || size > MAX_DRAFT_IMAGE_BYTES
                || typeof mimeType !== 'string' || !DRAFT_IMAGE_MIME_TYPES.has(mimeType.toLowerCase())
                || typeof lastModified !== 'number' || !Number.isFinite(lastModified) || lastModified < 0) return;
            gallery.push({ id, type: 'file', name, size, mimeType: mimeType.toLowerCase(), lastModified });
            return;
        }
        if (!['existing', 'url'].includes(String(row.type)) || typeof row.url !== 'string'
            || !row.url.trim() || row.url.startsWith('blob:')) return;
        gallery.push({ id, type: row.type as 'existing' | 'url', url: row.url,
            ...(typeof row.name === 'string' ? { name: row.name } : {}) });
    });
    return gallery;
}

function safeVariantState(value: unknown, gallery: SellerListingDraft['gallery']): Record<string, unknown> {
    if (!value || typeof value !== 'object') return {};
    const input = value as Record<string, unknown>;
    const sourceVariants = input.variants && typeof input.variants === 'object' ? input.variants as Record<string, unknown> : {};
    const galleryIds = new Set(gallery.map(item => item.id));
    const byUrl = new Map(gallery.flatMap((item, index) => 'url' in item ? [[item.url, index] as const] : []));
    const colors = Array.isArray(sourceVariants.colors) ? sourceVariants.colors.slice(0, 20).map((value) => {
        const color = value && typeof value === 'object' ? value as Record<string, unknown> : {};
        const imageUrl = typeof color.image_url === 'string' && !color.image_url.startsWith('blob:') ? color.image_url : null;
        const galleryItemId = typeof color.gallery_item_id === 'string' && galleryIds.has(color.gallery_item_id) ? color.gallery_item_id : null;
        const galleryIndex = galleryItemId ? gallery.findIndex(item => item.id === galleryItemId)
            : imageUrl ? (byUrl.get(imageUrl) ?? null) : null;
        const safeColor = Object.fromEntries(['id', 'name', 'hex', 'in_stock'].flatMap(field => {
            const item = safePrimitive(color[field]);
            return item === undefined ? [] : [[field, item]];
        }));
        return { ...safeColor, image_url: imageUrl, gallery_item_id: galleryItemId, gallery_index: galleryIndex };
    }) : [];
    const sizes = safeRows(sourceVariants.sizes, ['id', 'name', 'extra_price', 'stock'], 20);
    const combinations = safeRows(sourceVariants.combinations, ['color', 'size', 'stock', 'extra_price', 'price', 'sku'], 400);
    const variants = {
        ...(sourceVariants.pricing_mode === 'surcharge' || sourceVariants.pricing_mode === 'independent' ? { pricing_mode: sourceVariants.pricing_mode } : {}),
        ...(typeof sourceVariants.option1_name === 'string' || sourceVariants.option1_name === null ? { option1_name: sourceVariants.option1_name } : {}),
        ...(typeof sourceVariants.option2_name === 'string' || sourceVariants.option2_name === null ? { option2_name: sourceVariants.option2_name } : {}),
        colors, sizes,
        ...(sourceVariants.combinations !== undefined ? { combinations } : {}),
    };
    return {
        hasOption1: Boolean(input.hasOption1), option1Name: typeof input.option1Name === 'string' ? input.option1Name : 'Color / Edition',
        hasOption2: Boolean(input.hasOption2), option2Name: typeof input.option2Name === 'string' ? input.option2Name : 'Size / Specification',
        imageMode: input.imageMode === 'url' ? 'url' : 'file',
        urlInput: typeof input.urlInput === 'string' ? input.urlInput : '',
        variants,
        newColorName: typeof input.newColorName === 'string' ? input.newColorName : '',
        newColorHex: typeof input.newColorHex === 'string' ? input.newColorHex : '#111111',
        newSizeName: typeof input.newSizeName === 'string' ? input.newSizeName : '',
        newSizePrice: typeof input.newSizePrice === 'string' ? input.newSizePrice : '',
        draftPhoto: input.draftPhoto && typeof input.draftPhoto === 'object'
            ? (() => {
                const photo = input.draftPhoto as Record<string, unknown>;
                const galleryItemId = typeof photo.gallery_item_id === 'string' && galleryIds.has(photo.gallery_item_id) ? photo.gallery_item_id : null;
                const imageUrl = typeof photo.image_url === 'string' && !photo.image_url.startsWith('blob:') ? photo.image_url : '';
                if (!imageUrl && !galleryItemId) return null;
                return { image_url: imageUrl, gallery_item_id: galleryItemId,
                    gallery_index: galleryItemId ? gallery.findIndex(item => item.id === galleryItemId) : byUrl.get(imageUrl) ?? null };
            })()
            : null,
    };
}

export function saveSellerListingDraft(storage: Pick<Storage, 'setItem'> | undefined, key: string, payload: unknown): boolean {
    if (!storage || !payload || typeof payload !== 'object') return false;
    const input = payload as Record<string, unknown>;
    const form = safeForm(input.form);
    const gallery = safeGallery(input.gallery);
    const draft: SellerListingDraft = { form, gallery, variantState: safeVariantState(input.variantState, gallery) };
    try {
        storage.setItem(key, JSON.stringify({ version: 1, draft }));
        return true;
    } catch {
        return false;
    }
}

export function loadSellerListingDraft(storage: Pick<Storage, 'getItem'> | undefined, key: string): SellerListingDraft | null {
    if (!storage) return null;
    try {
        const value = storage.getItem(key);
        if (!value) return null;
        const parsed = JSON.parse(value) as Record<string, unknown>;
        if (parsed.version !== 1 || !parsed.draft || typeof parsed.draft !== 'object') return null;
        const draft = parsed.draft as Record<string, unknown>;
        const form = safeForm(draft.form);
        const gallery = safeGallery(draft.gallery);
        return { form, gallery, variantState: safeVariantState(draft.variantState, gallery) };
    } catch {
        return null;
    }
}

export function scheduleSellerListingDraftSave(
    schedule: (callback: () => void, delayMs: number) => number,
    cancel: (handle: number) => void,
    save: () => void,
): () => void {
    const handle = schedule(save, 3000);
    return () => cancel(handle);
}

export function registerSellerListingDraftExitFlush(
    target: Pick<Window, 'addEventListener' | 'removeEventListener'>,
    flush: () => void,
): () => void {
    const handler: EventListener = () => flush();
    target.addEventListener('pagehide', handler);
    target.addEventListener('beforeunload', handler);
    return () => {
        target.removeEventListener('pagehide', handler);
        target.removeEventListener('beforeunload', handler);
    };
}

export function clearSellerListingDraft(storage: Pick<Storage, 'removeItem'> | undefined, key: string): void {
    try { storage?.removeItem(key); } catch { /* Storage may be unavailable in private browsing. */ }
}

export async function saveSellerListingDraftImages(
    store: SellerListingDraftImageStore | undefined,
    shopId: string | number,
    draftId: string,
    gallery: readonly SellerListingDraftFileItem[],
): Promise<boolean> {
    if (!store) return gallery.every(item => item.type !== 'file');
    const scope = draftImageScope(shopId, draftId);
    const fileItems = gallery.filter(item => item.type === 'file');
    if (fileItems.length > MAX_DRAFT_IMAGES || new Set(fileItems.map(item => item.id)).size !== fileItems.length) return false;
    let totalBytes = 0;
    const records: SellerListingDraftImageRecord[] = [];
    for (const item of fileItems) {
        const file = item.file;
        const mimeType = file?.type.toLowerCase();
        if (!item.id || item.id.length > 200 || !file || typeof file.name !== 'string' || file.name.length > 255
            || !mimeType || !DRAFT_IMAGE_MIME_TYPES.has(mimeType) || !Number.isInteger(file.size) || file.size < 1 || file.size > MAX_DRAFT_IMAGE_BYTES
            || !Number.isFinite(file.lastModified) || file.lastModified < 0) return false;
        totalBytes += file.size;
        if (totalBytes > MAX_DRAFT_IMAGE_TOTAL_BYTES) return false;
        records.push({
            key: draftImageKey(scope, item.id), scope, shopId: String(shopId), draftId, itemId: item.id,
            name: file.name, size: file.size, type: mimeType, lastModified: file.lastModified, blob: file,
        });
    }
    try {
        await queueImageDraftChange(scope, () => store.replaceScope(scope, records));
        return true;
    } catch {
        return false;
    }
}

export async function restoreSellerListingDraftImages(
    store: SellerListingDraftImageStore | undefined,
    shopId: string | number,
    draftId: string,
    refs: readonly SellerListingDraft['gallery'][number][],
): Promise<{ available: boolean; files: Map<string, File>; missingIds: string[] }> {
    const fileRefs = refs.filter((item): item is Extract<SellerListingDraft['gallery'][number], { type: 'file' }> => item.type === 'file');
    if (!fileRefs.length) return { available: true, files: new Map(), missingIds: [] };
    if (!store || typeof globalThis.File !== 'function') {
        return { available: false, files: new Map(), missingIds: fileRefs.map(item => item.id) };
    }
    const scope = draftImageScope(shopId, draftId);
    try {
        await (imageDraftQueues.get(scope) ?? Promise.resolve()).catch(() => undefined);
        const records = await store.readScope(scope);
        const byId = new Map(records.map(record => [record.itemId, record]));
        const files = new Map<string, File>();
        const missingIds: string[] = [];
        for (const ref of fileRefs) {
            const record = byId.get(ref.id);
            if (!record || record.shopId !== String(shopId) || record.draftId !== draftId || record.scope !== scope
                || record.name !== ref.name || record.size !== ref.size || record.type !== ref.mimeType
                || record.lastModified !== ref.lastModified || record.blob.size !== ref.size || record.blob.type !== ref.mimeType) {
                missingIds.push(ref.id);
                continue;
            }
            files.set(ref.id, new File([record.blob], record.name, { type: record.type, lastModified: record.lastModified }));
        }
        return { available: true, files, missingIds };
    } catch {
        return { available: false, files: new Map(), missingIds: fileRefs.map(item => item.id) };
    }
}

export async function clearSellerListingDraftImages(
    store: SellerListingDraftImageStore | undefined,
    shopId: string | number,
    draftId: string,
): Promise<boolean> {
    if (!store) return true;
    const scope = draftImageScope(shopId, draftId);
    try {
        await queueImageDraftChange(scope, () => store.clearScope(scope));
        return true;
    } catch {
        return false;
    }
}
