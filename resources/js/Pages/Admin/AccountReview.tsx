import { Head, Link, router, useForm } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import RestrictionReview, { type RestrictionSubject } from '@/Components/RestrictionReview';
import InputError from '@/Components/InputError';
import { reviewActionLabels, reviewStatusLabels, type ReviewRequest } from '@/types/accountReviews';

type Review = {
    request: ReviewRequest; owner: { id: number; name: string; email: string; role: string; status: string; kyc_status: string };
    source_reason: string; stale: boolean; actions: string[]; activity: RestrictionSubject | null; review_token: string;
};

function DecisionForm({ review, endpoint }: { review: Review; endpoint: string }) {
    const actions = review.actions.filter(action => action !== 'reactivate');
    const form = useForm({ review_token: review.review_token, action: actions.includes('reopen_application') ? 'reopen_application' : actions[0] ?? '', reason: '' });
    if (!actions.length) return null;
    return <form className="space-y-4 rounded-xl border border-slate-300 bg-white p-5" onSubmit={event => { event.preventDefault(); form.post(endpoint, { preserveScroll: true }); }}>
        <h2 className="font-semibold">Respond to this request</h2>
        <p className="text-sm leading-relaxed text-slate-600">Returning an application to review does not approve it. The normal evidence review still applies. Declining a request keeps the current restriction in place.</p>
        <label htmlFor="review-action" className="block text-sm font-semibold">Response</label>
        <select id="review-action" value={form.data.action} onChange={event => form.setData('action', event.target.value)} className="w-full rounded-lg border border-slate-300 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]">
            {actions.map(action => <option key={action} value={action}>{reviewActionLabels[action]}</option>)}
        </select>
        <label htmlFor="review-reason" className="block text-sm font-semibold">Explain the outcome</label>
        <p className="text-xs text-slate-500">The owner will receive this response in Account status and by email.</p>
        <textarea id="review-reason" value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} minLength={5} maxLength={1000} required rows={4} className="w-full rounded-lg border border-slate-300 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]" />
        {Object.entries(form.errors).map(([key, error]) => <InputError key={key} message={error} role="alert" />)}
        <button type="submit" disabled={form.processing || form.data.reason.trim().length < 5} className="rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{form.processing ? 'Saving response...' : 'Save response'}</button>
        <button type="button" onClick={() => router.reload({ only: ['review'] })} className="ml-3 text-sm font-semibold underline underline-offset-2">Reload current status</button>
    </form>;
}

export default function AccountReview({ review, endpoint }: { review: Review; endpoint: string }) {
    return <DashboardLayout title={`Review request #${review.request.id}`} subtitle="Read the owner explanation and the current account status" actions={<Link href={route('admin.account-reviews.index')} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">Back to requests</Link>}>
        <Head title="Account review — BagooPH" />
        <div className="space-y-5">
            <section className="space-y-3 rounded-xl border border-slate-300 bg-white p-5">
                <h2 className="text-lg font-semibold">{review.owner.name}</h2><p className="break-all text-sm text-slate-600">{review.owner.email} · {review.owner.role === 'courier' ? 'Rider' : review.owner.role}</p>
                <p className="text-sm font-semibold">{reviewStatusLabels[review.request.status]}</p>
                <p className="text-sm leading-relaxed text-slate-700">Current feedback: {review.source_reason}</p>
                <div className="border-t border-slate-300 pt-3"><h3 className="text-sm font-semibold">Owner explanation</h3><p className="mt-2 whitespace-pre-line text-sm leading-relaxed text-slate-700">{review.request.message}</p></div>
                {review.request.decision && <p className="whitespace-pre-line text-sm text-slate-700">Admin response: {review.request.decision.reason}</p>}
                {review.stale && review.request.status === 'pending' && <p role="status" className="text-sm text-amber-800">The account or restriction changed after this request. Close it as outdated and review any newer request.</p>}
                <Link href={route('admin.users.context', review.owner.id)} className="inline-block text-sm font-semibold text-[#E00D42] underline underline-offset-2">Open account history</Link>
            </section>
            <DecisionForm key={review.review_token} review={review} endpoint={endpoint} />
            {review.activity && review.actions.includes('reactivate') && <RestrictionReview key={review.review_token + review.activity.source_token} subject={review.activity} endpoint={endpoint} reviewToken={review.review_token} />}
        </div>
    </DashboardLayout>;
}
