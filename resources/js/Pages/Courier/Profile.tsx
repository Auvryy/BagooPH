import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import CourierLayout from '@/Layouts/CourierLayout';
import { User } from '@/types';
import { 
    User as UserIcon, 
    Truck, 
    ShieldCheck, 
    Check, 
    Power, 
    MapPin, 
    Phone, 
    Mail, 
    FileText, 
    Star, 
    Award 
} from 'lucide-react';

interface FleetData {
    vehicle_type: string;
    plate_number: string;
    license_number: string;
    license_status: string;
    or_cr_status: string;
    zone: string;
    completed_deliveries: number;
    rating: number;
}

interface Props {
    user: User;
    isOnline: boolean;
    fleetData: FleetData;
}

export default function CourierProfile({ user, isOnline, fleetData }: Props) {
    const [toggling, setToggling] = useState(false);

    const handleToggleDuty = () => {
        setToggling(true);
        router.post(route('courier.toggleDuty'), {}, {
            preserveScroll: true,
            onFinish: () => setToggling(false),
        });
    };

    return (
        <CourierLayout
            title="Courier Driver & Vehicle Profile"
            subtitle="Verified fleet identity, vehicle credentials, and dispatch readiness"
            isOnline={isOnline}
        >
            <Head title="Driver Profile — Bagoo Express" />

            <div className="space-y-6 font-sans">
                
                {/* 1. DRIVER HERO CARD */}
                <div className="bg-slate-950 rounded-3xl p-6 sm:p-8 text-white border border-slate-800 shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-6 relative overflow-hidden">
                    <div className="flex items-center gap-4 relative z-10">
                        <div className="w-16 h-16 rounded-2xl bg-amber-500 text-slate-950 font-black text-2xl flex items-center justify-center shadow-lg shrink-0">
                            {user.name.charAt(0).toUpperCase()}
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-xl sm:text-2xl font-black">{user.name}</h1>
                                <span className="px-2.5 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 font-mono text-[10px] font-bold border border-emerald-500/30 flex items-center gap-1">
                                    <ShieldCheck className="w-3 h-3" /> VERIFIED RIDER
                                </span>
                            </div>
                            <p className="text-xs text-slate-400 font-mono mt-1">
                                {user.email} • {user.phone || '+63 9XX XXX XXXX'}
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-3 font-mono text-xs z-10">
                        <button
                            type="button"
                            onClick={handleToggleDuty}
                            disabled={toggling}
                            className={`px-5 py-2.5 rounded-xl font-bold uppercase transition flex items-center gap-2 shadow-xs ${
                                isOnline 
                                    ? 'bg-emerald-500 text-slate-950 hover:bg-emerald-600' 
                                    : 'bg-slate-800 text-slate-300 hover:bg-slate-700'
                            }`}
                        >
                            <Power className="w-4 h-4" />
                            <span>{isOnline ? 'Online (Accepting Jobs)' : 'Offline (Paused)'}</span>
                        </button>
                    </div>
                </div>

                {/* 2. FLEET & VEHICLE DETAILS GRID */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    {/* Vehicle & Verification Card */}
                    <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4">
                        <div className="flex items-center gap-2 pb-3 border-b border-slate-100 font-mono text-xs">
                            <Truck className="w-4 h-4 text-[#E00D42]" />
                            <h3 className="font-bold text-slate-900 text-sm uppercase">Registered Fleet Vehicle</h3>
                        </div>

                        <div className="space-y-3 font-mono text-xs">
                            <div className="flex items-center justify-between p-3 bg-slate-50 rounded-xl border border-slate-200">
                                <span className="text-slate-500">Vehicle Type:</span>
                                <span className="font-bold text-slate-900">{fleetData.vehicle_type}</span>
                            </div>
                            <div className="flex items-center justify-between p-3 bg-slate-50 rounded-xl border border-slate-200">
                                <span className="text-slate-500">Plate Number:</span>
                                <span className="font-black text-slate-900 text-sm px-2 py-0.5 rounded bg-amber-100 border border-amber-300">
                                    {fleetData.plate_number}
                                </span>
                            </div>
                            <div className="flex items-center justify-between p-3 bg-slate-50 rounded-xl border border-slate-200">
                                <span className="text-slate-500">OR / CR Status:</span>
                                <span className="font-bold text-emerald-600 flex items-center gap-1">
                                    <Check className="w-3.5 h-3.5" /> {fleetData.or_cr_status}
                                </span>
                            </div>
                            <div className="flex items-center justify-between p-3 bg-slate-50 rounded-xl border border-slate-200">
                                <span className="text-slate-500">Driver License:</span>
                                <span className="font-bold text-slate-900">{fleetData.license_number}</span>
                            </div>
                        </div>
                    </div>

                    {/* Rider Performance & Service Territory */}
                    <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4">
                        <div className="flex items-center gap-2 pb-3 border-b border-slate-100 font-mono text-xs">
                            <Award className="w-4 h-4 text-amber-500" />
                            <h3 className="font-bold text-slate-900 text-sm uppercase">Performance & Territory</h3>
                        </div>

                        <div className="space-y-3 font-mono text-xs">
                            <div className="flex items-center justify-between p-3 bg-slate-50 rounded-xl border border-slate-200">
                                <span className="text-slate-500">Service Territory Zone:</span>
                                <span className="font-bold text-slate-900">{fleetData.zone}</span>
                            </div>
                            <div className="flex items-center justify-between p-3 bg-slate-50 rounded-xl border border-slate-200">
                                <span className="text-slate-500">Total Fulfilled Deliveries:</span>
                                <span className="font-black text-slate-900 text-sm">{fleetData.completed_deliveries} Drops</span>
                            </div>
                            <div className="flex items-center justify-between p-3 bg-slate-50 rounded-xl border border-slate-200">
                                <span className="text-slate-500">Rider Rating:</span>
                                <span className="font-black text-amber-600 flex items-center gap-1">
                                    <Star className="w-4 h-4 fill-amber-400 text-amber-400" /> {fleetData.rating} / 5.0
                                </span>
                            </div>
                            <div className="flex items-center justify-between p-3 bg-slate-50 rounded-xl border border-slate-200">
                                <span className="text-slate-500">Disciplinary Strikes:</span>
                                <span className="font-bold text-emerald-600">0 Strikes (Clean Record)</span>
                            </div>
                        </div>
                    </div>

                </div>

            </div>
        </CourierLayout>
    );
}
