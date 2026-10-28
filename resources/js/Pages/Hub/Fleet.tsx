import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Bike, Building2, CheckCircle2, Phone, Truck, UserRound } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { HubBadge, HubEmpty, HubPageHeader, HubSearch, hubButton, hubInput } from '@/Components/Logistics/Workspace';

interface FleetVehicle {
    id: number; plate_number: string; vehicle_type: string; model: string; capacity_kg: number;
    status: string; hub_name: string; hub_code: string; driver_name: string; driver_phone: string; driver_email: string;
}
interface Props {
    activeHub: { id: number; name: string; code: string; tier: string } | null;
    fleet: FleetVehicle[];
    stats: { total: number; active: number; motorcycles: number; vans: number; trucks: number };
    filters: { tier: string; status: string };
}
const vehicleNames: Record<string, string> = { motorcycle: 'Motorcycle', tricycle: 'Tricycle', l300_van: 'Feeder van', wing_truck: 'Wing truck' };
const vehicleWork: Record<string, string> = { motorcycle: 'Pickup and doorstep delivery', tricycle: 'Pickup and doorstep delivery', l300_van: 'Child hub ↔ Mother Hub', wing_truck: 'Mother Hub ↔ Mother Hub' };

export default function HubFleet({ activeHub, fleet, stats, filters }: Props) {
    const [search, setSearch] = useState('');
    const handleFilter = (tier: string, status: string) => router.get(route('hub.fleet'), { tier, status, ...(activeHub ? { hub_id: activeHub.id } : {}) }, { preserveState: true, preserveScroll: true });
    const shown = fleet.filter(vehicle => [vehicle.plate_number, vehicle.model, vehicle.driver_name, vehicle.hub_name].join(' ').toLowerCase().includes(search.trim().toLowerCase()));
    return <DashboardLayout title="Fleet & drivers"><Head title="Fleet & drivers — BagooPH" />
        <div className="space-y-6">
            <HubPageHeader title="Fleet & drivers" icon={Truck} description={`Vehicles based at ${activeHub?.name ?? 'your assigned facility'}. Check the vehicle, driver and job before preparing a transfer.`} actions={<Link href={route('hub.manifests.index')} className={hubButton}><Truck className="h-4 w-4" />Open transfers</Link>} />
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{[
                { label: 'Vehicles at this hub', value: stats.total, Icon: Building2 },
                { label: 'Eligible active vehicles', value: stats.active, Icon: CheckCircle2 },
                { label: 'Feeder vans', value: stats.vans, Icon: Truck },
                { label: 'Wing trucks', value: stats.trucks, Icon: Truck },
            ].map(({ label, value, Icon }) => <div key={label} className="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><span className="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-50 text-slate-500"><Icon className="h-4 w-4" /></span><div><p className="text-xs text-slate-500">{label}</p><p className="mt-1 text-xl font-bold text-slate-900">{value}</p></div></div>)}</div>
            <section className="space-y-4" aria-label="Facility vehicles">
                <div className="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><div className="min-w-48 flex-1"><HubSearch label="Search loaded vehicles" value={search} onChange={setSearch} placeholder="Plate, driver or model" /></div>
                    <label className="min-w-36 text-xs font-semibold text-slate-600">Vehicle type<select value={filters.tier} onChange={event => handleFilter(event.target.value, filters.status)} className={`${hubInput} mt-1`}><option value="all">All vehicle types</option><option value="motorcycle">Motorcycles</option><option value="tricycle">Tricycles</option><option value="l300_van">Feeder vans</option><option value="wing_truck">Wing trucks</option></select></label>
                    <label className="min-w-36 text-xs font-semibold text-slate-600">Vehicle activity<select value={filters.status} onChange={event => handleFilter(filters.tier, event.target.value)} className={`${hubInput} mt-1`}><option value="all">All activity</option><option value="active">Active</option><option value="idle">Idle</option><option value="maintenance">Maintenance</option></select></label>
                </div>
                <p className="text-xs leading-5 text-slate-500">{shown.length} matching vehicles. Active status alone does not reserve a vehicle or confirm driver availability. The transfer form checks the actual route and driver.</p>
                {!shown.length ? <div className="rounded-xl border border-slate-200 bg-white"><HubEmpty icon={Truck} title="No vehicles in this view" description="Try another type or activity filter, or clear the search. Vehicle placement is managed in Hub network." /></div> : <div className="grid gap-4 lg:grid-cols-2">{shown.map(vehicle => { const Icon = ['motorcycle', 'tricycle'].includes(vehicle.vehicle_type) ? Bike : Truck;return <article key={vehicle.id} className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"><div className="flex items-start justify-between gap-3 border-b border-slate-100 p-5"><div className="flex items-center gap-3"><span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-slate-600"><Icon className="h-6 w-6" /></span><div><h2 className="text-base font-bold text-slate-900">{vehicle.plate_number}</h2><p className="mt-1 text-xs text-slate-500">{vehicleNames[vehicle.vehicle_type] ?? vehicle.vehicle_type.replaceAll('_', ' ')}{vehicle.model ? ` · ${vehicle.model}` : ''}</p></div></div><HubBadge tone={vehicle.status === 'maintenance' ? 'warning' : 'neutral'}>{vehicle.status}</HubBadge></div>
                    <div className="space-y-4 p-5"><div className="flex flex-wrap justify-between gap-2 text-sm"><p className="font-medium text-slate-700">{vehicleWork[vehicle.vehicle_type] ?? 'Assigned hub work'}</p><p className="text-slate-500">{vehicle.capacity_kg.toLocaleString()} kg capacity</p></div><div className="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50/60 p-3.5"><UserRound className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" /><div className="min-w-0"><p className="text-xs text-slate-500">Assigned driver</p><p className="mt-1 text-sm font-semibold text-slate-800">{vehicle.driver_name}</p>{vehicle.driver_phone !== 'N/A' && <p className="mt-1 flex items-center gap-1.5 text-xs text-slate-500"><Phone className="h-3 w-3" />{vehicle.driver_phone}</p>}</div></div><p className="flex items-center gap-2 text-xs text-slate-500"><Building2 className="h-3.5 w-3.5" />{vehicle.hub_name} · {vehicle.hub_code}</p></div>
                </article>; })}</div>}
            </section>
        </div>
    </DashboardLayout>;
}
