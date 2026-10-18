import { useForm } from '@inertiajs/react';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { useRef, useState } from 'react';

export default function BuyerSavedReview({ review }: { review: { id: number; rating?: number; comment?: string | null; edit_url?: string | null; edited_at?: string | null } }) {
    const [open, setOpen] = useState(false);
    const busy = useRef(false);
    const form = useForm({ rating: review.rating ?? 5, comment: review.comment ?? '' });
    return <div className="space-y-1">
        <p className="text-xs font-semibold text-emerald-700">Review saved{review.edited_at ? ' · Edited' : ''}</p>
        {review.edit_url && <button type="button" onClick={() => { form.setData({ rating: review.rating ?? 5, comment: review.comment ?? '' }); setOpen(true); }} className="text-xs font-semibold text-[#C20836] underline">Edit review</button>}
        <Dialog open={open} onClose={() => { if (!busy.current) setOpen(false); }} className="relative z-50">
            <div className="fixed inset-0 bg-slate-900/50" aria-hidden="true" />
            <div className="fixed inset-0 flex items-center justify-center overflow-y-auto p-4"><DialogPanel className="w-full max-w-md space-y-4 rounded-2xl bg-white p-6 shadow-xl">
                <DialogTitle className="text-lg font-bold text-slate-900">Edit your review</DialogTitle>
                <p className="text-sm text-slate-600">You can change the rating and text once, within 30 days of posting. Your existing photos stay attached.</p>
                <form className="space-y-4" onSubmit={event => {
                    event.preventDefault(); if (busy.current || !review.edit_url) return; busy.current = true;
                    form.patch(review.edit_url, { preserveScroll: true, onSuccess: () => setOpen(false), onFinish: () => { busy.current = false; } });
                }}>
                    <label className="block text-sm font-semibold text-slate-700">Rating<select value={form.data.rating} onChange={event => form.setData('rating', Number(event.target.value))} disabled={form.processing} className="mt-2 w-full rounded-lg border-slate-300 text-sm">{[5, 4, 3, 2, 1].map(rating => <option key={rating} value={rating}>{rating} {rating === 1 ? 'star' : 'stars'}</option>)}</select></label>
                    <label className="block text-sm font-semibold text-slate-700">Your review<textarea maxLength={1000} value={form.data.comment} onChange={event => form.setData('comment', event.target.value)} disabled={form.processing} rows={4} className="mt-2 w-full rounded-lg border-slate-300 text-sm" /></label>
                    <p className="text-xs text-slate-500">Up to 1,000 characters.</p>
                    {Object.values(form.errors).map(error => <p key={error} role="alert" className="text-sm text-red-700">{error}</p>)}
                    <div className="flex justify-end gap-3"><button type="button" disabled={form.processing} onClick={() => setOpen(false)} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold">Keep review</button><button type="submit" disabled={form.processing} className="rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{form.processing ? 'Saving…' : 'Save edit'}</button></div>
                </form>
            </DialogPanel></div>
        </Dialog>
    </div>;
}
