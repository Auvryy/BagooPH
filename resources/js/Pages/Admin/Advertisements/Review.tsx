import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import AdvertisementOfferTerms from '@/Components/AdvertisementOfferTerms';
import PromotionHistory from '@/Components/PromotionHistory';
import { PromotionDetail, PromotionSubmission, dateLabel, displayLabel, manilaInput, reviewLabel, slotLabel } from '@/types/advertisements';

interface Props { advertisement: PromotionDetail; slots: string[]; displayEnabled: boolean; baseUrl: string }
const field = 'mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]';
const button = 'rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-bold disabled:cursor-not-allowed disabled:opacity-50';
export default function ReviewPromotion(props: Props) { return <Review key={props.advertisement.id} {...props} />; }
function Review({ advertisement: ad, slots, displayEnabled, baseUrl }: Props) {
    const [version, setVersion] = useState(ad.version);
    const [selectedId, setSelectedId] = useState(ad.submissions[0]?.id ?? 0);
    const selected = ad.submissions.find((submission) => submission.id === selectedId);
    const [slot, setSlot] = useState('main_1');
    const [start, setStart] = useState(selected?.revision.starts_at ?? '');
    const [end, setEnd] = useState(selected?.revision.ends_at ?? '');
    const [publicationId, setPublicationId] = useState('');
    const [reviewed, setReviewed] = useState(false);
    const [reason, setReason] = useState('');
    const [pending, setPending] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const stale = version !== ad.version;
    const disabled = pending || stale || ad.archived;
    const publications = ad.publications.filter((publication) => publication.revision_id === selected?.revision_id);
    const currentPlacements = publications.filter((publication) => !['ended', 'archived'].includes(publication.state));
    const canPlace = selected?.state === 'approved' && selected.context_current !== false && !selected.eligibility_problem && (publicationId ? currentPlacements.some((publication) => publication.id === Number(publicationId)) : publications.length === 0);
    const choose = (submission: PromotionSubmission) => { setSelectedId(submission.id); setPublicationId(''); setStart(submission.revision.starts_at ?? ''); setEnd(submission.revision.ends_at ?? ''); setReviewed(false); setReason(''); setErrors({}); };
    const choosePlacement = (id: string) => { setPublicationId(id); setReviewed(false); const publication = publications.find((row) => row.id === Number(id)); if (publication) { setSlot(publication.slot); setStart(manilaInput(publication.starts_at)); setEnd(manilaInput(publication.ends_at)); } };
    const send = (kind: 'review' | 'actions', data: Record<string, string | number | boolean | null>) => {
        if (disabled) return;
        setPending(true); setErrors({});
        router.post(`${baseUrl}/${ad.id}/${kind}`, { ...data, source_version: version, request_key: crypto.randomUUID() }, {
            preserveScroll: true, onError: (messages) => { setErrors(messages); setReviewed(false); },
            onSuccess: (page) => { const saved = page.props.advertisement as PromotionDetail; setVersion(saved.version); setReviewed(false); setReason(''); },
            onFinish: () => setPending(false),
        });
    };
    const decide = (decision: string) => { if (!selected?.can_review || (decision === 'approved' && (!reviewed || selected.context_current === false || selected.eligibility_problem))) return;
        send('review', { decision, submission_id: selected.id, revision_id: selected.revision_id, reason, ...(decision === 'approved' ? { claims_reviewed: reviewed } : {}) });
    };
    const place = (now: boolean) => { if (!selected || !canPlace || !reviewed) return;
        send('actions', { action: publicationId ? 'reschedule' : 'publish', submission_id: selected.id,
            publication_id: publicationId ? Number(publicationId) : null, slot, starts_at: start, ends_at: end, claims_reviewed: reviewed, publish_now: now, reason });
    };
    return <DashboardLayout title="Seller promotion review" subtitle={`${ad.shop_name ?? 'Seller shop'} · Review the exact submitted content before choosing a placement.`} actions={<Link href={baseUrl} className={button}>All advertisements</Link>}>
        <Head title="Seller promotion review" />
        <div className="space-y-6">
            <section className="flex flex-wrap gap-4 rounded-lg border border-slate-300 bg-white p-4 text-sm"><p>Seller: <strong>{ad.creator}</strong></p><p>Current draft: <strong>{reviewLabel(ad.review_state)}</strong></p><p>Display: <strong>{displayLabel(ad.state)}</strong></p>{ad.archived && <p className="font-bold">Archived</p>}</section>
            {!displayEnabled && <p role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm">Public display is disabled. Review and history remain available.</p>}
            {stale && <div role="alert" className="space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm"><p>This promotion changed. Your note and placement choices are kept, but reload and review the saved version before deciding.</p><button type="button" onClick={() => router.get(`${baseUrl}/${ad.id}`, {}, { preserveState: false })} className="font-bold underline">Reload saved promotion and discard entered changes</button></div>}
            {Object.keys(errors).length > 0 && <div role="alert" className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800"><ul className="list-disc space-y-1 pl-5">{[...new Set(Object.values(errors))].map((error) => <li key={error}>{error}</li>)}</ul></div>}
            <label className="block max-w-xl text-sm font-semibold">Submitted version<select className={field} value={selectedId} disabled={pending} onChange={(e) => { const submission = ad.submissions.find((row) => row.id === Number(e.target.value)); if (submission) choose(submission); }}>
                {!ad.submissions.length && <option value={0}>No version submitted yet</option>}{ad.submissions.map((submission) => <option value={submission.id} key={submission.id}>Revision {submission.revision.revision_number} · {reviewLabel(submission.state)} · {submission.revision.title}</option>)}
            </select></label>
            {selected ? <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
                <section className="space-y-4 rounded-lg border border-slate-300 bg-white p-5"><h2 className="text-lg font-bold">Exact submitted artwork</h2><img src={selected.revision.image} alt={selected.revision.alt_text} width={selected.revision.width} height={selected.revision.height} className="h-auto w-full" />
                    <p className="text-xs text-slate-500">{selected.revision.width} × {selected.revision.height} · {(selected.revision.image_bytes / 1024).toFixed(0)} KiB{selected.revision.image_bytes > 358400 ? ' · Review image weight and text readability before publication.' : ''}</p>
                    <h3 className="font-bold">{selected.revision.title}</h3><p className="text-sm text-slate-600">{selected.revision.alt_text}</p>{selected.revision.summary && <p className="whitespace-pre-line text-sm text-slate-600">{selected.revision.summary}</p>}
                    {selected.shop_scope && <p className="text-sm text-slate-600">Submitted shop: {selected.shop_scope.name} · Category: {selected.shop_scope.root_category_name ?? 'Recorded category'}</p>}
                    {selected.context_current === false && <p role="status" className="text-sm text-amber-800">The reviewed shop details changed. Request a new draft before approval or placement.</p>}
                    {selected.eligibility_problem && <p role="status" className="text-sm text-amber-800">{selected.eligibility_problem}</p>}
                    {selected.revision.target.url && <Link href={selected.revision.target.url} target="_blank" rel="noopener noreferrer" className="inline-block text-sm text-[#E00D42] underline">Open destination in a new tab</Link>}
                    {!!selected.products?.length && <section className="space-y-2"><h3 className="text-sm font-bold">Currently available products</h3><ul className="space-y-2">{selected.products.map((product) => <li key={product.id} className="flex flex-wrap justify-between gap-2 text-sm"><Link href={product.url} target="_blank" rel="noopener noreferrer" className="text-[#E00D42] underline">{product.name}</Link><span>{new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(product.price))}</span></li>)}</ul></section>}
                    <dl className="space-y-3 text-sm"><div><dt className="text-slate-500">Selected destination</dt><dd className="font-semibold">{selected.revision.target.label ?? 'Unavailable destination'}</dd></div><div><dt className="text-slate-500">Requested period (Asia/Manila)</dt><dd>{dateLabel(selected.revision.starts_at ? `${selected.revision.starts_at}+08:00` : null)} – {dateLabel(selected.revision.ends_at ? `${selected.revision.ends_at}+08:00` : null)}</dd></div></dl>
                    <Link href={`${baseUrl}/${ad.id}/preview?revision=${selected.revision_id}&slot=${slot}`} className="inline-block text-sm font-bold text-[#E00D42] underline">Preview this submitted version at the selected position</Link>
                    <p className="text-xs leading-relaxed text-slate-500">Artwork, destination, linked offer and requested dates belong to the seller's retained version. Request changes when any of these need editing.</p>
                    {selected.revision.offer && <AdvertisementOfferTerms offer={selected.revision.offer} />}
                </section>
                <aside className="space-y-5">
                    <section className="space-y-4 rounded-lg border border-slate-300 bg-white p-4"><h2 className="font-bold">Review decision</h2><p className="text-sm">{reviewLabel(selected.state)} · Revision {selected.revision.revision_number}</p>
                        <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={reviewed} disabled={disabled || (!selected.can_review && selected.state !== 'approved')} onChange={(e) => setReviewed(e.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42]" />I reviewed this exact artwork, ownership, destination, requested period and every offer claim against the actual terms.</label>
                        <label className="block text-sm font-semibold">Feedback or placement note<textarea className={field} value={reason} maxLength={600} rows={3} disabled={disabled} onChange={(e) => setReason(e.target.value)} /><span className="mt-1 block text-xs font-normal text-slate-500">A useful reason of 5–600 characters is required for changes or rejection.</span></label>
                        <div className="flex flex-wrap gap-2"><button className={button} disabled={disabled || !selected.can_review || !reviewed || selected.context_current === false || Boolean(selected.eligibility_problem)} onClick={() => decide('approved')}>Approve version</button><button className={button} disabled={disabled || !selected.can_review || reason.trim().length < 5} onClick={() => decide('changes_requested')}>Request changes</button><button className={button} disabled={disabled || !selected.can_review || reason.trim().length < 5} onClick={() => decide('rejected')}>Reject version</button></div>
                        <p className="text-xs leading-relaxed text-slate-500">Approval does not reserve a position or make the promotion live. A newer pending draft cannot reuse an earlier version's approval.</p>
                    </section>
                    {selected.state === 'approved' && <section className="space-y-4 rounded-lg border border-slate-300 bg-white p-4"><h2 className="font-bold">Homepage placement</h2>
                        {publications.length > 0 && <label className="block text-sm font-semibold">Placement to replace<select value={publicationId} onChange={(e) => choosePlacement(e.target.value)} className={field}><option value="">Choose a current placement</option>{currentPlacements.map((publication) => <option value={publication.id} key={publication.id}>{slotLabel(publication.slot)} · {displayLabel(publication.state)}</option>)}</select></label>}
                        <label className="block text-sm font-semibold">Position<select value={slot} onChange={(e) => { setSlot(e.target.value); setReviewed(false); }} className={field}>{slots.map((position) => <option value={position} key={position}>{slotLabel(position)}</option>)}</select></label>
                        <label className="block text-sm font-semibold">Starts (Asia/Manila)<input type="datetime-local" className={field} value={start} onChange={(e) => { setStart(e.target.value); setReviewed(false); }} /></label>
                        <label className="block text-sm font-semibold">Ends (Asia/Manila)<input type="datetime-local" className={field} value={end} onChange={(e) => { setEnd(e.target.value); setReviewed(false); }} /></label>
                        <p className="text-xs leading-relaxed text-slate-500">Stay within the seller's reviewed requested period and real offer dates. A replacement retains the original placement. Paused positions stay reserved until ended.</p>
                        {!canPlace && <p role="status" className="text-sm text-amber-800">Choose a current placement to replace. Ended versions need a new seller draft and review.</p>}
                        <div className="flex flex-wrap gap-2"><button className={button} disabled={disabled || !canPlace || !reviewed} onClick={() => place(false)}>Schedule placement</button><button className={button} disabled={disabled || !canPlace || !reviewed} onClick={() => place(true)}>Publish now</button></div>
                    </section>}
                </aside>
            </div> : <div className="flex min-h-56 items-center justify-center rounded-lg border border-slate-300 bg-white p-6 text-center text-sm text-slate-500">This seller has saved a private draft but has not submitted a version for review.</div>}
            <section className="space-y-3"><h2 className="text-lg font-bold">Recorded placements</h2>{ad.publications.length ? ad.publications.map((publication) => <article key={publication.id} className="space-y-2 rounded-lg border border-slate-300 bg-white p-4"><h3 className="font-bold">{publication.title} · {slotLabel(publication.slot)}</h3><p className="text-sm">{displayLabel(publication.state)} · {dateLabel(publication.starts_at)} – {dateLabel(publication.effective_end)} (Manila)</p>{publication.reason && <p className="text-sm text-amber-800">{publication.reason}</p>}{publication.replaces_publication_id && <p className="text-xs text-slate-500">Replaces recorded placement #{publication.replaces_publication_id}.</p>}{!['ended', 'archived'].includes(publication.state) && <div className="flex flex-wrap gap-2"><button className={button} disabled={disabled} onClick={() => send('actions', { action: publication.state === 'paused' ? 'resume' : 'pause', publication_id: publication.id, reason })}>{publication.state === 'paused' ? 'Resume' : 'Pause'}</button><button className={button} disabled={disabled} onClick={() => send('actions', { action: 'end', publication_id: publication.id, reason })}>End placement</button></div>}</article>) : <p className="text-sm text-slate-500">No homepage placement has been recorded.</p>}</section>
            <section className="space-y-4"><h2 className="text-lg font-bold">Submission history</h2>{ad.submissions.map((submission) => <article id={`submission-${submission.id}`} key={submission.id} className="space-y-2 rounded-lg border border-slate-300 bg-white p-4"><button type="button" onClick={() => choose(submission)} className="text-left font-bold text-[#E00D42] underline">Revision {submission.revision.revision_number} · {reviewLabel(submission.state)}</button><p className="text-xs text-slate-500">Submitted {dateLabel(submission.submitted_at)} (Manila)</p>{submission.reviews.map((review) => <div className="border-l-2 border-slate-300 pl-3 text-sm" key={review.id}><p className="font-semibold">{reviewLabel(review.decision)} · {review.actor}</p>{review.reason && <p>{review.reason}</p>}<p className="text-xs text-slate-500">{dateLabel(review.created_at)} (Manila)</p></div>)}</article>)}</section>
            <PromotionHistory history={ad.history} id={ad.id} baseUrl={baseUrl} />
            <button className={button} disabled={disabled} onClick={() => send('actions', { action: 'archive', reason })}>Archive promotion and end placements</button>
        </div>
    </DashboardLayout>;
}
