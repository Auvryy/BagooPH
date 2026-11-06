import { useState } from 'react';
import axios from 'axios';
import { BadgeCheck, LoaderCircle, Sparkles, WandSparkles } from 'lucide-react';

interface Copy { title: string; alt_text: string; summary: string }
interface Review { valid: boolean; findings: string[]; advice: string; artwork_checked: boolean; artwork_technical: { format: string; bytes: number; width: number; height: number } | null }
interface Props {
    endpoint: string;
    title: string;
    altText: string;
    summary: string;
    purpose: string;
    image: File | null;
    disabled?: boolean;
    submissionId?: number;
    checkOnly?: boolean;
    onUseCopy: (copy: Copy) => void;
}

export default function PromotionAiTools({ endpoint, title, altText, summary, purpose, image, disabled = false, submissionId, checkOnly = false, onUseCopy }: Props) {
    const [loading, setLoading] = useState<'generate' | 'improve' | 'check' | null>(null);
    const [copy, setCopy] = useState<Copy | null>(null);
    const [review, setReview] = useState<Review | null>(null);
    const [error, setError] = useState<string | null>(null);

    const run = async (action: 'generate' | 'improve' | 'check') => {
        if (loading || disabled) return;
        setLoading(action); setError(null); setReview(null); setCopy(null);
        const data = new FormData();
        data.append('action', action); data.append('title', title); data.append('alt_text', altText);
        data.append('summary', summary); data.append('purpose', purpose);
        if (submissionId) data.append('submission_id', String(submissionId));
        if (action === 'check' && image) data.append('image', image);
        try {
            const response = await axios.post<{ content?: Copy; review?: Review }>(endpoint, data, { headers: { Accept: 'application/json' } });
            if (action === 'check') setReview(response.data.review ?? null);
            else setCopy(response.data.content ?? null);
        } catch (caught) {
            const response = axios.isAxiosError(caught) ? caught.response : undefined;
            const errors = response?.data?.errors as Record<string, string[]> | undefined;
            const message = response?.data?.message ?? Object.values(errors ?? {}).flat().join(' ');
            setError(message || 'Gemini is unavailable right now. You can keep editing and save your draft manually.');
        } finally {
            setLoading(null);
        }
    };

    return <section aria-label="Promotion writing assistant" className="space-y-3 rounded-xl border border-slate-300 bg-slate-50 p-4">
        <div className="flex items-center gap-2"><WandSparkles aria-hidden="true" className="h-4 w-4 text-[#E00D42]" /><h3 className="text-sm font-bold text-slate-900">Gemini writing and content check</h3></div>
        <p className="text-xs leading-relaxed text-slate-600">Generate or improve promotion copy, then check the current text and artwork. Suggestions are drafts; admin review still decides publication.</p>
        <div className="flex flex-wrap gap-2">
            {!checkOnly && <button type="button" onClick={() => run('generate')} disabled={disabled || !!loading} className="inline-flex items-center gap-1.5 rounded-lg bg-[#E00D42] px-3 py-2 text-xs font-bold text-white disabled:opacity-50"><Sparkles className="h-3.5 w-3.5" />{loading === 'generate' ? 'Generating…' : 'Generate copy'}</button>}
            {!checkOnly && <button type="button" onClick={() => run('improve')} disabled={disabled || !!loading} className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-800 disabled:opacity-50">{loading === 'improve' ? 'Improving…' : 'Improve copy'}</button>}
            <button type="button" onClick={() => run('check')} disabled={disabled || !!loading} className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-800 disabled:opacity-50"><BadgeCheck className="h-3.5 w-3.5" />{loading === 'check' ? <><LoaderCircle className="h-3.5 w-3.5 animate-spin" />Checking…</> : 'Check text and artwork'}</button>
        </div>
        {error && <p role="alert" className="rounded-lg border border-rose-300 bg-rose-50 p-3 text-xs text-rose-800">{error}</p>}
        {copy && <div className="space-y-2 rounded-lg border border-slate-300 bg-white p-3 text-xs">
            <p className="font-bold text-slate-900">Suggested copy</p><p className="font-semibold">{copy.title}</p><p className="text-slate-600">Image description: {copy.alt_text}</p>{copy.summary && <p className="whitespace-pre-line text-slate-600">{copy.summary}</p>}
            <button type="button" onClick={() => onUseCopy(copy)} className="rounded-lg border border-slate-300 px-3 py-2 font-semibold hover:border-[#E00D42] hover:text-[#E00D42]">Use this copy</button>
        </div>}
        {review && <div role="status" className={`space-y-2 rounded-lg border p-3 text-xs ${review.valid ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-rose-300 bg-rose-50 text-rose-900'}`}>
            <p className="font-bold">{review.valid ? 'No obvious content issues found' : 'Review these possible issues'}</p>
            {review.findings.length > 0 && <ul className="list-disc space-y-1 pl-4">{review.findings.map((finding, index) => <li key={`${index}-${finding}`}>{finding}</li>)}</ul>}
            {review.advice && <p>{review.advice}</p>}
            {review.artwork_checked && review.artwork_technical && <p>Artwork checked: {review.artwork_technical.format}, {review.artwork_technical.width} × {review.artwork_technical.height}, {Math.ceil(review.artwork_technical.bytes / 1024)} KiB.</p>}
            {!review.artwork_checked && <p>Only text was checked. Select or retain artwork for the visual check.</p>}
            <p className="font-semibold">This check does not approve a promotion. Human review remains required.</p>
        </div>}
    </section>;
}
