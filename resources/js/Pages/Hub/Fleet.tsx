import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Bike, Building2, CheckCircle2, Phone, Truck, UserRound } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { HubBadge, HubEmpty, HubPageHeader, HubSearch, hubButton } from '@/Components/Logistics/Workspace';

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
    return (
        <DashboardLayout title="Fleet & drivers">
            <Head title="Fleet & drivers — BagooPH" />
            <div className="space-y-6">
                <HubPageHeader
                    title="Fleet & drivers"
                    icon={Truck}
                    description={`Vehicles based at ${activeHub?.name ?? 'your assigned facility'}. Check the vehicle, driver and job before preparing a transfer.`}
                    actions={<Link href={route('hub.manifests.index')} className={hubButton}><Truck className="h-4 w-4" />Open transfers</Link>}
                />

                <div className="grid grid-cols-2 divide-x divide-y divide-zinc-200/80 overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs lg:grid-cols-4 lg:divide-y-0">
                    {[
                        { label: 'Vehicles at this hub', value: stats.total, Icon: Building2 },
                        { label: 'Eligible active vehicles', value: stats.active, Icon: CheckCircle2 },
                        { label: 'Feeder vans', value: stats.vans, Icon: Truck },
                        { label: 'Wing trucks', value: stats.trucks, Icon: Truck },
                    ].map(({ label, value, Icon }) => (
                        <div key={label} className="p-4 sm:p-5">
                            <div className="flex items-center justify-between gap-2">
                                <p className="text-xs font-semibold text-zinc-500">{label}</p>
                                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-zinc-200/80 bg-zinc-50 text-zinc-600">
                                    <Icon className="h-3.5 w-3.5" />
                                </span>
                            </div>
                            <p className="mt-2 font-mono text-2xl font-bold tabular-nums text-[#121316]">{value}</p>
                        </div>
                    ))}
                </div>

                <section className="space-y-4" aria-label="Facility vehicles">
                    <div className="flex flex-wrap items-end gap-3.5 rounded-xl border border-zinc-200/90 bg-white p-4 shadow-xs sm:p-5">
                        <div className="min-w-48 flex-1">
                            <HubSearch label="Search loaded vehicles" value={search} onChange={setSearch} placeholder="Plate, driver or model" />
                        </div>
                        <label className="min-w-40 text-xs font-semibold text-[#121316]">
                            Vehicle type
                            <select
                                value={filters.tier}
                                onChange={event => handleFilter(event.target.value, filters.status)}
                                className="mt-1.5 block w-full rounded-lg border border-zinc-200/90 bg-white px-3.5 py-2 text-xs font-medium text-[#121316] shadow-2xs transition-colors focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15"
                            >
                                <option value="all">All vehicle types</option>
                                <option value="motorcycle">Motorcycles</option>
                                <option value="tricycle">Tricycles</option>
                                <option value="l300_van">Feeder vans</option>
                                <option value="wing_truck">Wing trucks</option>
                            </select>
                        </label>
                        <label className="min-w-40 text-xs font-semibold text-[#121316]">
                            Vehicle activity
                            <select
                                value={filters.status}
                                onChange={event => handleFilter(filters.tier, event.target.value)}
                                className="mt-1.5 block w-full rounded-lg border border-zinc-200/90 bg-white px-3.5 py-2 text-xs font-medium text-[#121316] shadow-2xs transition-colors focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15"
                            >
                                <option value="all">All activity</option>
                                <option value="active">Active</option>
                                <option value="idle">Idle</option>
                                <option value="maintenance">Maintenance</option>
                            </select>
                        </label>
                    </div>

                    <p className="text-xs leading-5 text-zinc-500">
                        {shown.length} matching vehicles. Active status alone does not reserve a vehicle or confirm driver availability. The transfer form checks the actual route and driver.
                    </p>

                    {!shown.length ? (
                        <div className="rounded-xl border border-zinc-200/90 bg-white p-4 shadow-xs">
                            <HubEmpty icon={Truck} title="No vehicles in this view" description="Try another type or activity filter, or clear the search. Vehicle placement is managed in Hub network." />
                        </div>
                    ) : (
                        <div className="grid gap-4 lg:grid-cols-2">
                            {shown.map(vehicle => {
                                const Icon = ['motorcycle', 'tricycle'].includes(vehicle.vehicle_type) ? Bike : Truck;
                                return (
                                    <article
                                        key={vehicle.id}
                                        className="flex flex-col justify-between overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs transition-all duration-150 hover:border-zinc-300"
                                    >
                                        <div className="flex items-start justify-between gap-3 border-b border-zinc-100 bg-zinc-50/40 px-5 py-4">
                                            <div className="flex items-center gap-3">
                                                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-zinc-200/80 bg-white text-zinc-600 shadow-2xs">
                                                    <Icon className="h-4 w-4" />
                                                </span>
                                                <div>
                                                    <h2 className="font-mono text-sm font-bold text-[#121316]">{vehicle.plate_number}</h2>
                                                    <p className="mt-0.5 text-xs text-zinc-500">
                                                        {vehicleNames[vehicle.vehicle_type] ?? vehicle.vehicle_type.replaceAll('_', ' ')}
                                                        {vehicle.model ? ` · ${vehicle.model}` : ''}
                                                    </p>
                                                </div>
                                            </div>
                                            <HubBadge tone={vehicle.status === 'maintenance' ? 'warning' : 'neutral'}>
                                                {vehicle.status}
                                            </HubBadge>
                                        </div>
                                        <div className="space-y-4 p-5">
                                            <div className="flex flex-wrap justify-between gap-2 text-xs">
                                                <p className="font-semibold text-[#121316]">{vehicleWork[vehicle.vehicle_type] ?? 'Assigned hub work'}</p>
                                                <p className="font-mono font-medium text-zinc-500">{vehicle.capacity_kg.toLocaleString()} kg capacity</p>
                                            </div>
                                            <div className="flex items-start gap-3 rounded-lg border border-zinc-200/80 bg-zinc-50/50 p-3.5">
                                                <UserRound className="mt-0.5 h-4 w-4 shrink-0 text-zinc-400" />
                                                <div className="min-w-0">
                                                    <p className="text-[11px] font-medium text-zinc-500">Assigned driver</p>
                                                    <p className="mt-0.5 text-xs font-semibold text-[#121316]">{vehicle.driver_name}</p>
                                                    {vehicle.driver_phone !== 'N/A' && (
                                                        <p className="mt-1 flex items-center gap-1.5 font-mono text-xs text-zinc-500">
                                                            <Phone className="h-3 w-3 text-zinc-400" />
                                                            {vehicle.driver_phone}
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                            <p className="flex items-center gap-2 text-xs text-zinc-500">
                                                <Building2 className="h-3.5 w-3.5 text-zinc-400" />
                                                {vehicle.hub_name} · <span className="font-mono font-semibold text-zinc-600">{vehicle.hub_code}</span>
                                            </p>
                                        </div>
                                    </article>
                                );
                            })}
                        </div>
                    )}
                </section>
            </div>
        </DashboardLayout>
    );
}
