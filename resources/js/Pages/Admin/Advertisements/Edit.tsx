import { FormEvent, useEffect, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { AdvertisementDetail, Options, dateLabel, slotLabel, stateLabel } from '@/types/advertisements';

interface Props { advertisement: AdvertisementDetail | null; options: Options; slots: string[]; displayEnabled: boolean; baseUrl: string }
interface AdForm {
    title: string; alt_text: string; summary: string; purpose: string; destination_type: string; destination_id: string;
    product_ids: number[]; offer_revision_id: string; slot: string; starts_at: string; ends_at: string;
    image: File | null; request_key: string; source_version: number;
}
const fieldClass = 'mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]';
const buttonClass = 'rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-bold disabled:cursor-not-allowed disabled:opacity-50';
const localTime = (offset: number) => new Date(Date.now() + offset + 8 * 3600000).toISOString().slice(0, 16);
const initial = (ad: AdvertisementDetail | null): AdForm => ({
    title: ad?.draft.title ?? '', alt_text: ad?.draft.alt_text ?? '', summary: ad?.draft.summary ?? '',
    purpose: ad?.draft.purpose ?? 'feature', destination_type: ad?.draft.destination_type ?? 'product',
    destination_id: ad?.draft.destination_id?.toString() ?? '', product_ids: ad?.draft.product_ids ?? [],
    offer_revision_id: ad?.draft.offer_revision_id?.toString() ?? '', slot: ad?.draft.slot ?? 'main_1',
    starts_at: ad?.draft.starts_at ?? localTime(300000), ends_at: ad?.draft.ends_at ?? localTime(7 * 86400000),
    image: null, request_key: typeof crypto !== 'undefined' ? crypto.randomUUID() : '', source_version: ad?.version ?? 0,
});

export default function EditAdvertisement(props: Props) {
    return <Editor key={props.advertisement?.id ?? 'new'} {...props} />;
}

function Editor({ advertisement: ad, options, slots, displayEnabled, baseUrl }: Props) {
    const form = useForm<AdForm>(initial(ad));
    const [imagePreview, setImagePreview] = useState<string | null>(null);
    const [reviewed, setReviewed] = useState(false);
    const [reason, setReason] = useState('');
    const [pending, setPending] = useState(false);
    const [actionErrors, setActionErrors] = useState<Record<string, string>>({});
    useEffect(() => {
        if (!form.data.image) { setImagePreview(null); return; }
        const url = URL.createObjectURL(form.data.image);
        setImagePreview(url);
        return () => URL.revokeObjectURL(url);
    }, [form.data.image]);
    const collection = ['collection', 'offer'].includes(form.data.destination_type);
    const destinations = form.data.destination_type === 'shop' ? options.shops : form.data.destination_type === 'category' ? options.categories : options.products;
    const errors = form.errors as Record<string, string>;
    const save = (event: FormEvent) => {
        event.preventDefault();
        form.post(ad ? `${baseUrl}/${ad.id}/draft` : baseUrl, {
            forceFormData: true, preserveScroll: true,
            onSuccess: (page) => {
                const saved = page.props.advertisement as AdvertisementDetail;
                const values = initial(saved);
                form.setDefaults(values); form.setData(values); form.clearErrors(); setReviewed(false);
            },
        });
    };
    const decide = (action: string, publicationId: number | null = null, publishNow = false) => {
        if (!ad || pending || form.processing || form.isDirty || form.data.source_version !== ad.version) return;
        setPending(true); setActionErrors({});
        router.post(`${baseUrl}/${ad.id}/actions`, {
            action, publication_id: publicationId, source_version: form.data.source_version, request_key: crypto.randomUUID(), reason,
            ...(action === 'publish' ? { claims_reviewed: reviewed, publish_now: publishNow } : {}),
        }, {
            preserveScroll: true, onError: (errors) => { setActionErrors(errors); setReviewed(false); },
            onSuccess: (page) => { const values = initial(page.props.advertisement as AdvertisementDetail); form.setDefaults(values); form.setData(values); setReviewed(false); setReason(''); },
            onFinish: () => setPending(false),
        });
    };
    const chooseImage = (file: File | undefined) => {
        if (!file) return;
        if (file.size > 5242880 || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
            form.setError('image', 'Choose a JPEG, PNG or WebP image no larger than 5 MiB. The previous image is kept.'); return;
        }
        form.clearErrors('image'); form.setData('image', file);
    };
    const disabled = pending || form.processing || Boolean(ad?.archived);
    const stale = Boolean(ad && form.data.source_version !== ad.version);
    const cannotDecide = disabled || form.isDirty || stale;
    const publishableDraft = ad?.has_draft && !ad.draft.imported_candidate;

    return <DashboardLayout title={ad ? 'Manage advertisement' : 'New advertisement'} subtitle="Save a private draft, check the image and destination, then choose when it appears."
        actions={<Link href={baseUrl} className={buttonClass}>All advertisements</Link>}>
        <Head title={ad?.title ?? 'New advertisement'} />
        <div className="space-y-6">
            {!displayEnabled && <p role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm">Public display is disabled. Publication history and private previews remain available.</p>}
            {ad?.archived && <p role="status" className="rounded-lg border border-slate-300 bg-slate-50 p-4 text-sm">This advertisement is archived. Its artwork and decisions remain recorded.</p>}
            {stale && <div role="alert" className="space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm"><p>The saved advertisement changed. Your entered details are kept, but reload and review the saved version before publishing.</p><button type="button" onClick={() => router.get(`${baseUrl}/${ad?.id}`, {}, { preserveState: false, preserveScroll: true })} className="font-bold underline">Reload saved version and discard entered changes</button></div>}
            {ad?.draft.imported_candidate && <p role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm">Imported artwork is an incomplete draft. Choose matching products, a real offer where promised, a position and valid dates before saving.</p>}
            {[...Object.values(errors), ...Object.values(actionErrors)].length > 0 && <div role="alert" className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800">
                <p className="font-bold">Check these details</p><ul className="mt-2 list-disc space-y-1 pl-5">{[...new Set([...Object.values(errors), ...Object.values(actionErrors)])].map((error) => <li key={error}>{error}</li>)}</ul>
                {actionErrors.advertisement?.includes('Reload') && <button type="button" onClick={() => router.get(`${baseUrl}/${ad?.id}`, {}, { preserveState: false, preserveScroll: true })} className="mt-3 font-bold underline">Reload saved advertisement</button>}
            </div>}
            <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
                <form onSubmit={save} className="space-y-5 rounded-lg border border-slate-300 bg-white p-5">
                    <fieldset disabled={disabled} className="space-y-5 disabled:opacity-60"><legend className="mb-4 text-lg font-bold">Draft details</legend>
                        <label className="block text-sm font-semibold">Title *<input className={fieldClass} value={form.data.title} required maxLength={80} onChange={(e) => form.setData('title', e.target.value)} aria-invalid={Boolean(errors.title)} /></label>
                        <label className="block text-sm font-semibold">Image description *<input className={fieldClass} value={form.data.alt_text} required maxLength={180} onChange={(e) => form.setData('alt_text', e.target.value)} aria-invalid={Boolean(errors.alt_text)} /><span className="mt-1 block text-xs font-normal text-slate-500">Describe the artwork for people using a screen reader. Maximum 180 characters.</span></label>
                        <label className="block text-sm font-semibold">Terms or collection summary<textarea className={fieldClass} value={form.data.summary} maxLength={600} rows={3} onChange={(e) => form.setData('summary', e.target.value)} aria-invalid={Boolean(errors.summary)} /></label>
                        <label className="block text-sm font-semibold">Artwork {ad ? '(optional replacement)' : '*'}<input type="file" accept="image/jpeg,image/png,image/webp" required={!ad} onChange={(e) => chooseImage(e.target.files?.[0])} className="mt-2 block w-full text-sm" aria-describedby="artwork-hint" aria-invalid={Boolean(errors.image)} />
                            <span id="artwork-hint" className="mt-2 block text-xs font-normal leading-relaxed text-slate-500">JPEG, PNG or WebP only. Maximum 5 MiB (5,242,880 bytes). Recommended 1880 × 880; minimum 940 × 440, with the same 47:22 shape. Maximum 4096 pixels per edge and 16 megapixels. Artwork is not cropped.</span>
                        </label>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label className="text-sm font-semibold">Purpose *<select className={fieldClass} value={form.data.purpose} onChange={(e) => form.setData('purpose', e.target.value)}><option value="feature">Product or shop feature</option><option value="event">Event or collection</option><option value="offer">Offer or saving</option></select></label>
                            <label className="text-sm font-semibold">Click destination *<select className={fieldClass} value={form.data.destination_type} onChange={(e) => form.setData({ ...form.data, destination_type: e.target.value, destination_id: '', product_ids: [] })}>{['product', 'shop', 'category', 'collection', 'offer'].map((type) => <option value={type} key={type}>{stateLabel(type)}</option>)}</select></label>
                        </div>
                        {!collection ? <label className="block text-sm font-semibold">Selected {form.data.destination_type} *<select required className={fieldClass} value={form.data.destination_id} onChange={(e) => form.setData('destination_id', e.target.value)}><option value="">Choose an available destination</option>{destinations.map((target) => <option key={target.id} value={target.id}>{target.name}</option>)}</select></label>
                            : <fieldset className="rounded-lg border border-slate-300 p-3"><legend className="px-1 text-sm font-semibold">Matching products * ({form.data.product_ids.length}/24)</legend><div className="max-h-64 space-y-2 overflow-y-auto">
                                {options.products.length ? options.products.map((product) => <label key={product.id} className="flex items-start gap-2 text-sm"><input type="checkbox" className="mt-1 rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" checked={form.data.product_ids.includes(product.id)} disabled={!form.data.product_ids.includes(product.id) && form.data.product_ids.length >= 24} onChange={(e) => form.setData('product_ids', e.target.checked ? [...form.data.product_ids, product.id] : form.data.product_ids.filter((id) => id !== product.id))} />{product.name}</label>) : <p className="text-sm text-slate-500">No eligible products are available.</p>}
                            </div></fieldset>}
                        <label className="block text-sm font-semibold">Linked published offer {(form.data.purpose === 'offer' || form.data.destination_type === 'offer') ? '*' : '(optional)'}<select className={fieldClass} value={form.data.offer_revision_id} required={form.data.purpose === 'offer' || form.data.destination_type === 'offer'} onChange={(e) => form.setData('offer_revision_id', e.target.value)}><option value="">No offer linked</option>{options.offers.map((offer) => <option key={offer.id} value={offer.id}>{offer.name} · {offer.code}</option>)}</select><span className="mt-1 block text-xs font-normal text-slate-500">An image does not activate a discount. Its promised saving must match the linked offer, selected products and dates.</span></label>
                        <label className="block text-sm font-semibold">Homepage position *<select className={fieldClass} value={form.data.slot} onChange={(e) => form.setData('slot', e.target.value)}>{slots.map((slot) => <option key={slot} value={slot}>{slotLabel(slot)}</option>)}</select></label>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label className="text-sm font-semibold">Starts (Asia/Manila) *<input type="datetime-local" required className={fieldClass} value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} /></label>
                            <label className="text-sm font-semibold">Ends (Asia/Manila) *<input type="datetime-local" required className={fieldClass} value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} /></label>
                        </div>
                        <button disabled={disabled} className="rounded-lg bg-[#E00D42] px-5 py-2.5 text-sm font-bold text-white disabled:opacity-50">{form.processing ? 'Saving draft…' : 'Save draft'}</button>
                    </fieldset>
                </form>
                <aside className="space-y-5">
                    <section className="space-y-3 rounded-lg border border-slate-300 bg-white p-4"><h2 className="font-bold">Artwork preview</h2>
                        {imagePreview || ad?.draft.image ? <img src={imagePreview ?? ad!.draft.image} alt={form.data.alt_text || 'Selected advertisement artwork'} width={ad?.draft.width ?? 1880} height={ad?.draft.height ?? 880} className="h-auto w-full" /> : <div className="flex min-h-40 items-center justify-center bg-slate-50 text-sm text-slate-500">Choose artwork to preview</div>}
                        {ad && <><p className="text-xs text-slate-500">Saved image: {ad.draft.width} × {ad.draft.height} · {(ad.draft.image_bytes / 1024).toFixed(0)} KiB{ad.draft.image_bytes > 358400 ? ' · Review image weight before publication.' : ''}</p><p className="text-sm">Saved destination: {ad.draft.target.label ?? 'Not selected'}</p><Link href={`${baseUrl}/${ad.id}/preview`} className="inline-block text-sm font-bold text-[#E00D42] underline">Preview saved draft on desktop and mobile</Link></>}
                    </section>
                    {ad && <section className="space-y-4 rounded-lg border border-slate-300 bg-white p-4"><h2 className="font-bold">Publish and retain history</h2>
                        <p className="text-sm text-slate-600">{stateLabel(ad.state)} · {ad.has_draft ? 'Unpublished draft available' : 'Latest revision already placed'}</p>
                        {form.isDirty && <p role="status" className="text-sm text-amber-800">Save your draft before making a publication decision.</p>}
                        <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={reviewed} onChange={(e) => setReviewed(e.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42]" disabled={cannotDecide || !publishableDraft} />I checked the saved artwork, destination, dates and every offer claim against the actual published terms.</label>
                        <label className="block text-sm font-semibold">Decision note (optional)<textarea className={fieldClass} maxLength={600} rows={2} value={reason} onChange={(e) => setReason(e.target.value)} disabled={disabled} /></label>
                        <div className="flex flex-wrap gap-2"><button className={buttonClass} disabled={cannotDecide || !reviewed || !publishableDraft} onClick={() => decide('publish', null, false)}>Schedule</button><button className={`${buttonClass} text-[#E00D42]`} disabled={cannotDecide || !reviewed || !publishableDraft} onClick={() => decide('publish', null, true)}>Publish now</button></div>
                        <p className="text-xs leading-relaxed text-slate-500">Scheduling needs a future start. Publish now uses the current server time and the saved end time. Pausing keeps a position reserved; ending releases it.</p>
                        <button className={buttonClass} disabled={cannotDecide} onClick={() => decide('archive')}>Archive advertisement</button>
                    </section>}
                </aside>
            </div>
            {ad && <><section className="space-y-3"><h2 className="text-lg font-bold">Recorded placements</h2>
                {ad.publications.length ? ad.publications.map((publication) => <article key={publication.id} className="space-y-2 rounded-lg border border-slate-300 bg-white p-4">
                    <h3 className="font-bold">{publication.title} · {slotLabel(publication.slot)}</h3><p className="text-sm">{stateLabel(publication.state)} · {dateLabel(publication.starts_at)} – {dateLabel(publication.effective_end)} (Manila)</p>
                    {publication.reason && <p className="text-sm text-amber-800">{publication.reason}</p>}
                    <Link href={`${baseUrl}/${ad.id}/preview?revision=${publication.revision_id}`} className="mr-4 text-sm text-[#E00D42] underline">View this revision</Link>
                    {!['ended', 'archived'].includes(publication.state) && <div className="mt-2 flex flex-wrap gap-2"><button className={buttonClass} disabled={cannotDecide} onClick={() => decide(publication.state === 'paused' ? 'resume' : 'pause', publication.id)}>{publication.state === 'paused' ? 'Resume' : 'Pause'}</button><button className={buttonClass} disabled={cannotDecide} onClick={() => decide('end', publication.id)}>End placement</button></div>}
                </article>) : <p className="text-sm text-slate-500">This draft has not been published.</p>}
            </section><section className="space-y-3"><h2 className="text-lg font-bold">Decision history</h2>
                <ol className="divide-y divide-slate-300 rounded-lg border border-slate-300 bg-white">{ad.history.data.map((entry) => <li key={entry.id} className="space-y-1 p-4"><p className="text-sm font-bold">{stateLabel(entry.action.replaceAll('_', ' '))} · {entry.actor}</p><p className="text-xs text-slate-500">{dateLabel(entry.created_at)} (Manila) · Version {entry.before_version} → {entry.after_version}</p>{entry.reason && <p className="text-sm text-slate-700">{entry.reason}</p>}<Link href={`${baseUrl}/${ad.id}/preview?revision=${entry.revision_id}`} className="inline-block text-sm text-[#E00D42] underline">Preview recorded revision</Link></li>)}</ol>
                <nav aria-label="Decision history pages" className="flex gap-4 text-sm">{ad.history.prev_page_url && <Link href={ad.history.prev_page_url} preserveScroll>Previous decisions</Link>}{ad.history.next_page_url && <Link href={ad.history.next_page_url} preserveScroll>Next decisions</Link>}</nav>
            </section></>}
        </div>
    </DashboardLayout>;
}
