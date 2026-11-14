import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { CheckCircle2, ShieldAlert, Star, Truck } from 'lucide-react';

export interface DeliveryFeedbackItem {
    id: number;
    rating: number;
    timeliness_rating?: number | null;
    handling_rating?: number | null;
    communication_rating?: number | null;
    comment?: string | null;
    tags?: string[] | null;
    edited_at?: string | null;
    submitted_at?: string | null;
}

export interface SafetyCheckinSignalItem {
    id: number;
    reference: string;
    signal_type: string;
    status: string;
    summary: string;
    buyer_checkin_response?: string | null;
    checked_in_at?: string | null;
}

const FEEDBACK_TAGS: Array<{ value: string; label: string }> = [
    { value: 'fast_delivery', label: 'Fast delivery' },
    { value: 'polite_rider', label: 'Polite rider' },
    { value: 'parcel_intact', label: 'Parcel intact' },
    { value: 'late_delivery', label: 'Late delivery' },
    { value: 'rude_behavior', label: 'Rude behavior' },
    { value: 'damaged_outer_box', label: 'Damaged outer box' },
];

function SafetyCheckinCard({ signal }: { signal: SafetyCheckinSignalItem }) {
    const form = useForm({
        response: 'safe_on_platform' as 'safe_on_platform' | 'reported_bypass',
        notes: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/buyer/safety-checkins/${signal.id}`, {
            preserveScroll: true,
        });
    };

    return (
        <div className="rounded-xl border border-amber-200 bg-amber-50/70 p-4 space-y-2.5">
            <div className="flex items-start gap-2.5">
                <ShieldAlert className="h-4 w-4 text-amber-700 shrink-0 mt-0.5" />
                <div className="space-y-1">
                    <p className="text-xs font-bold text-slate-900">
                        Bagoo Anti-Scam Safety Check-In ({signal.reference})
                    </p>
                    <p className="text-xs text-slate-700">{signal.summary}</p>
                </div>
            </div>

            {signal.buyer_checkin_response ? (
                <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-white px-3 py-2 text-xs text-emerald-800">
                    <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-600" />
                    <span>
                        Check-in recorded:{' '}
                        {signal.buyer_checkin_response === 'reported_bypass'
                            ? 'Reported off-platform payment or contact request'
                            : 'Confirmed transaction stayed on Bagoo'}
                    </span>
                </div>
            ) : (
                <form onSubmit={submit} className="space-y-2.5 rounded-lg border border-amber-200 bg-white p-3">
                    <div className="flex flex-wrap gap-3 text-xs">
                        <label className="flex items-center gap-1.5 cursor-pointer text-slate-800">
                            <input
                                type="radio"
                                name={`checkin-${signal.id}`}
                                checked={form.data.response === 'safe_on_platform'}
                                onChange={() => form.setData('response', 'safe_on_platform')}
                                className="text-[#E00D42] focus:ring-[#E00D42]"
                            />
                            <span>Everything stayed inside Bagoo</span>
                        </label>
                        <label className="flex items-center gap-1.5 cursor-pointer text-rose-800">
                            <input
                                type="radio"
                                name={`checkin-${signal.id}`}
                                checked={form.data.response === 'reported_bypass'}
                                onChange={() => form.setData('response', 'reported_bypass')}
                                className="text-[#E00D42] focus:ring-[#E00D42]"
                            />
                            <span>Asked to transact or pay outside Bagoo</span>
                        </label>
                    </div>
                    <input
                        type="text"
                        value={form.data.notes}
                        onChange={e => form.setData('notes', e.target.value)}
                        placeholder="Optional safety note for our moderation team..."
                        className="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs text-slate-900"
                    />
                    {form.errors.response && <p className="text-xs text-rose-700">{form.errors.response}</p>}
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                    >
                        {form.processing ? 'Sending check-in...' : 'Submit safety check-in'}
                    </button>
                </form>
            )}
        </div>
    );
}

export default function CourierDeliveryFeedbackSection({
    deliveryFeedback = null,
    deliveryFeedbackUrl = null,
    safetyCheckinSignals = [],
}: {
    deliveryFeedback?: DeliveryFeedbackItem | null;
    deliveryFeedbackUrl?: string | null;
    safetyCheckinSignals?: SafetyCheckinSignalItem[];
}) {
    const [editing, setEditing] = useState(!deliveryFeedback);
    const form = useForm({
        rating: deliveryFeedback?.rating ?? 5,
        timeliness_rating: deliveryFeedback?.timeliness_rating ?? 5,
        handling_rating: deliveryFeedback?.handling_rating ?? 5,
        communication_rating: deliveryFeedback?.communication_rating ?? 5,
        comment: deliveryFeedback?.comment ?? '',
        tags: deliveryFeedback?.tags ?? ([] as string[]),
    });

    if (!deliveryFeedbackUrl && !deliveryFeedback && safetyCheckinSignals.length === 0) {
        return null;
    }

    const toggleTag = (tag: string) => {
        const current = form.data.tags;
        if (current.includes(tag)) {
            form.setData('tags', current.filter(t => t !== tag));
        } else if (current.length < 5) {
            form.setData('tags', [...current, tag]);
        }
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!deliveryFeedbackUrl) return;
        form.post(deliveryFeedbackUrl, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    return (
        <div className="space-y-4">
            {safetyCheckinSignals.length > 0 && (
                <div className="space-y-3">
                    {safetyCheckinSignals.map(signal => (
                        <SafetyCheckinCard key={signal.id} signal={signal} />
                    ))}
                </div>
            )}

            {(deliveryFeedbackUrl || deliveryFeedback) && (
                <section className="rounded-xl border border-slate-200 bg-white p-5 space-y-3">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="flex items-start gap-2.5">
                            <Truck className="h-5 w-5 text-[#E00D42] shrink-0 mt-0.5" />
                            <div>
                                <h3 className="text-sm font-bold text-slate-900">
                                    Delivery & Courier Service Feedback
                                </h3>
                                <p className="text-xs text-slate-600">
                                    Rated separately from seller products. Courier feedback measures rider timeliness and parcel care without penalizing the shop item score.
                                </p>
                            </div>
                        </div>
                        {deliveryFeedback && !deliveryFeedback.edited_at && deliveryFeedbackUrl && !editing && (
                            <button
                                type="button"
                                onClick={() => setEditing(true)}
                                className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Edit courier rating
                            </button>
                        )}
                    </div>

                    {deliveryFeedback && !editing ? (
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3.5 space-y-2 text-xs">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="inline-flex items-center gap-1 font-bold text-slate-900">
                                    <Star className="h-3.5 w-3.5 fill-amber-400 text-amber-400" />
                                    {deliveryFeedback.rating} / 5 overall delivery experience
                                </span>
                                {deliveryFeedback.edited_at && (
                                    <span className="text-slate-500">Updated once</span>
                                )}
                            </div>
                            {deliveryFeedback.comment && (
                                <p className="text-slate-700">{deliveryFeedback.comment}</p>
                            )}
                            {deliveryFeedback.tags && deliveryFeedback.tags.length > 0 && (
                                <div className="flex flex-wrap gap-1.5">
                                    {deliveryFeedback.tags.map(tag => (
                                        <span key={tag} className="rounded-full bg-white border border-slate-200 px-2.5 py-0.5 text-[11px] font-medium text-slate-700">
                                            {FEEDBACK_TAGS.find(item => item.value === tag)?.label ?? tag}
                                        </span>
                                    ))}
                                </div>
                            )}
                        </div>
                    ) : deliveryFeedbackUrl ? (
                        <form onSubmit={submit} className="space-y-3 rounded-lg border border-slate-200 bg-slate-50/70 p-4">
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 text-xs">
                                {(
                                    [
                                        ['rating', 'Overall Courier'],
                                        ['timeliness_rating', 'Timeliness'],
                                        ['handling_rating', 'Parcel Care'],
                                        ['communication_rating', 'Communication'],
                                    ] as const
                                ).map(([field, label]) => (
                                    <label key={field} className="space-y-1">
                                        <span className="block font-semibold text-slate-700">{label}</span>
                                        <select
                                            value={form.data[field]}
                                            onChange={e => form.setData(field, Number(e.target.value))}
                                            className="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-900"
                                        >
                                            {[5, 4, 3, 2, 1].map(score => (
                                                <option key={score} value={score}>
                                                    {score} Star{score > 1 ? 's' : ''}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                ))}
                            </div>

                            <div className="flex flex-wrap gap-1.5">
                                {FEEDBACK_TAGS.map(tag => {
                                    const active = form.data.tags.includes(tag.value);
                                    return (
                                        <button
                                            key={tag.value}
                                            type="button"
                                            onClick={() => toggleTag(tag.value)}
                                            className={`rounded-full border px-2.5 py-1 text-[11px] font-semibold transition ${
                                                active
                                                    ? 'border-[#E00D42] bg-rose-50 text-[#C20836]'
                                                    : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-100'
                                            }`}
                                        >
                                            {tag.label}
                                        </button>
                                    );
                                })}
                            </div>

                            <textarea
                                value={form.data.comment}
                                onChange={e => form.setData('comment', e.target.value)}
                                rows={2}
                                maxLength={1000}
                                placeholder="Share feedback on rider punctuality, box handling, or courier communication..."
                                className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900"
                            />
                            {form.errors.rating && <p className="text-xs text-rose-700">{form.errors.rating}</p>}
                            {form.errors.comment && <p className="text-xs text-rose-700">{form.errors.comment}</p>}

                            <div className="flex gap-2">
                                <button
                                    type="submit"
                                    disabled={form.processing}
                                    className="rounded-lg bg-[#E00D42] px-4 py-2 text-xs font-bold text-white hover:bg-[#c50b3a] disabled:opacity-50"
                                >
                                    {form.processing ? 'Saving...' : deliveryFeedback ? 'Update courier rating' : 'Submit courier feedback'}
                                </button>
                                {deliveryFeedback && (
                                    <button
                                        type="button"
                                        onClick={() => setEditing(false)}
                                        className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100"
                                    >
                                        Cancel
                                    </button>
                                )}
                            </div>
                        </form>
                    ) : null}
                </section>
            )}
        </div>
    );
}
