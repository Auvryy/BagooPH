import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import InputError from '@/Components/InputError';
import type { ReviewSource } from '@/types/accountReviews';

export default function AccountReviewRequestForm({ source }: { source: ReviewSource }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ subject_type: source.type, subject_id: source.id, source_token: source.source_token, message: '' });
    if (source.pending_request_id) return <p role="status" className="mt-3 text-sm font-semibold text-amber-800">Request #{source.pending_request_id} is waiting for review.</p>;

    return <div className="mt-3">
        <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} aria-controls={`review-form-${source.type}-${source.id}`}
            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50 focus:ring-2 focus:ring-[#E00D42]">{open ? 'Cancel request' : 'Request review'}</button>
        {open && <form id={`review-form-${source.type}-${source.id}`} className="mt-4 space-y-3" onSubmit={event => {
            event.preventDefault();
            form.post(route('account.reviews.store'), { preserveScroll: true });
        }}>
            <label htmlFor={`review-message-${source.type}-${source.id}`} className="block text-sm font-semibold">Why are you requesting a review?</label>
            <textarea id={`review-message-${source.type}-${source.id}`} name="message" value={form.data.message}
                onChange={event => form.setData('message', event.target.value)} minLength={20} maxLength={2000} rows={4} required
                placeholder="Explain what happened and what you have corrected."
                className="w-full rounded-lg border border-slate-300 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]" />
            <p className="text-xs leading-relaxed text-slate-600">Use 20–2,000 characters. Your current access stays unchanged while an admin reviews your request. Use application corrections to update documents or reviewed details.</p>
            {Object.entries(form.errors).map(([field, error]) => <InputError key={field} role="alert" message={error} />)}
            <button type="submit" disabled={form.processing || form.data.message.trim().length < 20}
                className="rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white hover:bg-[#C20836] disabled:opacity-50">{form.processing ? 'Sending request...' : 'Send review request'}</button>
        </form>}
    </div>;
}
