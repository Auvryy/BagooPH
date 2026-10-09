import { Star } from 'lucide-react';

export function recordedRating(value: number | string | null | undefined): number | null {
    const rating = Number(value);
    return Number.isFinite(rating) && rating >= 1 && rating <= 5 ? rating : null;
}

export function ratingLabel(value: number | string | null | undefined): string {
    const rating = recordedRating(value);
    return rating === null ? 'No ratings yet' : rating.toFixed(1);
}

export default function RatingStars({ value, className = 'w-4 h-4', color = 'text-amber-400 fill-amber-400' }: {
    value: number | string | null | undefined;
    className?: string;
    color?: string;
}) {
    const rating = recordedRating(value);
    return <span className="inline-flex" aria-label={rating === null ? 'No ratings yet' : `${rating.toFixed(1)} out of 5 stars`}>
        {Array.from({ length: 5 }, (_, i) => <Star key={i} className={`${className} ${rating !== null && i < Math.round(rating) ? color : 'text-slate-300'}`} />)}
    </span>;
}
