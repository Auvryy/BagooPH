import React from 'react';
import { Head } from '@inertiajs/react';
import { Building2, CheckCircle2, Clock3, PackageCheck } from 'lucide-react';
import CourierLayout from '@/Layouts/CourierLayout';

interface Props {
    scope: {
        company: string | null;
        hub: string | null;
        hubCode: string | null;
        barangay: string | null;
        isAssigned: boolean;
        isOperational: boolean;
    };
    summary: {
        completedDeliveries: number;
        completedToday: number;
    };
    isOnline: boolean;
    trips: Array<{
        id: number;
        trackingNumber: string;
        orderNumber: string | null;
        recipientName: string;
        deliveryAddress: string;
        paymentMethod: string;
        destinationHub: string | null;
        deliveredAt: string | null;
    }>;
}

export default function CourierCompletedTrips({ scope, summary, trips, isOnline }: Props) {
    const formatDate = (value: string | null) => {
        if (!value) return 'Not recorded';
        return new Intl.DateTimeFormat('en-PH', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        }).format(new Date(value));
    };

    return (
        <CourierLayout
            title="Completed trips"
            subtitle={
                scope.isAssigned
                    ? `${scope.hub} · ${scope.company}`
                    : 'No working facility assigned'
            }
            isOnline={isOnline}
        >
            <Head title="Completed trips — BagooPH" />

            <div className="space-y-5">
                <div className="rounded-lg border border-blue-300 bg-blue-50 p-4 text-sm leading-6 text-blue-950">
                    This page records final-mile delivery activity only. Rider compensation, COD custody, and
                    remittance will appear after the financial ledger workflow is implemented.
                </div>

                <section className="grid gap-3 sm:grid-cols-3">
                    <SummaryCard
                        label="Completed deliveries"
                        value={summary.completedDeliveries}
                        icon={PackageCheck}
                    />
                    <SummaryCard
                        label="Delivered today"
                        value={summary.completedToday}
                        icon={CheckCircle2}
                    />
                    <div className="rounded-lg border border-slate-300 bg-white p-4">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-xs font-medium text-slate-500">Assigned facility</p>
                                <p className="mt-2 text-sm font-bold text-slate-950">
                                    {scope.hub ?? 'Not assigned'}
                                </p>
                                <p className="mt-1 text-xs text-slate-500">{scope.hubCode ?? 'No facility code'}</p>
                            </div>
                            <span className="rounded-sm border border-slate-300 bg-slate-50 p-2 text-slate-600">
                                <Building2 className="h-4 w-4" />
                            </span>
                        </div>
                    </div>
                </section>

                <section className="rounded-lg border border-slate-300 bg-white">
                    <div className="border-b border-slate-200 p-4 sm:p-5">
                        <h2 className="text-base font-bold text-slate-950">Trip history</h2>
                        <p className="mt-1 text-sm text-slate-500">
                            Deliveries completed by this final-mile rider at the assigned company and hub.
                        </p>
                    </div>

                    {trips.length === 0 ? (
                        <div className="p-10 text-center">
                            <Clock3 className="mx-auto h-9 w-9 text-slate-300" />
                            <p className="mt-3 text-sm font-semibold text-slate-800">
                                No completed final-mile trips
                            </p>
                            <p className="mt-1 text-sm text-slate-500">
                                Successful deliveries with recorded proof will appear here.
                            </p>
                        </div>
                    ) : (
                        <div className="divide-y divide-slate-200">
                            {trips.map((trip) => (
                                <article
                                    key={trip.id}
                                    className="grid gap-4 p-4 sm:grid-cols-[1fr_1.3fr_auto] sm:items-center sm:p-5"
                                >
                                    <div>
                                        <p className="text-sm font-bold text-slate-950">
                                            {trip.trackingNumber}
                                        </p>
                                        <p className="mt-1 text-xs text-slate-500">
                                            Order {trip.orderNumber} · {trip.paymentMethod}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-sm font-semibold text-slate-800">
                                            {trip.recipientName}
                                        </p>
                                        <p className="mt-1 line-clamp-2 text-xs leading-5 text-slate-500">
                                            {trip.deliveryAddress}
                                        </p>
                                    </div>
                                    <div className="text-left sm:min-w-44 sm:text-right">
                                        <p className="text-xs font-medium text-slate-700">
                                            {formatDate(trip.deliveredAt)}
                                        </p>
                                        <p className="mt-1 text-xs text-slate-500">
                                            {trip.destinationHub ?? 'Destination hub unavailable'}
                                        </p>
                                    </div>
                                </article>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </CourierLayout>
    );
}

function SummaryCard({
    label,
    value,
    icon: Icon,
}: {
    label: string;
    value: number;
    icon: React.ElementType;
}) {
    return (
        <div className="rounded-lg border border-slate-300 bg-white p-4">
            <div className="flex items-start justify-between">
                <div>
                    <p className="text-xs font-medium text-slate-500">{label}</p>
                    <p className="mt-2 text-2xl font-bold text-slate-950">{value}</p>
                </div>
                <span className="rounded-sm border border-slate-300 bg-slate-50 p-2 text-slate-600">
                    <Icon className="h-4 w-4" />
                </span>
            </div>
        </div>
    );
}
