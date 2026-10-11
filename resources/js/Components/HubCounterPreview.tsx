import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Boxes, Search } from 'lucide-react';
import { HubParcelNextStep, HubParcelState, ParcelGuidance } from '@/Components/HubParcelGuidance';

export interface CounterPreviewParcel {
    id: number;
    tracking_number: string;
    buyer_name: string;
    buyer_phone: string;
    order_number: string;
    destination_bin: string | null;
    total_amount: number;
    payment_method: string;
    guidance: ParcelGuidance;
}

export default function HubCounterPreview({ parcels, total, hubId, basePath }: {
    parcels: CounterPreviewParcel[];
    total: number;
    hubId: number;
    basePath: string;
}) {
    const [search, setSearch] = useState('');
    const query = search.trim().toLowerCase();
    const shown = parcels.filter(parcel => [parcel.tracking_number, parcel.order_number, parcel.buyer_name, parcel.buyer_phone]
        .some(value => value.toLowerCase().includes(query)));
    const counterUrl = (value: string) => `${basePath}/counter?hub_id=${hubId}&search=${encodeURIComponent(value)}`;

    return <div className="space-y-4 font-sans">
        <div className="rounded-xs border border-slate-300 bg-white p-4 shadow-xs sm:p-5">
            <h3 className="flex items-center gap-2 text-sm font-bold text-slate-900"><Boxes className="h-4 w-4" />Self-Pickup Counter Preview</h3>
            <p className="mt-1 text-xs text-slate-600">Showing the latest {parcels.length} of {total} counter parcels. Search here filters this preview; the full counter searches every matching parcel.</p>
            <p className="mt-1 text-xs text-slate-600">Use the counter to verify buyer photo ID, the private claim code and exact COD before handover.</p>
            <div className="mt-3 flex flex-col gap-2 sm:flex-row">
                <div className="relative flex-1"><Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input aria-label="Search counter preview" value={search} onChange={event => setSearch(event.target.value)} maxLength={120}
                        placeholder="Customer name, phone, order number or tracking code" className="w-full rounded-xs border border-slate-300 py-2 pl-9 pr-3 text-xs text-slate-900 focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42]" />
                </div>
                <Link href={counterUrl(search.trim())} className="rounded-xs border border-slate-300 px-3 py-2 text-center text-xs font-semibold text-[#E00D42] hover:bg-rose-50">Open full counter queue</Link>
            </div>
        </div>
        <div className="space-y-3">
            {shown.length === 0 ? <div className="rounded-xs border border-slate-300 bg-white p-6 text-center text-sm text-slate-600">{total === 0 ? 'No parcels waiting at this counter.' : 'No matching parcels in this preview. Search the full counter queue.'}</div>
                : shown.map(parcel => <div key={parcel.id} className="rounded-xs border border-slate-300 bg-white p-4 shadow-xs sm:p-5">
                    <div className="flex flex-wrap items-center gap-2 text-xs">
                        <span className="font-bold text-slate-900">{parcel.tracking_number}</span>
                        <span className="rounded-xs border border-slate-300 bg-slate-50 px-2 py-1 text-slate-700">{parcel.destination_bin?.trim() ? parcel.destination_bin : 'Shelf not recorded'}</span>
                    </div>
                    <p className="mt-2 text-xs text-slate-700">{parcel.buyer_name} · ₱{parcel.total_amount.toFixed(2)} ({parcel.payment_method.toUpperCase()})</p>
                    <div className="mt-3 space-y-3"><HubParcelState guidance={parcel.guidance} /><HubParcelNextStep guidance={parcel.guidance} hideLink /></div>
                    <Link href={counterUrl(parcel.tracking_number)} className="mt-3 inline-flex rounded-xs border border-slate-300 px-3 py-2 text-xs font-semibold text-[#E00D42] hover:bg-rose-50">Open counter record</Link>
                </div>)}
        </div>
    </div>;
}
