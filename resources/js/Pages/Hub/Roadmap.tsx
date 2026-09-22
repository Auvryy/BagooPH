import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { 
    Users, 
    ShieldCheck, 
    Wallet, 
    Compass, 
    RotateCcw, 
    Cpu, 
    Building2, 
    CheckCircle2, 
    AlertTriangle, 
    TrendingUp, 
    ArrowRight, 
    Sparkles, 
    Layers, 
    MapPin, 
    FileText, 
    Truck, 
    DollarSign, 
    Clock, 
    Check,
    ChevronRight,
    HelpCircle
} from 'lucide-react';

interface Props {
    activeHub: {
        id: number;
        name: string;
        code: string;
        tier: string;
    } | null;
    hubs: any[];
    selectedModule?: string;
}

export default function HubRoadmap({ activeHub, hubs, selectedModule = 'personnel' }: Props) {
    const [activeTab, setActiveTab] = useState<string>(selectedModule);

    const modules = [
        {
            id: 'personnel',
            title: '1. Personnel & Rider KYC',
            shortName: 'Personnel & KYC',
            icon: Users,
            badge: 'Planned Module',
            category: 'Corporate & Fleet',
            summary: 'Rider accreditation, document verification, barangay spatial assignment matrix, and scoped hub staff accounts.',
        },
        {
            id: 'cod',
            title: '2. COD & Remittance Ledger',
            shortName: 'COD Remittance',
            icon: Wallet,
            badge: 'Planned Module',
            category: 'Financials',
            summary: 'Doorstep cash reconciliation, Bayan Hub counter balances, platform commission deductions, and merchant escrow remittances.',
        },
        {
            id: 'rates',
            title: '3. Rates & Service Zones',
            shortName: 'Rates & Zones',
            icon: Compass,
            badge: 'Planned Module',
            category: 'Corporate & Fleet',
            summary: 'Weight & DWS volumetric pricing matrix, highway line-haul distance tiers, and provincial/barangay service coverage toggles.',
        },
        {
            id: 'exceptions',
            title: '4. Exceptions & RTS Protocols',
            shortName: 'Exceptions & RTS',
            icon: RotateCcw,
            badge: 'Planned Module',
            category: 'Parcel Operations',
            summary: 'Delivery failure tracking, 3-attempt escalation policies, and reverse logistics return-to-merchant routing.',
        },
        {
            id: 'density',
            title: '5. AI Barangay Density Engine',
            shortName: 'AI Density Engine',
            icon: Cpu,
            badge: 'Planned AI Subsystem',
            category: 'Parcel Operations',
            summary: '06:00 AM predictive parcel volume analyzer, 60-parcel threshold triggers, and dynamic auxiliary rider load balancing.',
        },
    ];

    return (
        <DashboardLayout
            title="Enterprise Corporate Roadmap"
            subtitle={
                <span className="flex items-center gap-1.5 font-sans text-xs">
                    <span className="px-1.5 py-0.5 rounded-xs bg-purple-50 text-purple-700 border border-purple-200 font-bold uppercase">
                        Planned Enterprise Modules
                    </span>
                    <span>• High-Level Corporate Logistics Extensions</span>
                </span>
            }
            actions={
                <Link
                    href={route('hub.dashboard')}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white hover:bg-slate-50 text-slate-800 text-xs font-bold font-sans rounded-xs border border-slate-300 shadow-2xs transition uppercase tracking-wider"
                >
                    <Building2 className="w-3.5 h-3.5 text-slate-600" />
                    <span>Hub Overview</span>
                </Link>
            }
        >
            <Head title="Enterprise Logistics Roadmap — BagooPH" />

            <div className="space-y-6 font-sans">

                {/* 1. ARCHITECTURAL SCOPE BANNER */}
                <div className="bg-white rounded-xs p-5 border border-slate-300 shadow-xs">
                    <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                        <div className="space-y-1">
                            <div className="flex items-center gap-2">
                                <span className="px-2 py-0.5 rounded-xs bg-[#FDF2F4] text-[#E00D42] text-[10px] font-bold uppercase tracking-wider font-sans border border-[#FCE7EA]">
                                    Logistics Admin Architecture
                                </span>
                                <span className="text-xs text-slate-400 font-sans">
                                    Curriculum & Enterprise Expansion
                                </span>
                            </div>
                            <h2 className="text-base sm:text-lg font-black text-slate-900 tracking-tight">
                                Company Admin Modules & Feature Specification
                            </h2>
                            <p className="text-xs text-slate-600 max-w-3xl leading-relaxed">
                                While floor stations handle physical parcel throughput (Scan Station, Sorting & Counter Pickup), nationwide courier management requires five high-level enterprise modules for financial reconciliation, rider governance, and spatial load-balancing.
                            </p>
                        </div>
                        <div className="flex items-center gap-3 shrink-0">
                            <div className="p-3 rounded-xs bg-slate-50 border border-slate-200 text-center font-sans">
                                <span className="block text-[10px] uppercase text-slate-400 font-bold">Baseline Core</span>
                                <span className="text-sm font-black text-emerald-600">6 Implemented</span>
                            </div>
                            <div className="p-3 rounded-xs bg-slate-50 border border-slate-200 text-center font-sans">
                                <span className="block text-[10px] uppercase text-slate-400 font-bold">Enterprise Suite</span>
                                <span className="text-sm font-black text-[#E00D42]">5 Planned</span>
                            </div>
                        </div>
                    </div>

                    {/* Module Select Buttons */}
                    <div className="mt-5 pt-4 border-t border-slate-200 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2 font-sans">
                        {modules.map((m) => {
                            const isCurrent = activeTab === m.id;
                            const IconComponent = m.icon;
                            return (
                                <button
                                    key={m.id}
                                    type="button"
                                    onClick={() => setActiveTab(m.id)}
                                    className={`p-2.5 rounded-xs border text-left transition cursor-pointer flex flex-col justify-between min-h-[72px] ${
                                        isCurrent
                                            ? 'bg-slate-900 text-white border-slate-900 shadow-xs'
                                            : 'bg-slate-50 hover:bg-white text-slate-700 border-slate-300'
                                    }`}
                                >
                                    <div className="flex items-center justify-between">
                                        <IconComponent className={`w-4 h-4 ${isCurrent ? 'text-[#E00D42]' : 'text-slate-500'}`} />
                                        <span className={`text-[9px] font-bold px-1 py-0.2 rounded-xs uppercase ${
                                            isCurrent ? 'bg-white/20 text-white' : 'bg-slate-200 text-slate-600'
                                        }`}>
                                            {m.category.split(' ')[0]}
                                        </span>
                                    </div>
                                    <div className="mt-2 font-bold text-[11px] leading-tight truncate">
                                        {m.shortName}
                                    </div>
                                </button>
                            );
                        })}
                    </div>
                </div>

                {/* 2. ACTIVE MODULE DEEP DIVE */}
                {activeTab === 'personnel' && (
                    <div className="space-y-4">
                        <div className="bg-white rounded-xs p-5 border border-slate-300 shadow-xs">
                            <div className="flex items-start justify-between pb-4 border-b border-slate-200">
                                <div>
                                    <span className="px-2 py-0.5 rounded-xs bg-blue-50 text-blue-700 text-[10px] font-bold uppercase tracking-wider font-sans border border-blue-200">
                                        Module 01 • Corporate & Fleet Management
                                    </span>
                                    <h3 className="text-base font-black text-slate-900 mt-1">
                                        Personnel & Onboarding Management (Riders & Sorters)
                                    </h3>
                                    <p className="text-xs text-slate-500 mt-0.5">
                                        Centralized driver accreditation, government document verification, municipal barangay assignment matrix, and facility-scoped hub handlers.
                                    </p>
                                </div>
                                <span className="text-[10px] font-sans font-bold px-2 py-1 rounded-xs bg-slate-100 text-slate-700 border border-slate-300 shrink-0">
                                    Roadmap Spec
                                </span>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mt-5 font-sans text-xs">
                                <div className="p-4 rounded-xs bg-slate-50 border border-slate-300 space-y-2">
                                    <div className="flex items-center gap-2 font-bold text-slate-900">
                                        <ShieldCheck className="w-4 h-4 text-emerald-600" />
                                        <span>Rider KYC & Document Queue</span>
                                    </div>
                                    <p className="text-[11px] text-slate-600 font-sans">
                                        Review driver's licenses, LTO vehicle OR/CR, NBI clearances, and insurance policies submitted during courier registration before activating accounts.
                                    </p>
                                    <div className="pt-2 border-t border-slate-200 text-[10px] text-slate-500">
                                        Status: <span className="font-bold text-slate-800">Ready for Backend Hook</span>
                                    </div>
                                </div>

                                <div className="p-4 rounded-xs bg-slate-50 border border-slate-300 space-y-2">
                                    <div className="flex items-center gap-2 font-bold text-slate-900">
                                        <MapPin className="w-4 h-4 text-[#E00D42]" />
                                        <span>Barangay Spatial Matrix</span>
                                    </div>
                                    <p className="text-[11px] text-slate-600 font-sans">
                                        Assign active riders to specific Local Bayan Hubs and dedicate them to neighborhood barangays (baseline 1 rider per barangay with dynamic auxiliary overflow).
                                    </p>
                                    <div className="pt-2 border-t border-slate-200 text-[10px] text-slate-500">
                                        Topology: <span className="font-bold text-slate-800">Laguna 14-Town Matrix</span>
                                    </div>
                                </div>

                                <div className="p-4 rounded-xs bg-slate-50 border border-slate-300 space-y-2">
                                    <div className="flex items-center gap-2 font-bold text-slate-900">
                                        <Users className="w-4 h-4 text-blue-600" />
                                        <span>Branch-Scoped Staff Accounts</span>
                                    </div>
                                    <p className="text-[11px] text-slate-600 font-sans">
                                        Provision handler accounts (`hub_staff`) scoped strictly to individual physical facilities (e.g. Santa Cruz vs San Pablo) for mobile scanner terminals.
                                    </p>
                                    <div className="pt-2 border-t border-slate-200 text-[10px] text-slate-500">
                                        Security: <span className="font-bold text-slate-800">RBAC Subdomain Guard</span>
                                    </div>
                                </div>
                            </div>

                            {/* Mock Visual Table Preview */}
                            <div className="mt-5 pt-4 border-t border-slate-200">
                                <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400 font-sans mb-2.5">
                                    Interactive Preview: Rider Barangay Assignment Matrix
                                </p>
                                <div className="border border-slate-300 rounded-xs overflow-hidden">
                                    <table className="w-full text-left font-sans text-xs">
                                        <thead className="bg-slate-100 border-b border-slate-300 text-slate-700 text-[10px] uppercase font-bold">
                                            <tr>
                                                <th className="p-2.5">Rider Name</th>
                                                <th className="p-2.5">Assigned Bayan Hub</th>
                                                <th className="p-2.5">Primary Barangay</th>
                                                <th className="p-2.5">Vehicle Tier</th>
                                                <th className="p-2.5">KYC Status</th>
                                                <th className="p-2.5 text-right">Daily Quota</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-200 bg-white text-[11px]">
                                            <tr>
                                                <td className="p-2.5 font-bold text-slate-900">Kuya Cardo Dalisay</td>
                                                <td className="p-2.5 text-slate-600">Santa Cruz Bayan Hub</td>
                                                <td className="p-2.5"><span className="px-1.5 py-0.5 bg-slate-100 rounded-xs border border-slate-300 font-bold">Poblacion I</span></td>
                                                <td className="p-2.5 text-slate-600">2W Motorcycle</td>
                                                <td className="p-2.5"><span className="px-1.5 py-0.5 bg-emerald-50 text-emerald-700 rounded-xs border border-emerald-300 font-bold">Verified</span></td>
                                                <td className="p-2.5 text-right font-bold text-slate-800">45 / 60 max</td>
                                            </tr>
                                            <tr>
                                                <td className="p-2.5 font-bold text-slate-900">Rider Juan Dela Cruz</td>
                                                <td className="p-2.5 text-slate-600">Los Baños Bayan Hub</td>
                                                <td className="p-2.5"><span className="px-1.5 py-0.5 bg-slate-100 rounded-xs border border-slate-300 font-bold">Batong Malake</span></td>
                                                <td className="p-2.5 text-slate-600">2W Motorcycle</td>
                                                <td className="p-2.5"><span className="px-1.5 py-0.5 bg-emerald-50 text-emerald-700 rounded-xs border border-emerald-300 font-bold">Verified</span></td>
                                                <td className="p-2.5 text-right font-bold text-slate-800">52 / 60 max</td>
                                            </tr>
                                            <tr>
                                                <td className="p-2.5 font-bold text-slate-900">Driver Mark Santos</td>
                                                <td className="p-2.5 text-slate-600">Laguna Regional Mother Hub</td>
                                                <td className="p-2.5"><span className="px-1.5 py-0.5 bg-blue-50 text-blue-700 rounded-xs border border-blue-200 font-bold">Line-Haul Feeder</span></td>
                                                <td className="p-2.5 text-slate-600">4W L300 Van</td>
                                                <td className="p-2.5"><span className="px-1.5 py-0.5 bg-emerald-50 text-emerald-700 rounded-xs border border-emerald-300 font-bold">Verified</span></td>
                                                <td className="p-2.5 text-right font-bold text-slate-800">350 parcels</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {activeTab === 'cod' && (
                    <div className="space-y-4">
                        <div className="bg-white rounded-xs p-5 border border-slate-300 shadow-xs">
                            <div className="flex items-start justify-between pb-4 border-b border-slate-200">
                                <div>
                                    <span className="px-2 py-0.5 rounded-xs bg-emerald-50 text-emerald-700 text-[10px] font-bold uppercase tracking-wider font-sans border border-emerald-200">
                                        Module 02 • Financials & Remittances
                                    </span>
                                    <h3 className="text-base font-black text-slate-900 mt-1">
                                        Cash-on-Delivery (COD) & Financial Remittance Ledger
                                    </h3>
                                    <p className="text-xs text-slate-500 mt-0.5">
                                        Reconciliation of physical cash collected by last-mile delivery riders, Bayan Hub counter collections, platform commission splits, and merchant payouts.
                                    </p>
                                </div>
                                <span className="text-[10px] font-sans font-bold px-2 py-1 rounded-xs bg-slate-100 text-slate-700 border border-slate-300 shrink-0">
                                    Roadmap Spec
                                </span>
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-5 font-sans">
                                <div className="p-4 rounded-xs bg-slate-50 border border-slate-300">
                                    <span className="text-[10px] text-slate-500 font-bold uppercase">Rider Cash in Transit</span>
                                    <p className="text-xl font-black text-slate-900 mt-1">₱18,450.00</p>
                                    <span className="text-[10px] text-slate-500 mt-1 block">8 active last-mile runs</span>
                                </div>
                                <div className="p-4 rounded-xs bg-slate-50 border border-slate-300">
                                    <span className="text-[10px] text-slate-500 font-bold uppercase">Hub Counter Cash Vault</span>
                                    <p className="text-xl font-black text-emerald-700 mt-1">₱34,200.00</p>
                                    <span className="text-[10px] text-slate-500 mt-1 block">Self-pickup claim receipts</span>
                                </div>
                                <div className="p-4 rounded-xs bg-slate-50 border border-slate-300">
                                    <span className="text-[10px] text-slate-500 font-bold uppercase">Platform Settlement Due</span>
                                    <p className="text-xl font-black text-[#E00D42] mt-1">₱52,650.00</p>
                                    <span className="text-[10px] text-slate-500 mt-1 block">Net of 10% platform fee</span>
                                </div>
                            </div>

                            {/* Schema Breakdown */}
                            <div className="mt-5 pt-4 border-t border-slate-200">
                                <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400 font-sans mb-2.5">
                                    Data Architecture Blueprint: `logistics_cod_ledgers`
                                </p>
                                <div className="p-3.5 bg-slate-900 text-slate-200 rounded-xs font-sans text-xs overflow-x-auto space-y-1">
                                    <p className="text-slate-400">// Database Migration Schema Proposal</p>
                                    <p className="text-amber-400">Schema::create('logistics_cod_ledgers', function (Blueprint $table) &#123;</p>
                                    <p className="pl-4">$table-&gt;id();</p>
                                    <p className="pl-4">$table-&gt;foreignId('delivery_id')-&gt;constrained();</p>
                                    <p className="pl-4">$table-&gt;foreignId('collected_by_user_id')-&gt;constrained('users');</p>
                                    <p className="pl-4">$table-&gt;foreignId('hub_id')-&gt;constrained('logistics_hubs');</p>
                                    <p className="pl-4 text-emerald-400">$table-&gt;decimal('amount_collected', 12, 2);</p>
                                    <p className="pl-4 text-emerald-400">$table-&gt;decimal('shipping_fee_earned', 8, 2);</p>
                                    <p className="pl-4 text-emerald-400">$table-&gt;decimal('platform_commission_deducted', 8, 2);</p>
                                    <p className="pl-4">$table-&gt;enum('remittance_status', ['held_by_rider', 'deposited_to_hub', 'remitted_to_escrow']);</p>
                                    <p className="pl-4">$table-&gt;timestamp('verified_at')-&gt;nullable();</p>
                                    <p className="pl-4">$table-&gt;timestamps();</p>
                                    <p className="text-amber-400">&#125;);</p>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {activeTab === 'rates' && (
                    <div className="space-y-4">
                        <div className="bg-white rounded-xs p-5 border border-slate-300 shadow-xs">
                            <div className="flex items-start justify-between pb-4 border-b border-slate-200">
                                <div>
                                    <span className="px-2 py-0.5 rounded-xs bg-purple-50 text-purple-700 text-[10px] font-bold uppercase tracking-wider font-sans border border-purple-200">
                                        Module 03 • Corporate & Fleet Management
                                    </span>
                                    <h3 className="text-base font-black text-slate-900 mt-1">
                                        Shipping Rates & Service Zone Mapping
                                    </h3>
                                    <p className="text-xs text-slate-500 mt-0.5">
                                        Configuring dynamic freight pricing tiers based on weight, volume (DWS tunnel calculations), and contiguous provincial highway coverage boundaries.
                                    </p>
                                </div>
                                <span className="text-[10px] font-sans font-bold px-2 py-1 rounded-xs bg-slate-100 text-slate-700 border border-slate-300 shrink-0">
                                    Roadmap Spec
                                </span>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-5 font-sans text-xs">
                                <div className="p-4 rounded-xs bg-slate-50 border border-slate-300 space-y-2">
                                    <div className="flex items-center justify-between font-bold text-slate-900">
                                        <span className="flex items-center gap-1.5"><Compass className="w-4 h-4 text-[#E00D42]" /> Intramunicipal (Same Bayan)</span>
                                        <span className="text-[#E00D42]">₱45.00 Base</span>
                                    </div>
                                    <p className="text-[11px] text-slate-600 font-sans">
                                        Direct merchant drop-off to local Bayan Hub and same-town doorstep delivery within 24 hours. (Free ₱0.00 for counter self-pickup).
                                    </p>
                                </div>

                                <div className="p-4 rounded-xs bg-slate-50 border border-slate-300 space-y-2">
                                    <div className="flex items-center justify-between font-bold text-slate-900">
                                        <span className="flex items-center gap-1.5"><Truck className="w-4 h-4 text-blue-600" /> Inter-Provincial Line-Haul</span>
                                        <span className="text-blue-700">₱85.00 + ₱15/kg</span>
                                    </div>
                                    <p className="text-[11px] text-slate-600 font-sans">
                                        Origin Bayan Hub → Regional Mother Hub sortation → 10W Wing Truck highway line-haul → Destination Bayan Hub.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {activeTab === 'exceptions' && (
                    <div className="space-y-4">
                        <div className="bg-white rounded-xs p-5 border border-slate-300 shadow-xs">
                            <div className="flex items-start justify-between pb-4 border-b border-slate-200">
                                <div>
                                    <span className="px-2 py-0.5 rounded-xs bg-rose-50 text-rose-700 text-[10px] font-bold uppercase tracking-wider font-sans border border-rose-200">
                                        Module 04 • Parcel Operations & Logistics
                                    </span>
                                    <h3 className="text-base font-black text-slate-900 mt-1">
                                        Exception & Return-to-Sender (RTS) Protocols
                                    </h3>
                                    <p className="text-xs text-slate-500 mt-0.5">
                                        Automated delivery failure queues, 3-attempt escalation workflows, customer re-delivery scheduling, and reverse logistics return-to-merchant routing.
                                    </p>
                                </div>
                                <span className="text-[10px] font-sans font-bold px-2 py-1 rounded-xs bg-slate-100 text-slate-700 border border-slate-300 shrink-0">
                                    Roadmap Spec
                                </span>
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-5 font-sans text-xs">
                                <div className="p-4 rounded-xs bg-amber-50/60 border border-amber-300 space-y-1.5">
                                    <div className="flex items-center justify-between font-bold text-amber-900">
                                        <span>Attempt 1: Reschedule</span>
                                        <Clock className="w-3.5 h-3.5 text-amber-700" />
                                    </div>
                                    <p className="text-[11px] text-amber-800 font-sans">
                                        Customer unreachable or requested re-delivery. Package remains in Bayan Hub staging bin for next-day dispatch.
                                    </p>
                                </div>

                                <div className="p-4 rounded-xs bg-orange-50/60 border border-orange-300 space-y-1.5">
                                    <div className="flex items-center justify-between font-bold text-orange-900">
                                        <span>Attempt 2: SMS Verification</span>
                                        <AlertTriangle className="w-3.5 h-3.5 text-orange-700" />
                                    </div>
                                    <p className="text-[11px] text-orange-800 font-sans">
                                        Secondary failure alert triggered. System prompts customer via SMS/Chat to confirm landmark or pin coordinates.
                                    </p>
                                </div>

                                <div className="p-4 rounded-xs bg-rose-50/60 border border-rose-300 space-y-1.5">
                                    <div className="flex items-center justify-between font-bold text-rose-900">
                                        <span>Attempt 3: RTS Triggered</span>
                                        <RotateCcw className="w-3.5 h-3.5 text-rose-700" />
                                    </div>
                                    <p className="text-[11px] text-rose-800 font-sans">
                                        Final delivery failure. Order state machine flips to `RETURNED_TO_SENDER` and reverse route waybill is generated back to seller.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {activeTab === 'density' && (
                    <div className="space-y-4">
                        <div className="bg-white rounded-xs p-5 border border-slate-300 shadow-xs">
                            <div className="flex items-start justify-between pb-4 border-b border-slate-200">
                                <div>
                                    <span className="px-2 py-0.5 rounded-xs bg-indigo-50 text-indigo-700 text-[10px] font-bold uppercase tracking-wider font-sans border border-indigo-200">
                                        Module 05 • Integrated AI Subsystem
                                    </span>
                                    <h3 className="text-base font-black text-slate-900 mt-1">
                                        Morning Barangay Density Engine (AI Rider Load Balancing)
                                    </h3>
                                    <p className="text-xs text-slate-500 mt-0.5">
                                        Automated daily 06:00 AM spatial analysis of parcel density across municipal barangays, triggering auxiliary rider allocation for high-volume zones (&gt;60 parcels).
                                    </p>
                                </div>
                                <span className="text-[10px] font-sans font-bold px-2 py-1 rounded-xs bg-slate-100 text-slate-700 border border-slate-300 shrink-0">
                                    AI Subsystem Spec
                                </span>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-5 font-sans text-xs">
                                <div className="p-4 rounded-xs bg-slate-50 border border-slate-300 space-y-2">
                                    <div className="flex items-center justify-between font-bold text-slate-900">
                                        <span className="flex items-center gap-1.5"><Sparkles className="w-4 h-4 text-indigo-600" /> 06:00 AM Cron Density Engine</span>
                                        <span className="px-1.5 py-0.5 rounded-xs bg-emerald-50 text-emerald-700 border border-emerald-300 font-bold">Automated</span>
                                    </div>
                                    <p className="text-[11px] text-slate-600 font-sans">
                                        Iterates all staged deliveries at Destination Bayan Hubs, grouping by `destination_barangay`. Computes density ratio per active driver.
                                    </p>
                                </div>

                                <div className="p-4 rounded-xs bg-slate-50 border border-slate-300 space-y-2">
                                    <div className="flex items-center justify-between font-bold text-slate-900">
                                        <span className="flex items-center gap-1.5"><Layers className="w-4 h-4 text-[#E00D42]" /> 60-Parcel Overflow Rule</span>
                                        <span className="px-1.5 py-0.5 rounded-xs bg-amber-50 text-amber-700 border border-amber-300 font-bold">&gt;60 Threshold</span>
                                    </div>
                                    <p className="text-[11px] text-slate-600 font-sans">
                                        If Barangay Poblacion I holds 84 packages, the system splits load between Primary Rider (50 pkgs) and Auxiliary Rider (34 pkgs) automatically.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

            </div>
        </DashboardLayout>
    );
}
