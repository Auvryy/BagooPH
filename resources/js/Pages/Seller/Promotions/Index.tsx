import { FormEvent } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { ImagePlus, Plus } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { AdvertisementSummary, Pagination, reviewLabel, displayLabel, dateLabel } from '@/types/advertisements';

interface Props { promotions: Pagination<AdvertisementSummary>; shop: { id: number; name: string }; available: boolean; canSubmit: boolean; baseUrl: string; filters: { search?: string; review?: string } }
export default function Promotions({ promotions, shop, available, canSubmit, baseUrl, filters }: Props) {
    const form = useForm({ search: filters.search ?? '', review: filters.review ?? 'all' });
    const search = (event: FormEvent) => { event.preventDefault(); form.get(baseUrl, { preserveState: true, preserveScroll: true }); };
    return <DashboardLayout title="Promotions" subtitle={`Propose marketplace artwork for ${shop.name}. Platform Admin reviews requests and chooses placement.`}
        actions={available && canSubmit && <Link href={`${baseUrl}/create`} className="inline-flex items-center gap-2 rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-bold text-white"><Plus className="h-4 w-4" />New promotion</Link>}>
        <Head title="Shop promotions" />
        <div className="space-y-6">
            {!available && <p role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm">Promotion requests need the pending application migration before they can be managed.</p>}
            {!canSubmit && <p role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm">Your shop needs current approval and activity before submitting new promotions. Your past requests remain available. <Link href={route('seller.settings')} className="font-bold underline">Review shop settings</Link></p>}
            <form onSubmit={search} className="flex flex-wrap items-end gap-3">
                <label className="text-sm font-semibold">Find a promotion<input value={form.data.search} maxLength={80} onChange={(e) => form.setData('search', e.target.value)} className="mt-1 block rounded-lg border-slate-300" placeholder="Search titles" /></label>
                <label className="text-sm font-semibold">Review state<select value={form.data.review} onChange={(e) => form.setData('review', e.target.value)} className="mt-1 block rounded-lg border-slate-300"><option value="all">All requests</option>{['not_submitted', 'pending', 'approved', 'changes_requested', 'rejected', 'withdrawn', 'superseded'].map((state) => <option key={state} value={state}>{reviewLabel(state)}</option>)}</select></label>
                <button disabled={form.processing} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-bold">Apply filters</button>
                {Object.values(form.errors).map((message) => <p role="alert" key={message} className="text-sm text-red-700">{message}</p>)}
            </form>
            {promotions.data.length ? <div className="grid gap-4 xl:grid-cols-2">{promotions.data.map((promotion) => <article key={promotion.id} className="flex flex-col gap-4 rounded-lg border border-slate-300 bg-white p-4 sm:flex-row">
                {promotion.image && <img src={promotion.image} alt="" width={1880} height={880} className="h-auto w-full self-start sm:w-40" />}
                <div className="min-w-0 flex-1 space-y-2"><Link href={`${baseUrl}/${promotion.id}`} className="font-bold text-slate-900 hover:text-[#E00D42]">{promotion.title}</Link>
                    <p className="text-sm text-slate-600">Review: <strong>{reviewLabel(promotion.review_state)}</strong></p><p className="text-sm text-slate-600">Display: <strong>{displayLabel(promotion.state)}</strong></p>
                    {promotion.starts_at && <p className="text-xs text-slate-500">{dateLabel(promotion.starts_at)} – {dateLabel(promotion.ends_at)} (Manila)</p>}
                    {promotion.reason && <p className="text-xs text-amber-800">{promotion.reason}</p>}
                    <Link href={`${baseUrl}/${promotion.id}`} className="inline-block text-sm font-bold text-[#E00D42] underline">Details and feedback</Link>
                </div>
            </article>)}</div> : <div className="flex min-h-64 flex-col items-center justify-center rounded-lg border border-slate-300 bg-white p-6 text-center"><ImagePlus className="mb-3 h-8 w-8 text-slate-400" /><h2 className="font-bold">No promotions found</h2><p className="mt-2 max-w-md text-sm text-slate-500">Create a private draft for your shop or adjust your filters. Approval and homepage placement are separate steps.</p></div>}
            <nav aria-label="Promotion pages" className="flex items-center justify-between text-sm"><span>{promotions.total} promotions · Page {promotions.current_page} of {promotions.last_page}</span><div className="flex gap-4">{promotions.prev_page_url && <Link href={promotions.prev_page_url}>Previous</Link>}{promotions.next_page_url && <Link href={promotions.next_page_url}>Next</Link>}</div></nav>
            <p className="text-xs leading-relaxed text-slate-500">Shop logo, banner, description and contact edits remain in Settings. A promotion request does not change your shop approval or activate a voucher.</p>
        </div>
    </DashboardLayout>;
}
