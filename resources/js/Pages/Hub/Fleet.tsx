import { useState, type ReactNode } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Bike, Phone, Truck, type LucideIcon } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { HubEmpty, HubPageHeader, HubSearch, hubButton } from '@/Components/Logistics/Workspace';

interface FleetVehicle {
    transport_driver_id: number | null; assigned_driver_id: number | null; driver_on_duty: boolean;
    id: number; hub_id: number; edit_token: string; plate_number: string; vehicle_type: string; model: string; capacity_kg: number;
    status: string; hub_name: string; hub_code: string; driver_name: string; driver_phone: string; driver_email: string;
}
interface Props {
    activeHub: { id: number; name: string; code: string; tier: string } | null;
    canRegisterVehicle: boolean;
    vehicleDefaults: Record<string, number>;
    transportDrivers: { id: number; name: string; hub_id: number }[];
    drivers: { id: number; name: string; hub_id: number; on_duty: boolean; plate: string | null }[];
    assignmentHubs: { id: number; name: string; code: string; tier: string }[];
    fleet: FleetVehicle[];
    stats: { total: number; active: number; motorcycles: number; vans: number; trucks: number };
    filters: { tier: string; status: string };
}

/** Categorical colors so each vehicle type is recognisable at a glance. */
const TYPES: { key: string; name: string; plural: string; work: string; Icon: LucideIcon; tile: string; bar: string }[] = [
    { key: 'motorcycle', name: 'Motorcycle', plural: 'Motorcycles', work: 'Pickup and doorstep delivery', Icon: Bike, tile: 'bg-info-soft text-info', bar: 'bg-info' },
    { key: 'tricycle', name: 'Tricycle', plural: 'Tricycles', work: 'Pickup and doorstep delivery', Icon: Bike, tile: 'bg-success-soft text-success', bar: 'bg-success' },
    { key: 'l300_van', name: 'Feeder van', plural: 'Feeder vans', work: 'Child hub to Mother Hub', Icon: Truck, tile: 'bg-warning-soft text-warning', bar: 'bg-warning' },
    { key: 'wing_truck', name: 'Wing truck', plural: 'Wing trucks', work: 'Mother Hub to Mother Hub', Icon: Truck, tile: 'bg-brand-50 text-brand-600', bar: 'bg-brand-500' },
];
const typeOf = (key: string) => TYPES.find(type => type.key === key) ?? { key, name: key.replaceAll('_', ' '), plural: key.replaceAll('_', ' '), work: 'Assigned hub work', Icon: Truck, tile: 'bg-surface-100 text-ink-600', bar: 'bg-ink-400' };

const STATUSES = [
    { key: 'active', label: 'Active', chip: 'bg-success-soft text-success', bar: 'bg-success' },
    { key: 'idle', label: 'Idle', chip: 'bg-surface-100 text-ink-700', bar: 'bg-line-300' },
    { key: 'maintenance', label: 'Maintenance', chip: 'bg-warning-soft text-warning', bar: 'bg-warning' },
];
const statusOf = (key: string) => STATUSES.find(status => status.key === key) ?? { key, label: key, chip: 'bg-surface-100 text-ink-700', bar: 'bg-line-300' };

const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]?.toUpperCase()).join('') || '?';

function Chip({ selected, onClick, children }: { selected: boolean; onClick: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            aria-pressed={selected}
            onClick={onClick}
            className={`inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3 text-[13px] font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 ${selected ? 'border-brand-500 bg-brand-50 text-brand-600' : 'border-line-300 bg-white text-ink-700 hover:border-ink-400'}`}
        >
            {children}
        </button>
    );
}

export default function HubFleet({ activeHub, fleet, stats, filters, canRegisterVehicle, vehicleDefaults, assignmentHubs, drivers, transportDrivers }: Props) {
    const [adding, setAdding] = useState(false);
    const [saved, setSaved] = useState('');
    const [editing, setEditing] = useState<number | null>(null);
    const defaultType = activeHub?.tier === 'regional_mother_hub' ? 'wing_truck' : 'l300_van';
    const form = useForm({ hub_id: activeHub?.id, target_hub_id: activeHub?.id ?? 0, expected_state: '', driver_id: '', transport_driver_id: '', transport_driver_name: '', plate_number: '', vehicle_type: defaultType, model: '', capacity_kg: String(vehicleDefaults[defaultType]) });
    const fieldClass = 'mt-1 block w-full rounded-lg border-line-300 text-sm focus:border-brand-500 focus:ring-brand-500';
    const [search, setSearch] = useState('');
    const handleFilter = (tier: string, status: string) => router.get(route('hub.fleet'), { tier, status, ...(activeHub ? { hub_id: activeHub.id } : {}) }, { preserveState: true, preserveScroll: true });
    const shown = fleet.filter(vehicle => [vehicle.plate_number, vehicle.model, vehicle.driver_name, vehicle.hub_name].join(' ').toLowerCase().includes(search.trim().toLowerCase()));

    const totalCapacity = fleet.reduce((sum, vehicle) => sum + (vehicle.capacity_kg || 0), 0);
    const maxCapacity = Math.max(1, ...fleet.map(vehicle => vehicle.capacity_kg || 0));
    const statusCounts = STATUSES.map(status => ({ ...status, count: fleet.filter(vehicle => vehicle.status === status.key).length }));
    const mix = TYPES.map(type => {
        const vehicles = fleet.filter(vehicle => vehicle.vehicle_type === type.key);
        return { ...type, count: vehicles.length, capacity: vehicles.reduce((sum, vehicle) => sum + (vehicle.capacity_kg || 0), 0) };
    }).filter(type => type.count > 0);
    const fleetSize = Math.max(1, fleet.length);

    return (
        <DashboardLayout title="Fleet & drivers">
            <Head title="Fleet & drivers — BagooPH" />
            <div className="space-y-6">
                <HubPageHeader
                    title="Fleet & drivers"
                    icon={Truck}
                    roleLabel={activeHub ? `${activeHub.name} · ${activeHub.code}` : 'All facilities'}
                    actions={<div className="flex flex-wrap gap-2"><Link href={route('hub.manifests.index')} className={hubButton}><Truck className="h-4 w-4 text-ink-500" aria-hidden="true" />Open transfers</Link>{canRegisterVehicle && <button type="button" className="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600" aria-expanded={adding} aria-controls="add-vehicle" onClick={() => { setEditing(null); form.reset(); form.clearErrors(); setAdding(true); setSaved(''); }}>Add vehicle</button>}</div>}
                />

                {saved && <p role="status" className="rounded-lg bg-success-soft p-3 text-sm text-success">{saved}</p>}
                {canRegisterVehicle && adding && <form id="add-vehicle" className="space-y-4 rounded-card border border-line-200 bg-white p-5 sm:p-6" onSubmit={event => {
                    event.preventDefault();
                    form.transform(data => ({ ...data, hub_id: activeHub?.id }));
                    const options = {
                        preserveScroll: true,
                        onSuccess: () => { form.reset(); setAdding(false); setSaved(editing ? 'Vehicle updated. A moved driver is off duty at the new hub.' : 'Vehicle added. Active trucks and vans with a named transport driver are ready for dispatch.'); setEditing(null); setSearch(''); },
                    };
                    if (editing) form.put(route('hub.fleet.update', editing), options);
                    else form.post(route('hub.fleet.store'), options);
                }}>
                    <h2 className="text-lg font-semibold text-ink-900">{editing ? 'Edit vehicle' : 'Add vehicle'}</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <label className="text-sm font-medium text-ink-700 sm:col-span-2">Assigned hub
                            <select required className={fieldClass} value={form.data.target_hub_id} onChange={event => {
                                const targetId = Number(event.target.value);
                                const type = assignmentHubs.find(hub => hub.id === targetId)?.tier === 'regional_mother_hub' ? 'wing_truck' : 'l300_van';
                                form.setData({ ...form.data, target_hub_id: targetId, driver_id: editing ? form.data.driver_id : '',
                                    ...(!editing ? { vehicle_type: type, capacity_kg: String(vehicleDefaults[type]) } : {}) });
                            }}>
                                {assignmentHubs.map(hub => <option key={hub.id} value={hub.id}>{hub.name} · {hub.code}</option>)}
                            </select>
                            <span className="mt-1 block text-xs font-normal text-ink-600">This is the sending hub. Choose the receiving hub in the manifest or Mother Bag.</span>
                            {form.errors.target_hub_id && <span role="alert" className="text-brand-700">{form.errors.target_hub_id}</span>}
                        </label>
                        <label className="text-sm font-medium text-ink-700">Vehicle type
                            <select className={fieldClass} value={form.data.vehicle_type} onChange={event => {
                                form.setData({ ...form.data, vehicle_type: event.target.value, capacity_kg: String(vehicleDefaults[event.target.value]) });
                            }}>{TYPES.map(type => <option key={type.key} value={type.key}>{type.name}</option>)}</select>
                            {form.errors.vehicle_type && <span role="alert" className="text-brand-700">{form.errors.vehicle_type}</span>}
                        </label>
                        <label className="text-sm font-medium text-ink-700">Plate number
                            <input className={fieldClass} required maxLength={50} value={form.data.plate_number} onChange={event => form.setData('plate_number', event.target.value.toUpperCase())} />
                            {form.errors.plate_number && <span role="alert" className="text-brand-700">{form.errors.plate_number}</span>}
                        </label>
                        <label className="text-sm font-medium text-ink-700">Model (optional)
                            <input className={fieldClass} maxLength={255} value={form.data.model} onChange={event => form.setData('model', event.target.value)} />
                            {form.errors.model && <span role="alert" className="text-brand-700">{form.errors.model}</span>}
                        </label>
                        <label className="text-sm font-medium text-ink-700">Capacity (kg)
                            <input className={fieldClass} type="number" required min="0.01" max="999999.99" step="0.01" value={form.data.capacity_kg} onChange={event => form.setData('capacity_kg', event.target.value)} />
                            {form.errors.capacity_kg && <span role="alert" className="text-brand-700">{form.errors.capacity_kg}</span>}
                        </label>
                    </div>
                    {Object.entries(form.errors).filter(([key]) => !['plate_number', 'model', 'vehicle_type', 'capacity_kg', 'target_hub_id'].includes(key)).map(([key, message]) => <p key={key} role="alert" className="text-sm text-brand-700">{message}</p>)}
                    {['l300_van', 'wing_truck'].includes(form.data.vehicle_type) ? <div className="space-y-3">
                        <label className="block text-sm font-medium text-ink-700">Transport driver
                            <select className={fieldClass} value={form.data.transport_driver_id} onChange={event => form.setData({ ...form.data, transport_driver_id: event.target.value, transport_driver_name: '' })}>
                                <option value="">Add a driver name</option>
                                {transportDrivers.filter(driver => driver.hub_id === form.data.target_hub_id).map(driver => <option key={driver.id} value={driver.id}>{driver.name}</option>)}
                            </select>
                        </label>
                        {!form.data.transport_driver_id && <label className="block text-sm font-medium text-ink-700">Driver name
                            <input className={fieldClass} maxLength={100} required={!form.data.driver_id} value={form.data.transport_driver_name} onChange={event => form.setData('transport_driver_name', event.target.value)} placeholder="Full name of the truck or van driver" />
                            {editing && form.data.driver_id && <span className="mt-1 block text-xs text-ink-600">Leave blank to keep the existing driver, or enter the new transport driver's name.</span>}
                        </label>}
                        <p className="text-xs text-ink-600">A company driver record only. No email, Bagoo account or rider invitation is needed.</p>
                    </div> : <>                    <label className="block text-sm font-medium text-ink-700">Assigned driver
                        <select className={fieldClass} value={form.data.driver_id} onChange={event => form.setData('driver_id', event.target.value)}>
                            <option value="">Assign a driver later</option>
                            {drivers.filter(driver => driver.hub_id === form.data.target_hub_id || (editing && String(driver.id) === form.data.driver_id)).map(driver => <option key={driver.id} value={driver.id}>{driver.name} · {driver.on_duty ? 'On duty' : 'Off duty'}{driver.plate ? ` · ${driver.plate}` : ''}</option>)}
                        </select>
                        <span className="mt-1 block text-xs font-normal text-ink-600">Choose a rider already working at this hub. The driver must go on duty in the rider portal before dispatch.</span>
                    </label>
</>}
                    <div className="flex gap-2">
                        <button type="submit" disabled={form.processing} className="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">{form.processing ? 'Saving…' : 'Save vehicle'}</button>
                        <button type="button" disabled={form.processing} className={hubButton} onClick={() => setAdding(false)}>Cancel</button>
                    </div>
                </form>}

                {/* Visual summary */}
                <section aria-label="Fleet summary" className="grid gap-6 rounded-card border border-line-200 bg-white p-5 sm:p-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
                    <div>
                        <p className="text-[13px] text-ink-600">Vehicles</p>
                        <p className="mt-1 flex items-baseline gap-2">
                            <span className="text-[40px] font-bold leading-none tabular-nums text-ink-900">{stats.total}</span>
                            <span className="text-sm text-ink-600"><span className="font-semibold text-success">{stats.active}</span> ready to work</span>
                        </p>
                        <div className="mt-5 flex h-2.5 w-full overflow-hidden rounded-full bg-surface-100" role="img" aria-label={statusCounts.map(status => `${status.count} ${status.label.toLowerCase()}`).join(', ')}>
                            {statusCounts.filter(status => status.count > 0).map(status => (
                                <span key={status.key} className={`h-full ${status.bar}`} style={{ width: `${(status.count / fleetSize) * 100}%` }} />
                            ))}
                        </div>
                        <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[13px] text-ink-700">
                            {statusCounts.map(status => (
                                <li key={status.key} className="flex items-center gap-1.5">
                                    <span aria-hidden="true" className={`h-2 w-2 rounded-full ${status.bar}`} />
                                    {status.label} <span className="tabular-nums text-ink-500">{status.count}</span>
                                </li>
                            ))}
                        </ul>
                        <p className="mt-4 text-[13px] text-ink-600">Total capacity <span className="font-semibold tabular-nums text-ink-900">{totalCapacity.toLocaleString()} kg</span></p>
                    </div>

                    <div>
                        <p className="text-[13px] text-ink-600">Fleet mix</p>
                        {mix.length === 0 ? (
                            <p className="mt-3 text-sm text-ink-600">No vehicles placed yet.</p>
                        ) : (
                            <ul className="mt-3 space-y-3">
                                {mix.map(type => (
                                    <li key={type.key} className="flex items-center gap-3">
                                        <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${type.tile}`}>
                                            <type.Icon className="h-4 w-4" aria-hidden="true" />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-baseline justify-between gap-2 text-sm">
                                                <span className="font-medium text-ink-900">{type.plural} <span className="font-normal tabular-nums text-ink-500">{type.count}</span></span>
                                                <span className="tabular-nums text-ink-600">{type.capacity.toLocaleString()} kg</span>
                                            </div>
                                            <span className="mt-1.5 block h-1.5 overflow-hidden rounded-full bg-surface-100">
                                                <span className={`block h-full rounded-full ${type.bar}`} style={{ width: `${Math.max(4, (type.capacity / Math.max(1, totalCapacity)) * 100)}%` }} />
                                            </span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </section>

                <section className="space-y-4" aria-label="Facility vehicles">
                    <div className="space-y-3">
                        <div className="max-w-md">
                            <HubSearch label="Search loaded vehicles" value={search} onChange={setSearch} placeholder="Search plate, driver or model" />
                        </div>
                        <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Vehicle type">
                            <Chip selected={filters.tier === 'all'} onClick={() => handleFilter('all', filters.status)}>All types</Chip>
                            {TYPES.map(type => (
                                <Chip key={type.key} selected={filters.tier === type.key} onClick={() => handleFilter(type.key, filters.status)}>
                                    <type.Icon className="h-3.5 w-3.5" aria-hidden="true" />{type.plural}
                                </Chip>
                            ))}
                        </div>
                        <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Vehicle activity">
                            <Chip selected={filters.status === 'all'} onClick={() => handleFilter(filters.tier, 'all')}>Any status</Chip>
                            {STATUSES.map(status => (
                                <Chip key={status.key} selected={filters.status === status.key} onClick={() => handleFilter(filters.tier, status.key)}>
                                    <span aria-hidden="true" className={`h-2 w-2 rounded-full ${status.bar}`} />{status.label}
                                </Chip>
                            ))}
                        </div>
                    </div>

                    {!shown.length ? (
                        <div className="rounded-card border border-line-200 bg-white p-4">
                            <HubEmpty icon={Truck} title="No vehicles match" description="Try another filter or clear the search." />
                        </div>
                    ) : (
                        <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            {shown.map(vehicle => {
                                const type = typeOf(vehicle.vehicle_type);
                                const status = statusOf(vehicle.status);
                                const share = Math.round(((vehicle.capacity_kg || 0) / maxCapacity) * 100);
                                const hasPhone = vehicle.driver_phone && vehicle.driver_phone !== 'N/A';
                                return (
                                    <li key={vehicle.id} className="rounded-card border border-line-200 bg-white p-4">
                                        {canRegisterVehicle && <button type="button" aria-label={`Edit ${vehicle.plate_number}`} className="mb-3 rounded-lg border border-line-300 px-3 py-1.5 text-sm font-medium text-ink-700 hover:bg-surface-100" onClick={() => {
                                            form.clearErrors(); form.setData({ hub_id: activeHub?.id, target_hub_id: vehicle.hub_id, expected_state: vehicle.edit_token, transport_driver_id: vehicle.transport_driver_id ? String(vehicle.transport_driver_id) : '', transport_driver_name: '', driver_id: vehicle.assigned_driver_id ? String(vehicle.assigned_driver_id) : '',
                                                plate_number: vehicle.plate_number, vehicle_type: vehicle.vehicle_type, model: vehicle.model ?? '', capacity_kg: String(vehicle.capacity_kg) });
                                            setEditing(vehicle.id); setAdding(true); setSaved('');
                                            setTimeout(() => document.getElementById('add-vehicle')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
                                        }}>Edit vehicle</button>}

                                        <div className="flex items-start justify-between gap-3">
                                            <div className="flex min-w-0 items-center gap-3">
                                                <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${type.tile}`}>
                                                    <type.Icon className="h-5 w-5" aria-hidden="true" />
                                                </span>
                                                <div className="min-w-0">
                                                    <h2 className="truncate text-sm font-semibold tabular-nums text-ink-900">{vehicle.plate_number}</h2>
                                                    <p className="truncate text-[13px] text-ink-600">{type.name}{vehicle.model ? ` · ${vehicle.model}` : ''}</p>
                                                </div>
                                            </div>
                                            <span className={`inline-flex shrink-0 items-center gap-1 rounded-chip px-2 py-0.5 text-xs font-medium ${status.chip}`}>
                                                <span aria-hidden="true" className={`h-1.5 w-1.5 rounded-full ${status.bar}`} />
                                                {status.label}
                                            </span>
                                        </div>

                                        <div className="mt-4">
                                            <div className="flex items-baseline justify-between text-[13px]">
                                                <span className="text-ink-600">{type.work}</span>
                                                <span className="font-medium tabular-nums text-ink-900">{vehicle.capacity_kg.toLocaleString()} kg</span>
                                            </div>
                                            <span className="mt-1.5 block h-1.5 overflow-hidden rounded-full bg-surface-100" role="img" aria-label={`Capacity ${vehicle.capacity_kg} kg`}>
                                                <span className={`block h-full rounded-full ${type.bar}`} style={{ width: `${Math.max(4, share)}%` }} />
                                            </span>
                                        </div>

                                        <div className="mt-4 flex items-center justify-between gap-3 border-t border-line-200 pt-3">
                                            <div className="flex min-w-0 items-center gap-2.5">
                                                <span aria-hidden="true" className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-surface-100 text-xs font-semibold text-ink-700">{initials(vehicle.driver_name)}</span>
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-medium text-ink-900">{vehicle.driver_name}</p><p className="text-xs text-ink-500">{vehicle.transport_driver_id ? 'Company transport driver' : !vehicle.assigned_driver_id ? 'Assign a driver to enable dispatch' : vehicle.driver_on_duty ? 'Driver on duty' : 'Driver off duty — sign in as the rider and go on duty'}</p>
                                                    <p className="truncate text-xs text-ink-500">{vehicle.hub_name} · {vehicle.hub_code}</p>
                                                </div>
                                            </div>
                                            {hasPhone && (
                                                <a href={`tel:${vehicle.driver_phone}`} aria-label={`Call ${vehicle.driver_name}`} className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-line-300 bg-white text-ink-700 hover:border-ink-400 hover:bg-surface-100 hover:text-ink-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500">
                                                    <Phone className="h-4 w-4" aria-hidden="true" />
                                                </a>
                                            )}
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>
            </div>
        </DashboardLayout>
    );
}
