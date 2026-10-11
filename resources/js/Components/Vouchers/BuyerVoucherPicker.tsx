import { useEffect, useState } from 'react';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import axios from 'axios';
import { VoucherOffer } from '@/hooks/usePurchaseQuote';
import { PaginatedData } from '@/types';
import SavedVoucherButton from './SavedVoucherButton';

const money = (value: number | string) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value));

export function VoucherChoiceCards({ offers, chosen, onChoose, busy = false }: {
    offers: VoucherOffer[]; chosen: number | null; onChoose: (id: number | null) => void; busy?: boolean;
}) {
    return <div className="space-y-3">
        <label className="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border border-slate-300 p-4">
            <input type="radio" name="voucher-choice" checked={chosen === null} disabled={busy} onChange={() => onChoose(null)} className="text-[#E00D42] focus:ring-[#E00D42]" />
            <span className="text-sm font-semibold">No voucher</span>
        </label>
        {offers.map(offer => <div key={offer.revision_id} className={`rounded-xl border p-4 ${chosen === offer.revision_id ? 'border-[#E00D42] bg-rose-50' : 'border-slate-300 bg-white'}`}>
            <label className="flex cursor-pointer items-start gap-3">
                <input type="radio" name="voucher-choice" checked={chosen === offer.revision_id} disabled={busy || offer.eligible === false}
                    onChange={() => onChoose(offer.revision_id ?? null)} className="mt-1 text-[#E00D42] focus:ring-[#E00D42]" />
                <span className="min-w-0 space-y-1">
                    <span className="block text-xs text-slate-600">{offer.issuer === 'platform' ? 'Bagoo offer' : offer.shop_name}</span>
                    <span className="block text-sm font-bold text-slate-900">{offer.name || offer.code}</span>
                    <span className="block text-sm font-bold text-[#C20836]">{offer.discount_type === 'percent' ? `${offer.discount_value}% off` : offer.discount_type === 'free_shipping' ? 'Shipping discount' : `${money(offer.discount_value)} off`}
                        {offer.max_discount !== null ? ` · Up to ${money(offer.max_discount)}` : ''}</span>
                    <span className="block text-xs text-slate-600">Minimum spend {money(offer.min_spend)}</span>
                    {offer.expires_at && <span className="block text-xs text-slate-600">Expires {new Date(offer.expires_at).toLocaleString('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' })} · Philippine time</span>}
                    {offer.unavailable_reason ? <span className="block text-xs text-amber-800">{offer.unavailable_reason}</span>
                        : <span className="block text-xs font-bold text-[#C20836]">Save {money(offer.saving ?? 0)} on this checkout</span>}
                </span>
            </label>
            <div className="mt-3 flex items-center justify-between gap-3 border-t border-slate-200 pt-3"><span className="text-xs text-slate-600">{offer.code}</span><SavedVoucherButton offer={offer} /></div>
        </div>)}
    </div>;
}

export default function BuyerVoucherPicker({ title, shopId, currentId, context, onClose, onApply, onCode }: {
    title: string; shopId: number | null; currentId: number | null; context: Record<string, unknown>;
    onClose: () => void; onApply: (offer: VoucherOffer | null) => void; onCode: (code: string) => void;
}) {
    const [chosen, setChosen] = useState(currentId);
    const [chosenOffer, setChosenOffer] = useState<VoucherOffer | null>(null);
    const [page, setPage] = useState(1);
    const [offers, setOffers] = useState<PaginatedData<VoucherOffer> | null>(null);
    const [pending, setPending] = useState(true);
    const [error, setError] = useState('');
    const [code, setCode] = useState('');
    const contextKey = JSON.stringify(context);
    useEffect(() => { setChosenOffer(null); }, [contextKey]);
    const key = JSON.stringify({ ...context, shop_id: shopId, voucher_page: page });
    useEffect(() => {
        const controller = new AbortController();
        setPending(true); setError('');
        axios.post(route('buyer.vouchers.choices'), JSON.parse(key), { signal: controller.signal }).then(response => {
            if (!controller.signal.aborted) setOffers(response.data.offers);
        }).catch(failure => {
            if (!controller.signal.aborted) setError(axios.isAxiosError(failure) && failure.response?.status === 419 ? 'Reload to renew your session.' : 'Could not load vouchers. Close and try again.');
        }).finally(() => { if (!controller.signal.aborted) setPending(false); });
        return () => controller.abort();
    }, [key]);
    const selected = offers?.data.find(offer => offer.revision_id === chosen) ?? (chosenOffer?.revision_id === chosen ? chosenOffer : undefined);
    const canApply = !pending && !error && (chosen === null || selected?.eligible === true);
    return <Dialog open onClose={onClose} className="relative z-[60]">
        <div className="fixed inset-0 bg-slate-900/50" aria-hidden="true" />
        <div className="fixed inset-0 flex items-end justify-center sm:items-center sm:p-6">
            <DialogPanel className="flex max-h-[85dvh] w-full flex-col rounded-t-2xl bg-white shadow-xl sm:max-w-xl sm:rounded-2xl">
                <div className="flex items-center justify-between gap-3 border-b border-slate-300 p-5"><DialogTitle className="font-bold text-slate-900">{title}</DialogTitle>
                    <button type="button" onClick={onClose} className="min-h-11 rounded-xl border border-slate-300 px-3 text-xs font-bold">Close</button></div>
                <div className="min-h-0 overflow-y-auto p-5">
                    {pending && <p role="status" className="flex min-h-48 items-center justify-center text-sm text-slate-600">Checking offers for these items…</p>}
                    {error && <p role="alert" className="text-sm text-rose-700">{error}</p>}
                    {!pending && !error && <>
                        <VoucherChoiceCards offers={offers?.data ?? []} chosen={chosen} onChoose={id => { setChosen(id); setChosenOffer(offers?.data.find(offer => offer.revision_id === id) ?? null); }} />
                        {offers?.data.length === 0 && <p className="flex min-h-32 items-center justify-center p-4 text-center text-xs text-slate-600">No offers for this checkout. You can continue without a voucher.</p>}
                        {offers && offers.last_page > 1 && <nav aria-label="Voucher pages" className="mt-4 flex items-center justify-between gap-3 text-xs">
                            <button type="button" disabled={page === 1} onClick={() => setPage(page - 1)} className="min-h-11 rounded-xl border border-slate-300 px-3 disabled:opacity-50">Previous</button>
                            <span>{page} of {offers.last_page}</span><button type="button" disabled={page === offers.last_page} onClick={() => setPage(page + 1)} className="min-h-11 rounded-xl border border-slate-300 px-3 disabled:opacity-50">Next</button>
                        </nav>}
                    </>}
                    <details className="mt-5 border-t border-slate-300 pt-4"><summary className="cursor-pointer text-xs font-semibold text-slate-600">Have a code?</summary>
                        <div className="mt-3 flex gap-2"><input value={code} onChange={e => setCode(e.target.value)} aria-label="Optional voucher code" maxLength={50} className="min-w-0 flex-1 rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                            <button type="button" disabled={pending || !code.trim()} onClick={() => onCode(code.trim().toUpperCase())} className="min-h-11 rounded-xl border border-slate-300 px-3 text-xs font-bold disabled:opacity-50">Use code</button></div>
                    </details>
                </div>
                <div className="border-t border-slate-300 p-5"><button type="button" disabled={!canApply} onClick={() => onApply(chosen === null ? null : selected ?? null)} className="min-h-11 w-full rounded-xl bg-[#E00D42] px-4 py-3 text-sm font-bold text-white disabled:opacity-50">Apply</button></div>
            </DialogPanel>
        </div>
    </Dialog>;
}
