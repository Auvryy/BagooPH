import { useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { Container } from 'lucide-react';
import { hubButton, hubInput } from './Workspace';

export interface ReturnSack {
    id: string; reference: string; physical_tag: string; seal_number: string | null; status: string; version: number; barcodes: string[];
    capacity: { count: number; weight_kg: number | null; volume_cm3: number | null; max_count: number; max_weight_kg: number; max_volume_cm3: number };
}
export interface ReturnTripParcel { barcode: string; status: string; baggable: boolean | null; weight_kg: number | null; manifest_id: number }
interface PackingTrip {
    id: number; status: string; version: number; canDepart: boolean; canReceive?: boolean;
    returnBags?: ReturnSack[]; returnParcels?: ReturnTripParcel[]; packingReason?: string | null;
}
const words = (value: string) => value.replaceAll('_', ' ');
const labels: Record<string, string> = {seal: 'Seal sack', reopen: 'Reopen before departure', remove: 'Unpack a return', open: 'Verify and open at destination'};
function Errors({ errors }: {errors: Record<string, string>}) {
    return Object.keys(errors).length > 0 ? <div role="alert" className="rounded-lg border border-rose-200 bg-white p-3 text-xs text-rose-700">{Object.values(errors).join(' ')}</div> : null;
}

function SackControls({ trip, bag, basePath }: {trip: PackingTrip; bag: ReturnSack; basePath: string}) {
    const actions = trip.status === 'ready' && trip.canDepart
        ? bag.status === 'open' ? ['seal', 'remove'] : bag.status === 'sealed' ? ['reopen'] : []
        : trip.status === 'dispatched' && trip.canReceive && bag.status === 'in_transit' ? ['open'] : [];
    const form = useForm({version: trip.version, bag_version: bag.version, request_token: crypto.randomUUID(), action: '', barcode: '', return_barcode: '', seal_number: '', seal_intact: false, notes: ''});
    const pending = useRef(false);
    useEffect(() => { form.setData({version: trip.version, bag_version: bag.version, request_token: crypto.randomUUID(), action: '', barcode: '', return_barcode: '', seal_number: '', seal_intact: false, notes: ''}); form.clearErrors(); }, [trip.version, bag.version]);
    if (!actions.length) return null;
    const action = form.data.action;
    return <form aria-label={`Manage sack ${bag.physical_tag}`} className="mt-4 space-y-3 border-t border-rose-100 pt-4" onSubmit={event => {
        event.preventDefault();
        if (pending.current || form.processing || !actions.includes(action)) return;
        pending.current = true;
        form.transform(data => ({version: data.version, bag_version: data.bag_version, request_token: data.request_token, barcode: data.barcode.trim().toUpperCase(),
            ...(['seal', 'open'].includes(action) ? {seal_number: data.seal_number.trim().toUpperCase()} : {}),
            ...(action === 'open' ? {seal_intact: data.seal_intact} : {}),
            ...(action === 'remove' ? {return_barcode: data.return_barcode.trim().toUpperCase()} : {}),
            ...(['remove', 'reopen'].includes(action) ? {notes: data.notes} : {})}));
        form.post(`${basePath}/trips/${trip.id}/return-sacks/${bag.id}/${action}`, {preserveScroll: true, onFinish: () => {pending.current = false;}});
    }}>
        <fieldset disabled={form.processing} className="space-y-3">
            <label className="block text-xs font-semibold text-slate-700">Sack action<select required className={`${hubInput} mt-1.5`} value={action} onChange={event => {form.setData('action', event.target.value); form.clearErrors();}}>
                <option value="">Choose the actual action</option>{actions.map(value => <option key={value} value={value}>{labels[value]}</option>)}
            </select></label>
            {action && <><label className="block text-xs font-semibold text-slate-700">Scan master bag tag<input required autoComplete="off" spellCheck={false} maxLength={80} className={`${hubInput} mt-1.5`} value={form.data.barcode} onChange={event => form.setData('barcode', event.target.value)} /></label>
                {['seal', 'open'].includes(action) && <label className="block text-xs font-semibold text-slate-700">Numbered seal<input required autoComplete="off" maxLength={80} className={`${hubInput} mt-1.5`} value={form.data.seal_number} onChange={event => form.setData('seal_number', event.target.value)} /></label>}
                {action === 'remove' && <label className="block text-xs font-semibold text-slate-700">Return barcode to unpack<input required autoComplete="off" maxLength={40} className={`${hubInput} mt-1.5`} value={form.data.return_barcode} onChange={event => form.setData('return_barcode', event.target.value)} /></label>}
                {['remove', 'reopen'].includes(action) && <label className="block text-xs font-semibold text-slate-700">Reason<textarea required minLength={10} maxLength={1000} rows={2} className={`${hubInput} mt-1.5`} value={form.data.notes} onChange={event => form.setData('notes', event.target.value)} /></label>}
                {action === 'open' && <label className="flex items-start gap-2 text-xs text-slate-700"><input type="checkbox" required checked={form.data.seal_intact} className="mt-0.5 rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" onChange={event => form.setData('seal_intact', event.target.checked)} />I checked this arriving sack and its intact numbered seal.</label>}
            </>}
            <button className={hubButton} disabled={form.processing || !actions.includes(action)}>{form.processing ? 'Saving…' : labels[action] ?? 'Save sack action'}</button>
        </fieldset>
        <Errors errors={form.errors} />
    </form>;
}

export default function ReturnTripPackingPanel({trip, basePath}: {trip: PackingTrip; basePath: string}) {
    const form = useForm({version: trip.version, request_token: crypto.randomUUID(), barcode: '', physical_tag: '', physical_checked: false});
    const pending = useRef(false);
    const bags = trip.returnBags ?? [];
    const parcels = trip.returnParcels ?? [];
    useEffect(() => { form.setData({version: trip.version, request_token: crypto.randomUUID(), barcode: '', physical_tag: '', physical_checked: false}); form.clearErrors(); }, [trip.id, trip.version]);
    return <section className="mt-5 space-y-4 border-t border-rose-200 pt-5" aria-label="Return sacks and loose cargo">
        <div className="flex items-center gap-2 text-sm font-semibold text-[#9F1239]"><Container className="h-4 w-4" /><h3>Return sacks and loose cargo</h3></div>
        <div className="divide-y divide-rose-100 rounded-lg border border-rose-200 bg-white px-3">{parcels.map(parcel => <div key={parcel.barcode} className="flex flex-wrap justify-between gap-2 py-2.5 text-xs">
            <span className="break-all font-semibold text-slate-700">{parcel.barcode}</span><span className="text-slate-600">{parcel.weight_kg ?? 'Unknown'} kg · {parcel.baggable === null ? 'Readings needed' : parcel.baggable ? 'Sack required' : 'Individual loose cargo'}</span>
        </div>)}</div>
        {trip.status === 'ready' && trip.canDepart && parcels.some(parcel => parcel.baggable) && <form aria-label="Pack return into sack" className="space-y-3 rounded-lg border border-rose-200 bg-white p-4" onSubmit={event => {
            event.preventDefault(); if (pending.current || form.processing) return; pending.current = true;
            form.transform(data => ({...data, version: trip.version, barcode: data.barcode.trim().toUpperCase(), physical_tag: data.physical_tag.trim().toUpperCase()}));
            form.post(`${basePath}/trips/${trip.id}/return-sacks`, {preserveScroll: true,
                onSuccess: () => {form.reset('barcode', 'physical_checked'); form.setData('request_token', crypto.randomUUID());},
                onFinish: () => {pending.current = false;}});
        }}>
            <fieldset disabled={form.processing} className="space-y-3"><legend className="text-sm font-semibold text-slate-800">Pack one actual return</legend>
                <div className="grid gap-3 sm:grid-cols-2"><label className="text-xs font-semibold text-slate-700">Return barcode<input required autoComplete="off" maxLength={40} className={`${hubInput} mt-1.5`} value={form.data.barcode} onChange={event => form.setData('barcode', event.target.value)} /></label>
                    <label className="text-xs font-semibold text-slate-700">Permanent sack tag<input required autoComplete="off" maxLength={80} className={`${hubInput} mt-1.5`} value={form.data.physical_tag} onChange={event => form.setData('physical_tag', event.target.value)} /></label></div>
                <label className="flex items-start gap-2 text-xs text-slate-700"><input type="checkbox" required checked={form.data.physical_checked} className="mt-0.5 rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" onChange={event => form.setData('physical_checked', event.target.checked)} />I checked this parcel and the physical sack tag.</label>
                <button className={hubButton} disabled={form.processing}>{form.processing ? 'Packing…' : 'Pack scanned return'}</button>
            </fieldset><Errors errors={form.errors} />
        </form>}
        {bags.map(bag => <article key={bag.id} className="rounded-lg border border-rose-200 bg-white p-4">
            <div className="flex flex-wrap items-center justify-between gap-2"><h4 className="text-sm font-semibold text-slate-800">Sack {bag.physical_tag}</h4><span className="rounded-lg bg-rose-50 px-2 py-1 text-xs font-medium capitalize text-[#9F1239]">{words(bag.status)}</span></div>
            <p className="mt-2 break-all text-xs font-medium text-slate-600">Master tag: {bag.reference}</p>
            {bag.seal_number && <p className="mt-1 text-xs text-slate-600">Seal: {bag.seal_number}</p>}
            <p className="mt-3 rounded-lg bg-rose-50/60 p-3 text-xs text-slate-700">{bag.capacity.count}/{bag.capacity.max_count} parcels · {bag.capacity.weight_kg ?? 'Unknown'}/{bag.capacity.max_weight_kg} kg · {bag.capacity.volume_cm3 === null ? 'Unknown' : (bag.capacity.volume_cm3 / 1000000).toFixed(3)}/0.300 m³</p>
            <ul className="mt-3 space-y-1 text-xs text-slate-600">{bag.barcodes.map(barcode => <li key={barcode} className="break-all">{barcode}</li>)}</ul>
            <SackControls trip={trip} bag={bag} basePath={basePath} />
        </article>)}
        {trip.packingReason && <p role="status" className="rounded-lg border border-rose-200 bg-white p-3 text-xs text-[#9F1239]">{trip.packingReason}</p>}
    </section>;
}
