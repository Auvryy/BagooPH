import React from 'react';
import { Head } from '@inertiajs/react';
import {
    Bike,
    Building2,
    CheckCircle2,
    FileCheck2,
    IdCard,
    Mail,
    MapPin,
    PackageCheck,
    Phone,
    ShieldCheck,
} from 'lucide-react';
import CourierLayout from '@/Layouts/CourierLayout';

interface Props {
    rider: {
        name: string;
        email: string;
        phone: string | null;
        account_status: string;
        kyc_status: string;
    };
    assignment: {
        company: string | null;
        hub: string | null;
        hub_code: string | null;
        barangay: string | null;
    };
    vehicle: {
        type: string | null;
        model: string | null;
        plate_number: string | null;
        fleet_status: string | null;
        license_number: string | null;
        registration_status: string | null;
    };
    isOnline: boolean;
    completedDeliveries: number;
}

export default function CourierProfile({
    rider,
    assignment,
    vehicle,
    isOnline,
    completedDeliveries,
}: Props) {
    return (
        <CourierLayout
            title="Rider profile"
            subtitle="Approved identity, working facility, and assigned vehicle"
            isOnline={isOnline}
        >
            <Head title="Rider profile — BagooPH" />

            <div className="space-y-5">
                <section className="rounded-lg border border-slate-300 bg-white p-5 sm:p-6">
                    <div className="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex min-w-0 items-center gap-4">
                            <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-md bg-slate-900 text-xl font-bold text-white">
                                {rider.name.charAt(0).toUpperCase()}
                            </div>
                            <div className="min-w-0">
                                <h2 className="truncate text-xl font-bold text-slate-950">{rider.name}</h2>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    <StatusBadge label={rider.account_status} tone="slate" />
                                    <StatusBadge label={rider.kyc_status} tone="green" />
                                    <StatusBadge
                                        label={isOnline ? 'on duty' : 'off duty'}
                                        tone={isOnline ? 'green' : 'slate'}
                                    />
                                </div>
                            </div>
                        </div>
                        <div className="rounded-md border border-slate-300 bg-slate-50 px-4 py-3 sm:text-right">
                            <p className="text-xs font-medium text-slate-500">Completed final-mile deliveries</p>
                            <p className="mt-1 text-2xl font-bold text-slate-950">{completedDeliveries}</p>
                        </div>
                    </div>

                    <div className="mt-5 grid gap-3 border-t border-slate-200 pt-5 sm:grid-cols-2">
                        <InfoRow icon={Mail} label="Email" value={rider.email} />
                        <InfoRow icon={Phone} label="Phone" value={rider.phone} />
                    </div>
                </section>

                <div className="grid gap-5 lg:grid-cols-2">
                    <section className="rounded-lg border border-slate-300 bg-white">
                        <div className="flex items-center gap-2 border-b border-slate-200 p-4 sm:p-5">
                            <Building2 className="h-5 w-5 text-[#E00D42]" />
                            <div>
                                <h2 className="text-base font-bold text-slate-950">Work assignment</h2>
                                <p className="mt-0.5 text-xs text-slate-500">
                                    Controls which pickup and final-mile tasks you may receive.
                                </p>
                            </div>
                        </div>
                        <div className="space-y-3 p-4 sm:p-5">
                            <InfoRow icon={Building2} label="Logistics company" value={assignment.company} />
                            <InfoRow
                                icon={MapPin}
                                label="Working facility"
                                value={
                                    assignment.hub
                                        ? `${assignment.hub}${assignment.hub_code ? ` (${assignment.hub_code})` : ''}`
                                        : null
                                }
                            />
                            <InfoRow
                                icon={PackageCheck}
                                label="Assigned barangay"
                                value={assignment.barangay}
                            />
                        </div>
                    </section>

                    <section className="rounded-lg border border-slate-300 bg-white">
                        <div className="flex items-center gap-2 border-b border-slate-200 p-4 sm:p-5">
                            <Bike className="h-5 w-5 text-[#E00D42]" />
                            <div>
                                <h2 className="text-base font-bold text-slate-950">Vehicle and credentials</h2>
                                <p className="mt-0.5 text-xs text-slate-500">
                                    Data recorded during approval or fleet assignment.
                                </p>
                            </div>
                        </div>
                        <div className="space-y-3 p-4 sm:p-5">
                            <InfoRow
                                icon={Bike}
                                label="Vehicle"
                                value={
                                    vehicle.model
                                        ? `${vehicle.type ?? 'Vehicle'} · ${vehicle.model}`
                                        : vehicle.type
                                }
                            />
                            <InfoRow icon={IdCard} label="Plate number" value={vehicle.plate_number} />
                            <InfoRow
                                icon={ShieldCheck}
                                label="Driver license"
                                value={vehicle.license_number}
                            />
                            <InfoRow
                                icon={FileCheck2}
                                label="Registration status"
                                value={vehicle.registration_status}
                            />
                            <InfoRow
                                icon={CheckCircle2}
                                label="Fleet status"
                                value={vehicle.fleet_status}
                            />
                        </div>
                    </section>
                </div>

                <div className="rounded-lg border border-blue-300 bg-blue-50 p-4 text-sm leading-6 text-blue-950">
                    Verified identity, license, hub, and vehicle fields are read-only here. Contact your
                    logistics administrator when an approved assignment or credential needs correction.
                </div>
            </div>
        </CourierLayout>
    );
}

function InfoRow({
    icon: Icon,
    label,
    value,
}: {
    icon: React.ElementType;
    label: string;
    value: string | null;
}) {
    return (
        <div className="flex items-start gap-3 rounded-md border border-slate-300 bg-slate-50 p-3">
            <Icon className="mt-0.5 h-4 w-4 shrink-0 text-slate-500" />
            <div className="min-w-0">
                <p className="text-xs font-medium text-slate-500">{label}</p>
                <p className="mt-1 break-words text-sm font-semibold text-slate-900">
                    {value || 'Not recorded'}
                </p>
            </div>
        </div>
    );
}

function StatusBadge({
    label,
    tone,
}: {
    label: string;
    tone: 'green' | 'slate';
}) {
    return (
        <span
            className={`rounded-sm border px-2 py-1 text-[11px] font-bold uppercase ${
                tone === 'green'
                    ? 'border-emerald-300 bg-emerald-50 text-emerald-800'
                    : 'border-slate-300 bg-slate-50 text-slate-700'
            }`}
        >
            {label.replaceAll('_', ' ')}
        </span>
    );
}
