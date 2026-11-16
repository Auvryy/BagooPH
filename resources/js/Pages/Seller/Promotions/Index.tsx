import { FormEvent } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { ChevronRight, ImagePlus, Plus } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Badge, Notice, buttonClasses, inputClasses, labelClasses, type BadgeTone } from '@/Components/ui';
import { AdvertisementSummary, Pagination, reviewLabel, displayLabel, dateLabel } from '@/types/advertisements';
import type { SaleEventSummary } from '@/Components/Promotions/saleEvents';

interface Props {
    promotions: Pagination<AdvertisementSummary>; shop: { id: number; name: string }; available: boolean; canSubmit: boolean; baseUrl: string;
    filters: { search?: string; review?: string }; saleEvent?: SaleEventSummary | null; saleEventsUrl?: string;
}

const reviewTone = (state?: string): BadgeTone => state === 'approved' ? 'success' : state === 'pending' ? 'info'
    : state === 'changes_requested' ? 'warning' : state === 'rejected' ? 'danger' : 'neutral';
const displayTone = (state: string): BadgeTone => state === 'live' ? 'success' : state === 'scheduled' ? 'info' : state === 'paused' ? 'warning' : 'neutral';

export default function Promotions({ promotions, available, canSubmit, baseUrl, filters, saleEvent, saleEventsUrl = '/seller/sale-events' }: Props) {
    const form = useForm({ search: filters.search ?? '', review: filters.review ?? 'all' });
    const search = (event: FormEvent) => { event.preventDefault(); form.get(baseUrl, { preserveState: true, preserveScroll: true }); };
    return <DashboardLayout title="Promotions"
        actions={available && <div className="flex flex-wrap gap-2">
            <Link href={`${baseUrl}/performance`} className={buttonClasses('secondary')}>Performance</Link>
            {canSubmit && <Link href={`${baseUrl}/create`} className={buttonClasses('primary')}><Plus className="h-4 w-4" aria-hidden="true" />New promotion</Link>}
        </div>}>
        <Head title="Shop promotions" />
        <div className="space-y-6">
            {saleEvent && (
                <Link href={`${saleEventsUrl}/${saleEvent.id}`} className="flex items-center gap-4 rounded-card border border-line-200 bg-white p-4 hover:border-line-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500">
                    {saleEvent.banner_url && <img src={saleEvent.banner_url} alt="" className="hidden h-12 w-24 shrink-0 rounded object-cover sm:block" />}
                    <span className="min-w-0 flex-1">
                        <span className="block text-sm font-semibold text-ink-900">{saleEvent.title} {saleEvent.status === 'live' ? 'is live' : 'is coming up'}</span>
                        <span className="block text-sm text-brand-600">Next: add product discounts and vouchers</span>
                    </span>
                    <ChevronRight aria-hidden="true" className="h-5 w-5 shrink-0 text-ink-400" />
                </Link>
            )}
            {!available && <Notice tone="warning" role="status">Promotion requests need the pending application migration before they can be managed.</Notice>}
            {!canSubmit && <Notice tone="warning" role="status">Your shop needs current approval before submitting new promotions. Past requests stay here. <Link href={route('seller.settings')} className="font-semibold underline">Review shop settings</Link></Notice>}

            <section className="rounded-card border border-line-200 bg-white">
                <form onSubmit={search} className="flex flex-wrap items-end gap-3 border-b border-line-200 p-4 sm:px-6">
                    <label className="min-w-0 flex-1 basis-56"><span className={labelClasses}>Find a promotion</span><input value={form.data.search} maxLength={80} onChange={(e) => form.setData('search', e.target.value)} className={inputClasses} placeholder="Search titles" /></label>
                    <label className="w-full sm:w-56"><span className={labelClasses}>Review state</span><select value={form.data.review} onChange={(e) => form.setData('review', e.target.value)} className={inputClasses}><option value="all">All requests</option>{['not_submitted', 'pending', 'approved', 'changes_requested', 'rejected', 'withdrawn', 'superseded'].map((state) => <option key={state} value={state}>{reviewLabel(state)}</option>)}</select></label>
                    <button disabled={form.processing} className={buttonClasses('secondary')}>Apply</button>
                    {Object.values(form.errors).map((message) => <p role="alert" key={message} className="basis-full text-sm text-brand-700">{message}</p>)}
                </form>
                {promotions.data.length ? (
                    <ul className="divide-y divide-line-200">
                        {promotions.data.map((promotion) => (
                            <li key={promotion.id}>
                                <Link href={`${baseUrl}/${promotion.id}`} className="flex items-center gap-4 px-4 py-3 hover:bg-surface-100 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-500 sm:px-6">
                                    {promotion.image ? <img src={promotion.image} alt="" width={1880} height={880} className="h-12 w-24 shrink-0 rounded object-cover" /> : <span className="h-12 w-24 shrink-0 rounded bg-surface-100" />}
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-semibold text-ink-900">{promotion.title}</span>
                                        {promotion.starts_at && <span className="block text-xs text-ink-600">{dateLabel(promotion.starts_at)} – {dateLabel(promotion.ends_at)}</span>}
                                        {promotion.reason && <span className="block text-xs text-warning">{promotion.reason}</span>}
                                    </span>
                                    <span className="hidden flex-col items-end gap-1 sm:flex"><Badge tone={reviewTone(promotion.review_state)}>{reviewLabel(promotion.review_state)}</Badge><Badge tone={displayTone(promotion.state)}>{displayLabel(promotion.state)}</Badge></span>
                                    <ChevronRight aria-hidden="true" className="h-4 w-4 shrink-0 text-ink-400" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <div className="flex min-h-64 flex-col items-center justify-center p-6 text-center">
                        <ImagePlus className="mb-3 h-8 w-8 text-ink-400" aria-hidden="true" />
                        <h2 className="font-semibold text-ink-900">No promotions found</h2>
                        <p className="mt-1 max-w-md text-sm text-ink-600">Create a banner for your shop. Bagoo reviews it, then places it on the marketplace.</p>
                        {canSubmit && available && <Link href={`${baseUrl}/create`} className={`${buttonClasses('secondary')} mt-4`}>Create a promotion</Link>}
                    </div>
                )}
                <nav aria-label="Promotion pages" className="flex items-center justify-between border-t border-line-200 px-4 py-3 text-xs text-ink-600 sm:px-6">
                    <span>{promotions.total} {promotions.total === 1 ? 'promotion' : 'promotions'} · page {promotions.current_page} of {promotions.last_page}</span>
                    <div className="flex gap-2">
                        {promotions.prev_page_url && <Link href={promotions.prev_page_url} className={buttonClasses('secondary', 'sm')}>Previous</Link>}
                        {promotions.next_page_url && <Link href={promotions.next_page_url} className={buttonClasses('secondary', 'sm')}>Next</Link>}
                    </div>
                </nav>
            </section>
            <p className="text-xs text-ink-600">Shop logo, banner, description and contact edits remain in Settings.</p>
        </div>
    </DashboardLayout>;
}
