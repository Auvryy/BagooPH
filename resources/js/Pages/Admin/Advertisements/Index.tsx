import { FormEvent, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ImagePlus, Plus } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { AdvertisementSummary, Pagination, dateLabel, slotLabel, stateLabel, reviewLabel } from '@/types/advertisements';

interface Props {
    advertisements: Pagination<AdvertisementSummary>; available: boolean; displayEnabled: boolean; baseUrl: string;
    filters: { search?: string; state?: string; scope?: string; review?: string };
    suppliedArtwork: { key: string; title: string; image: string; imported: boolean }[];
}
export default function Advertisements({ advertisements, available, displayEnabled, baseUrl, filters, suppliedArtwork }: Props) {
    const form = useForm({ search: filters.search ?? '', state: filters.state ?? 'all', scope: filters.scope ?? 'all', review: filters.review ?? 'all' });
    const [pending, setPending] = useState(false);
    const [error, setError] = useState('');
    const search = (event: FormEvent) => { event.preventDefault(); form.get(baseUrl, { preserveState: true, preserveScroll: true }); };
    const importArtwork = (artwork: string) => {
        setPending(true); setError('');
        router.post(`${baseUrl}/import`, { artwork, source_version: 0, request_key: crypto.randomUUID() }, {
            preserveScroll: true, onError: (errors) => setError(Object.values(errors).join(' ')), onFinish: () => setPending(false),
        });
    };
    return <DashboardLayout title="Advertisements" subtitle="Manage marketplace images, destinations and display dates."
        actions={available && <Link href={`${baseUrl}/create`} className="inline-flex items-center gap-2 rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-bold text-white"><Plus className="h-4 w-4" />New advertisement</Link>}>
        <Head title="Advertisements" />
        <div className="space-y-6">
            {!available && <p role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm">Advertisement management needs the pending application migration. Normal marketplace browsing remains available.</p>}
            {!displayEnabled && <p role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm">Public advertisement display is disabled. Drafts and publication history remain available here.</p>}
            {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
            <form onSubmit={search} className="flex flex-wrap items-end gap-3">
                <label className="text-sm font-semibold">Find an advertisement<input value={form.data.search} maxLength={80} onChange={(e) => form.setData('search', e.target.value)} className="mt-1 block rounded-lg border-slate-300" placeholder="Search titles" /></label>
                <label className="text-sm font-semibold">Display state<select value={form.data.state} onChange={(e) => form.setData('state', e.target.value)} className="mt-1 block rounded-lg border-slate-300">
                    {['all', 'draft', 'live', 'scheduled', 'paused', 'ended', 'archived', 'unavailable'].map((state) => <option key={state} value={state}>{stateLabel(state)}</option>)}
                </select></label>
                <label className="text-sm font-semibold">Source<select value={form.data.scope} onChange={(e) => form.setData('scope', e.target.value)} className="mt-1 block rounded-lg border-slate-300"><option value="all">All advertisements</option><option value="platform">Platform editorial</option><option value="seller">Seller proposals</option></select></label>
                <label className="text-sm font-semibold">Seller review<select value={form.data.review} onChange={(e) => form.setData('review', e.target.value)} className="mt-1 block rounded-lg border-slate-300"><option value="all">All review states</option>{['pending', 'approved', 'changes_requested', 'rejected', 'withdrawn', 'superseded', 'not_submitted'].map((state) => <option key={state} value={state}>{reviewLabel(state)}</option>)}</select></label>
                <button disabled={form.processing} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-bold">Apply filters</button>
                {Object.values(form.errors).map((message) => <p role="alert" key={message} className="text-sm text-red-700">{message}</p>)}
            </form>
            {advertisements.data.length ? <div className="grid gap-4 xl:grid-cols-2">{advertisements.data.map((ad) => <article key={ad.id} className="flex flex-col gap-4 rounded-lg border border-slate-300 bg-white p-4 sm:flex-row">
                {ad.image && <img src={ad.image} alt="" width={1880} height={880} className="h-auto w-full self-start sm:w-40" />}
                <div className="min-w-0 flex-1 space-y-2">
                    <Link href={`${baseUrl}/${ad.id}`} className="font-bold text-slate-900 hover:text-[#E00D42]">{ad.title}</Link>
                    <p className="text-xs font-semibold text-slate-600">{stateLabel(ad.state)}{ad.has_draft ? ' · Unpublished draft' : ''} · {slotLabel(ad.slot)}</p>
                    {ad.owner_type === 'seller' && <p className="text-xs font-semibold text-slate-600">Seller proposal · {ad.shop_name} · Review: {reviewLabel(ad.review_state)}</p>}
                    <p className="text-xs text-slate-500">{ad.creator} · {ad.target.label ?? 'Choose a destination'}</p>
                    <p className="text-xs text-slate-500">{dateLabel(ad.starts_at)} – {dateLabel(ad.ends_at)} (Manila)</p>
                    {ad.reason && <p className="text-xs text-amber-800">{ad.reason}</p>}
                    <Link href={`${baseUrl}/${ad.id}`} className="inline-block text-sm font-semibold text-[#E00D42] underline">Manage advertisement</Link>
                </div>
            </article>)}</div> : <div className="flex min-h-56 flex-col items-center justify-center rounded-lg border border-slate-300 bg-white px-6 text-center">
                <ImagePlus className="mb-3 h-8 w-8 text-slate-400" /><h2 className="font-bold">No advertisements found</h2><p className="mt-2 max-w-md text-sm text-slate-500">Create a draft or adjust your filters. Buyers continue to see the normal product catalogue when no promotions are live.</p>
            </div>}
            <nav aria-label="Advertisement pages" className="flex items-center justify-between text-sm">
                <span>{advertisements.total} advertisements · Page {advertisements.current_page} of {advertisements.last_page}</span>
                <div className="flex gap-4">{advertisements.prev_page_url && <Link href={advertisements.prev_page_url}>Previous</Link>}{advertisements.next_page_url && <Link href={advertisements.next_page_url}>Next</Link>}</div>
            </nav>
            {available && <section className="space-y-3 border-t border-slate-300 pt-6"><h2 className="font-bold">Supplied artwork</h2>
                <p className="text-sm text-slate-600">Import an image as a private draft. Verify dates, destinations and every advertised saving before publication.</p>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">{suppliedArtwork.map((art) => <article key={art.key} className="space-y-2 rounded-lg border border-slate-300 bg-white p-3">
                    <img src={art.image} alt={art.title} width={1880} height={880} loading="lazy" className="h-auto w-full" /><h3 className="text-sm font-bold">{art.title}</h3>
                    <button disabled={pending} onClick={() => importArtwork(art.key)} className="text-sm font-semibold text-[#E00D42] underline disabled:opacity-50">{art.imported ? 'Open imported advertisement' : 'Import draft'}</button>
                </article>)}</div>
            </section>}
        </div>
    </DashboardLayout>;
}
