import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import RatingStars, { ratingLabel } from '@/Components/RatingStars';
import { PaginatedData, Review, Shop } from '@/types';
import { MessageSquare, CornerDownRight, CheckCircle2 } from 'lucide-react';

interface Props {
    reviews: PaginatedData<Review & { product?: { id: number; name: string } }>;
    stats: {
        average_rating: number | null;
        total_reviews: number;
        response_rate: string;
        rating_breakdown: Record<'5_star' | '4_star' | '3_star' | '2_star' | '1_star', number>;
    };
    shop: Shop;
}

export default function SellerReviews({ reviews, stats, shop }: Props) {
    const [replyingReviewId, setReplyingReviewId] = useState<number | null>(null);
    const reply = useForm({ reply_text: '' });

    const beginReply = (review: Review) => {
        if (reply.processing) return;
        reply.clearErrors();
        reply.setData('reply_text', review.reply?.text ?? '');
        setReplyingReviewId(review.id);
    };
    const saveReply = (event: React.FormEvent, reviewId: number) => {
        event.preventDefault();
        if (reply.processing) return;
        reply.post(route('seller.reviews.reply', reviewId), {
            preserveScroll: true,
            onSuccess: () => { setReplyingReviewId(null); reply.reset(); },
        });
    };

    return <DashboardLayout title="Reviews" subtitle="Customer reviews and ratings">
        <Head title="Reviews — BagooPH Seller" />
        <div className="space-y-6">
            <div className="grid grid-cols-1 gap-5 md:grid-cols-3">
                <div className="flex items-center gap-5 rounded-2xl border border-slate-300 bg-white p-6 shadow-xs">
                    <div className="space-y-2">
                        <span className="text-2xl font-black text-amber-600">{ratingLabel(stats.average_rating)}</span>
                        <RatingStars value={stats.average_rating} />
                        <h3 className="text-sm font-bold text-slate-900">Store rating</h3>
                        <p className="text-xs text-slate-500">Based on {stats.total_reviews} verified purchase reviews</p>
                    </div>
                </div>
                <div className="flex items-center gap-4 rounded-2xl border border-slate-300 bg-white p-6 shadow-xs">
                    <MessageSquare className="h-6 w-6 shrink-0 text-emerald-600" />
                    <div>
                        <h3 className="text-xs font-bold text-slate-500">Review reply rate</h3>
                        <p className="text-xl font-black text-slate-900">{stats.response_rate}</p>
                        <p className="text-xs text-slate-500">Verified reviews with a saved seller reply</p>
                    </div>
                </div>
                <div className="flex items-center gap-4 rounded-2xl border border-slate-300 bg-white p-6 shadow-xs">
                    <CheckCircle2 className="h-6 w-6 shrink-0 text-[#E00D42]" />
                    <div>
                        <h3 className="text-sm font-bold text-slate-900">{shop.name}</h3>
                        <p className="text-xs text-slate-500">Review status: {shop.review_status ?? 'Review required'}</p>
                    </div>
                </div>
            </div>
            <div className="space-y-6 rounded-2xl border border-slate-300 bg-white p-6 shadow-xs">
                <div className="space-y-2 border-b border-slate-300 pb-4">
                    <h2 className="text-sm font-bold text-slate-900">Customer feedback ({reviews.total})</h2>
                    <p className="text-xs text-slate-500">Legacy reviews remain visible. Only verified purchases contribute to your rating.</p>
                    <div className="flex flex-wrap gap-3 text-xs text-slate-600">
                        {[5, 4, 3, 2, 1].map(star => <span key={star}>{star} stars: {stats.rating_breakdown[`${star}_star` as keyof typeof stats.rating_breakdown]}</span>)}
                    </div>
                </div>
                {reviews.data.length === 0 && <div className="space-y-2 py-12 text-center">
                    <MessageSquare className="mx-auto h-6 w-6 text-slate-400" />
                    <h3 className="font-bold text-slate-800">No customer reviews yet</h3>
                    <p className="text-xs text-slate-500">Completed purchases can be reviewed after the buyer confirms receipt.</p>
                </div>}
                <div className="divide-y divide-slate-300">
                    {reviews.data.map(review => <article key={review.id} className="space-y-3 py-6 first:pt-0">
                        <div className="flex items-start justify-between gap-3">
                            <div className="flex items-center gap-3">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-slate-900 text-xs font-bold text-white">
                                    {review.buyer?.avatar ? <img src={review.buyer.avatar} alt="" className="h-full w-full object-cover" /> : (review.buyer?.name ?? 'Buyer').charAt(0)}
                                </div>
                                <div>
                                    <h3 className="text-xs font-bold text-slate-900">{review.buyer?.name ?? 'Buyer'}</h3>
                                    <RatingStars value={review.rating} className="h-3 w-3" />
                                    <p className="text-xs text-slate-500">{review.created_at ? new Date(review.created_at).toLocaleDateString('en-PH') : 'Date unavailable'}</p>
                                </div>
                            </div>
                            <span className={`rounded px-2 py-1 text-xs font-semibold ${review.verified_purchase ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>
                                {review.verified_purchase ? 'Verified purchase' : 'Unverified legacy review'}
                            </span>
                        </div>
                        {review.product && <p className="text-xs text-slate-600">Product: <strong>{review.product.name}</strong></p>}
                        <p className="whitespace-pre-line text-xs leading-relaxed text-slate-700">{review.comment}</p>
                        {review.images?.length ? <div className="flex flex-wrap gap-2">{review.images.map((url, i) => <img key={i} src={url} alt="Buyer review photo" className="h-16 w-16 rounded-lg border border-slate-300 object-cover" />)}</div> : null}
                        {review.reply && <div className="space-y-1 rounded-r-xl border-l-4 border-[#E00D42] bg-rose-50/50 p-3.5 text-xs">
                            <p className="font-bold text-[#E00D42]">Seller reply</p>
                            <p className="whitespace-pre-line text-slate-700">{review.reply.text}</p>
                            <p className="text-slate-500">Published {new Date(review.reply.created_at).toLocaleDateString('en-PH')}{review.reply.updated_at !== review.reply.created_at ? ` · Updated ${new Date(review.reply.updated_at).toLocaleDateString('en-PH')}` : ''}</p>
                        </div>}
                        {replyingReviewId === review.id ? <form onSubmit={event => saveReply(event, review.id)} className="space-y-2 text-xs">
                            <label htmlFor={`reply-${review.id}`} className="font-semibold text-slate-700">Public seller reply</label>
                            <textarea id={`reply-${review.id}`} rows={3} maxLength={500} required disabled={reply.processing}
                                value={reply.data.reply_text} onChange={event => reply.setData('reply_text', event.target.value)}
                                className="w-full rounded-xl border border-slate-300 bg-slate-50 p-3 text-xs focus:border-[#E00D42] focus:ring-[#E00D42]" />
                            {reply.errors.reply_text && <p role="alert" className="text-rose-700">{reply.errors.reply_text}</p>}
                            <div className="flex gap-2">
                                <button disabled={reply.processing} className="rounded-lg bg-[#E00D42] px-4 py-2 font-bold text-white disabled:opacity-50">{reply.processing ? 'Saving…' : review.reply ? 'Save reply' : 'Post reply'}</button>
                                <button type="button" disabled={reply.processing} onClick={() => setReplyingReviewId(null)} className="rounded-lg border border-slate-300 px-4 py-2 text-slate-600">Cancel</button>
                            </div>
                        </form> : <button type="button" disabled={reply.processing} onClick={() => beginReply(review)} className="flex items-center gap-1.5 text-xs font-bold text-[#E00D42] hover:underline disabled:opacity-50">
                            <CornerDownRight className="h-3.5 w-3.5" />{review.reply ? 'Edit saved reply' : 'Reply to review'}
                        </button>}
                    </article>)}
                </div>
                {reviews.last_page > 1 && <nav aria-label="Review pages" className="flex items-center justify-between gap-3 border-t border-slate-300 pt-4 text-xs">
                    {reviews.prev_page_url ? <Link href={reviews.prev_page_url} preserveScroll className="rounded-lg border border-slate-300 px-3 py-2">Previous</Link> : <span />}
                    <span>Page {reviews.current_page} of {reviews.last_page}</span>
                    {reviews.next_page_url ? <Link href={reviews.next_page_url} preserveScroll className="rounded-lg border border-slate-300 px-3 py-2">Next</Link> : <span />}
                </nav>}
            </div>
        </div>
    </DashboardLayout>;
}
