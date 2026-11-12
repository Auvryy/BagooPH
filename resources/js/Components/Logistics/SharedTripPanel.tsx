import { Link, useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { Truck } from 'lucide-react';
import { hubButton } from './Workspace';

export interface TripManifest { id: number; reference: string; direction: string; status?: string; outstanding?: number }
export interface SharedTrip {
    id: number; reference: string; status: string; version: number;
    canManage: boolean; canDepart: boolean; canClose: boolean; manifests: TripManifest[];
}
const words = (value: string) => value.replaceAll('_', ' ');

export default function SharedTripPanel({ trip, candidates, selectedId, token, basePath }: {
    trip: SharedTrip | null; candidates: TripManifest[]; selectedId: number | null; token: string; basePath: string;
}) {
    const form = useForm({ manifest_ids: selectedId ? [selectedId] : [], request_token: token, version: trip?.version ?? 1 });
    useEffect(() => {
        form.setData({ manifest_ids: selectedId ? [selectedId] : [], request_token: token, version: trip?.version ?? 1 });
        form.clearErrors();
    }, [selectedId, token, trip?.version]);
    if (!trip && candidates.length < 2) return null;
    const errors = Object.values(form.errors);
    const command = (action: string) => {
        if (!form.processing && trip) form.post(`${basePath}/trips/${trip.id}/${action}`, { preserveScroll: true });
    };

    return <section aria-label="Shared vehicle trip" className="rounded-lg border border-rose-200 bg-rose-50/40 p-5">
        <div className="flex items-center gap-2 text-sm font-semibold text-[#9F1239]"><Truck className="h-4 w-4" /><h2>Shared vehicle trip</h2></div>
        {trip ? <>
            <p className="mt-2 text-sm font-medium capitalize">{trip.status} · {trip.manifests.length} manifests</p>
            <p className="mt-1 break-all text-xs text-slate-600">Trip {trip.reference}</p>
            <ul className="mt-3 divide-y divide-rose-100">{trip.manifests.map(member => <li key={member.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-xs">
                <Link className="font-semibold text-[#C20836] underline underline-offset-2" href={`${basePath}/${member.id}`}>{member.reference}</Link>
                <span className="capitalize">{words(member.direction)} · {words(member.status ?? '')} · {member.outstanding ?? 0} awaiting receipt</span>
            </li>)}</ul>
            <div className="mt-4 flex flex-wrap gap-2">
                {trip.status === 'ready' && trip.canDepart && <button type="button" className={hubButton} disabled={form.processing} onClick={() => command('dispatch')}>Record shared departure</button>}
                {trip.status === 'ready' && trip.canManage && <button type="button" className={hubButton} disabled={form.processing} onClick={() => command('cancel')}>Cancel grouping</button>}
                {trip.status === 'dispatched' && trip.canClose && <button type="button" className={hubButton} disabled={form.processing} onClick={() => command('close')}>Close received trip</button>}
            </div>
        </> : <form className="mt-3" onSubmit={event => { event.preventDefault(); if (!form.processing) form.post(`${basePath}/trips`, { preserveScroll: true }); }}>
            <fieldset disabled={form.processing}><legend className="text-xs text-slate-600">Sealed manifests for this vehicle and next hub</legend>
                <div className="mt-2 space-y-2">{candidates.map(member => <label key={member.id} className="flex cursor-pointer items-start gap-3 rounded-lg border border-rose-200 bg-white p-3 text-xs">
                    <input type="checkbox" className="mt-0.5 rounded border-slate-300 text-[#E00D42] focus:ring-rose-500" checked={form.data.manifest_ids.includes(member.id)} disabled={member.id === selectedId}
                        onChange={event => form.setData('manifest_ids', event.target.checked ? [...form.data.manifest_ids, member.id] : form.data.manifest_ids.filter(id => id !== member.id))} />
                    <span className="break-all font-medium">{member.reference}<span className="ml-2 capitalize text-slate-600">{words(member.direction)}</span></span>
                </label>)}</div>
                <button className={`${hubButton} mt-4`} disabled={form.processing || form.data.manifest_ids.length < 2}>Prepare shared trip</button>
            </fieldset>
        </form>}
        {errors.length > 0 && <div role="alert" className="mt-3 rounded-lg border border-rose-200 bg-white p-3 text-xs text-rose-700">{errors.join(' ')}</div>}
    </section>;
}
