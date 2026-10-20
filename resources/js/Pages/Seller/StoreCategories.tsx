import { useRef, useState } from 'react';
import { DialogTitle } from '@headlessui/react';
import { Head, router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Edit3, Eye, EyeOff, Folder, Plus, Trash2 } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import Modal from '@/Components/Modal';
import type { Shop, StoreCategory } from '@/types';

type Preview = { category: { id: number; name: string }; children: { id: number; name: string }[]; products: { id: number; name: string }[]; product_count: number; version: string };

export default function StoreCategories({ shop, categories }: { shop: Shop; categories: StoreCategory[] }) {
    const [editing, setEditing] = useState<number | null>(null);
    const [pending, setPending] = useState(false);
    const [error, setError] = useState('');
    const [preview, setPreview] = useState<Preview | null>(null);
    const guard = useRef(false);
    const form = useForm({ name: '', parent_id: '', is_visible: true });
    const roots = categories.filter(category => !category.parent_id);
    const ordered = roots.flatMap(root => [root, ...categories.filter(category => category.parent_id === root.id)]);
    const cleanName = form.data.name.trim().replace(/\s+/g, ' ');
    const nameError = form.data.name && (!cleanName || Array.from(cleanName).length > 50) ? 'Use 1–50 characters for the category name.' : '';
    const clear = () => { form.reset(); form.clearErrors(); setEditing(null); };
    const edit = (category: StoreCategory) => {
        if (pending || form.processing) return;
        form.clearErrors(); setEditing(category.id);
        form.setData({ name: category.name, parent_id: category.parent_id ? String(category.parent_id) : '', is_visible: category.is_visible });
    };
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        if (form.processing || pending || nameError) return;
        const options = { preserveScroll: true, onSuccess: clear };
        if (editing) form.put(route('seller.store-categories.update', editing), options);
        else form.post(route('seller.store-categories.store'), options);
    };
    const action = (send: (options: object) => void) => {
        if (guard.current || form.processing) return;
        guard.current = true; setPending(true); setError('');
        send({ preserveScroll: true, onError: (errors: Record<string, string>) => setError(Object.values(errors).join(' ')),
            onSuccess: () => setPreview(null), onFinish: () => { guard.current = false; setPending(false); } });
    };
    const move = (category: StoreCategory, offset: number) => {
        const siblings = categories.filter(row => row.parent_id === category.parent_id);
        const index = siblings.findIndex(row => row.id === category.id);
        if (index + offset < 0 || index + offset >= siblings.length) return;
        [siblings[index], siblings[index + offset]] = [siblings[index + offset], siblings[index]];
        action(options => router.post(route('seller.store-categories.reorder'), { category_ids: siblings.map(row => row.id) }, options));
    };
    const showPreview = async (category: StoreCategory) => {
        if (guard.current || form.processing) return;
        guard.current = true; setPending(true); setError('');
        try {
            const response = await fetch(route('seller.store-categories.preview', category.id), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error();
            setPreview(await response.json());
        } catch { setError('Could not load the affected products. Refresh this page and try again.'); }
        finally { guard.current = false; setPending(false); }
    };
    const input = 'w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]';
    return <DashboardLayout title="Store categories" subtitle={`Organize the storefront for ${shop.name}`}>
        <Head title="Store categories — BagooPH Seller" />
        <div className="space-y-5">
            <p className="text-sm leading-relaxed text-slate-600">Group products for your customers. These categories organize your store; products keep their approved department.</p>
            {error && <p role="alert" className="rounded-xl border border-rose-300 bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
            <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                <section className="overflow-hidden rounded-2xl border border-slate-300 bg-white shadow-xs" aria-label="Saved store categories">
                    <div className="flex items-center gap-3 border-b border-slate-300 p-5"><Folder className="h-5 w-5 text-[#E00D42]" /><h2 className="text-sm font-bold">Your categories ({categories.length})</h2></div>
                    {!ordered.length && <div className="flex min-h-72 flex-col items-center justify-center gap-2 p-6 text-center"><Folder className="h-8 w-8 text-slate-400" /><h3 className="text-sm font-bold">No store categories yet</h3><p className="max-w-sm text-xs leading-relaxed text-slate-500">Add a category, then assign products from the product editor. Your existing listings remain available.</p></div>}
                    <ul className="divide-y divide-slate-200">{ordered.map(category => {
                        const siblings = categories.filter(row => row.parent_id === category.parent_id);
                        const index = siblings.findIndex(row => row.id === category.id);
                        const parentHidden = category.parent_id && !categories.find(row => row.id === category.parent_id)?.is_visible;
                        return <li key={category.id} className={`flex flex-wrap items-center justify-between gap-3 px-5 py-4 ${category.parent_id ? 'ml-6 border-l border-slate-300' : ''}`}>
                            <div className="min-w-0 flex-1"><p className="break-words text-sm font-semibold text-slate-900">{category.name}</p><p className="mt-1 text-xs text-slate-500">{category.products_count ?? 0} products · {category.is_visible && !parentHidden ? 'Visible' : parentHidden ? 'Hidden with parent' : 'Hidden'}</p></div>
                            <div className="flex items-center gap-1">
                                <button type="button" disabled={pending || form.processing || index === 0} aria-label={`Move ${category.name} up`} onClick={() => move(category, -1)} className="rounded-lg p-2 text-slate-600 hover:bg-slate-100 disabled:opacity-30"><ArrowUp className="h-4 w-4" /></button>
                                <button type="button" disabled={pending || form.processing || index === siblings.length - 1} aria-label={`Move ${category.name} down`} onClick={() => move(category, 1)} className="rounded-lg p-2 text-slate-600 hover:bg-slate-100 disabled:opacity-30"><ArrowDown className="h-4 w-4" /></button>
                                <button type="button" disabled={pending || form.processing} aria-label={`${category.is_visible ? 'Hide' : 'Show'} ${category.name}`} onClick={() => action(options => router.put(route('seller.store-categories.update', category.id), { name: category.name, parent_id: category.parent_id ?? '', is_visible: !category.is_visible }, options))} className="rounded-lg p-2 text-slate-600 hover:bg-slate-100 disabled:opacity-30">{category.is_visible ? <Eye className="h-4 w-4" /> : <EyeOff className="h-4 w-4" />}</button>
                                <button type="button" disabled={pending || form.processing} aria-label={`Edit ${category.name}`} onClick={() => edit(category)} className="rounded-lg p-2 text-slate-600 hover:bg-slate-100 disabled:opacity-30"><Edit3 className="h-4 w-4" /></button>
                                <button type="button" disabled={pending || form.processing} aria-label={`Delete ${category.name}`} onClick={() => void showPreview(category)} className="rounded-lg p-2 text-rose-700 hover:bg-rose-50 disabled:opacity-30"><Trash2 className="h-4 w-4" /></button>
                            </div>
                        </li>;
                    })}</ul>
                </section>
                <form onSubmit={submit} className="space-y-4 rounded-2xl border border-slate-300 bg-white p-5 shadow-xs">
                    <h2 className="flex items-center gap-2 text-sm font-bold"><Plus className="h-4 w-4 text-[#E00D42]" />{editing ? 'Edit category' : 'Add category'}</h2>
                    <label className="block space-y-2 text-xs font-semibold text-slate-700">Category name<input required maxLength={200} value={form.data.name} onChange={event => form.setData('name', event.target.value)} aria-invalid={Boolean(nameError || form.errors.name)} className={input} /><span className="block font-normal text-slate-500">1–50 characters. Names are unique within your store.</span>{(nameError || form.errors.name) && <span role="alert" className="block font-normal text-rose-700">{nameError || form.errors.name}</span>}</label>
                    <label className="block space-y-2 text-xs font-semibold text-slate-700">Parent category<select value={form.data.parent_id} disabled={Boolean(editing && categories.some(row => row.parent_id === editing))} onChange={event => form.setData('parent_id', event.target.value)} className={input}><option value="">None — top level</option>{roots.filter(root => root.id !== editing).map(root => <option key={root.id} value={root.id}>{root.name}</option>)}</select><span className="block font-normal text-slate-500">Use up to two levels. A parent with children stays at the top level.</span>{form.errors.parent_id && <span role="alert" className="block font-normal text-rose-700">{form.errors.parent_id}</span>}</label>
                    <label className="flex items-center gap-2 text-xs text-slate-700"><input type="checkbox" checked={form.data.is_visible} onChange={event => form.setData('is_visible', event.target.checked)} className="rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" />Show on storefront</label>
                    {form.errors.is_visible && <p role="alert" className="text-xs text-rose-700">{form.errors.is_visible}</p>}
                    <div className="flex gap-2"><button disabled={form.processing || pending || Boolean(nameError)} className="rounded-xl bg-[#E00D42] px-4 py-2.5 text-xs font-bold text-white disabled:opacity-50">{form.processing ? 'Saving…' : editing ? 'Save category' : 'Add category'}</button>{editing && <button type="button" disabled={form.processing} onClick={clear} className="rounded-xl border border-slate-300 px-4 py-2.5 text-xs font-semibold">Cancel</button>}</div>
                </form>
            </div>
        </div>
        <Modal show={Boolean(preview)} onClose={() => !pending && setPreview(null)} closeable={!pending} maxWidth="lg">
            {preview && <div className="space-y-4 p-6"><DialogTitle className="text-lg font-bold text-slate-900">Delete {preview.category.name}?</DialogTitle><p className="text-sm leading-relaxed text-slate-600">This affects {preview.product_count} {preview.product_count === 1 ? 'product' : 'products'}. Products will stay listed. Those with no remaining store category will appear under Uncategorized.</p>
                {preview.children.length > 0 && <div><h3 className="text-xs font-bold text-slate-700">Subcategories to delete</h3><ul className="mt-2 space-y-1 text-sm text-slate-600">{preview.children.map(child => <li key={child.id}>{child.name}</li>)}</ul></div>}
                <div className="max-h-52 overflow-y-auto"><h3 className="text-xs font-bold text-slate-700">Affected products</h3>{preview.products.length ? <ul className="mt-2 space-y-1 text-sm text-slate-600">{preview.products.map(product => <li key={product.id}>{product.name}</li>)}</ul> : <p className="mt-2 text-sm text-slate-500">No products are assigned.</p>}</div>
                {error && <p role="alert" className="text-sm text-rose-700">{error}</p>}
                <div className="flex justify-end gap-2"><button type="button" disabled={pending} onClick={() => setPreview(null)} className="rounded-xl border border-slate-300 px-4 py-2.5 text-xs font-semibold">Cancel</button><button type="button" disabled={pending} onClick={() => action(options => router.delete(route('seller.store-categories.destroy', preview.category.id), { ...options, data: { version: preview.version } }))} className="rounded-xl bg-[#E00D42] px-4 py-2.5 text-xs font-bold text-white disabled:opacity-50">{pending ? 'Deleting…' : 'Delete category'}</button></div>
            </div>}
        </Modal>
    </DashboardLayout>;
}
