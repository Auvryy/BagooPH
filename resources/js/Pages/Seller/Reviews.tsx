import React, { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { PaginatedData, Review, Shop } from '@/types';
import { 
    Star, 
    MessageSquare, 
    CornerDownRight, 
    Send, 
    CheckCircle2, 
    ThumbsUp, 
    Filter, 
    Search, 
    Calendar, 
    Check, 
    Sparkles 
} from 'lucide-react';

interface StatsData {
    average_rating: number;
    total_reviews: number;
    response_rate: string;
    rating_breakdown: {
        '5_star': number;
        '4_star': number;
        '3_star': number;
        '2_star': number;
        '1_star': number;
    };
}

interface ReviewItem {
    id: number;
    product_id: number;
    buyer_id: number;
    order_id?: number;
    rating: number;
    comment: string;
    images?: string[];
    created_at?: string;
    product?: {
        id: number;
        name: string;
        featured_image?: string;
    };
    buyer?: {
        id: number;
        name: string;
    };
}

interface Props {
    reviews: PaginatedData<any>;
    stats: StatsData;
    shop?: Shop | null;
}

export default function SellerReviews({ reviews, stats, shop }: Props) {
    const [replyingReviewId, setReplyingReviewId] = useState<number | null>(null);
    const [replyText, setReplyText] = useState('');
    const [repliedMap, setRepliedMap] = useState<Record<number, string>>({});

    // Use actual reviews passed from server
    const displayReviews: ReviewItem[] = (reviews?.data || []) as ReviewItem[];

    const handleSendReply = (reviewId: number) => {
        if (!replyText.trim()) return;
        setRepliedMap((prev) => ({ ...prev, [reviewId]: replyText.trim() }));
        setReplyingReviewId(null);
        setReplyText('');
    };

    return (
        <DashboardLayout
            title="Reviews"
            subtitle="Customer reviews and ratings"
        >
            <Head title="Reviews — BagooPH Seller" />

            <div className="space-y-6">
                
                {/* 1. STORE RATING SUMMARY CARDS */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
                    
                    {/* Overall Rating Box */}
                    <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs flex items-center gap-5">
                        <div className="w-16 h-16 rounded-2xl bg-amber-50 border border-amber-200 flex flex-col items-center justify-center font-sans shrink-0">
                            <span className="text-2xl font-black text-amber-500">{stats.average_rating}</span>
                            <span className="text-[10px] text-slate-400 font-bold">/ 5.0</span>
                        </div>
                        <div className="space-y-1">
                            <div className="flex text-amber-400">
                                <Star className="w-4 h-4 fill-amber-400" />
                                <Star className="w-4 h-4 fill-amber-400" />
                                <Star className="w-4 h-4 fill-amber-400" />
                                <Star className="w-4 h-4 fill-amber-400" />
                                <Star className="w-4 h-4 fill-amber-400" />
                            </div>
                            <h4 className="font-bold text-slate-900 text-sm">Store Performance</h4>
                            <p className="text-xs text-slate-500 font-sans">Based on {stats.total_reviews} verified buyer reviews</p>
                        </div>
                    </div>

                    {/* Merchant Response Rate */}
                    <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs flex items-center gap-4">
                        <div className="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold shrink-0">
                            <MessageSquare className="w-6 h-6" />
                        </div>
                        <div>
                            <span className="text-[10px] font-sans text-slate-400 uppercase">Response Rate</span>
                            <h3 className="text-xl font-black font-sans text-slate-900">{stats.response_rate}</h3>
                            <p className="text-xs text-slate-500">Replies published within hours</p>
                        </div>
                    </div>

                    {/* Verified Merchant Badge */}
                    <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs flex items-center gap-4">
                        <div className="w-12 h-12 rounded-xl bg-rose-50 text-[#E00D42] flex items-center justify-center font-bold shrink-0">
                            <Sparkles className="w-6 h-6" />
                        </div>
                        <div>
                            <span className="text-[10px] font-sans text-slate-400 uppercase">Mall Status</span>
                            <h3 className="text-sm font-black text-slate-900 uppercase">Bagoo Mall Merchant</h3>
                            <p className="text-xs text-emerald-600 font-bold font-sans">Top Rated Seller</p>
                        </div>
                    </div>

                </div>

                {/* 2. REVIEWS FEED */}
                <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-6">
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-slate-100 font-sans text-xs">
                        <h3 className="font-bold text-slate-900 text-sm uppercase">Recent Customer Feedback</h3>
                        <div className="flex items-center gap-2">
                            <button className="px-3 py-1.5 rounded-lg bg-slate-100 font-bold text-slate-700 hover:bg-slate-200">
                                All ({displayReviews.length})
                            </button>
                            <button className="px-3 py-1.5 rounded-lg bg-slate-50 font-bold text-slate-500 hover:bg-slate-100">
                                5 Stars ({stats.rating_breakdown['5_star']})
                            </button>
                        </div>
                    </div>

                    {displayReviews.length === 0 ? (
                        <div className="py-16 text-center space-y-3 font-sans">
                            <div className="w-12 h-12 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mx-auto">
                                <MessageSquare className="w-6 h-6" />
                            </div>
                            <h3 className="text-base font-bold text-slate-800">No customer reviews yet</h3>
                            <p className="text-xs text-slate-500 max-w-sm mx-auto font-sans">
                                When customers complete their purchases and leave ratings, their feedback and photo reviews will appear here.
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-6 divide-y divide-slate-100">
                            {displayReviews.map((rev) => {
                            const hasLocalReply = repliedMap[rev.id];
                            return (
                                <div key={rev.id} className="pt-6 first:pt-0 space-y-3 font-sans">
                                    
                                    {/* Review Top Meta */}
                                    <div className="flex items-start justify-between">
                                        <div className="flex items-center gap-3">
                                            <div className="w-10 h-10 rounded-full bg-slate-900 text-white flex items-center justify-center font-black text-xs">
                                                {rev.buyer?.name ? rev.buyer.name.charAt(0) : 'B'}
                                            </div>
                                            <div>
                                                <h4 className="font-bold text-xs text-slate-900">{rev.buyer?.name || 'Verified Buyer'}</h4>
                                                <div className="flex items-center gap-2 mt-0.5">
                                                    <div className="flex text-amber-400">
                                                        {Array.from({ length: rev.rating }).map((_, i) => (
                                                            <Star key={i} className="w-3 h-3 fill-amber-400" />
                                                        ))}
                                                    </div>
                                                    <span className="font-sans text-[10px] text-slate-400">{rev.created_at || 'Recently'}</span>
                                                </div>
                                            </div>
                                        </div>

                                        <span className="text-[10px] font-sans font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded">
                                            Verified Purchase
                                        </span>
                                    </div>

                                    {/* Purchased Product Tag */}
                                    {rev.product && (
                                        <div className="flex items-center gap-2 p-2 bg-slate-50 rounded-xl border border-slate-100 w-fit text-xs font-sans">
                                            <span className="text-slate-400 text-[10px]">Product:</span>
                                            <span className="font-bold text-slate-800">{rev.product.name}</span>
                                        </div>
                                    )}

                                    {/* Review Comment */}
                                    <p className="text-xs text-slate-700 leading-relaxed font-sans">
                                        {rev.comment}
                                    </p>

                                    {/* Attached Customer Photos */}
                                    {rev.images && rev.images.length > 0 && (
                                        <div className="flex items-center gap-2 pt-1">
                                            {rev.images.map((img, i) => (
                                                <img key={i} src={img} alt="Buyer proof" className="w-16 h-16 object-cover rounded-lg border border-slate-200" />
                                            ))}
                                        </div>
                                    )}

                                    {/* Merchant Reply Section */}
                                    {hasLocalReply ? (
                                        <div className="ml-6 p-3.5 bg-rose-50/50 border-l-4 border-l-[#E00D42] rounded-r-xl space-y-1 font-sans text-xs">
                                            <span className="font-sans font-bold text-[#E00D42] text-[11px] block">
                                                Merchant Response ({shop?.name || 'Store Owner'}):
                                            </span>
                                            <p className="text-slate-700">{hasLocalReply}</p>
                                        </div>
                                    ) : replyingReviewId === rev.id ? (
                                        <div className="ml-6 pt-2 space-y-2 font-sans text-xs">
                                            <textarea
                                                rows={2}
                                                value={replyText}
                                                onChange={(e) => setReplyText(e.target.value)}
                                                placeholder="Write your public reply to this customer review..."
                                                className="w-full rounded-xl bg-slate-50 border border-slate-200 p-2.5 text-xs focus:ring-[#E00D42] focus:border-[#E00D42]"
                                            />
                                            <div className="flex items-center gap-2">
                                                <button
                                                    onClick={() => handleSendReply(rev.id)}
                                                    className="px-3.5 py-1.5 rounded-lg bg-[#E00D42] text-white font-sans font-bold uppercase text-[11px]"
                                                >
                                                    Post Reply
                                                </button>
                                                <button
                                                    onClick={() => { setReplyingReviewId(null); setReplyText(''); }}
                                                    className="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 font-sans text-[11px]"
                                                >
                                                    Cancel
                                                </button>
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="pt-1">
                                            <button
                                                onClick={() => { setReplyingReviewId(rev.id); setReplyText(''); }}
                                                className="text-xs font-sans font-bold text-[#E00D42] hover:underline flex items-center gap-1.5"
                                            >
                                                <CornerDownRight className="w-3.5 h-3.5" />
                                                <span>Reply to Review</span>
                                            </button>
                                        </div>
                                    )}

                                </div>
                            );
                        })}
                        </div>
                    )}
                </div>

            </div>
        </DashboardLayout>
    );
}
