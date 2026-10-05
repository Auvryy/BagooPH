import React, { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import ShopReviewHistory, { ShopDecision } from '@/Components/ShopReviewHistory';
import { Shop } from '@/types';

interface ReviewShop extends Shop {
    review_token: string;
    review_issues: string[];
    documents: Record<string, string | null>;
    decision_history: ShopDecision[];
    owner_birthday: string | null;
    owner_age: number | null;
}
interface Props {
    shops: { data: ReviewShop[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    filters: { status: string; search: string };
}

export default function ShopReviews({ shops, filters }: Props) {
    const [selected, setSelected] = useState<number | null>(null);
    const [search, setSearch] = useState(filters.search);
    const form = useForm({ review_token: '', evidence_confirmed: false, reason: '' });
    const open = (shop: ReviewShop) => { setSelected(shop.id); form.setData({ review_token: shop.review_token, evidence_confirmed: false, reason: '' }); form.clearErrors(); };
    const filter = (status: string) => router.get(route('admin.shops.index'), { status, search }, { preserveState: false });

    return <DashboardLayout title="Shop reviews" subtitle="Review each shop independently of the seller account.">
        <Head title="Shop reviews" />
        <form onSubmit={e => { e.preventDefault(); filter(filters.status); }} className="mb-5 flex flex-wrap gap-3">
            <label className="sr-only" htmlFor="shop-review-search">Search shop or seller email</label>
            <input id="shop-review-search" value={search} onChange={e => setSearch(e.target.value)} placeholder="Shop name or seller email" className="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2" />
            <label className="sr-only" htmlFor="shop-review-status">Review state</label>
            <select id="shop-review-status" value={filters.status} onChange={e => filter(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2">
                {Object.entries({ pending_approval: 'Pending', approved: 'Approved', rejected: 'Rejected', legacy: 'Legacy — needs review', all: 'All shops' }).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
            <button className="rounded-lg border border-slate-300 px-4 py-2 font-semibold">Search</button>
        </form>
        <p className="mb-3 text-sm text-slate-600">{shops.total} matching shops</p>
        <div className="space-y-4">
            {shops.data.map(shop => <section key={shop.id} className="rounded-lg border border-slate-300 bg-white p-5 shadow-sm">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div><h2 className="text-lg font-bold">{shop.name}</h2><p className="text-sm text-slate-600">{shop.user?.name} · {shop.user?.email}</p>
                        <p className="mt-2 text-sm">Account review: {shop.user?.kyc_status} · Account activity: {shop.user?.status}</p>
                        <p className="text-sm">Shop review: {shop.review_status?.replaceAll('_', ' ') ?? 'Missing legacy provenance'} · Shop activity: {shop.status} · {shop.eligible ? 'Eligible for new work' : 'New work blocked'}</p>
                    </div>
                    <button type="button" onClick={() => selected === shop.id ? setSelected(null) : open(shop)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">{selected === shop.id ? 'Close details' : 'Review details'}</button>
                </div>
                {selected === shop.id && <div className="mt-4 space-y-4 border-t border-slate-300 pt-4">
                    <dl className="grid gap-2 text-sm sm:grid-cols-2">
                        <div><dt className="font-semibold">Master category</dt><dd>{shop.root_category?.name ?? 'Missing category'}</dd></div>
                        <div><dt className="font-semibold">Shop contact</dt><dd>{shop.phone ?? 'Missing contact'}</dd></div>
                        <div><dt className="font-semibold">Seller birth date</dt><dd>{shop.owner_birthday ?? 'Missing birth date'}{shop.owner_age !== null ? ` · ${shop.owner_age} years old` : ''}</dd></div>
                        <div className="sm:col-span-2"><dt className="font-semibold">Shop address</dt><dd>{shop.address ?? 'Missing address'}, {shop.city ?? 'Missing city'}</dd></div>
                    </dl>
                    <div className="flex flex-wrap gap-4 text-sm">{Object.entries(shop.documents).map(([kind, href]) => href ? <a key={kind} href={href} target="_blank" rel="noopener noreferrer" className="text-[#E00D42] underline">Inspect current {kind === 'id' ? 'seller identity document' : 'shop business permit'}</a> : <span key={kind} className="text-red-700">Missing {kind === 'id' ? 'identity document' : 'shop permit'}</span>)}</div>
                    {shop.review_issues.length > 0 && <ul className="list-disc space-y-1 pl-5 text-sm text-red-700">{shop.review_issues.map(issue => <li key={issue}>{issue}</li>)}</ul>}
                    {shop.review_status === 'pending_approval' && <div className="space-y-3">
                        <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={form.data.evidence_confirmed} onChange={e => form.setData('evidence_confirmed', e.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42]" />I opened and inspected both current documents and checked this shop’s details and category.</label>
                        <label className="block text-sm font-semibold">Rejection reason<textarea value={form.data.reason} onChange={e => form.setData('reason', e.target.value)} maxLength={1000} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal" /></label>
                        {Object.entries(form.errors).map(([field, error]) => <p key={field} className="text-sm text-red-700">{error}</p>)}
                        <div className="flex flex-wrap gap-3">
                            <button type="button" disabled={form.processing || !form.data.evidence_confirmed || shop.review_issues.length > 0} onClick={() => form.post(route('admin.shops.approve', shop.id), { preserveScroll: true })} className="rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Approve this shop</button>
                            <button type="button" disabled={form.processing || form.data.reason.trim().length < 5} onClick={() => form.post(route('admin.shops.reject', shop.id), { preserveScroll: true })} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold disabled:opacity-50">Reject with feedback</button>
                        </div>
                    </div>}
                    {shop.review_status === null && <p className="text-sm text-slate-600">The seller must submit this shop’s actual details and permit before an independent review. No old approval is inferred.</p>}
                    <div><h3 className="mb-2 font-semibold">Recorded decisions</h3><ShopReviewHistory decisions={shop.decision_history} /></div>
                </div>}
            </section>)}
            {!shops.data.length && <p className="rounded-lg border border-slate-300 bg-white p-6 text-slate-600">No shops match these filters.</p>}
        </div>
        <nav aria-label="Shop review pages" className="mt-5 flex flex-wrap gap-2">{shops.links.map((link, index) => link.url ? <Link key={index} href={link.url} aria-current={link.active ? 'page' : undefined} className={`rounded-lg border border-slate-300 px-3 py-2 text-sm ${link.active ? 'bg-slate-900 text-white' : 'bg-white'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-400" dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav>
    </DashboardLayout>;
}
