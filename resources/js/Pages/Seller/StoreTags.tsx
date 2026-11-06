import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { Hash, Pencil, Plus, Trash2 } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import type { Shop, StoreTag } from '@/types';

export default function StoreTags({ shop, tags }: { shop: Shop; tags: StoreTag[] }) {
    const [editing, setEditing] = useState<number | null>(null);
    const [pending, setPending] = useState(false);
    const form = useForm({ name: '', product_name: '' });
    const clear = () => { form.reset(); form.clearErrors(); setEditing(null); };
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        if (form.processing || pending) return;
        const options = { preserveScroll: true, onSuccess: clear };
        if (editing) form.put(route('seller.store-tags.update', editing), options);
        else form.post(route('seller.store-tags.store'), options);
    };
    const edit = (tag: StoreTag) => {
        if (form.processing || pending) return;
        form.clearErrors();
        setEditing(tag.id);
        form.setData({ name: tag.name, product_name: '' });
    };
    const remove = (tag: StoreTag) => {
        if (form.processing || pending) return;
        const linkedCount = tag.products_count ?? 0;
        if (!window.confirm(`Remove “${tag.name}” from your shop and ${linkedCount} linked ${linkedCount === 1 ? 'product' : 'products'}?`)) return;
        setPending(true);
        router.delete(route('seller.store-tags.destroy', tag.id), { preserveScroll: true, onFinish: () => setPending(false) });
    };
    const cleanName = form.data.name.trim().replace(/\s+/g, ' ');
    const nameError = form.data.name && (!cleanName || Array.from(cleanName).length > 50) ? 'Use 1–50 characters for a tag name.' : '';
    const tagRelevanceError = (form.errors as typeof form.errors & { tag_names?: string }).tag_names;
    const editingTag = editing === null ? null : tags.find(tag => tag.id === editing);
    const needsProductContext = editing === null || (editingTag?.products_count ?? 0) === 0 || Boolean(form.errors.product_name);
    const input = 'mt-2 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]';

    return <DashboardLayout title="Store tags" subtitle={`Describe products in ${shop.name}`}>
        <Head title="Store tags — BagooPH Seller" />
        <div className="space-y-5">
            <div className="rounded-xl border border-slate-300 bg-white p-4 text-sm leading-relaxed text-slate-600">
                Every product stays in the department approved for your shop. Use flat tags to describe products and help buyers find them. Gemini checks each new tag against your approved department before it is saved.
            </div>
            <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                <section className="overflow-hidden rounded-2xl border border-slate-300 bg-white shadow-xs" aria-label="Saved product tags">
                    <div className="flex items-center gap-3 border-b border-slate-300 p-5"><Hash className="h-5 w-5 text-[#E00D42]" /><h2 className="text-sm font-bold">Your tags ({tags.length})</h2></div>
                    {!tags.length && <div className="flex min-h-64 flex-col items-center justify-center gap-2 p-6 text-center"><Hash className="h-8 w-8 text-slate-400" /><h3 className="text-sm font-bold">No tags yet</h3><p className="max-w-sm text-xs leading-relaxed text-slate-500">Add a tag here or describe products with tags in the product editor.</p></div>}
                    <ul className="divide-y divide-slate-200">{tags.map(tag => <li key={tag.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div className="min-w-0 flex-1"><p className="break-words text-sm font-semibold text-slate-900">{tag.name}</p><p className="mt-1 text-xs text-slate-500">{tag.products_count ?? 0} linked {(tag.products_count ?? 0) === 1 ? 'product' : 'products'}</p></div>
                        <div className="flex items-center gap-1"><button type="button" disabled={pending || form.processing} onClick={() => edit(tag)} aria-label={`Edit ${tag.name}`} className="rounded-lg p-2 text-slate-600 hover:bg-slate-100 disabled:opacity-30"><Pencil className="h-4 w-4" /></button><button type="button" disabled={pending || form.processing} onClick={() => remove(tag)} aria-label={`Remove ${tag.name}`} className="rounded-lg p-2 text-rose-700 hover:bg-rose-50 disabled:opacity-30"><Trash2 className="h-4 w-4" /></button></div>
                    </li>)}</ul>
                </section>
                <form onSubmit={submit} className="space-y-4 rounded-2xl border border-slate-300 bg-white p-5 shadow-xs">
                    <h2 className="flex items-center gap-2 text-sm font-bold"><Plus className="h-4 w-4 text-[#E00D42]" />{editing ? 'Edit tag' : 'Add product tag'}</h2>
                    <label className="block space-y-1 text-xs font-semibold text-slate-700">Tag name<input required maxLength={50} value={form.data.name} onChange={event => form.setData('name', event.target.value)} placeholder="e.g. travel bag" className={input} /><span className="block font-normal text-slate-500">1–50 characters. Tags are flat and unique within your shop.</span>{(nameError || form.errors.name) && <span role="alert" className="block font-normal text-rose-700">{nameError || form.errors.name}</span>}</label>
                    {needsProductContext && <label className="block space-y-1 text-xs font-semibold text-slate-700">Product name for relevance check<input required minLength={5} maxLength={120} value={form.data.product_name} onChange={event => form.setData('product_name', event.target.value)} placeholder="Which product will use this tag?" className={input} /><span className="block font-normal text-slate-500">Gemini checks this tag against the product and your approved shop department.</span></label>}
                    {(form.errors.product_name || tagRelevanceError) && <p role="alert" className="text-xs text-rose-700">{form.errors.product_name || tagRelevanceError}</p>}
                    <div className="flex gap-2"><button disabled={form.processing || pending || Boolean(nameError)} className="rounded-xl bg-[#E00D42] px-4 py-2.5 text-xs font-bold text-white disabled:opacity-50">{form.processing ? 'Checking…' : editing ? 'Save tag' : 'Add tag'}</button>{editing && <button type="button" disabled={form.processing || pending} onClick={clear} className="rounded-xl border border-slate-300 px-4 py-2.5 text-xs font-semibold">Cancel</button>}</div>
                </form>
            </div>
        </div>
    </DashboardLayout>;
}
