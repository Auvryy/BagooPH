import React, { FormEvent, useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import ShopReviewHistory, { ShopDecision } from '@/Components/ShopReviewHistory';
import { Category, PageProps, Shop } from '@/types';

interface ReviewedShop extends Shop {
    review_token: string;
    review_issues: string[];
    owner_birthday: string | null;
    can_submit: boolean;
    decision_history: ShopDecision[];
    documents: Record<string, string | null>;
}

export default function Shops({ shop, categories }: { shop: ReviewedShop | null; categories: Category[] }) {
    const { auth } = usePage<PageProps>().props;
    const [showForm, setShowForm] = useState(false);
    const [showHistory, setShowHistory] = useState(false);
    const form = useForm({ name: '', phone: '', address: '', city: '', root_category_id: '', description: '', business_permit: null as File | null, review_token: '' });
    const errors = form.errors as Record<string, string>;

    const openForm = () => {
        if (!shop) return;
        form.clearErrors();
        form.setData({ name: shop.name, phone: shop.phone ?? '', address: shop.address ?? '', city: shop.city ?? '',
            root_category_id: shop.root_category_id?.toString() ?? '', description: shop.description ?? '', business_permit: null, review_token: shop.review_token });
        setShowForm(true);
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!shop) return;
        form.post(route('seller.shops.resubmit', shop.id), {
            forceFormData: true, preserveScroll: true, onSuccess: () => { setShowForm(false); form.reset(); },
        });
    };

    return <DashboardLayout title="Shop" subtitle="Your registered shop, category and approval.">
        <Head title="Shop" />
        <div className="mb-6 rounded-lg border border-slate-300 bg-white p-4 text-sm">
            <p className="font-semibold">Seller account: {auth.user?.kyc_status} · Activity: {auth.user?.status}</p>
            <p className="mt-1 text-slate-600">Your account manages one shop in the master category chosen at registration. Products and new orders require current account and shop approval.</p>
        </div>
        {!shop && <p className="rounded-lg border border-slate-300 bg-white p-6 text-slate-600">Your registered shop is missing. Contact an administrator to review your account.</p>}
        {shop && <section className="max-w-3xl rounded-lg border border-slate-300 bg-white p-5 shadow-sm">
            <h2 className="text-lg font-bold text-slate-900">{shop.name}</h2>
            <p className="mt-1 text-sm text-slate-600">Category: {shop.root_category?.name ?? 'Missing category'}</p>
            <p className="mt-2 text-sm">Review: <strong>{shop.review_status?.replaceAll('_', ' ') ?? 'Legacy record — review required'}</strong> · Activity: {shop.status}</p>
            {shop.review_feedback && <p className="mt-3 whitespace-pre-wrap rounded-lg border border-slate-300 bg-slate-50 p-3 text-sm">{shop.review_feedback}</p>}
            {!shop.eligible && shop.review_issues.length > 0 && <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-slate-600">{shop.review_issues.map(issue => <li key={issue}>{issue}</li>)}</ul>}
            {!shop.eligible && !shop.owner_birthday && <p className="mt-3 text-sm text-slate-600">Your birth date is part of your account identity. <Link href={route('identity-corrections.own')} className="font-semibold text-[#E00D42] underline">Request an identity correction</Link> with supporting evidence before shop approval.</p>}
            <div className="mt-4 flex flex-wrap items-center gap-3 text-sm">
                {shop.eligible && <Link href={route('seller.dashboard')} className="rounded-lg bg-[#E00D42] px-3 py-2 font-semibold text-white">Open dashboard</Link>}
                {shop.can_submit && <button type="button" onClick={openForm} className="rounded-lg border border-slate-300 px-3 py-2 font-semibold">{shop.review_status ? 'Update submission' : 'Submit for review'}</button>}
                {shop.review_status === 'approved' && <Link href={route('identity-corrections.own')} className="text-[#E00D42] underline">Correct reviewed details</Link>}
                <Link href={route('seller.orders.index')} className="text-[#E00D42] underline">View orders</Link>
                <button type="button" aria-expanded={showHistory} onClick={() => setShowHistory(!showHistory)} className="underline">Review history</button>
            </div>
            {!shop.eligible && <p className="mt-3 text-sm text-slate-600">Your existing orders remain available while shop approval or activity is reviewed.</p>}
            {showHistory && <div className="mt-4"><ShopReviewHistory decisions={shop.decision_history} /></div>}
        </section>}
        {showForm && shop && <div className="fixed inset-0 z-50 overflow-y-auto bg-black/50 p-4" role="dialog" aria-modal="true" aria-labelledby="shop-form-title">
            <form onSubmit={submit} className="mx-auto my-6 max-w-xl space-y-4 rounded-lg border border-slate-300 bg-white p-6 shadow-lg">
                <h2 id="shop-form-title" className="text-xl font-bold">Update shop submission</h2>
                <p className="text-sm text-slate-600">Update your existing shop's actual details and permit. An admin must review the submission before it can accept new work.</p>
                {(['name', 'phone', 'address', 'city'] as const).map(field => <label key={field} className="block text-sm font-semibold">
                    {{ name: 'Shop name', phone: 'Shop contact number', address: 'Shop street address', city: 'Shop city' }[field]}
                    <input required type={field === 'phone' ? 'tel' : 'text'} value={form.data[field]} onChange={e => form.setData(field, e.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal focus:border-[#E00D42] focus:ring-[#E00D42]" />
                    {(errors[field] || errors[`shop_${field}`]) && <span className="mt-1 block font-normal text-red-700">{errors[field] || errors[`shop_${field}`]}</span>}
                </label>)}
                <label className="block text-sm font-semibold">Master category
                    <select required value={form.data.root_category_id} onChange={e => form.setData('root_category_id', e.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                        <option value="">Choose a master category</option>{categories.map(category => <option key={category.id} value={category.id}>{category.name}</option>)}
                    </select>
                    {categories.length === 0 && <span className="mt-1 block font-normal text-amber-800">Categories are unavailable. Contact an administrator before submitting.</span>}
                    {errors.root_category_id && <span className="mt-1 block text-red-700">{errors.root_category_id}</span>}
                </label>
                <label className="block text-sm font-semibold">Description
                    <textarea value={form.data.description} maxLength={1000} onChange={e => form.setData('description', e.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal" />
                    {errors.description && <span className="mt-1 block text-red-700">{errors.description}</span>}
                </label>
                <label className="block text-sm font-semibold">Business permit (PDF, JPG, PNG or WEBP; up to 5 MB)
                    <input required={!shop.documents.permit} type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" onChange={e => form.setData('business_permit', e.target.files?.[0] ?? null)} className="mt-1 block w-full rounded-lg border border-slate-300 p-2 font-normal" />
                    {shop.documents.permit && <a href={shop.documents.permit} target="_blank" rel="noopener noreferrer" className="mt-1 block font-normal text-[#E00D42] underline">View current permit; leave upload empty to keep it</a>}
                    {errors.business_permit && <span className="mt-1 block text-red-700">{errors.business_permit}</span>}
                </label>
                {errors.review_token && <p className="text-sm text-red-700">{errors.review_token}</p>}
                <div className="flex justify-end gap-3 border-t border-slate-300 pt-4">
                    <button type="button" disabled={form.processing} onClick={() => setShowForm(false)} className="rounded-lg border border-slate-300 px-4 py-2">Cancel</button>
                    <button disabled={form.processing} className="rounded-lg bg-[#E00D42] px-4 py-2 font-semibold text-white disabled:opacity-50">{form.processing ? 'Submitting…' : 'Submit for review'}</button>
                </div>
            </form>
        </div>}
    </DashboardLayout>;
}
