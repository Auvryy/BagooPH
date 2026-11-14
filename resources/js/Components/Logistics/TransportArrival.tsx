import { FormEvent, useCallback, useRef, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Truck } from 'lucide-react';
import useRequestError from '@/hooks/useCourierRequestError';
import { PageProps } from '@/types';

export interface TransportArrivalState { can_record: boolean; record: { reference: string; created_at: string } | null }
interface Props { value: TransportArrivalState; manifestId: number; version: number; driver: string; plate: string; basePath: string }

function ArrivalForm({ manifestId, version, driver, plate, basePath }: Props) {
    const form = useForm({ version, request_token: crypto.randomUUID(), vehicle_plate: '', reason: '', arrival_confirmed: false, driver_identity_confirmed: false });
    const pending = useRef(false);
    const [failure, setFailure] = useState('');
    useRequestError(pending, useCallback(message => setFailure(message), []));
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (pending.current || form.processing) return;
        if (form.data.vehicle_plate.trim().toUpperCase() !== plate.toUpperCase() || !form.data.arrival_confirmed || !form.data.driver_identity_confirmed || form.data.reason.trim().length < 10 || form.data.reason.trim().length > 1000) {
            setFailure('Check the original vehicle plate, driver and actual arrival, then give 10–1,000 characters of receiving details.'); return;
        }
        pending.current = true; setFailure('');
        form.transform(data => ({ ...data, vehicle_plate: data.vehicle_plate.trim().toUpperCase(), reason: data.reason.trim() }));
        form.post(`${basePath}/${manifestId}/transport-arrival`, { preserveScroll: true, onFinish: () => { pending.current = false; } });
    };
    const field = 'mt-2 w-full rounded-lg border border-slate-300 bg-white text-sm text-slate-900 focus:border-[#E00D42] focus:ring-[#E00D42]';
    return <form onSubmit={submit} className="space-y-4" aria-busy={form.processing || undefined}>
        <p className="text-sm leading-6 text-slate-600">Record the original vehicle arriving here. Missing parcels and unopened sacks keep their separate holds.</p>
        <label className="block text-sm font-semibold">Plate observed on the vehicle<input required maxLength={40} autoComplete="off" disabled={form.processing} value={form.data.vehicle_plate} onChange={event => form.setData('vehicle_plate', event.target.value)} className={field} /></label>
        <label className="flex items-start gap-3 text-sm leading-6"><input required type="checkbox" disabled={form.processing} checked={form.data.arrival_confirmed} onChange={event => form.setData('arrival_confirmed', event.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42]" />I observed this original vehicle physically arrive at this destination hub.</label>
        <label className="flex items-start gap-3 text-sm leading-6"><input required type="checkbox" disabled={form.processing} checked={form.data.driver_identity_confirmed} onChange={event => form.setData('driver_identity_confirmed', event.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42]" />I checked that its distinct assigned driver is {driver}.</label>
        <label className="block text-sm font-semibold">Receiving details<textarea required minLength={10} maxLength={1000} rows={3} autoComplete="off" disabled={form.processing} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} className={field} /></label>
        {failure && <p role="alert" className="text-sm text-rose-700">{failure}</p>}{Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="text-sm text-rose-700">{error}</p>)}
        <button disabled={form.processing} className="rounded-lg bg-[#E00D42] px-4 py-3 text-sm font-bold text-white hover:bg-[#C20836] disabled:opacity-50">{form.processing ? 'Recording arrival…' : 'Record vehicle arrival'}</button>
    </form>;
}

export default function TransportArrival(props: Props) {
    const { auth } = usePage<PageProps>().props;
    if (!props.value.record && !props.value.can_record) return null;
    return <section className="space-y-4 rounded-lg border border-rose-200 bg-white p-5 shadow-sm"><h3 className="flex items-center gap-2 font-bold text-slate-900"><Truck className="h-5 w-5 text-[#E00D42]" />Original vehicle arrival</h3>
        {props.value.record ? <p role="status" className="text-sm leading-6 text-slate-700">Arrival recorded · {props.value.record.reference}. Parcel and sack receipts remain separate.</p>
            : <ArrivalForm key={`${auth.user?.id}:${props.manifestId}:${props.version}`} {...props} />}
    </section>;
}
