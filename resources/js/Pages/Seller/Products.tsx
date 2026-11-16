import VariantStockMatrix, { type StockCombination } from '@/Components/VariantStockMatrix';
import UploadHint from '@/Components/UploadHint';
import SellerListingDetails, { emptyListingDetails, type ListingDetails } from '@/Components/SellerListingDetails';
import React, { useCallback, useEffect, useState, useRef } from 'react';
import { createPortal } from 'react-dom';
import { Head, Link, useForm, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Badge, buttonClasses, inputClasses, labelClasses } from '@/Components/ui';
import type { BadgeTone } from '@/Components/ui';
import PortalLink from '@/Components/PortalLink';
import { visitPortal } from '@/utils/catalogueRuntime';
import useResolvedPage from '@/hooks/useResolvedPage';
import useBodyScrollLock from '@/hooks/useBodyScrollLock';
import ListingAssistantPanel from '@/Components/ListingAssistantPanel';
import { Category, PaginatedData, Product, Shop, StoreTag } from '@/types';
import { newListingStatus, sellerInventoryQuery, sellerVariantStock, SellerInventoryFilters } from '@/utils/sellerInventory';
import { acceptPriceDraft, acceptStockDraft, acceptWholeNumberDraft, listingHints, sellerListingError, type ListingField } from '@/utils/sellerListingValidation';
import { clearSellerListingDraft, clearSellerListingDraftImages, indexedDbSellerListingDraftImageStore, loadSellerListingDraft, restoreSellerListingDraftImages, saveSellerListingDraft, saveSellerListingDraftImages, scheduleSellerListingDraftSave, registerSellerListingDraftExitFlush, type SellerListingDraft } from '@/utils/sellerListingDraft';
import { 
    Package, 
    Plus, 
    Trash2, 
    Edit3, 
    X, 
    Check, 
    Search, 
    Star, 
    Layers, 
    Image as ImageIcon, 
    Tag, 
    DollarSign, 
    Box, 
    Upload, 
    Link as LinkIcon,
    AlertCircle, 
    GripVertical, 
    Sliders,
    Camera, Copy, ChevronRight
} from 'lucide-react';

const MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024; // 5MB
const ALLOWED_MIME_TYPES = [
    'image/jpeg',
    'image/png',
    'image/jpg',
    'image/webp',
    'image/gif'
];

function localDraftStorage(): Storage | undefined {
    try { return typeof window === 'undefined' ? undefined : window.localStorage; } catch { return undefined; }
}

interface GalleryItem {
    id: string;
    type: 'file' | 'existing' | 'url';
    file?: File;
    url: string;
    name?: string;
    size?: number;
    mimeType?: string;
    lastModified?: number;
    recoveryMissing?: boolean;
}

function restoreDraftGallery(draft: SellerListingDraft, files: Map<string, File>): { gallery: GalleryItem[]; missingIds: string[] } {
    const missingIds: string[] = [];
    const gallery = draft.gallery.map((item): GalleryItem => {
        if (item.type !== 'file') return { ...item };
        const file = files.get(item.id);
        if (!file) {
            missingIds.push(item.id);
            return { ...item, url: '', mimeType: item.mimeType, lastModified: item.lastModified, recoveryMissing: true };
        }
        return { id: item.id, type: 'file', file, url: URL.createObjectURL(file), name: item.name, size: item.size,
            mimeType: item.mimeType, lastModified: item.lastModified };
    });
    return { gallery, missingIds };
}

function revokeGalleryFilePreviews(gallery: GalleryItem[]): void {
    gallery.forEach(item => {
        if (item.type === 'file' && item.url.startsWith('blob:')) URL.revokeObjectURL(item.url);
    });
}

function restoreVariantImageReferences(state: Record<string, unknown>, gallery: GalleryItem[]): Record<string, unknown> {
    const variants = state.variants && typeof state.variants === 'object' ? state.variants as ProductVariants : undefined;
    const restoredVariants = variants ? {
        ...variants,
        colors: variants.colors.map(color => {
            const item = color.gallery_item_id ? gallery.find(candidate => candidate.id === color.gallery_item_id) : undefined;
            const indexed = !item && typeof color.gallery_index === 'number' ? gallery[color.gallery_index] : undefined;
            const match = item ?? indexed;
            return { ...color, image_url: match?.url || color.image_url || null,
                gallery_index: match ? gallery.indexOf(match) : color.gallery_index };
        }),
    } : undefined;
    const photo = state.draftPhoto && typeof state.draftPhoto === 'object'
        ? state.draftPhoto as { image_url?: string; gallery_item_id?: string | null; gallery_index?: number | null }
        : null;
    const photoItem = photo?.gallery_item_id ? gallery.find(item => item.id === photo.gallery_item_id)
        : photo && typeof photo.gallery_index === 'number' ? gallery[photo.gallery_index] : undefined;
    return {
        ...state,
        ...(restoredVariants ? { variants: restoredVariants } : {}),
        draftPhoto: photo ? (photoItem?.url || photo.image_url ? { ...photo, image_url: photoItem?.url || photo.image_url || '' } : null) : null,
    };
}

interface VariantColor {
    id: string;
    name: string;
    hex: string;
    image_url?: string | null;
    gallery_item_id?: string | null;
    gallery_index?: number | null;
    in_stock: boolean;
}

interface VariantSize {
    id: string;
    name: string;
    extra_price: number;
    stock: number;
}

interface ProductVariants {
    pricing_mode?: 'surcharge' | 'independent';
    combinations?: StockCombination[];
    option1_name?: string | null;
    option2_name?: string | null;
    colors: VariantColor[];
    sizes: VariantSize[];
}

interface Props {
    products: PaginatedData<Product>;
    categories: Category[];
    storeTags?: StoreTag[];
    shop: Shop;
    filters?: SellerInventoryFilters;
    bulkResults?: { id: number; name: string; result: string; reason: string }[];
}

export default function SellerProducts({ products, categories, shop, filters = {}, bulkResults = [], storeTags = [] }: Props) {
    const activeShopCategory = categories.find(c => Number(c.id) === Number(shop.root_category_id)) || shop.root_category;
    const departmentSubCategories = activeShopCategory?.sub_categories || [];
    useResolvedPage(products.current_page);
    const createDraftKey = `bagoo:seller-product-draft:v1:${shop.id}:create`;
    const editDraftKey = (productId: number) => `bagoo:seller-product-draft:v1:${shop.id}:product:${productId}`;
    const [isCreateOpen, setIsCreateOpen] = useState(false);
    const [editingProduct, setEditingProduct] = useState<Product | null>(null);
    const [createDraftAuxDirty, setCreateDraftAuxDirty] = useState(false);
    const [editDraftAuxDirty, setEditDraftAuxDirty] = useState(false);
    const [createDraftSaved, setCreateDraftSaved] = useState(false);
    const [editDraftSaved, setEditDraftSaved] = useState(false);
    const [createDraftNotice, setCreateDraftNotice] = useState('');
    const [editDraftNotice, setEditDraftNotice] = useState('');
    const [stockProduct, setStockProduct] = useState<Product | null>(null);
    const [productPendingRemoval, setProductPendingRemoval] = useState<Product | null>(null);
    const [isRemovingProduct, setIsRemovingProduct] = useState(false);
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [categoryFilter, setCategoryFilter] = useState(String(filters.category_id ?? ''));
    const [statusFilter, setStatusFilter] = useState<SellerInventoryFilters['status']>(filters.status ?? 'all');
    const [stockFilter, setStockFilter] = useState<SellerInventoryFilters['stock']>(filters.stock ?? 'all');
    const [filtering, setFiltering] = useState(false);
    const filteringGuard = useRef(false);
    const [filterError, setFilterError] = useState('');
    const listProps = ['copiedProduct', 'storeTags', 'bulkResults', 'products', 'filters', 'shop', 'categories', 'auth', 'flash', 'errors'];
    const createSubmitting = useRef(false);
    const draftRestoreSequence = useRef({ create: 0, edit: 0 });
    const discardingDraft = useRef({ create: false, editProductIds: new Set<number>() });
    const cancelDraftAutosave = useRef<{ create?: () => void; edit?: () => void }>({});
    const [selectedListings, setSelectedListings] = useState<number[]>([]);
    const [bulkAction, setBulkAction] = useState<'deactivate' | 'delete' | null>(null);
    const [actionPending, setActionPending] = useState(false);
    const listingActionGuard = useRef(false);
    const copyTokens = useRef(new Map<number, string>());
    useEffect(() => { setSelectedListings([]); setBulkAction(null); }, [products.current_page, products.data]);
    const toggleListing = (id: number) => setSelectedListings(ids => ids.includes(id) ? ids.filter(value => value !== id) : [...ids, id]);
    const newToken = () => {
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
        const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    };
    const duplicateListing = (product: Product) => {
        if (listingActionGuard.current) return;
        listingActionGuard.current = true; setActionPending(true); setFilterError('');
        const token = copyTokens.current.get(product.id) || newToken();
        copyTokens.current.set(product.id, token);
        router.post(route('seller.products.duplicate', product.id), { request_token: token }, { preserveScroll: true,
            onSuccess: page => { copyTokens.current.delete(product.id); const copy = page.props.copiedProduct as Product | null; if (copy) openEdit(copy); },
            onError: errors => setFilterError(Object.values(errors).join(' ')),
            onFinish: () => { listingActionGuard.current = false; setActionPending(false); },
        });
    };
    const runBulk = () => {
        if (!bulkAction || !selectedListings.length || listingActionGuard.current) return;
        listingActionGuard.current = true; setActionPending(true); setFilterError('');
        router.post(route('seller.products.bulk'), { action: bulkAction, product_ids: selectedListings }, { preserveScroll: true,
            onSuccess: () => { setSelectedListings([]); setBulkAction(null); },
            onError: errors => setFilterError(Object.values(errors).join(' ')),
            onFinish: () => { listingActionGuard.current = false; setActionPending(false); },
        });
    };
    const createDescriptionRef = useRef<HTMLTextAreaElement>(null);
    const editDescriptionRef = useRef<HTMLTextAreaElement>(null);

    // Create Modal Gallery & Mode States
    const [createImageMode, setCreateImageMode] = useState<'file' | 'url'>('file');
    const [createGallery, setCreateGallery] = useState<GalleryItem[]>([]);
    const [createUrlInput, setCreateUrlInput] = useState('');
    const [createFileError, setCreateFileError] = useState<string | null>(null);
    const createFileInputRef = useRef<HTMLInputElement>(null);
    const [createDragIndex, setCreateDragIndex] = useState<number | null>(null);
    const [createDragOverIndex, setCreateDragOverIndex] = useState<number | null>(null);

    // Edit Modal Gallery & Mode States
    const [editImageMode, setEditImageMode] = useState<'file' | 'url'>('file');
    const [editGallery, setEditGallery] = useState<GalleryItem[]>([]);
    const [editUrlInput, setEditUrlInput] = useState('');
    const [editFileError, setEditFileError] = useState<string | null>(null);
    const editFileInputRef = useRef<HTMLInputElement>(null);
    const [editDragIndex, setEditDragIndex] = useState<number | null>(null);
    const [editDragOverIndex, setEditDragOverIndex] = useState<number | null>(null);

    // Create Modal Variants State
    const [createHasOption1, setCreateHasOption1] = useState(false);
    const [createOption1Name, setCreateOption1Name] = useState('Color / Edition');
    const [createHasOption2, setCreateHasOption2] = useState(false);
    const [createOption2Name, setCreateOption2Name] = useState('Size / Specification');
    const [createVariants, setCreateVariants] = useState<ProductVariants>({ option1_name: null, option2_name: null, colors: [], sizes: [] });
    const [newCreateColorName, setNewCreateColorName] = useState('');
    const [optionDraftErrors, setOptionDraftErrors] = useState<Record<string, string>>({});
    const [newCreateColorHex, setNewCreateColorHex] = useState('#111111');
    const [newCreateSizeName, setNewCreateSizeName] = useState('');
    const [newCreateSizePrice, setNewCreateSizePrice] = useState('');

    // Edit Modal Variants State
    const [editHasOption1, setEditHasOption1] = useState(false);
    const [editOption1Name, setEditOption1Name] = useState('Color / Edition');
    const [editHasOption2, setEditHasOption2] = useState(false);
    const [editOption2Name, setEditOption2Name] = useState('Size / Specification');
    const [editVariants, setEditVariants] = useState<ProductVariants>({ option1_name: null, option2_name: null, colors: [], sizes: [] });
    const [newEditColorName, setNewEditColorName] = useState('');
    const [newEditColorHex, setNewEditColorHex] = useState('#111111');
    const [newEditSizeName, setNewEditSizeName] = useState('');
    const [newEditSizePrice, setNewEditSizePrice] = useState('');

    // Variant Photo Picker Modal State
    const [activePhotoPicker, setActivePhotoPicker] = useState<{
        isCreate: boolean;
        colorId: string; // color ID or 'draft'
    } | null>(null);
    useBodyScrollLock(Boolean(isCreateOpen || editingProduct || stockProduct || productPendingRemoval || bulkAction || activePhotoPicker));

    // Draft Photo state for Create and Edit modals
    const [createDraftPhoto, setCreateDraftPhoto] = useState<{
        image_url: string;
        gallery_item_id?: string | null;
        gallery_index?: number | null;
    } | null>(null);

    const [editDraftPhoto, setEditDraftPhoto] = useState<{
        image_url: string;
        gallery_item_id?: string | null;
        gallery_index?: number | null;
    } | null>(null);

    const markDraftAuxDirty = (isCreate: boolean) => isCreate ? setCreateDraftAuxDirty(true) : setEditDraftAuxDirty(true);

    const formatPrice = (val: string | number | undefined | null) => {
        const num = Number(val || 0);
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(num);
    };

    useEffect(() => {
        setSearchQuery(filters.search || '');
        setCategoryFilter(String(filters.category_id ?? ''));
        setStatusFilter(filters.status ?? 'all');
        setStockFilter(filters.stock ?? 'all');
    }, [filters.search, filters.category_id, filters.status, filters.stock]);

    const resizeDescription = (textarea: HTMLTextAreaElement | null) => {
        if (!textarea) return;
        textarea.style.height = 'auto';
        textarea.style.height = `${Math.min(textarea.scrollHeight, 240)}px`;
        textarea.style.overflowY = textarea.scrollHeight > 240 ? 'auto' : 'hidden';
    };

    const createForm = useForm<{
        name: string;
        category_id: string | number;
        sub_category_id: string | number;
        price: string;
        compare_at_price: string;
        stock: string;
        purchase_limit: string;
        sku: string;
        variants: string;
        featured_image: string;
        image_files: File[];
        gallery_manifest: string;
        description: string;
        status: 'active' | 'draft';
        request_token: string;
    } & ListingDetails>({
        ...emptyListingDetails,
        name: '',
        category_id: String(shop.root_category_id ?? ''),
        sub_category_id: '',
        price: '',
        compare_at_price: '',
        stock: '',
        purchase_limit: '',
        sku: '',
        variants: '',
        featured_image: '',
        image_files: [],
        gallery_manifest: '',
        description: '',
        status: 'active',
        request_token: '',
    });

    const editForm = useForm<{
        name: string;
        category_id: string | number;
        sub_category_id: string | number;
        price: string;
        compare_at_price: string;
        stock: string;
        purchase_limit: string;
        sku: string;
        variants: string;
        featured_image: string;
        image_files: File[];
        gallery_manifest: string;
        description: string;
        status: 'active' | 'draft' | 'archived';
        _method: string;
    } & ListingDetails>({
        ...emptyListingDetails,
        name: '',
        category_id: String(shop.root_category_id ?? ''),
        sub_category_id: '',
        price: '',
        compare_at_price: '',
        stock: '0',
        purchase_limit: '',
        sku: '',
        variants: '',
        featured_image: '',
        image_files: [],
        gallery_manifest: '',
        description: '',
        status: 'active',
        _method: 'PUT',
    });

    const stockForm = useForm<{
        mode: 'set' | 'add';
        quantity: string;
    }>({
        mode: 'set',
        quantity: '0',
    });

    const createDraftPayload = () => ({
        form: createForm.data, gallery: createGallery,
        variantState: { hasOption1: createHasOption1, option1Name: createOption1Name, hasOption2: createHasOption2,
            option2Name: createOption2Name, variants: createVariants, newColorName: newCreateColorName,
            newColorHex: newCreateColorHex, newSizeName: newCreateSizeName, newSizePrice: newCreateSizePrice,
            draftPhoto: createDraftPhoto, imageMode: createImageMode, urlInput: createUrlInput },
    });
    const editDraftPayload = () => ({
        form: editForm.data, gallery: editGallery,
        variantState: { hasOption1: editHasOption1, option1Name: editOption1Name, hasOption2: editHasOption2,
            option2Name: editOption2Name, variants: editVariants, newColorName: newEditColorName,
            newColorHex: newEditColorHex, newSizeName: newEditSizeName, newSizePrice: newEditSizePrice,
            draftPhoto: editDraftPhoto, imageMode: editImageMode, urlInput: editUrlInput },
    });
    const draftLifecycle = useRef<{
        createOpen: boolean; createDirty: boolean; createPayload: ReturnType<typeof createDraftPayload>;
        editProductId: number | null; editDirty: boolean; editPayload: ReturnType<typeof editDraftPayload>;
    }>({ createOpen: false, createDirty: false, createPayload: createDraftPayload(), editProductId: null, editDirty: false, editPayload: editDraftPayload() });
    draftLifecycle.current = {
        createOpen: isCreateOpen, createDirty: createForm.isDirty || createDraftAuxDirty, createPayload: createDraftPayload(),
        editProductId: editingProduct?.id ?? null, editDirty: editForm.isDirty || editDraftAuxDirty, editPayload: editDraftPayload(),
    };
    const persistCreateDraft = useCallback(async () => {
        const current = draftLifecycle.current;
        if (discardingDraft.current.create || !current.createDirty) return true;
        const textSaved = saveSellerListingDraft(localDraftStorage(), createDraftKey, current.createPayload);
        const imagesSaved = textSaved && await saveSellerListingDraftImages(indexedDbSellerListingDraftImageStore, shop.id, 'create', current.createPayload.gallery);
        if (discardingDraft.current.create) return false;
        const saved = textSaved && imagesSaved;
        setCreateDraftSaved(saved);
        setCreateDraftNotice(!textSaved ? 'This browser could not save the listing details. Keep this page open while you finish.'
            : !imagesSaved ? 'Listing details were saved, but browser storage could not keep the selected images. Remove any unavailable image and re-select it before publishing.'
                : current => current.startsWith('This browser could not save') || current.startsWith('Listing details were saved, but browser storage') ? '' : current);
        return saved;
    }, [createDraftKey, shop.id]);
    const persistEditDraft = useCallback(async () => {
        const current = draftLifecycle.current;
        if (current.editProductId === null || discardingDraft.current.editProductIds.has(current.editProductId) || !current.editDirty) return true;
        const key = `bagoo:seller-product-draft:v1:${shop.id}:product:${current.editProductId}`;
        const draftId = `product:${current.editProductId}`;
        const textSaved = saveSellerListingDraft(localDraftStorage(), key, current.editPayload);
        const imagesSaved = textSaved && await saveSellerListingDraftImages(indexedDbSellerListingDraftImageStore, shop.id, draftId, current.editPayload.gallery);
        if (discardingDraft.current.editProductIds.has(current.editProductId)) return false;
        const saved = textSaved && imagesSaved;
        setEditDraftSaved(saved);
        setEditDraftNotice(!textSaved ? 'This browser could not save the listing details. Keep this page open while you finish.'
            : !imagesSaved ? 'Listing details were saved, but browser storage could not keep the selected images. Remove any unavailable image and re-select it before publishing.'
                : current => current.startsWith('This browser could not save') || current.startsWith('Listing details were saved, but browser storage') ? '' : current);
        return saved;
    }, [shop.id]);
    const closeCreate = () => { draftRestoreSequence.current.create += 1; void persistCreateDraft(); setIsCreateOpen(false); };
    const closeEdit = () => { draftRestoreSequence.current.edit += 1; void persistEditDraft(); setEditingProduct(null); };
    const discardCreateDraft = async () => {
        if (!window.confirm('Discard this listing and its locally saved recovery data?')) return;
        discardingDraft.current.create = true;
        cancelDraftAutosave.current.create?.();
        cancelDraftAutosave.current.create = undefined;
        draftRestoreSequence.current.create += 1;
        clearSellerListingDraft(localDraftStorage(), createDraftKey);
        revokeGalleryFilePreviews(createGallery);
        setCreateGallery([]);
        setCreateDraftSaved(false);
        setCreateDraftAuxDirty(false);
        setCreateDraftNotice('');
        setIsCreateOpen(false);
        await clearSellerListingDraftImages(indexedDbSellerListingDraftImageStore, shop.id, 'create');
        clearSellerListingDraft(localDraftStorage(), createDraftKey);
        discardingDraft.current.create = false;
    };
    const discardEditDraft = async () => {
        if (!editingProduct || !window.confirm('Discard these listing edits and their locally saved recovery data?')) return;
        const productId = editingProduct.id;
        discardingDraft.current.editProductIds.add(productId);
        cancelDraftAutosave.current.edit?.();
        cancelDraftAutosave.current.edit = undefined;
        draftRestoreSequence.current.edit += 1;
        clearSellerListingDraft(localDraftStorage(), editDraftKey(productId));
        revokeGalleryFilePreviews(editGallery);
        setEditGallery([]);
        setEditDraftSaved(false);
        setEditDraftAuxDirty(false);
        setEditDraftNotice('');
        setEditingProduct(null);
        await clearSellerListingDraftImages(indexedDbSellerListingDraftImageStore, shop.id, `product:${productId}`);
        clearSellerListingDraft(localDraftStorage(), editDraftKey(productId));
        discardingDraft.current.editProductIds.delete(productId);
    };

    useEffect(() => {
        const flushOpenDrafts = () => {
            const current = draftLifecycle.current;
            if (!discardingDraft.current.create && current.createOpen && current.createDirty) {
                const textSaved = saveSellerListingDraft(localDraftStorage(), createDraftKey, current.createPayload);
                if (textSaved) void saveSellerListingDraftImages(indexedDbSellerListingDraftImageStore, shop.id, 'create', current.createPayload.gallery);
            }
            if (current.editProductId !== null && !discardingDraft.current.editProductIds.has(current.editProductId) && current.editDirty) {
                const textSaved = saveSellerListingDraft(localDraftStorage(), editDraftKey(current.editProductId), current.editPayload);
                if (textSaved) void saveSellerListingDraftImages(indexedDbSellerListingDraftImageStore, shop.id, `product:${current.editProductId}`, current.editPayload.gallery);
            }
        };
        return registerSellerListingDraftExitFlush(window, flushOpenDrafts);
    }, [createDraftKey, shop.id]);

    useEffect(() => {
        resizeDescription(createDescriptionRef.current);
    }, [createForm.data.description]);

    useEffect(() => {
        resizeDescription(editDescriptionRef.current);
    }, [editForm.data.description]);

    useEffect(() => {
        if (!isCreateOpen || (!createForm.isDirty && !createDraftAuxDirty)) return;
        setCreateDraftSaved(false);
        const cancel = scheduleSellerListingDraftSave(
            (callback, delay) => window.setTimeout(callback, delay),
            handle => window.clearTimeout(handle),
            persistCreateDraft,
        );
        cancelDraftAutosave.current.create = cancel;
        return () => {
            cancel();
            if (cancelDraftAutosave.current.create === cancel) cancelDraftAutosave.current.create = undefined;
        };
    }, [isCreateOpen, createForm.isDirty, createForm.data, createDraftAuxDirty, createGallery, createHasOption1,
        createOption1Name, createHasOption2, createOption2Name, createVariants, newCreateColorName, newCreateColorHex,
        newCreateSizeName, newCreateSizePrice, createDraftPhoto, createImageMode, createUrlInput, createDraftKey, persistCreateDraft]);

    useEffect(() => {
        if (!editingProduct || (!editForm.isDirty && !editDraftAuxDirty)) return;
        setEditDraftSaved(false);
        const cancel = scheduleSellerListingDraftSave(
            (callback, delay) => window.setTimeout(callback, delay),
            handle => window.clearTimeout(handle),
            persistEditDraft,
        );
        cancelDraftAutosave.current.edit = cancel;
        return () => {
            cancel();
            if (cancelDraftAutosave.current.edit === cancel) cancelDraftAutosave.current.edit = undefined;
        };
    }, [editingProduct, editForm.isDirty, editForm.data, editDraftAuxDirty, editGallery, editHasOption1,
        editOption1Name, editHasOption2, editOption2Name, editVariants, newEditColorName, newEditColorHex,
        newEditSizeName, newEditSizePrice, editDraftPhoto, editImageMode, editUrlInput, persistEditDraft]);

    // Real-time slashed price validation: compare_at_price must be strictly greater than price
    const isSlashedPriceInvalid = (priceVal: string | number, compareVal: string | number | undefined | null) => {
        if (!compareVal || String(compareVal).trim() === '') return false;
        const p = parseFloat(String(priceVal));
        const c = parseFloat(String(compareVal));
        if (isNaN(c) || isNaN(p)) return false;
        return c <= p;
    };

    const createSlashedPriceError = isSlashedPriceInvalid(createForm.data.price, createForm.data.compare_at_price);
    const editSlashedPriceError = isSlashedPriceInvalid(editForm.data.price, editForm.data.compare_at_price);

    const listingFeedback = (isCreate: boolean, field: ListingField) => {
        const form = isCreate ? createForm : editForm;
        const variants = isCreate ? createVariants : editVariants;
        const value = field === 'stock' && variants.combinations
            ? String(variants.combinations.reduce((total, row) => total + Number(row.stock || 0), 0)) : form.data[field];
        const message = form.errors[field] || sellerListingError(field, value);
        return <p role={message ? 'alert' : undefined} className={`mt-1.5 ${message ? 'text-[13px] text-brand-700' : 'text-xs text-ink-600'}`}>{message || listingHints[field]}</p>;
    };

    const variantFeedback = (isCreate: boolean) => Object.entries((isCreate ? createForm : editForm).errors)
        .find(([field]) => field === 'variants' || ['colors', 'sizes', 'combinations'].some(group => field === group || field.startsWith(`${group}.`)))?.[1];

    const reorderList = (list: GalleryItem[], start: number, end: number): GalleryItem[] => {
        const result = Array.from(list);
        const [removed] = result.splice(start, 1);
        result.splice(end, 0, removed);
        return result;
    };

    const handleFilesSelected = (files: FileList | null, isCreate: boolean) => {
        if (files && (isCreate ? createGallery : editGallery).length + files.length > 9) {
            (isCreate ? setCreateFileError : setEditFileError)('Use no more than 9 product images. Remove an image before adding another.');
            return;
        }
        if (isCreate) createForm.clearErrors('gallery_manifest');
        else editForm.clearErrors('gallery_manifest');
        if (!files || files.length === 0) return;
        const newItems: GalleryItem[] = [];
        let errorMsg: string | null = null;

        Array.from(files).forEach((file) => {
            if (file.size > MAX_FILE_SIZE_BYTES) {
                const sizeMb = (file.size / (1024 * 1024)).toFixed(2);
                errorMsg = `File "${file.name}" (${sizeMb} MB) exceeds 5MB limit and was rejected.`;
                return;
            }
            if (!ALLOWED_MIME_TYPES.includes(file.type.toLowerCase())) {
                errorMsg = `File "${file.name}" has an unsupported format. Please use PNG, JPG, JPEG, WEBP, or GIF.`;
                return;
            }
            newItems.push({
                id: `file_${Date.now()}_${Math.random().toString(36).substring(2, 9)}`,
                type: 'file',
                file,
                url: URL.createObjectURL(file),
                name: file.name,
                size: file.size,
            });
        });

        if (errorMsg) {
            if (isCreate) setCreateFileError(errorMsg);
            else setEditFileError(errorMsg);
        } else {
            if (isCreate) setCreateFileError(null);
            else setEditFileError(null);
        }

        if (newItems.length > 0) {
            markDraftAuxDirty(isCreate);
            if (isCreate) {
                setCreateGallery((prev) => [...prev, ...newItems]);
            } else {
                setEditGallery((prev) => [...prev, ...newItems]);
            }
        }
    };

    const handleAddUrl = (url: string, isCreate: boolean) => {
        const trimmed = url.trim();
        if (!trimmed) return;
        if ((isCreate ? createGallery : editGallery).length >= 9) {
            (isCreate ? setCreateFileError : setEditFileError)('Use no more than 9 product images.');
            return;
        }
        if (isCreate) createForm.clearErrors('gallery_manifest');
        else editForm.clearErrors('gallery_manifest');
        markDraftAuxDirty(isCreate);
        const item: GalleryItem = {
            id: `url_${Date.now()}_${Math.random().toString(36).substring(2, 9)}`,
            type: 'url',
            url: trimmed,
        };
        if (isCreate) {
            setCreateGallery((prev) => [...prev, item]);
            setCreateUrlInput('');
        } else {
            setEditGallery((prev) => [...prev, item]);
            setEditUrlInput('');
        }
    };

    const moveGalleryItem = (index: number, direction: 'left' | 'right' | 'top', isCreate: boolean) => {
        markDraftAuxDirty(isCreate);
        const setGallery = isCreate ? setCreateGallery : setEditGallery;
        setGallery((prev) => {
            if (direction === 'top') {
                return reorderList(prev, index, 0);
            }
            if (direction === 'left' && index > 0) {
                return reorderList(prev, index, index - 1);
            }
            if (direction === 'right' && index < prev.length - 1) {
                return reorderList(prev, index, index + 1);
            }
            return prev;
        });
    };

    const removeGalleryItem = (index: number, isCreate: boolean) => {
        markDraftAuxDirty(isCreate);
        const removed = (isCreate ? createGallery : editGallery)[index];
        if (removed?.type === 'file' && removed.url.startsWith('blob:')) URL.revokeObjectURL(removed.url);
        const setGallery = isCreate ? setCreateGallery : setEditGallery;
        setGallery((prev) => prev.filter((_, i) => i !== index));
    };

    const handleDragStart = (index: number, isCreate: boolean) => {
        if (isCreate) setCreateDragIndex(index);
        else setEditDragIndex(index);
    };

    const handleDragOver = (e: React.DragEvent, index: number, isCreate: boolean) => {
        e.preventDefault();
        if (isCreate) setCreateDragOverIndex(index);
        else setEditDragOverIndex(index);
    };

    const handleDrop = (index: number, isCreate: boolean) => {
        if (isCreate) {
            if (createDragIndex !== null && createDragIndex !== index) {
                markDraftAuxDirty(true);
                setCreateGallery((prev) => reorderList(prev, createDragIndex, index));
            }
            setCreateDragIndex(null);
            setCreateDragOverIndex(null);
        } else {
            if (editDragIndex !== null && editDragIndex !== index) {
                markDraftAuxDirty(false);
                setEditGallery((prev) => reorderList(prev, editDragIndex, index));
            }
            setEditDragIndex(null);
            setEditDragOverIndex(null);
        }
    };

    const handleDragEnd = (isCreate: boolean) => {
        if (isCreate) {
            setCreateDragIndex(null);
            setCreateDragOverIndex(null);
        } else {
            setEditDragIndex(null);
            setEditDragOverIndex(null);
        }
    };

    const openCreate = async () => {
        if (discardingDraft.current.create) return;
        discardingDraft.current.create = false;
        const restoreSequence = ++draftRestoreSequence.current.create;
        revokeGalleryFilePreviews(createGallery);
        setOptionDraftErrors({});
        setCreateFileError(null);
        if (createFileInputRef.current) {
            createFileInputRef.current.value = '';
        }
        createForm.reset();
        const requestToken = newToken();
        createForm.setData('request_token', requestToken);
        createForm.setDefaults('request_token', requestToken);
        const draft = loadSellerListingDraft(localDraftStorage(), createDraftKey);
        setCreateImageMode('file');
        setCreateGallery([]);
        setCreateUrlInput('');
        setCreateHasOption1(false);
        setCreateOption1Name('Color / Edition');
        setCreateHasOption2(false);
        setCreateOption2Name('Size / Specification');
        setCreateVariants({ option1_name: null, option2_name: null, colors: [], sizes: [] });
        setNewCreateColorName('');
        setNewCreateColorHex('#111111');
        setNewCreateSizeName('');
        setNewCreateSizePrice('');
        setCreateDraftPhoto(null);
        setCreateDraftAuxDirty(false);
        setCreateDraftSaved(false);
        setCreateDraftNotice('');
        if (draft) {
            const restoredImages = await restoreSellerListingDraftImages(indexedDbSellerListingDraftImageStore, shop.id, 'create', draft.gallery);
            if (draftRestoreSequence.current.create !== restoreSequence) return;
            const recovered = restoreDraftGallery(draft, restoredImages.files);
            const state = restoreVariantImageReferences(draft.variantState, recovered.gallery);
            createForm.setData(current => ({ ...current, ...(draft.form as Partial<typeof current>), request_token: requestToken, image_files: [], gallery_manifest: '' }));
            setCreateImageMode(state.imageMode === 'url' ? 'url' : 'file');
            setCreateGallery(recovered.gallery);
            setCreateUrlInput(String(state.urlInput ?? ''));
            setCreateHasOption1(Boolean(state.hasOption1));
            setCreateOption1Name(String(state.option1Name ?? 'Color / Edition'));
            setCreateHasOption2(Boolean(state.hasOption2));
            setCreateOption2Name(String(state.option2Name ?? 'Size / Specification'));
            if (state.variants && typeof state.variants === 'object') setCreateVariants(state.variants as ProductVariants);
            setNewCreateColorName(String(state.newColorName ?? ''));
            setNewCreateColorHex(String(state.newColorHex ?? '#111111'));
            setNewCreateSizeName(String(state.newSizeName ?? ''));
            setNewCreateSizePrice(String(state.newSizePrice ?? ''));
            setCreateDraftPhoto(state.draftPhoto && typeof state.draftPhoto === 'object' ? state.draftPhoto as { image_url: string; gallery_item_id?: string | null; gallery_index?: number | null } : null);
            setCreateDraftAuxDirty(true);
            setCreateDraftSaved(recovered.missingIds.length === 0);
            setCreateDraftNotice(recovered.missingIds.length
                ? `Recovered listing details, but ${recovered.missingIds.length} image${recovered.missingIds.length === 1 ? '' : 's'} could not be restored. Remove the unavailable image tiles and select those files again.`
                : `Recovered an unsaved draft from this device${recovered.gallery.some(item => item.type === 'file') ? ', including its selected images' : ''}.`);
        }
        if (draftRestoreSequence.current.create !== restoreSequence) return;
        setActivePhotoPicker(null);
        setIsCreateOpen(true);
    };

    const openEdit = async (product: Product) => {
        if (discardingDraft.current.editProductIds.has(product.id)) return;
        discardingDraft.current.editProductIds.delete(product.id);
        const restoreSequence = ++draftRestoreSequence.current.edit;
        revokeGalleryFilePreviews(editGallery);
        setOptionDraftErrors({});
        setEditDraftAuxDirty(false);
        setEditDraftSaved(false);
        setEditDraftNotice('');
        setEditImageMode('file');
        setEditFileError(null);
        setEditUrlInput('');

        const initialGallery: GalleryItem[] = [];
        if (product.images && product.images.length > 0) {
            product.images.forEach((img, idx) => {
                initialGallery.push({
                    id: `existing_${img.id || idx}_${Math.random().toString(36).substring(2, 7)}`,
                    type: 'existing',
                    url: img.image_url,
                });
            });
        } else if (product.featured_image) {
            initialGallery.push({
                id: `existing_primary_${Math.random().toString(36).substring(2, 7)}`,
                type: 'existing',
                url: product.featured_image,
            });
        }

        setEditGallery(initialGallery);

        if (editFileInputRef.current) {
            editFileInputRef.current.value = '';
        }

        const hasColors = Boolean(product.variants?.colors && product.variants.colors.length > 0);
        const hasSizes = Boolean(product.variants?.sizes && product.variants.sizes.length > 0);
        const opt1Name = product.variants?.option1_name || 'Color / Edition';
        const opt2Name = product.variants?.option2_name || 'Size / Specification';

        setEditHasOption1(hasColors);
        setEditOption1Name(opt1Name);
        setEditHasOption2(hasSizes);
        setEditOption2Name(opt2Name);

        const variantsData: ProductVariants = {
            pricing_mode: product.variants?.pricing_mode ?? 'surcharge',
            combinations: product.variants?.combinations,
            option1_name: hasColors ? opt1Name : null,
            option2_name: hasSizes ? opt2Name : null,
            colors: (product.variants?.colors || []).map((c, idx) => {
                const matchedItem = initialGallery.find(g => g.url === c.image_url);
                return {
                    id: c.id || `c_init_${idx}`,
                    name: c.name,
                    hex: c.hex,
                    image_url: c.image_url ?? null,
                    gallery_item_id: matchedItem?.id ?? null,
                    gallery_index: matchedItem ? initialGallery.indexOf(matchedItem) : (c.gallery_index ?? null),
                    in_stock: c.in_stock ?? true,
                };
            }),
            sizes: (product.variants?.sizes || []).map((s, idx) => ({
                id: s.id || `s_init_${idx}`,
                name: s.name,
                extra_price: Number(s.extra_price || 0),
                stock: sellerVariantStock(s.stock, product.stock),
            })),
        };
        setEditVariants(variantsData);
        setNewEditColorName('');
        setNewEditColorHex('#111111');
        setNewEditSizeName('');
        setNewEditSizePrice('');
        setEditDraftPhoto(null);
        setActivePhotoPicker(null);

        const editInitialData = {
            brand: product.brand ?? '', condition: product.condition ?? '', weight_kg: String(product.weight_kg ?? ''),
            length_cm: String(product.length_cm ?? ''), width_cm: String(product.width_cm ?? ''), height_cm: String(product.height_cm ?? ''),
            tag_names: (product.store_tags ?? []).map(tag => tag.name),
            tag_draft: '',
            name: product.name,
            category_id: String(product.category_id || ''),
            sub_category_id: String(product.sub_category_id || ''),
            price: product.status === 'draft' && Number(product.price) === 0 ? '' : String(product.price),
            compare_at_price: String(product.compare_at_price || ''),
            stock: String(product.stock),
            purchase_limit: String(product.purchase_limit ?? ''),
            sku: product.sku || '',
            variants: (hasColors || hasSizes) ? JSON.stringify(variantsData) : '',
            featured_image: product.featured_image || '',
            image_files: [],
            gallery_manifest: '',
            description: product.description,
            status: product.status as any,
            _method: 'PUT',
        };
        editForm.setData(editInitialData);
        editForm.setDefaults(editInitialData);
        const draft = loadSellerListingDraft(localDraftStorage(), editDraftKey(product.id));
        if (draft) {
            const restoredImages = await restoreSellerListingDraftImages(indexedDbSellerListingDraftImageStore, shop.id, `product:${product.id}`, draft.gallery);
            if (draftRestoreSequence.current.edit !== restoreSequence) return;
            const recovered = restoreDraftGallery(draft, restoredImages.files);
            const state = restoreVariantImageReferences(draft.variantState, recovered.gallery);
            editForm.setData(current => ({ ...current, ...(draft.form as Partial<typeof current>), image_files: [], gallery_manifest: '', _method: 'PUT' }));
            setEditImageMode(state.imageMode === 'url' ? 'url' : 'file');
            setEditGallery(recovered.gallery);
            setEditUrlInput(String(state.urlInput ?? ''));
            setEditHasOption1(Boolean(state.hasOption1));
            setEditOption1Name(String(state.option1Name ?? 'Color / Edition'));
            setEditHasOption2(Boolean(state.hasOption2));
            setEditOption2Name(String(state.option2Name ?? 'Size / Specification'));
            if (state.variants && typeof state.variants === 'object') setEditVariants(state.variants as ProductVariants);
            setNewEditColorName(String(state.newColorName ?? ''));
            setNewEditColorHex(String(state.newColorHex ?? '#111111'));
            setNewEditSizeName(String(state.newSizeName ?? ''));
            setNewEditSizePrice(String(state.newSizePrice ?? ''));
            setEditDraftPhoto(state.draftPhoto && typeof state.draftPhoto === 'object' ? state.draftPhoto as { image_url: string; gallery_item_id?: string | null; gallery_index?: number | null } : null);
            setEditDraftAuxDirty(true);
            setEditDraftSaved(recovered.missingIds.length === 0);
            setEditDraftNotice(recovered.missingIds.length
                ? `Recovered listing details, but ${recovered.missingIds.length} image${recovered.missingIds.length === 1 ? '' : 's'} could not be restored. Remove the unavailable image tiles and select those files again.`
                : `Recovered unsaved edits from this device${recovered.gallery.some(item => item.type === 'file') ? ', including its selected images' : ''}.`);
        }
        if (draftRestoreSequence.current.edit !== restoreSequence) return;
        setEditingProduct(product);
    };

    const handleCreate = (e: React.FormEvent) => {
        e.preventDefault();
        if (createSlashedPriceError || createSubmitting.current) {
            return;
        }
        const submitter = (e.nativeEvent as SubmitEvent).submitter as HTMLButtonElement | null;
        const status = newListingStatus(submitter?.value);
        if (createGallery.some(item => item.type === 'file' && !item.file)) {
            createForm.setError('gallery_manifest', 'Remove unavailable image tiles and select those files again before saving.');
            return;
        }
        if (createGallery.length > 9 || (status === 'active' && createGallery.length === 0)) {
            createForm.setError('gallery_manifest', 'Use 1–9 product images before publishing.');
            return;
        }

        const filesToUpload: File[] = [];
        const manifest = createGallery.map((item) => {
            if (item.type === 'file' && item.file) {
                const fileIndex = filesToUpload.length;
                filesToUpload.push(item.file);
                return { type: 'file', file_index: fileIndex };
            }
            return { type: item.type, url: item.url };
        });

        const hasActiveVariants = createHasOption1 || createHasOption2;
        const syncedVariants = hasActiveVariants ? {
            option1_name: createHasOption1 ? createOption1Name : null,
            option2_name: createHasOption2 ? createOption2Name : null,
            colors: createHasOption1 ? createVariants.colors.map((c) => {
                if (!c.image_url) {
                    return { ...c, gallery_index: null };
                }
                let matchIdx = -1;
                if (c.gallery_item_id) {
                    matchIdx = createGallery.findIndex(g => g.id === c.gallery_item_id);
                }
                if (matchIdx === -1 && c.image_url) {
                    matchIdx = createGallery.findIndex(g => g.url === c.image_url);
                }
                return {
                    ...c,
                    gallery_index: matchIdx !== -1 ? matchIdx : null,
                };
            }) : [],
            sizes: createHasOption2 ? createVariants.sizes : [],
            combinations: createVariants.combinations,
            pricing_mode: createVariants.pricing_mode ?? 'independent',
        } : null;

        const submissionToken = createForm.data.request_token || newToken();
        if (!createForm.data.request_token) createForm.setData('request_token', submissionToken);
        createForm.transform((data) => {
            const { tag_draft: tagDraft, ...formData } = data;
            const tagNames = [...data.tag_names, ...(tagDraft.trim() ? [tagDraft.trim()] : [])];
            return {
                ...formData,
                tag_names: JSON.stringify(tagNames),
                request_token: submissionToken,
                status,
                gallery_manifest: JSON.stringify(manifest),
                image_files: filesToUpload,
                featured_image: createGallery[0]?.url || '',
                variants: syncedVariants ? JSON.stringify(syncedVariants) : '',
            };
        });

        createSubmitting.current = true;
        createForm.post(route('seller.products.store'), {
            forceFormData: true,
            onFinish: () => { createSubmitting.current = false; },
            onSuccess: () => {
                clearSellerListingDraft(localDraftStorage(), createDraftKey);
                void clearSellerListingDraftImages(indexedDbSellerListingDraftImageStore, shop.id, 'create');
                revokeGalleryFilePreviews(createGallery);
                setCreateDraftSaved(false);
                setCreateDraftAuxDirty(false);
                setCreateDraftNotice('');
                setIsCreateOpen(false);
                createForm.reset();
                createForm.setData('request_token', newToken());
                setCreateGallery([]);
                setCreateHasOption1(false);
                setCreateOption1Name('Color / Edition');
                setCreateHasOption2(false);
                setCreateOption2Name('Size / Specification');
                setCreateVariants({ option1_name: null, option2_name: null, colors: [], sizes: [] });
                setCreateImageMode('file');
                setCreateFileError(null);
                setCreateUrlInput('');
                setCreateDraftPhoto(null);
                setActivePhotoPicker(null);
                if (createFileInputRef.current) {
                    createFileInputRef.current.value = '';
                }
            },
        });
    };

    const handleUpdate = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editingProduct) return;
        const submitter = (e.nativeEvent as SubmitEvent).submitter as HTMLButtonElement | null;
        const status = editingProduct.status === 'draft'
            ? (submitter?.value === 'publish' ? 'active' : 'draft')
            : editForm.data.status;
        if (editGallery.some(item => item.type === 'file' && !item.file)) {
            editForm.setError('gallery_manifest', 'Remove unavailable image tiles and select those files again before saving.');
            return;
        }
        if (editGallery.length > 9 || (status === 'active' && editGallery.length === 0)) {
            editForm.setError('gallery_manifest', 'Use 1–9 product images before publishing.');
            return;
        }
        if (editSlashedPriceError) {
            return;
        }

        const filesToUpload: File[] = [];
        const manifest = editGallery.map((item) => {
            if (item.type === 'file' && item.file) {
                const fileIndex = filesToUpload.length;
                filesToUpload.push(item.file);
                return { type: 'file', file_index: fileIndex };
            }
            return { type: item.type, url: item.url };
        });

        const hasActiveVariants = editHasOption1 || editHasOption2;
        const syncedVariants = hasActiveVariants ? {
            option1_name: editHasOption1 ? editOption1Name : null,
            option2_name: editHasOption2 ? editOption2Name : null,
            colors: editHasOption1 ? editVariants.colors.map((c) => {
                if (!c.image_url) {
                    return { ...c, gallery_index: null };
                }
                let matchIdx = -1;
                if (c.gallery_item_id) {
                    matchIdx = editGallery.findIndex(g => g.id === c.gallery_item_id);
                }
                if (matchIdx === -1 && c.image_url) {
                    matchIdx = editGallery.findIndex(g => g.url === c.image_url);
                }
                return {
                    ...c,
                    gallery_index: matchIdx !== -1 ? matchIdx : null,
                };
            }) : [],
            sizes: editHasOption2 ? editVariants.sizes : [],
            combinations: editVariants.combinations,
            pricing_mode: editVariants.pricing_mode ?? 'surcharge',
        } : null;

        editForm.transform((data) => {
            const { tag_draft: tagDraft, ...formData } = data;
            const tagNames = [...data.tag_names, ...(tagDraft.trim() ? [tagDraft.trim()] : [])];
            return {
                ...formData,
                tag_names: JSON.stringify(tagNames),
                gallery_manifest: JSON.stringify(manifest),
                image_files: filesToUpload,
                featured_image: editGallery[0]?.url || '',
                variants: syncedVariants ? JSON.stringify(syncedVariants) : '',
                status,
                _method: 'PUT',
            };
        });

        editForm.post(route('seller.products.update', editingProduct.id), {
            forceFormData: true,
            onSuccess: () => {
                clearSellerListingDraft(localDraftStorage(), editDraftKey(editingProduct.id));
                void clearSellerListingDraftImages(indexedDbSellerListingDraftImageStore, shop.id, `product:${editingProduct.id}`);
                revokeGalleryFilePreviews(editGallery);
                setEditDraftSaved(false);
                setEditDraftAuxDirty(false);
                setEditDraftNotice('');
                setEditingProduct(null);
                setEditGallery([]);
                setEditHasOption1(false);
                setEditOption1Name('Color / Edition');
                setEditHasOption2(false);
                setEditOption2Name('Size / Specification');
                setEditVariants({ option1_name: null, option2_name: null, colors: [], sizes: [] });
                setEditImageMode('file');
                setEditFileError(null);
                setEditUrlInput('');
                setEditDraftPhoto(null);
                setActivePhotoPicker(null);
                if (editFileInputRef.current) {
                    editFileInputRef.current.value = '';
                }
            },
        });
    };

    const openStockEditor = (product: Product) => {
        stockForm.clearErrors();
        stockForm.setData({
            mode: 'set',
            quantity: String(product.stock),
        });
        setStockProduct(product);
    };

    const changeStockMode = (mode: 'set' | 'add') => {
        if (!stockProduct) return;

        stockForm.clearErrors();
        stockForm.setData({
            mode,
            quantity: mode === 'set' ? String(stockProduct.stock) : '1',
        });
    };

    const handleStockUpdate = (event: React.FormEvent) => {
        event.preventDefault();
        if (!stockProduct) return;

        stockForm.patch(route('seller.products.stock.update', stockProduct.id), {
            preserveScroll: true,
            onSuccess: () => {
                setStockProduct(null);
                stockForm.reset();
            },
        });
    };

    const assignColorPhoto = (
        isCreate: boolean,
        colorId: string,
        imageUrl: string | null,
        galleryItemId?: string | null,
        galleryIndex?: number | null
    ) => {
        const variants = isCreate ? createVariants : editVariants;
        const alreadyAssigned = imageUrl && variants.colors.find((color) => color.id !== colorId && (
            (galleryItemId && color.gallery_item_id === galleryItemId) || color.image_url === imageUrl
        ));
        if (alreadyAssigned) {
            if (isCreate) setCreateFileError(`That photo is already assigned to ${alreadyAssigned.name}. Choose an unassigned photo.`);
            else setEditFileError(`That photo is already assigned to ${alreadyAssigned.name}. Choose an unassigned photo.`);
            return;
        }
        markDraftAuxDirty(isCreate);
        if (colorId === 'draft') {
            const setDraft = isCreate ? setCreateDraftPhoto : setEditDraftPhoto;
            if (imageUrl) {
                setDraft({
                    image_url: imageUrl,
                    gallery_item_id: galleryItemId || null,
                    gallery_index: galleryIndex ?? null,
                });
            } else {
                setDraft(null);
            }
            setActivePhotoPicker(null);
            return;
        }

        const setVariants = isCreate ? setCreateVariants : setEditVariants;
        setVariants((prev) => ({
            ...prev,
            colors: prev.colors.map((c) => {
                if (c.id !== colorId) return c;
                return {
                    ...c,
                    image_url: imageUrl,
                    gallery_item_id: galleryItemId || null,
                    gallery_index: galleryIndex ?? null,
                };
            }),
        }));
        setActivePhotoPicker(null);
    };

    const enableOptionGroup = (groupNum: 1 | 2, isCreate: boolean, defaultName?: string) => {
        markDraftAuxDirty(isCreate);
        if (groupNum === 1) {
            if (isCreate) {
                setCreateHasOption1(true);
                if (defaultName) setCreateOption1Name(defaultName);
            } else {
                setEditHasOption1(true);
                if (defaultName) setEditOption1Name(defaultName);
            }
        } else {
            if (isCreate) {
                setCreateHasOption2(true);
                if (defaultName) setCreateOption2Name(defaultName);
            } else {
                setEditHasOption2(true);
                if (defaultName) setEditOption2Name(defaultName);
            }
        }
        const setVariants = isCreate ? setCreateVariants : setEditVariants;
        setVariants((previous) => ({ ...previous, combinations: previous.combinations ?? [] }));
    };

    const disableOptionGroup = (groupNum: 1 | 2, isCreate: boolean) => {
        markDraftAuxDirty(isCreate);
        if (groupNum === 1) {
            if (isCreate) {
                setCreateHasOption1(false);
                setCreateVariants((prev) => ({ ...prev, colors: [], combinations: createHasOption2 ? [] : undefined }));
                setCreateDraftPhoto(null);
            } else {
                setEditHasOption1(false);
                setEditVariants((prev) => ({ ...prev, colors: [], combinations: editHasOption2 ? [] : undefined }));
                setEditDraftPhoto(null);
            }
        } else {
            if (isCreate) {
                setCreateHasOption2(false);
                setCreateVariants((prev) => ({ ...prev, sizes: [], combinations: createHasOption1 ? [] : undefined }));
            } else {
                setEditHasOption2(false);
                setEditVariants((prev) => ({ ...prev, sizes: [], combinations: editHasOption1 ? [] : undefined }));
            }
        }
    };

    const optionNameError = (value: string, options: { name: string }[]) => {
        const name = value.trim();
        if (!name) return 'Enter an option value before adding it.';
        if (name.length > 100) return 'Use no more than 100 characters for an option value.';
        if (options.some(option => option.name.trim().toLowerCase() === name.toLowerCase())) return 'This option is already listed. Enter a different value.';
        if (options.length >= 20) return 'Use no more than 20 values per option group.';
        return '';
    };
    const setOptionError = (isCreate: boolean, group: 'colors' | 'sizes', message: string) =>
        setOptionDraftErrors(previous => ({ ...previous, [`${isCreate ? 'create' : 'edit'}.${group}`]: message }));

    const addColor = (isCreate: boolean) => {
        const name = isCreate ? newCreateColorName.trim() : newEditColorName.trim();
        const hex = isCreate ? newCreateColorHex.trim() : newEditColorHex.trim();
        const message = optionNameError(name, (isCreate ? createVariants : editVariants).colors);
        setOptionError(isCreate, 'colors', message);
        if (message) return;
        markDraftAuxDirty(isCreate);

        const draft = isCreate ? createDraftPhoto : editDraftPhoto;

        const newColor: VariantColor = {
            id: `c_${Date.now()}_${Math.random().toString(36).substring(2, 6)}`,
            name,
            hex: hex || '#111111',
            image_url: draft?.image_url || null,
            gallery_item_id: draft?.gallery_item_id || null,
            gallery_index: draft?.gallery_index ?? null,
            in_stock: true,
        };

        const setVariants = isCreate ? setCreateVariants : setEditVariants;
        setVariants((prev) => ({ ...prev, colors: [...prev.colors, newColor], combinations: prev.combinations ?? [] }));

        if (isCreate) {
            setNewCreateColorName('');
            setNewCreateColorHex('#111111');
            setCreateDraftPhoto(null);
        } else {
            setNewEditColorName('');
            setNewEditColorHex('#111111');
            setEditDraftPhoto(null);
        }
    };

    const removeColor = (id: string, isCreate: boolean) => {
        markDraftAuxDirty(isCreate);
        const setVariants = isCreate ? setCreateVariants : setEditVariants;
        setVariants((prev) => ({ ...prev, colors: prev.colors.filter(c => c.id !== id) }));
    };

    const addSize = (isCreate: boolean) => {
        const name = isCreate ? newCreateSizeName.trim() : newEditSizeName.trim();
        const extraPrice = Number(isCreate ? newCreateSizePrice : newEditSizePrice) || 0;
        const message = optionNameError(name, (isCreate ? createVariants : editVariants).sizes);
        setOptionError(isCreate, 'sizes', message);
        if (message) return;
        markDraftAuxDirty(isCreate);

        const newSize: VariantSize = {
            id: `s_${Date.now()}_${Math.random().toString(36).substring(2, 6)}`,
            name,
            extra_price: extraPrice,
            stock: 0,
        };

        const setVariants = isCreate ? setCreateVariants : setEditVariants;
        setVariants((prev) => ({ ...prev, sizes: [...prev.sizes, newSize], combinations: prev.combinations ?? [] }));

        if (isCreate) {
            setNewCreateSizeName('');
            setNewCreateSizePrice('');
        } else {
            setNewEditSizeName('');
            setNewEditSizePrice('');
        }
    };

    const removeSize = (id: string, isCreate: boolean) => {
        markDraftAuxDirty(isCreate);
        const setVariants = isCreate ? setCreateVariants : setEditVariants;
        setVariants((prev) => ({ ...prev, sizes: prev.sizes.filter(s => s.id !== id) }));
    };

    const renderVariantManager = (isCreate: boolean) => {
        const hasOption1 = isCreate ? createHasOption1 : editHasOption1;
        const option1Name = isCreate ? createOption1Name : editOption1Name;
        const setOption1Name = (value: string) => { markDraftAuxDirty(isCreate); (isCreate ? setCreateOption1Name : setEditOption1Name)(value); };

        const hasOption2 = isCreate ? createHasOption2 : editHasOption2;
        const option2Name = isCreate ? createOption2Name : editOption2Name;
        const setOption2Name = (value: string) => { markDraftAuxDirty(isCreate); (isCreate ? setCreateOption2Name : setEditOption2Name)(value); };

        const variants = isCreate ? createVariants : editVariants;
        const colorName = isCreate ? newCreateColorName : newEditColorName;
        const setColorName = (value: string) => { markDraftAuxDirty(isCreate); (isCreate ? setNewCreateColorName : setNewEditColorName)(value); };
        const colorHex = isCreate ? newCreateColorHex : newEditColorHex;
        const setColorHex = (value: string) => { markDraftAuxDirty(isCreate); (isCreate ? setNewCreateColorHex : setNewEditColorHex)(value); };
        const draftPhoto = isCreate ? createDraftPhoto : editDraftPhoto;
        const sizeName = isCreate ? newCreateSizeName : newEditSizeName;
        const setSizeName = (value: string) => { markDraftAuxDirty(isCreate); (isCreate ? setNewCreateSizeName : setNewEditSizeName)(value); };
        const sizePrice = isCreate ? newCreateSizePrice : newEditSizePrice;
        const setSizePrice = (value: string) => { markDraftAuxDirty(isCreate); (isCreate ? setNewCreateSizePrice : setNewEditSizePrice)(value); };

        const colorFeedback = optionDraftErrors[`${isCreate ? 'create' : 'edit'}.colors`] || (colorName ? optionNameError(colorName, variants.colors) : '');
        const sizeFeedback = optionDraftErrors[`${isCreate ? 'create' : 'edit'}.sizes`] || (sizeName ? optionNameError(sizeName, variants.sizes) : '');
        const fieldErrors = (isCreate ? createForm : editForm).errors as Record<string, string>;
        const savedOptionError = (group: 'colors' | 'sizes', index: number) => {
            const message = fieldErrors[`${group}.${index}.name`] || '';
            if (/duplicate/i.test(message)) return 'This option is repeated in this group.';
            if (/required/i.test(message)) return 'Enter a value for this option.';
            return message.replace(/(?:colors|sizes)\.[0-9]+\.name/g, 'option value');
        };

        // 1. Zero Variations (Empty State)
        if (!hasOption1 && !hasOption2) {
            return (
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 className="text-base font-semibold text-ink-900">Options</h3>
                        <p className="mt-0.5 text-xs text-ink-600">Add options if buyers choose a color, size or flavor.</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => enableOptionGroup(1, isCreate, 'Color / Edition')}
                        className={buttonClasses('secondary', 'md', 'shrink-0')}
                    >
                        <Plus className="h-4 w-4" />
                        <span>Add Variation</span>
                    </button>
                </div>
            );
        }

        // 2. Active Variations UI
        return (
            <div className="space-y-4">
                <h3 className="text-base font-semibold text-ink-900">Options</h3>

                {/* OPTION 1 (Visual / Images) */}
                {hasOption1 ? (
                    <div className="p-4 bg-white rounded-2xl border border-slate-200/90 shadow-2xs space-y-3.5">
                        {/* Option 1 Header: Editable Title, Presets, Delete Button */}
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-2.5 border-b border-slate-100">
                            <div className="flex-1 space-y-1.5">
                                <div className="flex items-center gap-2">
                                    <span className="px-2 py-0.5 rounded bg-rose-50 text-[#E00D42] font-sans font-bold text-[10px] uppercase">
                                        Option 1 (Visual / Photo-linked)
                                    </span>
                                    <span className="text-[10px] text-slate-400 font-sans">
                                        ({variants.colors.length} items)
                                    </span>
                                </div>
                                <div className="flex items-center gap-2 max-w-sm">
                                    <input
                                        type="text"
                                        value={option1Name}
                                        maxLength={100}
                                        onChange={(e) => setOption1Name(e.target.value)}
                                        placeholder="e.g. Color, Model, Flavor, Shade..."
                                        className="px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold text-slate-800 focus:bg-white focus:ring-1 focus:ring-[#E00D42] w-full"
                                        title="Rename Option 1"
                                    />
                                </div>
                            </div>

                            <button
                                type="button"
                                onClick={() => disableOptionGroup(1, isCreate)}
                                className="self-start sm:self-center px-2.5 py-1.5 text-xs font-sans font-bold text-rose-600 hover:bg-rose-50 rounded-xl transition flex items-center gap-1 shrink-0"
                                title="Delete Option 1"
                            >
                                <Trash2 className="w-3.5 h-3.5" />
                                <span>Remove Option 1</span>
                            </button>
                        </div>

                        {/* Existing Option 1 Values */}
                        {variants.colors.length > 0 && (
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 pb-1 font-sans text-xs">
                                {variants.colors.map((c, index) => (
                                    <div
                                        key={c.id}
                                        className="flex items-center justify-between gap-2 p-2 bg-slate-50/70 rounded-xl border border-slate-200 hover:border-slate-300 transition"
                                    >
                                        <div className="flex items-center gap-2.5 min-w-0">
                                            <button
                                                type="button"
                                                onClick={() => setActivePhotoPicker({ isCreate, colorId: c.id })}
                                                className="relative group w-9 h-9 rounded-lg overflow-hidden border border-slate-200 bg-white shrink-0 flex items-center justify-center hover:border-[#E00D42] transition"
                                                title={c.image_url ? 'Click to change photo' : 'Click to assign photo'}
                                            >
                                                {c.image_url ? (
                                                    <>
                                                        <img
                                                            src={c.image_url}
                                                            alt={c.name}
                                                            className="w-full h-full object-cover"
                                                        />
                                                        <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 flex items-center justify-center transition">
                                                            <Camera className="w-3.5 h-3.5 text-white" />
                                                        </div>
                                                    </>
                                                ) : (
                                                    <div className="flex flex-col items-center justify-center text-slate-400 group-hover:text-[#E00D42] transition">
                                                        <Camera className="w-3.5 h-3.5" />
                                                        <span className="text-[7px] font-bold uppercase leading-none mt-0.5">+Pic</span>
                                                    </div>
                                                )}
                                            </button>

                                            <span
                                                className="w-3.5 h-3.5 rounded-full border border-black/20 shrink-0 shadow-2xs"
                                                style={{ backgroundColor: c.hex }}
                                                title={c.hex}
                                            />

                                            <div className="min-w-0">
                                                <p className="font-bold text-slate-800 text-xs truncate leading-tight font-sans">{c.name}</p>
                                                {savedOptionError('colors', index) && <span role="alert" className="mt-1 block text-xs text-rose-700">{savedOptionError('colors', index)}</span>}
                                                <div className="text-[10px] text-slate-400 leading-tight">
                                                    {c.image_url ? (
                                                        <span className="text-emerald-600 font-bold flex items-center gap-0.5">
                                                            <Check className="w-2.5 h-2.5" /> Photo linked
                                                        </span>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            onClick={() => setActivePhotoPicker({ isCreate, colorId: c.id })}
                                                            className="text-[#E00D42] hover:underline"
                                                        >
                                                            + Assign photo
                                                        </button>
                                                    )}
                                                </div>
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-1 shrink-0">
                                            <button
                                                type="button"
                                                onClick={() => setActivePhotoPicker({ isCreate, colorId: c.id })}
                                                className="px-2 py-1 text-[10px] font-bold rounded-md bg-white border border-slate-200 hover:border-slate-300 text-slate-600 hover:text-slate-900 transition"
                                            >
                                                {c.image_url ? 'Change' : '+ Photo'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => removeColor(c.id, isCreate)}
                                                className="p-1 text-slate-400 hover:text-rose-600 transition rounded-md hover:bg-rose-50"
                                                title="Remove"
                                            >
                                                <Trash2 className="w-3.5 h-3.5" />
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}

                        {/* Add Item to Option 1 */}
                        <div className="flex items-center gap-2 font-sans text-xs">
                            <button
                                type="button"
                                onClick={() => setActivePhotoPicker({ isCreate, colorId: 'draft' })}
                                className="w-9 h-9 rounded-lg border border-dashed border-slate-300 hover:border-[#E00D42] bg-white flex items-center justify-center text-slate-400 hover:text-[#E00D42] transition shrink-0 relative overflow-hidden"
                                title={draftPhoto?.image_url ? 'Draft photo assigned (click to change)' : 'Attach photo to new option value'}
                            >
                                {draftPhoto?.image_url ? (
                                    <>
                                        <img src={draftPhoto.image_url} alt="Draft" className="w-full h-full object-cover" />
                                        <div className="absolute top-0.5 right-0.5 w-2 h-2 rounded-full bg-emerald-500 ring-1 ring-white" />
                                    </>
                                ) : (
                                    <Camera className="w-4 h-4" />
                                )}
                            </button>

                            <div className="relative flex items-center">
                                <input
                                    type="color"
                                    value={colorHex}
                                    onChange={(e) => setColorHex(e.target.value)}
                                    className="w-9 h-9 rounded-lg border border-slate-200 p-0.5 cursor-pointer bg-white"
                                    title="Pick color / swatch hex"
                                />
                            </div>
                            <div className="min-w-0 flex-1"><input
                                type="text"
                                value={colorName} maxLength={100} aria-label={`${option1Name || 'Option 1'} value`} aria-invalid={!!colorFeedback}
                                onChange={(e) => { setColorName(e.target.value); setOptionError(isCreate, 'colors', ''); }}
                                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addColor(isCreate); } }}
                                placeholder={`Add ${option1Name || 'Option 1'} value (e.g. Matte Black)...`}
                                className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                            />{colorFeedback && <p role="alert" className="mt-1 text-xs text-rose-700">{colorFeedback}</p>}</div>
                            <button
                                type="button"
                                onClick={() => addColor(isCreate)}
                                className={buttonClasses('secondary', 'md', 'shrink-0')}
                            >
                                <Plus className="w-3 h-3" />
                                <span>Add</span>
                            </button>
                        </div>
                    </div>
                ) : (
                    <button
                        type="button"
                        onClick={() => enableOptionGroup(1, isCreate, 'Color / Edition')}
                        className="w-full py-3 px-4 border border-dashed border-slate-300 hover:border-[#E00D42] hover:bg-rose-50/30 rounded-2xl text-slate-600 hover:text-[#E00D42] font-sans text-xs font-bold transition flex items-center justify-center gap-2"
                    >
                        <Plus className="w-4 h-4" />
                        <span>+ Enable Primary Visual Option (e.g. Color, Model, Flavor)</span>
                    </button>
                )}

                {/* OPTION 2 (Specs / Modifiers) */}
                {hasOption2 ? (
                    <div className="p-4 bg-white rounded-2xl border border-slate-200/90 shadow-2xs space-y-3.5">
                        {/* Option 2 Header: Editable Title, Presets, Delete Button */}
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-2.5 border-b border-slate-100">
                            <div className="flex-1 space-y-1.5">
                                <div className="flex items-center gap-2">
                                    <span className="px-2 py-0.5 rounded bg-indigo-50 text-indigo-700 font-sans font-bold text-[10px] uppercase">
                                        Option 2 (Specifications / Price Modifiers)
                                    </span>
                                    <span className="text-[10px] text-slate-400 font-sans">
                                        ({variants.sizes.length} items)
                                    </span>
                                </div>
                                <div className="flex items-center gap-2 max-w-sm">
                                    <input
                                        type="text"
                                        value={option2Name}
                                        maxLength={100}
                                        onChange={(e) => setOption2Name(e.target.value)}
                                        placeholder="e.g. Size, Storage, Pack Size, Volume..."
                                        className="px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold text-slate-800 focus:bg-white focus:ring-1 focus:ring-[#E00D42] w-full"
                                        title="Rename Option 2"
                                    />
                                </div>
                            </div>

                            <button
                                type="button"
                                onClick={() => disableOptionGroup(2, isCreate)}
                                className="self-start sm:self-center px-2.5 py-1.5 text-xs font-sans font-bold text-rose-600 hover:bg-rose-50 rounded-xl transition flex items-center gap-1 shrink-0"
                                title="Delete Option 2"
                            >
                                <Trash2 className="w-3.5 h-3.5" />
                                <span>Remove Option 2</span>
                            </button>
                        </div>

                        {/* Existing Option 2 Values */}
                        {variants.sizes.length > 0 && (
                            <div className="flex flex-wrap gap-2 pb-1 font-sans text-xs">
                                {variants.sizes.map((s, index) => (
                                    <span
                                        key={s.id}
                                        className="inline-flex flex-wrap items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-50 border border-slate-200 text-slate-800 text-xs shadow-2xs"
                                    >
                                        <span className="font-bold">{s.name}</span>
                                        {savedOptionError('sizes', index) && <span role="alert" className="order-last basis-full text-xs text-rose-700">{savedOptionError('sizes', index)}</span>}
                                        {s.extra_price > 0 && (
                                            <span className="text-[10px] text-emerald-600 font-bold">
                                                (+{formatPrice(s.extra_price)})
                                            </span>
                                        )}
                                        <button
                                            type="button"
                                            onClick={() => removeSize(s.id, isCreate)}
                                            className="p-0.5 text-slate-400 hover:text-rose-600 transition"
                                            title="Remove"
                                        >
                                            <X className="w-3 h-3" />
                                        </button>
                                    </span>
                                ))}
                            </div>
                        )}

                        {/* Add Item to Option 2 */}
                        <div className="flex items-center gap-2 font-sans text-xs">
                            <div className="min-w-0 flex-1"><input
                                type="text"
                                value={sizeName} maxLength={100} aria-label={`${option2Name || 'Option 2'} value`} aria-invalid={!!sizeFeedback}
                                onChange={(e) => { setSizeName(e.target.value); setOptionError(isCreate, 'sizes', ''); }}
                                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addSize(isCreate); } }}
                                placeholder={`Add ${option2Name || 'Option 2'} value (e.g. 256GB, Large, 500ml)...`}
                                className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                            />{sizeFeedback && <p role="alert" className="mt-1 text-xs text-rose-700">{sizeFeedback}</p>}</div>
                            <div className="w-32 relative">
                                <span className="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs">₱+</span>
                                <input
                                    type="text"
                                    inputMode="decimal"
                                    maxLength={11}
                                    value={sizePrice}
                                    onChange={(e) => { const value = acceptPriceDraft(e.target.value, 99999999.99); if (value !== null) setSizePrice(value); }}
                                    placeholder="Extra ₱"
                                    className="w-full pl-7 pr-2.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                    title="Extra price modifier for this spec"
                                />
                            </div>
                            <button
                                type="button"
                                onClick={() => addSize(isCreate)}
                                className={buttonClasses('secondary', 'md', 'shrink-0')}
                            >
                                <Plus className="w-3 h-3" />
                                <span>Add</span>
                            </button>
                        </div>
                    </div>
                ) : (
                    <div className="p-4 rounded-2xl border border-dashed border-slate-300 bg-white flex flex-col sm:flex-row items-center justify-between gap-3">
                        <div>
                            <p className="font-bold text-slate-800 text-xs font-sans">
                                + Secondary Variation Group
                            </p>
                            <p className="text-[11px] text-slate-400 mt-0.5">
                                Add a secondary specification with optional price modifiers (e.g. Size, Storage, Pack Size, Volume).
                            </p>
                        </div>
                        <div>
                            <button
                                type="button"
                                onClick={() => enableOptionGroup(2, isCreate, 'Specification / Size')}
                                className={buttonClasses('secondary', 'sm')}
                            >
                                + Add option group
                            </button>
                        </div>
                    </div>
                )}
            </div>
        );
    };

    const renderGalleryManager = (isCreate: boolean) => {
        const imageMode = isCreate ? createImageMode : editImageMode;
        const setImageMode = (value: 'file' | 'url') => { markDraftAuxDirty(isCreate); (isCreate ? setCreateImageMode : setEditImageMode)(value); };
        const gallery = isCreate ? createGallery : editGallery;
        const fileError = isCreate ? createFileError : editFileError;
        const fileInputRef = isCreate ? createFileInputRef : editFileInputRef;
        const urlInput = isCreate ? createUrlInput : editUrlInput;
        const setUrlInput = (value: string) => { markDraftAuxDirty(isCreate); (isCreate ? setCreateUrlInput : setEditUrlInput)(value); };
        const dragIndex = isCreate ? createDragIndex : editDragIndex;
        const dragOverIndex = isCreate ? createDragOverIndex : editDragOverIndex;
        const galleryErrors = (isCreate ? createForm : editForm).errors;
        const galleryError = galleryErrors.gallery_manifest || galleryErrors.image_files
            || Object.entries(galleryErrors).find(([field]) => field.startsWith('image_files.'))?.[1];

        const full = gallery.length >= 9;
        const pickFiles = () => { if (!full) fileInputRef.current?.click(); };

        return (
            <div className="space-y-3">
                <div className="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h3 className="text-base font-semibold text-ink-900">Photos <span className="text-sm font-normal text-ink-500 tabular-nums">{gallery.length}/9</span></h3>
                        <p className="mt-0.5 text-xs text-ink-600">The first photo is the cover buyers see. Drag to reorder.</p>
                    </div>
                    <button type="button" onClick={() => setImageMode(imageMode === 'file' ? 'url' : 'file')} className="text-[13px] font-medium text-ink-700 underline underline-offset-2 hover:text-ink-900">
                        {imageMode === 'file' ? 'Add from a link instead' : 'Upload files instead'}
                    </button>
                </div>

                <input
                    ref={fileInputRef}
                    type="file"
                    multiple
                    disabled={full}
                    accept="image/jpeg,image/png,image/jpg,image/webp,image/gif"
                    onChange={(e) => {
                        handleFilesSelected(e.target.files, isCreate);
                        if (fileInputRef.current) fileInputRef.current.value = '';
                    }}
                    className="hidden"
                />

                {imageMode === 'url' && (
                    <div className="flex gap-2">
                        <div className="relative min-w-0 flex-1">
                            <LinkIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" />
                            <input
                                type="url"
                                value={urlInput}
                                aria-label="Image link"
                                onChange={(e) => setUrlInput(e.target.value)}
                                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); handleAddUrl(urlInput, isCreate); } }}
                                placeholder="https://… (JPG, PNG, WEBP or GIF)"
                                className={`${inputClasses} pl-9`}
                            />
                        </div>
                        <button type="button" onClick={() => handleAddUrl(urlInput, isCreate)} disabled={full || !urlInput.trim()} className={buttonClasses('secondary')}>Add photo</button>
                    </div>
                )}

                {fileError && (
                    <p role="alert" className="flex items-start gap-2 rounded-control bg-brand-50 px-3 py-2 text-[13px] text-brand-700">
                        <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />{fileError}
                    </p>
                )}
                {galleryError && <p role="alert" className="text-[13px] text-brand-700">{galleryError}</p>}

                {gallery.length === 0 ? (
                    <button
                        type="button"
                        onClick={imageMode === 'file' ? pickFiles : undefined}
                        className="flex w-full flex-col items-center justify-center gap-2 rounded-card border-2 border-dashed border-line-300 bg-surface-50 px-4 py-12 text-center hover:border-brand-500 hover:bg-white"
                    >
                        <Upload className="h-6 w-6 text-ink-400" />
                        <span className="text-sm font-semibold text-ink-900">{imageMode === 'file' ? 'Add photos' : 'Paste a link above'}</span>
                        <span className="text-xs text-ink-600">Up to 9 · JPG, PNG, WEBP or GIF · 5 MB each · needed to publish</span>
                    </button>
                ) : (
                    <ul className="grid grid-cols-3 gap-3 sm:grid-cols-5" aria-label="Product photos">
                        {gallery.map((item, index) => {
                            const isPrimary = index === 0;
                            const isBeingDragged = dragIndex === index;
                            const isOver = dragOverIndex === index;

                            return (
                                <li
                                    key={item.id}
                                    draggable
                                    onDragStart={() => handleDragStart(index, isCreate)}
                                    onDragOver={(e) => handleDragOver(e, index, isCreate)}
                                    onDrop={() => handleDrop(index, isCreate)}
                                    onDragEnd={() => handleDragEnd(isCreate)}
                                    className={`group relative aspect-square cursor-grab select-none overflow-hidden rounded-card border bg-surface-100 active:cursor-grabbing ${
                                        isPrimary ? 'col-span-2 row-span-2' : ''
                                    } ${isBeingDragged ? 'opacity-40' : ''} ${isOver ? 'border-brand-500 ring-2 ring-brand-500/40' : 'border-line-200'}`}
                                >
                                    {item.url ? (
                                        <img src={item.url} alt={item.name || `Photo ${index + 1}`} className="h-full w-full object-cover" />
                                    ) : (
                                        <div className="flex h-full w-full flex-col items-center justify-center gap-1 p-2 text-center text-xs text-brand-700">
                                            <ImageIcon className="h-5 w-5" />
                                            <span>Unavailable. Remove and add again.</span>
                                        </div>
                                    )}
                                    {isPrimary && <span className="absolute left-2 top-2 rounded bg-white px-2 py-0.5 text-xs font-semibold text-ink-900 shadow-sm">Cover</span>}
                                    <button
                                        type="button"
                                        onClick={() => removeGalleryItem(index, isCreate)}
                                        className="absolute right-2 top-2 inline-flex h-8 w-8 items-center justify-center rounded-control bg-white/95 text-ink-700 shadow-sm hover:text-brand-700"
                                        title="Remove image"
                                        aria-label={`Remove photo ${index + 1}`}
                                    >
                                        <X className="h-4 w-4" />
                                    </button>
                                    {!isPrimary && (
                                        <button
                                            type="button"
                                            onClick={() => moveGalleryItem(index, 'top', isCreate)}
                                            className="absolute inset-x-2 bottom-2 rounded-control bg-white/95 px-2 py-1 text-xs font-semibold text-ink-900 opacity-100 shadow-sm hover:bg-white sm:opacity-0 sm:group-hover:opacity-100 sm:focus:opacity-100"
                                            title="Make primary cover"
                                        >
                                            Set as cover
                                        </button>
                                    )}
                                </li>
                            );
                        })}
                        {!full && imageMode === 'file' && (
                            <li className="aspect-square">
                                <button type="button" onClick={pickFiles} className="flex h-full w-full flex-col items-center justify-center gap-1 rounded-card border-2 border-dashed border-line-300 text-ink-600 hover:border-brand-500 hover:text-ink-900">
                                    <Plus className="h-5 w-5" />
                                    <span className="text-xs font-semibold">Add photos</span>
                                </button>
                            </li>
                        )}
                    </ul>
                )}
            </div>
        );
    };

    const renderPhotoPickerModal = () => {
        if (!activePhotoPicker || typeof document === 'undefined') return null;

        const isCreate = activePhotoPicker.isCreate;
        const colorId = activePhotoPicker.colorId;
        const gallery = isCreate ? createGallery : editGallery;
        const variants = isCreate ? createVariants : editVariants;
        const setGallery = isCreate ? setCreateGallery : setEditGallery;
        const fileError = isCreate ? createFileError : editFileError;
        const setFileError = isCreate ? setCreateFileError : setEditFileError;

        let targetName = 'New Color';
        let targetHex = '#111111';
        let currentUrl: string | null = null;
        let currentGalleryItemId: string | null = null;

        if (colorId === 'draft') {
            targetName = (isCreate ? newCreateColorName : newEditColorName).trim() || 'New Color Edition';
            targetHex = isCreate ? newCreateColorHex : newEditColorHex;
            currentUrl = isCreate ? createDraftPhoto?.image_url || null : editDraftPhoto?.image_url || null;
            currentGalleryItemId = isCreate ? createDraftPhoto?.gallery_item_id || null : editDraftPhoto?.gallery_item_id || null;
        } else {
            const targetColor = variants.colors.find((c) => c.id === colorId);
            targetName = targetColor?.name || 'Color Edition';
            targetHex = targetColor?.hex || '#111111';
            currentUrl = targetColor?.image_url || null;
            currentGalleryItemId = targetColor?.gallery_item_id || null;
        }

        return createPortal(
            <div 
                className="fixed inset-0 z-[10000] flex items-center justify-center p-4 bg-black/75 backdrop-blur-xs animate-fade-in font-sans"
                onClick={() => setActivePhotoPicker(null)}
            >
                <div 
                    className="relative w-full max-w-xl bg-white rounded-3xl shadow-2xl border border-slate-200 overflow-hidden flex flex-col max-h-[90vh]"
                    onClick={(e) => e.stopPropagation()}
                >
                    {/* Header */}
                    <div className="px-6 py-4 bg-slate-50 border-b border-slate-200 flex items-center justify-between shrink-0">
                        <div className="flex items-center gap-2.5">
                            <div className="p-2 rounded-xl bg-rose-50 text-[#E00D42]">
                                <ImageIcon className="w-5 h-5" />
                            </div>
                            <div>
                                <h3 className="font-black text-sm text-slate-900 uppercase tracking-wide">
                                    Assign Variant Photo
                                </h3>
                                <div className="flex items-center gap-1.5 text-xs text-slate-500 font-sans mt-0.5">
                                    <span>Target:</span>
                                    <span
                                        className="w-3 h-3 rounded-full border border-black/20 shrink-0"
                                        style={{ backgroundColor: targetHex }}
                                    />
                                    <span className="font-bold text-slate-800">{targetName}</span>
                                </div>
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={() => setActivePhotoPicker(null)}
                            className="p-2 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-200/60 transition"
                        >
                            <X className="w-5 h-5" />
                        </button>
                    </div>

                    {/* Scrollable Content */}
                    <div className="p-6 space-y-6 overflow-y-auto font-sans text-xs">
                        {/* Currently Linked Photo */}
                        {currentUrl && (
                            <div className="p-3 bg-emerald-50 rounded-2xl border border-emerald-200 flex items-center justify-between gap-3 font-sans">
                                <div className="flex items-center gap-3 min-w-0">
                                    <img
                                        src={currentUrl}
                                        alt="Current variant preview"
                                        className="w-12 h-12 rounded-xl object-cover border border-emerald-300 shrink-0 bg-white"
                                    />
                                    <div className="min-w-0">
                                        <p className="font-bold text-emerald-900 text-xs flex items-center gap-1">
                                            <Check className="w-3.5 h-3.5 text-emerald-600" /> Active Variant Photo
                                        </p>
                                        <p className="text-[11px] text-emerald-700 truncate font-sans">
                                            Click any gallery photo below to change, or unlink.
                                        </p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => assignColorPhoto(isCreate, colorId, null, null, null)}
                                    className="px-3 py-1.5 text-xs font-bold text-rose-600 hover:text-white hover:bg-rose-600 border border-rose-200 hover:border-rose-600 rounded-xl transition shrink-0"
                                >
                                    Unlink Photo
                                </button>
                            </div>
                        )}

                        {/* SECTION 1: Choose from Product Gallery */}
                        <div className="space-y-3">
                            <div className="flex items-center justify-between">
                                <label className="text-xs font-bold text-slate-800 uppercase tracking-wider flex items-center gap-1.5">
                                    <span>From Product Gallery</span>
                                    <span className="text-slate-400 font-normal">({gallery.length} photos)</span>
                                </label>
                                <span className="text-[11px] text-slate-400 font-sans">Click photo to select</span>
                            </div>

                            {gallery.length === 0 ? (
                                <div className="p-4 bg-slate-50 border border-dashed border-slate-200 rounded-2xl text-center text-xs text-slate-500 space-y-1">
                                    <p className="font-bold text-slate-700">No photos in product gallery yet</p>
                                    <p className="text-[11px] text-slate-400 font-sans">
                                        Upload a photo below to add it directly to this variant and the gallery.
                                    </p>
                                </div>
                            ) : (
                                <div className="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 gap-2.5">
                                    {gallery.map((item, idx) => {
                                        const isSelected = currentUrl === item.url || currentGalleryItemId === item.id;
                                        const assignedTo = variants.colors.find((color) => color.id !== colorId && (
                                            color.gallery_item_id === item.id || color.image_url === item.url
                                        ));
                                        return (
                                            <button
                                                key={item.id || idx}
                                                type="button"
                                                disabled={!!assignedTo || !item.url}
                                                onClick={() => assignColorPhoto(isCreate, colorId, item.url, item.id, idx)}
                                                title={assignedTo ? `Already assigned to ${assignedTo.name}` : item.url ? 'Select this photo' : 'Remove this unavailable image and upload it again'}
                                                className={`relative aspect-square rounded-2xl overflow-hidden border-2 transition group flex flex-col items-center justify-center disabled:cursor-not-allowed disabled:opacity-50 ${
                                                    isSelected
                                                        ? 'border-[#E00D42] ring-3 ring-[#E00D42]/20 shadow-md'
                                                        : 'border-slate-200 hover:border-slate-400 hover:shadow-xs bg-slate-50'
                                                }`}
                                            >
                                                {item.url ? <img
                                                    src={item.url}
                                                    alt={`Gallery item ${idx + 1}`}
                                                    className="w-full h-full object-cover"
                                                /> : <span className="px-2 text-center text-rose-700 text-[10px]">Unavailable image</span>}
                                                <span className="absolute top-1.5 left-1.5 px-1.5 py-0.5 rounded-md bg-black/60 text-white font-sans text-[9px] font-bold">
                                                    #{idx + 1}
                                                </span>

                                                {assignedTo ? (
                                                    <div className="absolute inset-0 bg-slate-900/50 flex items-center justify-center">
                                                        <span className="px-2 py-1 rounded-full bg-surface-100 text-ink-700 font-bold text-[10px] shadow-sm font-sans">
                                                            Assigned: {assignedTo.name}
                                                        </span>
                                                    </div>
                                                ) : isSelected ? (
                                                    <div className="absolute inset-0 bg-[#E00D42]/20 flex items-center justify-center">
                                                        <span className="px-2 py-1 rounded-full bg-[#E00D42] text-white font-bold text-[10px] flex items-center gap-1 shadow-md font-sans">
                                                            <Check className="w-3 h-3" /> Selected
                                                        </span>
                                                    </div>
                                                ) : (
                                                    <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 flex items-center justify-center transition">
                                                        <span className="px-2 py-1 rounded-full bg-white text-slate-900 font-bold text-[10px] shadow-sm font-sans">
                                                            Select
                                                        </span>
                                                    </div>
                                                )}
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                        </div>

                        {/* SECTION 2: Upload New Photo */}
                        <div className="pt-4 border-t border-slate-100 space-y-2">
                            <label className="text-xs font-bold text-slate-800 uppercase tracking-wider block">
                                Or Upload New Photo
                            </label>
                            <label className="w-full cursor-pointer flex items-center justify-center gap-2 px-4 py-3 rounded-2xl border border-dashed border-slate-300 hover:border-[#E00D42] bg-slate-50 hover:bg-rose-50/30 text-slate-700 hover:text-[#E00D42] transition font-bold text-xs font-sans">
                                <Upload className="w-4 h-4 text-[#E00D42]" />
                                <span>Browse image file</span>
                                <input
                                    type="file"
                                    accept={ALLOWED_MIME_TYPES.join(',')}
                                    className="hidden"
                                    onChange={(e) => {
                                        const file = e.target.files?.[0];
                                        if (!file) return;
                                        if (gallery.length >= 9) {
                                            setFileError('Use no more than 9 product images. Remove an image before adding another.');
                                            e.target.value = '';
                                            return;
                                        }
                                        if (!ALLOWED_MIME_TYPES.includes(file.type.toLowerCase())) {
                                            setFileError('Invalid format. Please upload JPG, PNG, WEBP, or GIF.');
                                            e.target.value = '';
                                            return;
                                        }
                                        if (file.size > MAX_FILE_SIZE_BYTES) {
                                            setFileError('Image file exceeds the 5MB size limit.');
                                            e.target.value = '';
                                            return;
                                        }
                                        setFileError(null);
                                        const newItem: GalleryItem = {
                                            id: `var_${Date.now()}_${Math.random().toString(36).substring(2, 6)}`,
                                            type: 'file',
                                            file,
                                            url: URL.createObjectURL(file),
                                            name: file.name,
                                            size: file.size,
                                        };
                                        const newIndex = gallery.length;
                                        setGallery((prev) => [...prev, newItem]);
                                        assignColorPhoto(isCreate, colorId, newItem.url, newItem.id, newIndex);
                                        e.target.value = '';
                                    }}
                                />
                            </label>
                            <UploadHint formats="JPG, PNG, WEBP or GIF" />
                            {fileError && (
                                <div className="flex items-start gap-2 rounded-sm border border-rose-300 bg-rose-50 px-3 py-2 text-[11px] font-semibold text-rose-800" role="alert">
                                    <AlertCircle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                    <span>{fileError}</span>
                                </div>
                            )}
                        </div>

                        {/* SECTION 3: External Image URL */}
                        <div className="pt-4 border-t border-slate-100 space-y-2">
                            <label className="text-xs font-bold text-slate-800 uppercase tracking-wider block">
                                Or Web Image URL
                            </label>
                            <div className="flex items-center gap-2">
                                <input
                                    type="url"
                                    id="variantPhotoUrlInput"
                                    placeholder="https://images.unsplash.com/photo-edition.jpg"
                                    className="flex-1 px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            e.preventDefault();
                                            const val = (e.currentTarget as HTMLInputElement).value.trim();
                                            if (!val) return;
                                            if (gallery.length >= 9) { setFileError('Use no more than 9 product images. Remove an image before adding another.'); return; }
                                            const newItem: GalleryItem = {
                                                id: `var_url_${Date.now()}_${Math.random().toString(36).substring(2, 6)}`,
                                                type: 'url',
                                                url: val,
                                            };
                                            const newIndex = gallery.length;
                                            setGallery((prev) => [...prev, newItem]);
                                            assignColorPhoto(isCreate, colorId, val, newItem.id, newIndex);
                                        }
                                    }}
                                />
                                <button
                                    type="button"
                                    onClick={() => {
                                        const input = document.getElementById('variantPhotoUrlInput') as HTMLInputElement | null;
                                        const val = input?.value.trim();
                                        if (!val) return;
                                        if (gallery.length >= 9) { setFileError('Use no more than 9 product images. Remove an image before adding another.'); return; }
                                        const newItem: GalleryItem = {
                                            id: `var_url_${Date.now()}_${Math.random().toString(36).substring(2, 6)}`,
                                            type: 'url',
                                            url: val,
                                        };
                                        const newIndex = gallery.length;
                                        setGallery((prev) => [...prev, newItem]);
                                        assignColorPhoto(isCreate, colorId, val, newItem.id, newIndex);
                                    }}
                                    className={buttonClasses('secondary', 'md', 'shrink-0')}
                                >
                                    Attach URL
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* Footer */}
                    <div className="px-6 py-3.5 bg-slate-50 border-t border-slate-200 flex items-center justify-end gap-2 shrink-0 font-sans">
                        <button
                            type="button"
                            onClick={() => setActivePhotoPicker(null)}
                            className="px-5 py-2 text-xs font-bold text-slate-700 bg-white border border-slate-200 hover:border-slate-300 rounded-xl transition"
                        >
                            Done
                        </button>
                    </div>
                </div>
            </div>,
            document.body
        );
    };

    const handleDelete = (product: Product) => {
        setFilterError('');
        setProductPendingRemoval(product);
    };

    const confirmProductRemoval = () => {
        if (!productPendingRemoval || listingActionGuard.current) return;
        listingActionGuard.current = true;

        router.delete(route('seller.products.destroy', productPendingRemoval.id), {
            preserveScroll: true,
            onStart: () => setIsRemovingProduct(true),
            onSuccess: () => setProductPendingRemoval(null),
            onError: errors => setFilterError(Object.values(errors).join(' ')),
            onFinish: () => { listingActionGuard.current = false; setIsRemovingProduct(false); },
        });
    };

    const submitProductSearch = (event: React.FormEvent) => {
        event.preventDefault();
        loadProducts(sellerInventoryQuery({ search: searchQuery, category_id: categoryFilter, status: statusFilter, stock: stockFilter }));
    };

    const loadProducts = (query: Record<string, string | number>) => {
        if (filteringGuard.current || createForm.processing || editForm.processing || stockForm.processing || isRemovingProduct) return;
        filteringGuard.current = true; setFiltering(true); setFilterError('');
        const target = new URL(route('seller.products.index'), window.location.origin);
        for (const [key, value] of Object.entries(query)) target.searchParams.set(key, String(value));
        void visitPortal(target.pathname + target.search, { only: listProps, preserveState: true, preserveScroll: true, replace: true,
            onError: errors => setFilterError(Object.values(errors).join(' ')),
            onFinish: () => { filteringGuard.current = false; setFiltering(false); },
            onCancel: () => { filteringGuard.current = false; setFiltering(false); },
        });
    };

    const clearProductSearch = () => {
        setSearchQuery('');
        loadProducts(sellerInventoryQuery({ category_id: categoryFilter, status: statusFilter, stock: stockFilter }));
    };

    const clearProductFilters = () => {
        setSearchQuery('');
        setCategoryFilter('');
        setStatusFilter('all');
        setStockFilter('all');
        loadProducts({});
    };

    const hasFilters = Object.keys(sellerInventoryQuery(filters)).length > 0;

    /** Phones get stacked rows with the key facts; tapping a row opens the listing. */
    const renderMobileListings = () => (
        <ul className="divide-y divide-line-200 md:hidden" aria-label="Listings">
            {products.data.length === 0 ? (
                <li className="px-4 py-10 text-center">
                    {hasFilters
                        ? <><p className="text-sm text-ink-700">No listings match these filters.</p><button type="button" onClick={clearProductFilters} disabled={filtering} className={buttonClasses('secondary', 'sm', 'mt-3')}>Clear filters</button></>
                        : <><p className="text-sm font-semibold text-ink-900">No products yet</p><button type="button" onClick={openCreate} className={buttonClasses('primary', 'sm', 'mt-3')}><Plus className="h-4 w-4" />List New Product</button></>}
                </li>
            ) : products.data.map(product => (
                <li key={product.id} className="flex items-center gap-3 px-4 py-3">
                    <input type="checkbox" aria-label={`Select ${product.name}`} disabled={actionPending} checked={selectedListings.includes(product.id)} onChange={() => toggleListing(product.id)} className="h-5 w-5 shrink-0 rounded border-line-300 text-brand-500 focus:ring-brand-500" />
                    <button type="button" onClick={() => openEdit(product)} className="flex min-w-0 flex-1 items-center gap-3 text-left">
                        {product.featured_image
                            ? <img src={product.featured_image} alt="" className="h-14 w-14 shrink-0 rounded-control border border-line-200 bg-surface-100 object-cover" />
                            : <span className="flex h-14 w-14 shrink-0 items-center justify-center rounded-control border border-line-200 bg-surface-100 text-ink-400"><ImageIcon className="h-5 w-5" /></span>}
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm font-semibold text-ink-900">{product.name}</span>
                            <span className="mt-0.5 block text-sm font-semibold tabular-nums text-ink-900">{formatPrice(product.price)}</span>
                            <span className="mt-1 flex items-center gap-2 text-xs text-ink-600">
                                <Badge tone={product.status === 'active' ? 'success' : 'neutral'}>{product.status === 'active' ? 'Active' : product.status === 'draft' ? 'Draft' : 'Archived'}</Badge>
                                <span className={`tabular-nums ${product.stock === 0 ? 'text-brand-700' : product.stock <= 5 ? 'text-warning' : ''}`}>{product.stock === 0 ? 'Sold out' : `${product.stock} in stock`}</span>
                            </span>
                        </span>
                        <ChevronRight className="h-5 w-5 shrink-0 text-ink-400" />
                    </button>
                </li>
            ))}
        </ul>
    );

    type SharedListingField = 'name' | 'sub_category_id' | 'price' | 'compare_at_price' | 'stock' | 'purchase_limit' | 'sku' | 'description';
    const setListingField = (isCreate: boolean, key: SharedListingField, value: string) => (isCreate ? createForm.setData(key, value) : editForm.setData(key, value));

    /** New and edit listings share one centered dialog: photos first, then basics, price and stock, options, description and shipping details. */
    const renderListingDialog = (isCreate: boolean) => {
        const form = isCreate ? createForm : editForm;
        const product = isCreate ? null : editingProduct;
        const variants = isCreate ? createVariants : editVariants;
        const hasOption1 = isCreate ? createHasOption1 : editHasOption1;
        const hasOption2 = isCreate ? createHasOption2 : editHasOption2;
        const slashedPriceError = isCreate ? createSlashedPriceError : editSlashedPriceError;
        const draftNotice = isCreate ? createDraftNotice : editDraftNotice;
        const draftSaved = isCreate ? createDraftSaved : editDraftSaved;
        const close = isCreate ? closeCreate : closeEdit;
        const prefix = isCreate ? 'create' : 'edit';
        const combinedStock = variants.combinations ? variants.combinations.reduce((total, row) => total + Number(row.stock || 0), 0) : null;
        const department = shop.root_category?.name ?? categories[0]?.name ?? 'Department unavailable';
        const isDraftProduct = product?.status === 'draft';
        const section = 'space-y-4 border-t border-line-200 pt-6 first:border-t-0 first:pt-0';
        const sectionTitle = 'text-base font-semibold text-ink-900';

        return (
            <div className="fixed inset-0 z-[9999] flex items-stretch justify-center font-sans sm:items-center sm:p-6" role="dialog" aria-modal="true" aria-labelledby={`${prefix}-listing-title`}>
                <div className="fixed inset-0 bg-brand-900/45" onClick={close} aria-hidden="true" />
                <div className="relative z-10 flex h-full w-full max-w-4xl flex-col overflow-hidden bg-white text-ink-900 shadow-xl sm:h-auto sm:max-h-[calc(100vh-3rem)] sm:rounded-card">
                    <header className="flex shrink-0 items-start justify-between gap-4 border-b border-line-200 px-4 py-4 sm:px-6">
                        <div className="min-w-0">
                            <h2 id={`${prefix}-listing-title`} className="truncate text-lg font-semibold text-ink-900">{isCreate ? 'New product' : 'Edit product'}</h2>
                            {product && <p className="mt-0.5 truncate text-[13px] text-ink-600">{product.name} · SKU {product.sku || 'auto'}</p>}
                        </div>
                        <button type="button" onClick={close} title="Close" aria-label="Close" className="-mr-2 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-control text-ink-500 hover:bg-surface-100 hover:text-ink-900">
                            <X className="h-5 w-5" />
                        </button>
                    </header>

                    <form onSubmit={isCreate ? handleCreate : handleUpdate} className="flex min-h-0 flex-1 flex-col">
                        <div className="flex-1 space-y-6 overflow-y-auto px-4 py-6 text-sm sm:px-6">
                            {draftNotice && <p role="status" className="rounded-control bg-info-soft px-3 py-2 text-[13px] text-info">{draftNotice}</p>}

                            <section className={section} aria-label="Photos">
                                {renderGalleryManager(isCreate)}
                            </section>

                            <section className={section} aria-label="Basics">
                                <h3 className={sectionTitle}>Basics</h3>
                                <div>
                                    <label htmlFor={`${prefix}-name`} className={labelClasses}>Product title <span className="text-brand-700">*</span></label>
                                    <input id={`${prefix}-name`} type="text" value={form.data.name} minLength={5} maxLength={120} required
                                        onChange={(e) => setListingField(isCreate, 'name', e.target.value)}
                                        placeholder="e.g. Canvas Weekend Tote, 30L"
                                        className={inputClasses} />
                                    {listingFeedback(isCreate, 'name')}
                                </div>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                    <div>
                                        <span className={labelClasses}>Department</span>
                                        <input type="hidden" name="category_id" value={shop.root_category_id ?? ''} />
                                        <p className="flex h-10 items-center truncate text-sm font-medium text-ink-900" aria-label="Approved department, fixed for this shop">{department}</p>
                                    </div>
                                    <div>
                                        <label htmlFor={`${prefix}-subcategory`} className={labelClasses}>Subcategory</label>
                                        <select id={`${prefix}-subcategory`} value={form.data.sub_category_id} onChange={(e) => setListingField(isCreate, 'sub_category_id', e.target.value)} className={inputClasses}>
                                            <option value="">Choose a subcategory</option>
                                            {departmentSubCategories.map((sub) => <option key={sub.id} value={sub.id}>{sub.name}</option>)}
                                        </select>
                                    </div>
                                    <div>
                                        <label htmlFor={`${prefix}-sku`} className={labelClasses}>SKU <span className="font-normal text-ink-500">optional</span></label>
                                        <input id={`${prefix}-sku`} type="text" value={form.data.sku} maxLength={50}
                                            onChange={(e) => setListingField(isCreate, 'sku', e.target.value.toUpperCase())}
                                            placeholder="e.g. TOTE-30L" className={`${inputClasses} uppercase`} />
                                    </div>
                                </div>
                                {product && (
                                    <div className="sm:w-1/3 sm:pr-3">
                                        <label htmlFor="edit-status" className={labelClasses}>Listing status</label>
                                        {isDraftProduct ? (
                                            <p id="edit-status" role="status" className="flex h-10 items-center text-sm text-ink-700">Draft, not visible to buyers</p>
                                        ) : (
                                            <select id="edit-status" value={editForm.data.status} onChange={(e) => editForm.setData('status', e.target.value as 'active' | 'draft' | 'archived')} className={inputClasses}>
                                                <option value="active">Active, visible to buyers</option>
                                                <option value="draft">Draft, hidden</option>
                                                <option value="archived">Archived</option>
                                            </select>
                                        )}
                                    </div>
                                )}
                            </section>

                            <section className={section} aria-label="Price and stock">
                                <h3 className={sectionTitle}>Price and stock</h3>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    <div>
                                        <label htmlFor={`${prefix}-price`} className={labelClasses}>Price (₱) <span className="text-brand-700">*</span></label>
                                        <input id={`${prefix}-price`} type="text" inputMode="decimal" maxLength={10} required placeholder="0.00"
                                            value={form.data.price}
                                            onChange={(e) => { const value = acceptPriceDraft(e.target.value.trim()); if (value !== null) setListingField(isCreate, 'price', value); }}
                                            className={`${inputClasses} tabular-nums`} />
                                        {listingFeedback(isCreate, 'price')}
                                    </div>
                                    <div>
                                        <label htmlFor={`${prefix}-compare`} className={labelClasses}>Original price <span className="font-normal text-ink-500">optional</span></label>
                                        <input id={`${prefix}-compare`} type="text" inputMode="decimal" maxLength={11} placeholder="0.00"
                                            value={form.data.compare_at_price} aria-invalid={slashedPriceError || undefined}
                                            onChange={(e) => { const value = acceptPriceDraft(e.target.value.trim(), 99999999.99); if (value !== null) setListingField(isCreate, 'compare_at_price', value); }}
                                            className={`${inputClasses} tabular-nums`} />
                                        {slashedPriceError ? (
                                            <p role="alert" className="mt-1.5 flex items-start gap-1 text-[13px] text-brand-700"><AlertCircle className="mt-0.5 h-3.5 w-3.5 shrink-0" />Must be higher than {formatPrice(form.data.price)}</p>
                                        ) : form.errors.compare_at_price ? (
                                            <p role="alert" className="mt-1.5 text-[13px] text-brand-700">{form.errors.compare_at_price}</p>
                                        ) : (
                                            <p className="mt-1.5 text-xs text-ink-600">Shown crossed out</p>
                                        )}
                                    </div>
                                    <div>
                                        <label htmlFor={`${prefix}-stock`} className={labelClasses}>Stock <span className="text-brand-700">*</span></label>
                                        <input id={`${prefix}-stock`} type="text" inputMode="numeric" maxLength={5} required
                                            disabled={variants.combinations !== undefined}
                                            value={combinedStock ?? form.data.stock}
                                            onChange={(e) => { const value = acceptStockDraft(e.target.value.trim()); if (value !== null) setListingField(isCreate, 'stock', value); }}
                                            className={`${inputClasses} tabular-nums`} />
                                        {combinedStock !== null ? <p className="mt-1.5 text-xs text-ink-600">Total of the option rows below</p> : listingFeedback(isCreate, 'stock')}
                                    </div>
                                    <div>
                                        <label htmlFor={`${prefix}Form-purchase-limit`} className={labelClasses}>Limit per buyer <span className="font-normal text-ink-500">optional</span></label>
                                        <input id={`${prefix}Form-purchase-limit`} type="text" inputMode="numeric" maxLength={7} placeholder="No limit"
                                            value={form.data.purchase_limit}
                                            onChange={(e) => { const value = acceptWholeNumberDraft(e.target.value.trim(), 1000000, 1); if (value !== null) setListingField(isCreate, 'purchase_limit', value); }}
                                            className={`${inputClasses} tabular-nums`} />
                                        {form.errors.purchase_limit
                                            ? <p role="alert" className="mt-1.5 text-[13px] text-brand-700">{form.errors.purchase_limit}</p>
                                            : <p className="mt-1.5 text-xs text-ink-600">Units across all orders</p>}
                                    </div>
                                </div>
                            </section>

                            <section className={section} aria-label="Options">
                                {renderVariantManager(isCreate)}
                                <VariantStockMatrix colors={hasOption1 ? variants.colors : []} sizes={hasOption2 ? variants.sizes : []} value={variants.combinations}
                                    pricingMode={variants.pricing_mode ?? (isCreate ? 'independent' : 'surcharge')}
                                    onPricingModeChange={pricing_mode => { markDraftAuxDirty(isCreate); (isCreate ? setCreateVariants : setEditVariants)(previous => ({ ...previous, pricing_mode })); }}
                                    basePrice={form.data.price} listingSku={form.data.sku} errors={form.errors}
                                    onChange={combinations => { markDraftAuxDirty(isCreate); (isCreate ? setCreateVariants : setEditVariants)(previous => ({ ...previous, combinations })); }}
                                    error={variantFeedback(isCreate)} />
                            </section>

                            <section className={section} aria-label="Description">
                                <h3 className={sectionTitle}>Description</h3>
                                <div>
                                    <label htmlFor={`${prefix}-description`} className={labelClasses}>What buyers should know <span className="text-brand-700">*</span></label>
                                    <textarea id={`${prefix}-description`} ref={isCreate ? createDescriptionRef : editDescriptionRef}
                                        value={form.data.description} minLength={20} maxLength={3000} rows={4} required
                                        onChange={(e) => setListingField(isCreate, 'description', e.target.value)}
                                        onInput={(e) => resizeDescription(e.currentTarget)}
                                        placeholder="Material, size, what is in the box, care and warranty."
                                        className="block min-h-24 max-h-60 w-full resize-none overflow-y-hidden rounded-control border border-line-300 bg-white px-3 py-2 text-sm text-ink-900 placeholder:text-ink-500 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/40" />
                                    {listingFeedback(isCreate, 'description')}
                                </div>
                                <ListingAssistantPanel
                                    productName={form.data.name}
                                    category={categories.find(category => String(category.id) === String(form.data.category_id))?.name}
                                    currentDetails={form.data.description}
                                    onUseDescription={description => setListingField(isCreate, 'description', description)}
                                />
                            </section>

                            <section className={section} aria-label="Shipping and details">
                                <SellerListingDetails data={form.data} errors={form.errors} tags={storeTags} departmentName={department} productName={form.data.name}
                                    onChange={(key, value) => (isCreate ? createForm.setData(current => ({ ...current, [key]: value })) : editForm.setData(current => ({ ...current, [key]: value })))} />
                            </section>
                            {isCreate && createForm.errors.request_token && <p role="alert" className="text-[13px] text-brand-700">{createForm.errors.request_token}</p>}
                        </div>

                        <footer className="flex shrink-0 flex-col-reverse gap-3 border-t border-line-200 bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <div className="flex items-center gap-3">
                                <button type="button" onClick={isCreate ? discardCreateDraft : discardEditDraft} className="inline-flex h-10 items-center rounded-control px-3 text-sm font-semibold text-brand-700 hover:bg-brand-50">
                                    {isCreate ? 'Discard listing' : 'Discard changes'}
                                </button>
                                <span className="text-xs text-ink-500" aria-live="polite">{draftSaved ? 'Saved on this device' : 'Saves on this device as you type'}</span>
                            </div>
                            <div className="grid grid-cols-2 gap-2 sm:flex sm:items-center">
                                <button type="button" onClick={close} className={buttonClasses('secondary', 'md', 'hidden sm:inline-flex')}>Close</button>
                                {isCreate ? <>
                                    <button type="submit" name="status" value="draft" formNoValidate disabled={createForm.processing || slashedPriceError} className={buttonClasses('secondary')}>Save draft</button>
                                    <button type="submit" name="status" value="active" disabled={createForm.processing || slashedPriceError} className={buttonClasses('primary')}>
                                        {createForm.processing ? 'Saving…' : 'Publish'}
                                    </button>
                                </> : <>
                                    {isDraftProduct && <button type="submit" value="publish" disabled={editForm.processing || slashedPriceError} className={buttonClasses('primary', 'md', 'order-last sm:order-none')}>
                                        <Check className="h-4 w-4" /><span>Publish</span>
                                    </button>}
                                    <button type="submit" value="update"
                                        formNoValidate={isDraftProduct || editForm.data.status !== 'active'}
                                        disabled={editForm.processing || slashedPriceError}
                                        className={buttonClasses(isDraftProduct ? 'secondary' : 'primary', 'md', isDraftProduct ? '' : 'col-span-2')}>
                                        {editForm.processing ? 'Saving…' : isDraftProduct ? 'Update Listing' : 'Save changes'}
                                    </button>
                                </>}
                            </div>
                        </footer>
                    </form>
                </div>
            </div>
        );
    };

    return (
        <DashboardLayout
            title="Products"
            subtitle={`Catalog and inventory for ${shop.name}`}
        >
            <Head title="Products — BagooPH Seller" />

            <div className="space-y-4 font-sans">
                {filterError && <p role="alert" className="rounded-control bg-brand-50 px-3 py-2 text-[13px] text-brand-700">{filterError}</p>}

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-sm text-ink-600">
                        <span className="font-semibold text-ink-900 tabular-nums">{products.total ?? products.data.length}</span> {(products.total ?? products.data.length) === 1 ? 'listing' : 'listings'}
                        {hasFilters && <span> match these filters</span>}
                    </p>
                    <button type="button" onClick={openCreate} className={buttonClasses('primary')}>
                        <Plus className="h-4 w-4" />
                        <span>List New Product</span>
                    </button>
                </div>

                <form onSubmit={submitProductSearch} className="grid grid-cols-2 gap-3 rounded-card border border-line-200 bg-white p-3 lg:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))_auto]" aria-label="Filter listings">
                    <div className="relative col-span-2 lg:col-span-1">
                        <label htmlFor="inventory-search" className="sr-only">Search listings</label>
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" />
                        <input
                            id="inventory-search"
                            type="search"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            maxLength={100}
                            placeholder="Search by title or SKU"
                            className={`${inputClasses} pl-9 pr-9`}
                        />
                        {searchQuery && (
                            <button type="button" onClick={clearProductSearch} disabled={filtering} className="absolute right-1 top-1/2 inline-flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-control text-ink-500 hover:text-ink-900 disabled:opacity-50" aria-label="Clear product search">
                                <X className="h-4 w-4" />
                            </button>
                        )}
                    </div>
                    <div className="col-span-2 lg:col-span-1">
                        <label htmlFor="inventory-category" className="sr-only">Category</label>
                        <select id="inventory-category" value={categoryFilter} onChange={e => setCategoryFilter(e.target.value)} className={inputClasses}>
                            <option value="">All categories</option>
                            {categories.map(category => <option key={category.id} value={category.id}>{category.name}</option>)}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="inventory-status" className="sr-only">Listing status</label>
                        <select id="inventory-status" value={statusFilter} onChange={e => setStatusFilter(e.target.value as SellerInventoryFilters['status'])} className={inputClasses}>
                            <option value="all">All statuses</option>
                            <option value="active">Active</option>
                            <option value="draft">Draft</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                    <div>
                        <label htmlFor="inventory-stock" className="sr-only">Available stock</label>
                        <select id="inventory-stock" value={stockFilter} onChange={e => setStockFilter(e.target.value as SellerInventoryFilters['stock'])} className={inputClasses}>
                            <option value="all">All stock levels</option>
                            <option value="low_stock">Active: low stock (1–5)</option>
                            <option value="out_of_stock">Active: out of stock</option>
                            <option value="in_stock">Active: in stock</option>
                        </select>
                    </div>
                    <div className="col-span-2 flex gap-2 lg:col-span-1">
                        <button type="submit" disabled={filtering} className={buttonClasses('secondary', 'md', 'flex-1 lg:flex-none')}>
                            {filtering ? 'Applying…' : 'Apply'}
                        </button>
                        {hasFilters && <button type="button" onClick={clearProductFilters} disabled={filtering} className={buttonClasses('ghost', 'md', 'flex-1 lg:flex-none')}>Clear filters</button>}
                    </div>
                </form>

                {bulkResults.length > 0 && <section aria-label="Product action results" className="rounded-card border border-line-200 bg-white p-4 text-sm"><h2 className="mb-2 font-semibold text-ink-900">Selection results</h2><ul className="space-y-1 text-ink-700">{bulkResults.map(result => <li key={result.id}><strong className="font-semibold text-ink-900">{result.name}</strong>: {result.reason}</li>)}</ul></section>}
                {selectedListings.length > 0 && <div className="flex flex-wrap items-center gap-2 rounded-card border border-line-200 bg-white px-4 py-3 text-sm">
                    <strong className="mr-2 font-semibold text-ink-900">{selectedListings.length} selected</strong>
                    <button type="button" disabled={actionPending} onClick={() => setBulkAction('deactivate')} className={buttonClasses('secondary', 'sm')}>Deactivate</button>
                    <button type="button" disabled={actionPending} onClick={() => setBulkAction('delete')} className={buttonClasses('danger', 'sm')}>Delete</button>
                    <button type="button" disabled={actionPending} onClick={() => { setSelectedListings([]); setBulkAction(null); }} className={buttonClasses('ghost', 'sm')}>Clear selection</button>
                    {bulkAction && <div className="w-full border-t border-line-200 pt-3" role="group" aria-label="Confirm product selection action"><p className="mb-3 text-ink-700">{bulkAction === 'delete' ? 'Products with unfinished orders will be skipped. Products with order history are archived instead of deleted.' : 'Selected listings stop accepting new orders. Existing orders and history stay.'}</p><div className="flex gap-2"><button type="button" disabled={actionPending} onClick={runBulk} className={buttonClasses('primary', 'sm')}>{actionPending ? 'Saving…' : 'Confirm selection'}</button><button type="button" disabled={actionPending} onClick={() => setBulkAction(null)} className={buttonClasses('secondary', 'sm')}>Cancel</button></div></div>}
                </div>}

                <div className="overflow-hidden rounded-card border border-line-200 bg-white">
                    {renderMobileListings()}
                    <div className="hidden overflow-x-auto md:block">
                        <table className="w-full min-w-[720px] text-left text-sm">
                            <thead className="border-b border-line-200 bg-surface-50 text-xs font-medium uppercase tracking-wide text-ink-600">
                                <tr>
                                    <th scope="col" className="w-12 py-3 pl-4"><input type="checkbox" aria-label="Select this page" disabled={!products.data.length || actionPending} checked={products.data.length > 0 && products.data.every(product => selectedListings.includes(product.id))} onChange={e => setSelectedListings(e.target.checked ? products.data.map(product => product.id) : [])} className="rounded border-line-300 text-brand-500 focus:ring-brand-500" /></th>
                                    <th scope="col" className="py-3 pr-4">Product</th>
                                    <th scope="col" className="w-28 px-4 py-3 text-right">Price</th>
                                    <th scope="col" className="w-28 px-4 py-3 text-right">Stock</th>
                                    <th scope="col" className="w-20 px-4 py-3 text-right">Sold</th>
                                    <th scope="col" className="w-28 px-4 py-3">Status</th>
                                    <th scope="col" className="w-40 py-3 pl-2 pr-4 text-right"><span className="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line-200">
                                {products.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={7} className="px-4 py-12 text-center">
                                            {hasFilters ? (
                                                <><p className="text-sm text-ink-700">No listings match these filters.</p><button type="button" onClick={clearProductFilters} disabled={filtering} className={buttonClasses('secondary', 'sm', 'mt-3')}>Clear filters</button></>
                                            ) : (
                                                <><p className="text-sm font-semibold text-ink-900">No products yet</p><p className="mt-1 text-sm text-ink-600">Your listings appear here once you add them.</p><button type="button" onClick={openCreate} className={buttonClasses('primary', 'sm', 'mt-3')}><Plus className="h-4 w-4" />List New Product</button></>
                                            )}
                                        </td>
                                    </tr>
                                ) : (
                                    products.data.map((product) => {
                                        const optionCount = (product.variants?.combinations?.length) || ((product.variants?.colors?.length ?? 0) + (product.variants?.sizes?.length ?? 0));
                                        const stockTone = product.stock > 5 ? 'text-ink-900' : product.stock > 0 ? 'text-warning' : 'text-brand-700';
                                        const reserved = Number(product.open_order_units || 0);
                                        const statusTone: BadgeTone = product.status === 'active' ? 'success' : 'neutral';
                                        return (
                                            <tr key={product.id} className="align-middle hover:bg-surface-50">
                                                <td className="py-3 pl-4"><input type="checkbox" aria-label={`Select ${product.name}`} disabled={actionPending} checked={selectedListings.includes(product.id)} onChange={() => toggleListing(product.id)} className="rounded border-line-300 text-brand-500 focus:ring-brand-500" /></td>
                                                <td className="max-w-0 py-3 pr-4">
                                                    <div className="flex min-w-0 items-center gap-3">
                                                        {product.featured_image
                                                            ? <img src={product.featured_image} alt="" className="h-12 w-12 shrink-0 rounded-control border border-line-200 bg-surface-100 object-cover" />
                                                            : <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-control border border-line-200 bg-surface-100 text-ink-400"><ImageIcon className="h-5 w-5" /></span>}
                                                        <div className="min-w-0">
                                                            <button type="button" onClick={() => openEdit(product)} className="block max-w-full truncate text-left font-semibold text-ink-900 hover:underline" title={product.name}>{product.name}</button>
                                                            <p className="mt-0.5 truncate text-xs text-ink-600">
                                                                {[product.sub_category?.name, product.sku ? `SKU ${product.sku}` : null, optionCount ? `${optionCount} options` : null].filter(Boolean).join(' · ') || 'No SKU'}
                                                            </p>
                                                            {product.compliance_restricted && (
                                                                <details className="mt-1 max-w-sm text-xs text-brand-700">
                                                                    <summary className="cursor-pointer font-semibold">Restricted from new purchases</summary>
                                                                    <p className="mt-1 whitespace-pre-line">{product.compliance_feedback?.reason ?? 'Ask Platform Admin to review this restriction.'}</p>
                                                                    <p className="mt-1">{product.compliance_feedback?.next_action ?? 'Editing or publishing does not lift this restriction.'}</p>
                                                                </details>
                                                            )}
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3 text-right font-semibold tabular-nums text-ink-900">{formatPrice(product.price)}</td>
                                                <td className="px-4 py-3 text-right">
                                                    <button
                                                        type="button"
                                                        onClick={() => product.variants?.combinations ? openEdit(product) : openStockEditor(product)}
                                                        aria-label={`Update available stock for ${product.name}`}
                                                        className={`inline-flex items-center gap-1 rounded-control border border-line-300 px-2 py-1 font-semibold tabular-nums hover:border-ink-400 hover:bg-surface-100 ${stockTone}`}
                                                    >
                                                        {product.stock}<ChevronRight className="h-3.5 w-3.5 text-ink-400" />
                                                    </button>
                                                    {(product.stock <= 5 || reserved > 0) && (
                                                        <p className="mt-1 whitespace-nowrap text-xs text-ink-600">{product.stock === 0 ? 'Sold out' : product.stock <= 5 ? 'Low stock' : `${reserved} reserved`}</p>
                                                    )}
                                                    {(product.variants?.combinations?.length || product.variants?.sizes?.length || product.variants?.colors?.length) ? (
                                                        <details className="mt-1 text-left text-xs text-ink-700">
                                                            <summary className="cursor-pointer text-right font-medium text-ink-600 hover:text-ink-900">By option</summary>
                                                            <ul className="mt-1 space-y-0.5">
                                                                {product.variants?.combinations
                                                                    ? product.variants.combinations.map((row, index) => <li key={index}>{[row.color, row.size].filter(Boolean).join(' / ')}: <strong>{row.stock}</strong> available</li>)
                                                                    : <>
                                                                        {product.variants?.sizes?.map((variant, index) => <li key={variant.id || index}>{variant.name}: <strong>{sellerVariantStock(variant.stock, product.stock)}</strong>{variant.stock == null ? ' (listing stock)' : ' available'}</li>)}
                                                                        {product.variants?.colors?.map((variant, index) => <li key={variant.id || index}>{variant.name}: {variant.in_stock === false ? 'Unavailable' : 'Available'}</li>)}
                                                                    </>}
                                                            </ul>
                                                        </details>
                                                    ) : null}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums text-ink-700">{Number(product.completed_units || 0)}</td>
                                                <td className="px-4 py-3"><Badge tone={statusTone}>{product.status === 'active' ? 'Active' : product.status === 'draft' ? 'Draft' : 'Archived'}</Badge></td>
                                                <td className="py-3 pl-2 pr-4">
                                                    <div className="flex items-center justify-end gap-0.5">
                                                        <Link href={route('seller.products.sales.index', product.id)} className={buttonClasses('ghost', 'sm')}>Sales</Link>
                                                        <button type="button" onClick={() => openEdit(product)} className={'inline-flex h-8 w-8 items-center justify-center rounded-control text-ink-600 hover:bg-surface-100 hover:text-ink-900 disabled:opacity-50'} title="Edit Product" aria-label={`Edit ${product.name}`}><Edit3 className="h-4 w-4" /></button>
                                                        <button type="button" disabled={actionPending} onClick={() => duplicateListing(product)} title="Duplicate as draft" aria-label={`Duplicate ${product.name} as draft`} className={'inline-flex h-8 w-8 items-center justify-center rounded-control text-ink-600 hover:bg-surface-100 hover:text-ink-900 disabled:opacity-50'}><Copy className="h-4 w-4" /></button>
                                                        <button type="button" onClick={() => handleDelete(product)} className="inline-flex h-8 w-8 items-center justify-center rounded-control text-ink-600 hover:bg-brand-50 hover:text-brand-700" title="Remove Product" aria-label={`Remove ${product.name}`}><Trash2 className="h-4 w-4" /></button>
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>
                    {products.last_page > 1 && (
                        <div className="flex flex-col gap-3 border-t border-line-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-[13px] text-ink-600">
                                Page <strong>{products.current_page}</strong> of <strong>{products.last_page}</strong>
                            </p>
                            <nav className="flex flex-wrap items-center gap-1.5" aria-label="Product catalog pagination">
                                {products.links?.map((link, index) => {
                                    const isPrevious = index === 0;
                                    const isNext = index === (products.links?.length ?? 0) - 1;
                                    const label = isPrevious ? 'Previous' : isNext ? 'Next' : link.label;

                                    return link.url ? (
                                        <PortalLink
                                            key={`${label}-${index}`}
                                            href={link.url}
                                            only={listProps}
                                            preserveState
                                            preserveScroll
                                            className={`inline-flex h-8 min-w-8 items-center justify-center rounded-control border px-3 text-[13px] font-semibold tabular-nums ${
                                                link.active
                                                    ? 'border-brand-500 bg-brand-50 text-brand-700'
                                                    : 'border-line-300 bg-white text-ink-700 hover:bg-surface-100'
                                            }`}
                                        >
                                            {label}
                                        </PortalLink>
                                    ) : (
                                        <span
                                            key={`${label}-${index}`}
                                            className="inline-flex h-8 min-w-8 cursor-not-allowed items-center justify-center rounded-control border border-line-200 px-3 text-[13px] text-ink-400"
                                        >
                                            {label}
                                        </span>
                                    );
                                })}
                            </nav>
                        </div>
                    )}
                </div>
            </div>

            {typeof document !== 'undefined' && stockProduct && createPortal(
                <div
                    className="fixed inset-0 z-[10000] flex items-center justify-center bg-slate-950/60 p-4 font-sans"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="stock-modal-title"
                    onClick={() => !stockForm.processing && setStockProduct(null)}
                >
                    <div
                        className="w-full max-w-md rounded-lg border border-slate-300 bg-white shadow-2xl"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <div className="flex items-start justify-between border-b border-slate-200 px-5 py-4">
                            <div className="min-w-0 pr-4">
                                <h2 id="stock-modal-title" className="text-base font-bold text-slate-950">Update available stock</h2>
                                <p className="mt-1 truncate text-xs text-slate-500">{stockProduct.name}</p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setStockProduct(null)}
                                disabled={stockForm.processing}
                                className="rounded-sm p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-50"
                                aria-label="Close stock editor"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </div>

                        <form onSubmit={handleStockUpdate} className="space-y-5 p-5">
                            <div className="rounded-md border border-slate-300 bg-slate-50 px-4 py-3">
                                <p className="text-[11px] font-semibold text-slate-500">Current available stock</p>
                                <p className="mt-0.5 text-2xl font-bold text-slate-950">{stockProduct.stock}</p>
                            </div>

                            <div className="grid grid-cols-2 gap-2" aria-label="Stock update method">
                                <button
                                    type="button"
                                    onClick={() => changeStockMode('set')}
                                    className={`rounded-sm border px-3 py-2.5 text-xs font-bold transition ${stockForm.data.mode === 'set' ? 'border-[#E00D42] bg-rose-50 text-[#E00D42]' : 'border-slate-300 bg-white text-slate-600 hover:border-slate-400'}`}
                                >
                                    Set exact stock
                                </button>
                                <button
                                    type="button"
                                    onClick={() => changeStockMode('add')}
                                    className={`rounded-sm border px-3 py-2.5 text-xs font-bold transition ${stockForm.data.mode === 'add' ? 'border-[#E00D42] bg-rose-50 text-[#E00D42]' : 'border-slate-300 bg-white text-slate-600 hover:border-slate-400'}`}
                                >
                                    Add stock
                                </button>
                            </div>

                            <div>
                                <label htmlFor="stock-quantity" className="mb-1.5 block text-xs font-bold text-slate-700">
                                    {stockForm.data.mode === 'set' ? 'New available stock' : 'Units to add'}
                                </label>
                                <input
                                    id="stock-quantity"
                                    type="text"
                                    inputMode="numeric"
                                    maxLength={5}
                                    required
                                    autoFocus
                                    value={stockForm.data.quantity}
                                    onChange={(event) => { const value = acceptWholeNumberDraft(event.target.value.trim(), 99999, stockForm.data.mode === 'add' ? 1 : 0); if (value !== null) stockForm.setData('quantity', value); }}
                                    className="w-full rounded-sm border border-slate-300 bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-950 focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42]"
                                />
                                {stockForm.errors.quantity && (
                                    <p className="mt-1.5 text-xs font-semibold text-rose-700">{stockForm.errors.quantity}</p>
                                )}
                                {stockProduct.variants?.sizes?.length ? (
                                    <p className="mt-2 text-[11px] leading-relaxed text-slate-500">
                                        This updates overall inventory. Size-specific limits remain unchanged and can be managed in Edit Product.
                                    </p>
                                ) : null}
                            </div>

                            <div className="flex justify-end gap-2 border-t border-slate-200 pt-4">
                                <button
                                    type="button"
                                    onClick={() => setStockProduct(null)}
                                    disabled={stockForm.processing}
                                    className="rounded-sm border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={stockForm.processing}
                                    className="rounded-sm bg-[#E00D42] px-4 py-2 text-xs font-bold text-white hover:bg-[#C20836] disabled:opacity-50"
                                >
                                    {stockForm.processing ? 'Updating...' : 'Update stock'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>,
                document.body
            )}

            {typeof document !== 'undefined' && productPendingRemoval && createPortal(
                <div
                    className="fixed inset-0 z-[10000] flex items-center justify-center bg-slate-950/60 p-4 font-sans"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="remove-product-title"
                    onClick={() => !isRemovingProduct && setProductPendingRemoval(null)}
                >
                    <div
                        className="w-full max-w-md rounded-lg border border-slate-300 bg-white shadow-2xl"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <div className="flex items-start gap-3 border-b border-slate-200 px-5 py-4">
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-sm border border-rose-200 bg-rose-50 text-rose-700">
                                <Trash2 className="h-4 w-4" />
                            </div>
                            <div className="min-w-0 flex-1">
                                <h2 id="remove-product-title" className="text-base font-bold text-slate-950">
                                    Delete product?
                                </h2>
                                <p className="mt-1 truncate text-xs text-slate-500">{productPendingRemoval.name}</p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setProductPendingRemoval(null)}
                                disabled={isRemovingProduct}
                                className="rounded-sm p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-50"
                                aria-label="Close product removal dialog"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </div>

                        <div className="space-y-4 px-5 py-4">
                            {filterError && <p role="alert" className="text-sm text-rose-700">{filterError}</p>}
                            {Number(productPendingRemoval.order_items_count || 0) > 0 ? (
                                <div className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-xs leading-relaxed text-amber-900">
                                    Unfinished orders block deletion and leave the listing unchanged. Completed order history is archived and kept. Use Edit to archive the listing if you want to pause new purchases.
                                </div>
                            ) : (
                                <div className="rounded-md border border-rose-300 bg-rose-50 px-4 py-3 text-xs leading-relaxed text-rose-900">
                                    Unreferenced listings are permanently deleted. Saved references are archived to preserve their history. Unfinished orders always block deletion.
                                </div>
                            )}

                            <div className="flex justify-end gap-2 border-t border-slate-200 pt-4">
                                <button
                                    type="button"
                                    onClick={() => setProductPendingRemoval(null)}
                                    disabled={isRemovingProduct}
                                    className="rounded-sm border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    onClick={confirmProductRemoval}
                                    disabled={isRemovingProduct}
                                    className="rounded-sm bg-rose-700 px-4 py-2 text-xs font-bold text-white hover:bg-rose-800 disabled:opacity-50"
                                >
                                    {isRemovingProduct ? 'Working…' : 'Confirm removal'}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>,
                document.body
            )}

            {/* Create Product Slide-Over Sidebar Drawer */}
            {typeof document !== 'undefined' && isCreateOpen && createPortal(renderListingDialog(true), document.body)}
            {typeof document !== 'undefined' && editingProduct && createPortal(renderListingDialog(false), document.body)}

            {/* Dedicated Variant Photo Picker Modal */}
            {renderPhotoPickerModal()}
        </DashboardLayout>
    );
}
