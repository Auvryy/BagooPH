import { useForm } from '@inertiajs/react';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { useRef, useState } from 'react';

export default function BuyerCancelOrder({ url, reasons, disabled = false, mode = 'direct', paymentMessage, description }: { url: string; reasons: string[]; disabled?: boolean; mode?: 'direct' | 'request'; paymentMessage?: string; description?: string }) {
    const [open, setOpen] = useState(false);
    const busy = useRef(false);
    const form = useForm({ reason: '', notes: '', request_token: crypto.randomUUID() });
    const requesting = mode === 'request';
    return <>
        <button type="button" disabled={disabled} onClick={() => setOpen(true)} className="rounded-xl border border-slate-300 bg-rose-50 px-4 py-2.5 font-semibold text-[#C20836] hover:bg-rose-100 disabled:opacity-50">{requesting ? 'Request cancellation' : 'Cancel order'}</button>
        <Dialog open={open} onClose={() => { if (!busy.current) setOpen(false); }} className="relative z-50">
            <div className="fixed inset-0 bg-slate-900/50" aria-hidden="true" />
            <div className="fixed inset-0 flex items-center justify-center overflow-y-auto p-4">
                <DialogPanel className="w-full max-w-md space-y-4 rounded-2xl bg-white p-6 shadow-xl">
                    <DialogTitle className="text-lg font-bold text-slate-900">{requesting ? 'Request cancellation?' : 'Cancel this order?'}</DialogTitle>
                    <p className="text-sm text-slate-600">{requesting ? 'Your order stays active while the seller reviews your request. They have 24 hours to respond; expiry leaves your order and payment unchanged.' : description || 'You can cancel before the order is ready for pickup. Your other orders will continue.'}</p>
                    {paymentMessage && <p className="rounded-lg bg-rose-50 p-3 text-sm text-[#9F1239]">{paymentMessage}</p>}
                    <form className="space-y-4" onSubmit={event => {
                        event.preventDefault(); if (busy.current) return; busy.current = true;
                        form.post(url, { preserveScroll: true, onSuccess: () => { setOpen(false); form.reset(); form.setData('request_token', crypto.randomUUID()); }, onFinish: () => { busy.current = false; } });
                    }}>
                        <label className="block text-sm font-semibold text-slate-700">Reason<select required disabled={form.processing} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} className="mt-2 w-full rounded-lg border-slate-300 text-sm"><option value="">Choose a reason</option>{reasons.map(reason => <option key={reason}>{reason}</option>)}</select></label>
                        {Object.entries(form.errors).filter(([key]) => !['reason', 'notes'].includes(key)).map(([key, error]) => <p key={key} role="alert" className="text-sm text-red-700">{error}</p>)}
                        {form.errors.reason && <p role="alert" className="text-sm text-red-700">{form.errors.reason}</p>}
                        <label className="block text-sm font-semibold text-slate-700">Details {form.data.reason === 'Other reason' ? '(required)' : '(optional)'}<textarea maxLength={500} required={form.data.reason === 'Other reason'} disabled={form.processing} value={form.data.notes} onChange={event => form.setData('notes', event.target.value)} rows={3} className="mt-2 w-full rounded-lg border-slate-300 text-sm" /></label>
                        <p className="text-xs text-slate-500">Up to 500 characters.</p>
                        {form.errors.notes && <p role="alert" className="text-sm text-red-700">{form.errors.notes}</p>}
                        <div className="flex justify-end gap-3"><button type="button" disabled={form.processing} onClick={() => setOpen(false)} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold">Keep order</button><button type="submit" disabled={form.processing || !form.data.reason} className="rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{form.processing ? 'Saving…' : requesting ? 'Send request' : 'Cancel order'}</button></div>
                    </form>
                </DialogPanel>
            </div>
        </Dialog>
    </>;
}
