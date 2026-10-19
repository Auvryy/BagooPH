import { FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { MapPin, PackageCheck } from 'lucide-react';
import useRequestError from '@/hooks/useCourierRequestError';
import WaybillCodes from '@/Components/WaybillCodes';

export interface ReturnCustody {
    barcode: string | null; status: string; position: number; weight_grams: number | null;
    custody: { kind: string }; current_hub_name: string | null; manifest_reference: string | null; manifest_url: string | null;
    hubs: Array<{ id: number; name: string; code: string; address: string; operating_hours: string | null }>;
    actions: Array<{ action: string; hub_id: number | null; label: string }>;
    can_dropoff?: boolean;
}
const input = 'mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm text-slate-900 focus:border-[#E00D42] focus:ring-[#E00D42]';

export default function ReturnCustodyPanel({ custody, caseId, version }: { custody: ReturnCustody; caseId: number; version: number }) {
    const form = useForm({ action: '', barcode: '', hub_id: null as number | null, expected_version: version, request_token: crypto.randomUUID(),
        reason: '', weight_kg: '', condition: '', photo: null as File | null });
    const pending = useRef(false);
    const [failure, setFailure] = useState('');
    useRequestError(pending, useCallback(message => setFailure(message), []));
    useEffect(() => { form.setData({ action: '', barcode: '', hub_id: null, expected_version: version, request_token: crypto.randomUUID(), reason: '', weight_kg: '', condition: '', photo: null }); form.clearErrors(); }, [caseId, version]);
    const selected = custody.actions.find(option => option.action === form.data.action);
    const needsPhoto = ['intake', 'seller-receive'].includes(form.data.action);
    const submit = (event: FormEvent) => {
        event.preventDefault(); if (pending.current || form.processing || !selected) return;
        if (selected.action !== 'prepare-label' && form.data.barcode.trim().toUpperCase() !== custody.barcode) {
            form.setError('barcode', 'Scan this case’s separate return barcode. The original delivery waybill cannot record this return.'); return;
        }
        if (needsPhoto && (!form.data.photo || form.data.photo.size > 5242880 || !['image/jpeg', 'image/png', 'image/webp'].includes(form.data.photo.type))) {
            form.setError('photo', 'Choose a real JPG, PNG or WebP photo up to 5 MB.'); return;
        }
        pending.current = true; setFailure('');
        form.transform(data => ({ action: selected.action, expected_version: version, request_token: data.request_token,
            reason: data.reason, ...(selected.action !== 'prepare-label' ? { barcode: data.barcode.trim().toUpperCase() } : {}),
            ...(selected.hub_id ? { hub_id: selected.hub_id } : {}), ...(selected.action === 'intake' ? { weight_kg: data.weight_kg } : {}),
            ...(needsPhoto ? { condition: data.condition, photo: data.photo } : {}) }));
        form.post(`/buyer-returns/${caseId}/custody`, { forceFormData: needsPhoto, preserveScroll: true, onFinish: () => { pending.current = false; } });
    };
    const buyerHolding = custody.custody.kind === 'buyer';
    return <section className="rounded-2xl border border-slate-300 bg-white p-5 sm:p-6" aria-label="Actual return custody">
        <div className="flex items-center gap-2"><PackageCheck className="h-5 w-5 text-[#E00D42]" aria-hidden="true" /><h2 className="text-lg font-bold text-slate-900">Return parcel</h2></div>
        {custody.barcode && <div className="mt-4 rounded-xl border border-slate-300 bg-slate-50 p-4"><p className="text-xs font-semibold text-slate-600">Separate return barcode</p><p className="mt-2 break-all text-base font-bold text-slate-900">{custody.barcode}</p><p className="mt-2 text-xs leading-5 text-slate-600">Show this reference with the returned items. The original delivery waybill cannot record this return.</p></div>}
        {custody.barcode && <div className="mt-4 max-w-sm"><WaybillCodes trackingNumber={custody.barcode} compact /></div>}
        <p className="mt-4 text-sm leading-6 text-slate-700">{buyerHolding ? (custody.can_dropoff ? 'Bring the approved items to the original destination Bayan Hub before your drop-off deadline.' : 'No current drop-off authority is available. Review the saved decision and deadlines before bringing a parcel.')
            : custody.custody.kind === 'seller' ? 'The seller recorded physical receipt. Repayment still needs its separate financial review.'
                : custody.custody.kind === 'manifest' ? `Travelling on ${custody.manifest_reference ?? 'the recorded transfer'}. The next hub must scan its actual receipt.`
                    : `Recorded at ${custody.current_hub_name ?? 'the assigned hub'}. Continue only the next actual handover.`}</p>
        {custody.weight_grams !== null && <p className="mt-2 text-xs text-slate-500">Accepted return weight: {(custody.weight_grams / 1000).toFixed(3)} kg</p>}
        {custody.hubs.length > 0 && <ol className="mt-5 ml-2 border-l border-slate-300 pl-5">{custody.hubs.map((hub, index) => <li key={`${index}-${hub.id}`} className="relative pb-4 last:pb-0"><span className={`absolute -left-[25px] top-1 h-2.5 w-2.5 rounded-full border-2 border-white ring-1 ring-slate-300 ${!buyerHolding && index <= custody.position ? 'bg-[#E00D42]' : 'bg-slate-300'}`} aria-hidden="true" /><p className="text-sm font-semibold text-slate-900">{hub.name}</p><p className="mt-1 text-xs text-slate-500">{hub.code}{!buyerHolding && index === custody.position && custody.custody.kind === 'hub' ? ' · Current holder' : ''}</p>{buyerHolding && index === 0 && <p className="mt-2 flex items-start gap-1.5 text-xs leading-5 text-slate-600"><MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />{hub.address}{hub.operating_hours ? ` · ${hub.operating_hours}` : ''}</p>}</li>)}</ol>}
        {custody.manifest_url && <Link href={custody.manifest_url} className="mt-4 inline-flex rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-[#C20836]">Open actual manifest</Link>}
        {custody.actions.length > 0 && <form onSubmit={submit} className="mt-6 space-y-4 border-t border-slate-300 pt-5" aria-busy={form.processing || undefined}>
            <label className="block text-sm font-semibold text-slate-700">Actual handover<select required disabled={form.processing} className={input} value={form.data.action} onChange={event => { const item = custody.actions.find(item => item.action === event.target.value); form.setData({ ...form.data, action: event.target.value, hub_id: item?.hub_id ?? null, condition: '', photo: null }); }}><option value="">Choose the recorded step</option>{custody.actions.map(option => <option key={`${option.action}-${option.hub_id}`} value={option.action}>{option.label}</option>)}</select></label>
            {selected && selected.action !== 'prepare-label' && <label className="block text-sm font-semibold text-slate-700">Scan the separate return barcode<input required maxLength={40} disabled={form.processing} autoComplete="off" className={input} value={form.data.barcode} onChange={event => form.setData('barcode', event.target.value)} /></label>}
            {selected?.action === 'intake' && <label className="block text-sm font-semibold text-slate-700">Measured weight in kg<input required type="number" min="0.001" max="50000" step="0.001" disabled={form.processing} className={input} value={form.data.weight_kg} onChange={event => form.setData('weight_kg', event.target.value)} /></label>}
            {needsPhoto && <><label className="block text-sm font-semibold text-slate-700">Condition<select required disabled={form.processing} className={input} value={form.data.condition} onChange={event => form.setData('condition', event.target.value)}><option value="">Choose actual condition</option>{selected?.action === 'intake' ? <><option value="intact">Package intact</option><option value="damaged">Package damaged</option></> : <><option value="accepted">Received and return condition accepted</option><option value="disputed">Received, condition needs review</option></>}</select></label><label className="block text-sm font-semibold text-slate-700">Photo of the actual handover<input required type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" disabled={form.processing} className={input} onChange={event => form.setData('photo', event.target.files?.[0] ?? null)} /><span className="mt-1 block text-xs font-normal text-slate-500">Real JPG, PNG or WebP, up to 5 MB. Kept with the private handover evidence.</span></label></>}
            <label className="block text-sm font-semibold text-slate-700">Handover details<textarea required minLength={10} maxLength={1000} disabled={form.processing} className={input} rows={3} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /><span className="mt-1 block text-xs font-normal text-slate-500">10–1,000 characters describing what was physically checked.</span></label>
            {failure && <p role="alert" className="text-sm text-rose-700">{failure}</p>}{Object.entries(form.errors).map(([key, message]) => <p key={key} role="alert" className="text-sm text-rose-700">{String(message)}</p>)}
            <button type="submit" disabled={form.processing || !selected} className="rounded-xl bg-[#E00D42] px-5 py-3 text-sm font-bold text-white disabled:opacity-50">{form.processing ? 'Saving handover…' : selected?.label ?? 'Save actual handover'}</button>
        </form>}
    </section>;
}
