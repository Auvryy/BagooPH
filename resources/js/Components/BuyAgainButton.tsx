import { useForm } from '@inertiajs/react';
import { useRef } from 'react';

export default function BuyAgainButton({ url, token, disabled = false }: { url: string; token: string; disabled?: boolean }) {
    const busy = useRef(false);
    const form = useForm({ mode: 'purchase', request_token: token });
    const error = Object.values(form.errors)[0];
    return <div className="space-y-2">
        <button type="button" disabled={disabled || form.processing} aria-busy={form.processing || undefined} onClick={() => {
            if (busy.current || disabled) return;
            busy.current = true;
            form.transform(() => ({ mode: 'purchase', request_token: token }));
            form.post(url, {
                preserveScroll: false,
                onFinish: () => { busy.current = false; },
            });
        }} className="rounded-xl border border-slate-300 bg-white px-4 py-2.5 font-bold text-[#E00D42] hover:bg-rose-50 disabled:opacity-50">{form.processing ? 'Adding to Bag…' : 'Buy again'}</button>
        {error && <p role="alert" className="max-w-sm text-sm font-medium text-rose-700">{error}</p>}
    </div>;
}
