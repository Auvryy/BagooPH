import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { type CancellationRequest } from './OrderCancellationFeedback';

export default function OrderCancellationReview({ request, disabled = false }: { request: CancellationRequest; disabled?: boolean }) {
    const [decision, setDecision] = useState<'approve' | 'reject' | null>(null);
    const busy = useRef(false);
    const form = useForm({ decision_reason: '', expected_version: request.version, request_token: crypto.randomUUID() });
    if (!request.can_review) return null;
    return <div className="space-y-3 rounded-xl border border-rose-200 bg-white p-4">
        <p className="text-sm font-bold text-[#9F1239]">Review buyer cancellation</p>
        <div className="flex flex-wrap gap-2">{(['approve', 'reject'] as const).map(value => <button key={value} type="button" disabled={disabled || form.processing} aria-pressed={decision === value} onClick={() => { setDecision(value); form.clearErrors(); }} className={`rounded-lg border px-3 py-2 text-sm font-semibold disabled:opacity-50 ${decision === value ? 'border-[#E00D42] bg-[#E00D42] text-white' : 'border-rose-200 bg-rose-50 text-[#C20836] hover:bg-rose-100'}`}>{value === 'approve' ? 'Approve cancellation' : 'Decline request'}</button>)}</div>
        {decision && <form className="space-y-3" onSubmit={event => {
            event.preventDefault(); if (busy.current || disabled) return;
            const url = decision === 'approve' ? request.approve_url : request.reject_url;
            if (!url) return;
            busy.current = true;
            form.post(url, { preserveScroll: true, onSuccess: () => { setDecision(null); form.reset(); form.setData('request_token', crypto.randomUUID()); }, onFinish: () => { busy.current = false; } });
        }}>
            <p className="text-sm text-slate-600">{decision === 'approve' ? 'Approval cancels this order, restores its reserved stock and applies the recorded payment outcome once.' : 'Declining keeps this order active. The buyer will see your reason.'}</p>
            <label className="block text-sm font-semibold text-slate-700">Reason for your decision<textarea required minLength={10} maxLength={1000} rows={3} disabled={form.processing} value={form.data.decision_reason} onChange={event => form.setData('decision_reason', event.target.value)} className="mt-2 w-full rounded-lg border border-slate-300 text-sm" /></label>
            <p className="text-xs text-slate-500">10–1,000 characters · visible to the buyer</p>
            {Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="text-sm text-red-700">{error}</p>)}
            <button type="submit" disabled={disabled || form.processing || form.data.decision_reason.trim().length < 10} className="rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{form.processing ? 'Saving decision…' : 'Save decision'}</button>
        </form>}
    </div>;
}
