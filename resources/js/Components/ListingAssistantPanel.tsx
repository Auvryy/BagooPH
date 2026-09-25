import React, { useState } from 'react';
import { Check, ChevronDown, Loader2, Sparkles, WandSparkles } from 'lucide-react';

interface ListingDraft {
    description: string;
    selling_points: string[];
    seo_title: string;
    image_alt_text: string;
}

interface Props {
    productName: string;
    category?: string;
    currentDetails?: string;
    onUseDescription: (description: string) => void;
}

export default function ListingAssistantPanel({
    productName,
    category,
    currentDetails,
    onUseDescription,
}: Props) {
    const [open, setOpen] = useState(false);
    const [tone, setTone] = useState<'clear' | 'friendly' | 'premium'>('clear');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [draft, setDraft] = useState<ListingDraft | null>(null);
    const [used, setUsed] = useState(false);

    const generate = async () => {
        if (!productName.trim() || loading) return;
        setLoading(true);
        setError(null);
        setUsed(false);

        try {
            const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';
            const response = await fetch(route('seller.products.assist-description'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    name: productName.trim(),
                    category: category || null,
                    details: currentDetails?.trim() || null,
                    tone,
                }),
            });

            const payload = await response.json();
            if (!response.ok) {
                throw new Error(payload.message || 'The assistant is unavailable right now.');
            }
            setDraft(payload.content);
        } catch (assistantError) {
            setError(assistantError instanceof Error ? assistantError.message : 'The assistant is unavailable right now.');
        } finally {
            setLoading(false);
        }
    };

    const useDraft = () => {
        if (!draft?.description) return;
        const formattedDraft = [
            draft.description.trim(),
            draft.selling_points.length > 0
                ? `Key points:\n${draft.selling_points.map(point => `• ${point}`).join('\n')}`
                : '',
        ].filter(Boolean).join('\n\n');
        onUseDescription(formattedDraft);
        setUsed(true);
    };

    return (
        <div className="mt-2 border border-slate-200 rounded-md bg-slate-50/70 overflow-hidden">
            <button
                type="button"
                onClick={() => setOpen(value => !value)}
                className="w-full flex items-center justify-between gap-3 px-3 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-slate-100 transition"
            >
                <span className="flex items-center gap-2">
                    <WandSparkles className="w-3.5 h-3.5 text-[#E00D42]" />
                    Draft with Bagoo Assistant
                </span>
                <ChevronDown className={`w-3.5 h-3.5 transition-transform ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <div className="px-3 pb-3 space-y-3 border-t border-slate-200">
                    <p className="pt-2 text-[11px] leading-relaxed text-slate-500">
                        Creates a first draft from the details you provide. Review every claim before publishing.
                    </p>
                    <div className="flex items-center gap-2">
                        <label htmlFor="listing-tone" className="text-[11px] font-medium text-slate-600">Tone</label>
                        <select
                            id="listing-tone"
                            value={tone}
                            onChange={event => setTone(event.target.value as typeof tone)}
                            className="flex-1 px-2 py-1.5 text-[11px] bg-white border border-slate-200 rounded-xs text-slate-700"
                        >
                            <option value="clear">Clear and practical</option>
                            <option value="friendly">Friendly</option>
                            <option value="premium">Premium</option>
                        </select>
                        <button
                            type="button"
                            onClick={generate}
                            disabled={!productName.trim() || loading}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[#E00D42] text-white text-[11px] font-semibold rounded-xs disabled:opacity-50"
                        >
                            {loading ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Sparkles className="w-3.5 h-3.5" />}
                            {loading ? 'Drafting' : 'Draft'}
                        </button>
                    </div>

                    {error && <p className="text-[11px] text-rose-600">{error}</p>}

                    {draft && (
                        <div className="space-y-2 bg-white border border-slate-200 rounded-sm p-3">
                            <p className="text-xs leading-relaxed text-slate-700">{draft.description}</p>
                            {draft.selling_points.length > 0 && (
                                <ul className="pl-4 text-[11px] leading-relaxed text-slate-600 list-disc">
                                    {draft.selling_points.map((point, index) => <li key={`${point}-${index}`}>{point}</li>)}
                                </ul>
                            )}
                            <div className="flex items-center justify-between gap-2 pt-1">
                                <span className="text-[10px] text-slate-400">Draft only — you remain in control.</span>
                                <button
                                    type="button"
                                    onClick={useDraft}
                                    className="inline-flex items-center gap-1 px-2.5 py-1.5 border border-slate-300 text-slate-700 text-[11px] font-semibold rounded-xs hover:bg-slate-50"
                                >
                                    {used ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : null}
                                    {used ? 'Inserted' : 'Use full draft'}
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
