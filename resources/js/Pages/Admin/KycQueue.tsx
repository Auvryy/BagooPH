import React, { useState, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { Head, router, useForm } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { PaginatedData, User } from '@/types';
import {
    ShieldCheck,
    ShieldAlert,
    Clock,
    Search,
    Filter,
    Eye,
    Check,
    X,
    ExternalLink,
    FileText,
    Store,
    Truck,
    UserCheck,
    AlertTriangle,
    RefreshCw,
    ChevronLeft,
    ChevronRight,
    Sparkles,
    User as UserIcon,
    Phone,
    Mail,
    MapPin,
    Layers,
    FileCheck
} from 'lucide-react';

interface KycQueueProps {
    applicants: PaginatedData<User>;
    filters: {
        status: string;
        role: string;
        search: string;
    };
    stats: {
        pending_count: number;
        approved_count: number;
        rejected_count: number;
        total_count: number;
        pending_sellers: number;
        pending_couriers: number;
        pending_buyers: number;
    };
}

export default function KycQueue({ applicants, filters, stats }: KycQueueProps) {
    const [search, setSearch] = useState(filters.search || '');
    const [selectedStatus, setSelectedStatus] = useState(filters.status || 'pending_approval');
    const [selectedRole, setSelectedRole] = useState(filters.role || 'all');

    // Inspect Document Modal State
    const [inspectingApplicant, setInspectingApplicant] = useState<User | null>(null);
    const [activeDocTab, setActiveDocTab] = useState<'id' | 'permit' | 'license' | 'orcr'>('id');

    // Reject Modal State
    const [rejectingApplicant, setRejectingApplicant] = useState<User | null>(null);
    const { data: rejectData, setData: setRejectData, post: postReject, processing: rejectProcessing, reset: resetReject, errors: rejectErrors } = useForm({
        reason: '',
    });

    // Scroll locking for open modals
    useEffect(() => {
        if (inspectingApplicant || rejectingApplicant) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
        return () => {
            document.body.style.overflow = '';
        };
    }, [inspectingApplicant, rejectingApplicant]);

    const handleFilterChange = (newStatus?: string, newRole?: string) => {
        const s = newStatus !== undefined ? newStatus : selectedStatus;
        const r = newRole !== undefined ? newRole : selectedRole;
        setSelectedStatus(s);
        setSelectedRole(r);

        router.get(
            route('admin.kyc.index'),
            {
                status: s,
                role: r,
                search: search,
            },
            {
                preserveState: true,
                preserveScroll: true,
            }
        );
    };

    const handleSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        handleFilterChange();
    };

    const handleApprove = (applicant: User) => {
        if (confirm(`Approve KYC verification for ${applicant.name} (${applicant.role})?`)) {
            router.post(
                route('admin.kyc.approve', applicant.id),
                {},
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        if (inspectingApplicant?.id === applicant.id) {
                            setInspectingApplicant(null);
                        }
                    },
                }
            );
        }
    };

    const openRejectModal = (applicant: User) => {
        setRejectingApplicant(applicant);
        setRejectData('reason', '');
    };

    const handleRejectSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!rejectingApplicant) return;

        postReject(route('admin.kyc.reject', rejectingApplicant.id), {
            preserveScroll: true,
            onSuccess: () => {
                setRejectingApplicant(null);
                resetReject();
                if (inspectingApplicant?.id === rejectingApplicant.id) {
                    setInspectingApplicant(null);
                }
            },
        });
    };

    const fillPresetReason = (preset: string) => {
        setRejectData('reason', preset);
    };

    const getAvailableDocs = (applicant: User) => {
        const docs: { key: 'id' | 'permit' | 'license' | 'orcr'; label: string; path: string | null | undefined }[] = [
            { key: 'id', label: 'Gov ID', path: applicant.id_document_path },
        ];

        if (applicant.role === 'seller' || applicant.business_permit_path) {
            docs.push({ key: 'permit', label: 'Business Permit', path: applicant.business_permit_path });
        }

        if (applicant.role === 'courier' || applicant.driver_license_path) {
            docs.push({ key: 'license', label: "Driver's License", path: applicant.driver_license_path });
        }

        if (applicant.role === 'courier' || applicant.or_cr_path) {
            docs.push({ key: 'orcr', label: 'Vehicle OR/CR', path: applicant.or_cr_path });
        }

        return docs;
    };

    const getDocPath = (applicant: User, type: 'id' | 'permit' | 'license' | 'orcr') => {
        switch (type) {
            case 'id': return applicant.id_document_path;
            case 'permit': return applicant.business_permit_path;
            case 'license': return applicant.driver_license_path;
            case 'orcr': return applicant.or_cr_path;
        }
    };

    const renderDocumentPreview = (path: string | null | undefined) => {
        if (!path) {
            return (
                <div className="h-96 flex flex-col items-center justify-center text-slate-400 bg-slate-100 rounded-xl border border-dashed border-slate-300">
                    <FileText className="w-12 h-12 mb-2 text-slate-300" />
                    <p className="font-sans text-xs">No document file attached</p>
                </div>
            );
        }

        const isPdf = path.toLowerCase().endsWith('.pdf');

        if (isPdf) {
            return (
                <div className="h-96 flex flex-col items-center justify-center bg-slate-50 rounded-xl border border-slate-200 p-6 text-center space-y-3">
                    <FileText className="w-16 h-16 text-rose-500" />
                    <div>
                        <p className="font-bold text-sm text-slate-800">PDF Document Uploaded</p>
                        <p className="font-mono text-xs text-slate-500 truncate max-w-md">{path}</p>
                    </div>
                    <a
                        href={path}
                        target="_blank"
                        rel="noreferrer"
                        className="px-4 py-2 bg-slate-900 hover:bg-[#E00D42] text-white rounded-lg font-sans text-xs font-bold flex items-center gap-2 transition"
                    >
                        <span>Open Document in New Tab</span>
                        <ExternalLink className="w-4 h-4" />
                    </a>
                </div>
            );
        }

        return (
            <div className="relative group bg-slate-950 rounded-xl overflow-hidden border border-slate-800 flex items-center justify-center min-h-[380px] max-h-[520px] p-2">
                <img
                    src={path}
                    alt="Applicant Document Inspection"
                    className="max-h-[500px] w-auto max-w-full object-contain rounded-lg transition group-hover:scale-[1.01]"
                />
                <a
                    href={path}
                    target="_blank"
                    rel="noreferrer"
                    className="absolute top-3 right-3 px-3 py-1.5 bg-black/80 hover:bg-black text-white rounded-lg font-sans text-xs font-bold flex items-center gap-1.5 backdrop-blur-xs transition shadow-md"
                >
                    <ExternalLink className="w-3.5 h-3.5" />
                    <span>Full Resolution</span>
                </a>
            </div>
        );
    };

    return (
        <DashboardLayout
            title="KYC Verification Queue"
            subtitle="Review identification, merchant permits, driver licenses & vehicle registrations"
        >
            <Head title="KYC Verification Queue — BagooPH Admin" />

            <div className="space-y-6">
                {/* 1. Metric Cards Banner */}
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div 
                        onClick={() => handleFilterChange('pending_approval', 'all')}
                        className={`p-4 rounded-xl border transition cursor-pointer shadow-xs ${
                            selectedStatus === 'pending_approval' 
                                ? 'bg-amber-500 text-white border-amber-600 ring-2 ring-amber-500/20' 
                                : 'bg-white border-slate-200 hover:border-amber-400'
                        }`}
                    >
                        <div className="flex items-center justify-between">
                            <span className={`text-[10px] font-mono font-bold uppercase tracking-wider ${selectedStatus === 'pending_approval' ? 'text-amber-100' : 'text-slate-500'}`}>
                                Pending Review
                            </span>
                            <Clock className={`w-4 h-4 ${selectedStatus === 'pending_approval' ? 'text-white' : 'text-amber-500'}`} />
                        </div>
                        <p className="text-2xl font-black font-mono mt-1">{stats.pending_count}</p>
                        <div className={`text-[10px] font-mono mt-1 ${selectedStatus === 'pending_approval' ? 'text-amber-100' : 'text-slate-400'}`}>
                            {stats.pending_sellers} Sellers • {stats.pending_couriers} Couriers
                        </div>
                    </div>

                    <div 
                        onClick={() => handleFilterChange('approved', 'all')}
                        className={`p-4 rounded-xl border transition cursor-pointer shadow-xs ${
                            selectedStatus === 'approved' 
                                ? 'bg-emerald-600 text-white border-emerald-700 ring-2 ring-emerald-500/20' 
                                : 'bg-white border-slate-200 hover:border-emerald-400'
                        }`}
                    >
                        <div className="flex items-center justify-between">
                            <span className={`text-[10px] font-mono font-bold uppercase tracking-wider ${selectedStatus === 'approved' ? 'text-emerald-100' : 'text-slate-500'}`}>
                                Approved Accounts
                            </span>
                            <ShieldCheck className={`w-4 h-4 ${selectedStatus === 'approved' ? 'text-white' : 'text-emerald-600'}`} />
                        </div>
                        <p className="text-2xl font-black font-mono mt-1">{stats.approved_count}</p>
                        <span className={`text-[10px] font-mono mt-1 block ${selectedStatus === 'approved' ? 'text-emerald-100' : 'text-slate-400'}`}>
                            Active Platform Users
                        </span>
                    </div>

                    <div 
                        onClick={() => handleFilterChange('rejected', 'all')}
                        className={`p-4 rounded-xl border transition cursor-pointer shadow-xs ${
                            selectedStatus === 'rejected' 
                                ? 'bg-rose-600 text-white border-rose-700 ring-2 ring-rose-500/20' 
                                : 'bg-white border-slate-200 hover:border-rose-400'
                        }`}
                    >
                        <div className="flex items-center justify-between">
                            <span className={`text-[10px] font-mono font-bold uppercase tracking-wider ${selectedStatus === 'rejected' ? 'text-rose-100' : 'text-slate-500'}`}>
                                Rejected / Flagged
                            </span>
                            <ShieldAlert className={`w-4 h-4 ${selectedStatus === 'rejected' ? 'text-white' : 'text-rose-500'}`} />
                        </div>
                        <p className="text-2xl font-black font-mono mt-1">{stats.rejected_count}</p>
                        <span className={`text-[10px] font-mono mt-1 block ${selectedStatus === 'rejected' ? 'text-rose-100' : 'text-slate-400'}`}>
                            Awaiting Resubmission
                        </span>
                    </div>

                    <div 
                        onClick={() => handleFilterChange('all', 'all')}
                        className={`p-4 rounded-xl border transition cursor-pointer shadow-xs ${
                            selectedStatus === 'all' 
                                ? 'bg-slate-900 text-white border-black' 
                                : 'bg-white border-slate-200 hover:border-slate-400'
                        }`}
                    >
                        <div className="flex items-center justify-between">
                            <span className={`text-[10px] font-mono font-bold uppercase tracking-wider ${selectedStatus === 'all' ? 'text-slate-300' : 'text-slate-500'}`}>
                                Total Processed
                            </span>
                            <Layers className={`w-4 h-4 ${selectedStatus === 'all' ? 'text-white' : 'text-slate-600'}`} />
                        </div>
                        <p className="text-2xl font-black font-mono mt-1">{stats.total_count}</p>
                        <span className={`text-[10px] font-mono mt-1 block ${selectedStatus === 'all' ? 'text-slate-300' : 'text-slate-400'}`}>
                            Across All Roles
                        </span>
                    </div>
                </div>

                {/* 2. Search & Filter Bar */}
                <div className="p-4 bg-white rounded-xl border border-slate-200 shadow-xs space-y-3">
                    <div className="flex flex-col md:flex-row md:items-center justify-between gap-3">
                        {/* Status Pills */}
                        <div className="flex items-center gap-1.5 overflow-x-auto pb-1 md:pb-0 font-mono text-xs">
                            <button
                                onClick={() => handleFilterChange('pending_approval')}
                                className={`px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap cursor-pointer ${
                                    selectedStatus === 'pending_approval'
                                        ? 'bg-amber-500 text-white shadow-xs'
                                        : 'bg-slate-100 hover:bg-slate-200 text-slate-700'
                                }`}
                            >
                                Pending Review ({stats.pending_count})
                            </button>
                            <button
                                onClick={() => handleFilterChange('approved')}
                                className={`px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap cursor-pointer ${
                                    selectedStatus === 'approved'
                                        ? 'bg-emerald-600 text-white shadow-xs'
                                        : 'bg-slate-100 hover:bg-slate-200 text-slate-700'
                                }`}
                            >
                                Approved ({stats.approved_count})
                            </button>
                            <button
                                onClick={() => handleFilterChange('rejected')}
                                className={`px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap cursor-pointer ${
                                    selectedStatus === 'rejected'
                                        ? 'bg-rose-600 text-white shadow-xs'
                                        : 'bg-slate-100 hover:bg-slate-200 text-slate-700'
                                }`}
                            >
                                Rejected ({stats.rejected_count})
                            </button>
                            <button
                                onClick={() => handleFilterChange('all')}
                                className={`px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap cursor-pointer ${
                                    selectedStatus === 'all'
                                        ? 'bg-slate-900 text-white shadow-xs'
                                        : 'bg-slate-100 hover:bg-slate-200 text-slate-700'
                                }`}
                            >
                                All Users
                            </button>
                        </div>

                        {/* Search & Role Filter */}
                        <form onSubmit={handleSearchSubmit} className="flex items-center gap-2">
                            <select
                                value={selectedRole}
                                onChange={(e) => handleFilterChange(undefined, e.target.value)}
                                className="px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-mono font-bold text-slate-700 outline-hidden focus:border-slate-900"
                            >
                                <option value="all">All Roles</option>
                                <option value="seller">Sellers Only</option>
                                <option value="courier">Couriers Only</option>
                                <option value="buyer">Buyers Only</option>
                            </select>

                            <div className="relative flex-1 sm:w-64">
                                <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                <input
                                    type="text"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search applicant or email..."
                                    className="w-full pl-9 pr-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-mono text-slate-900 outline-hidden focus:border-slate-900"
                                />
                            </div>

                            <button
                                type="submit"
                                className="px-3 py-2 bg-slate-900 hover:bg-[#E00D42] text-white rounded-lg text-xs font-mono font-bold uppercase transition"
                            >
                                Filter
                            </button>
                        </form>
                    </div>
                </div>

                {/* 3. Applicant Data Table */}
                <div className="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left border-collapse">
                            <thead>
                                <tr className="bg-slate-50/80 border-b border-slate-200 text-[10px] font-mono font-bold uppercase tracking-wider text-slate-500">
                                    <th className="py-3 px-4">Applicant & Contact</th>
                                    <th className="py-3 px-4">Role & Business Specs</th>
                                    <th className="py-3 px-4">Submitted Documents</th>
                                    <th className="py-3 px-4">KYC Status</th>
                                    <th className="py-3 px-4 text-right">Verification Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 text-xs font-sans">
                                {applicants.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={5} className="py-12 text-center text-slate-400 font-mono">
                                            <FileCheck className="w-10 h-10 mx-auto mb-2 text-slate-300" />
                                            <p className="text-sm font-bold text-slate-600">No applicants matching criteria</p>
                                            <p className="text-xs">All applications in this filter have been processed.</p>
                                        </td>
                                    </tr>
                                ) : (
                                    applicants.data.map((applicant) => {
                                        const docs = getAvailableDocs(applicant);
                                        return (
                                            <tr key={applicant.id} className="hover:bg-slate-50/70 transition">
                                                {/* Applicant */}
                                                <td className="py-3.5 px-4">
                                                    <div className="flex items-center gap-3">
                                                        <div className="w-8 h-8 rounded-full bg-slate-900 text-white font-mono font-bold text-xs flex items-center justify-center shrink-0">
                                                            {applicant.name.charAt(0)}
                                                        </div>
                                                        <div>
                                                            <p className="font-bold text-slate-900">{applicant.name}</p>
                                                            <div className="flex items-center gap-2 text-[11px] text-slate-500 font-mono">
                                                                <span className="truncate">{applicant.email}</span>
                                                                {applicant.phone && (
                                                                    <span>• {applicant.phone}</span>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>

                                                {/* Role & Specs */}
                                                <td className="py-3.5 px-4 font-mono">
                                                    <div className="space-y-1">
                                                        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold uppercase ${
                                                            applicant.role === 'seller'
                                                                ? 'bg-purple-100 text-purple-800'
                                                                : applicant.role === 'courier'
                                                                ? 'bg-blue-100 text-blue-800'
                                                                : applicant.role === 'admin'
                                                                ? 'bg-red-100 text-red-800'
                                                                : 'bg-emerald-100 text-emerald-800'
                                                        }`}>
                                                            {applicant.role === 'seller' && <Store className="w-3 h-3" />}
                                                            {applicant.role === 'courier' && <Truck className="w-3 h-3" />}
                                                            {applicant.role}
                                                        </span>

                                                        {applicant.role === 'seller' && applicant.shop && (
                                                            <p className="text-[11px] font-bold text-slate-800 truncate">
                                                                {applicant.shop.name} ({applicant.shop.city || 'Metro Manila'})
                                                            </p>
                                                        )}

                                                        {applicant.role === 'courier' && applicant.courier_profile && (
                                                            <p className="text-[11px] text-slate-700">
                                                                {applicant.courier_profile.vehicle_type} • Plate: {applicant.courier_profile.plate_number || 'N/A'}
                                                            </p>
                                                        )}
                                                    </div>
                                                </td>

                                                {/* Documents */}
                                                <td className="py-3.5 px-4 font-mono text-[11px]">
                                                    <div className="flex flex-wrap gap-1">
                                                        {docs.map((doc) => (
                                                            <button
                                                                key={doc.key}
                                                                type="button"
                                                                onClick={() => {
                                                                    setInspectingApplicant(applicant);
                                                                    setActiveDocTab(doc.key);
                                                                }}
                                                                className={`px-2 py-1 rounded text-[10px] font-bold flex items-center gap-1 transition ${
                                                                    doc.path 
                                                                        ? 'bg-slate-100 hover:bg-slate-200 text-slate-800 border border-slate-200' 
                                                                        : 'bg-slate-50 text-slate-300 line-through'
                                                                }`}
                                                            >
                                                                <FileText className="w-3 h-3 text-[#E00D42]" />
                                                                <span>{doc.label}</span>
                                                            </button>
                                                        ))}
                                                    </div>
                                                </td>

                                                {/* Status */}
                                                <td className="py-3.5 px-4 font-mono">
                                                    {applicant.kyc_status === 'approved' ? (
                                                        <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                                            APPROVED
                                                        </span>
                                                    ) : applicant.kyc_status === 'rejected' ? (
                                                        <div className="space-y-0.5">
                                                            <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800 border border-rose-200">
                                                                REJECTED
                                                            </span>
                                                            {applicant.kyc_feedback && (
                                                                <p className="text-[10px] text-rose-600 truncate max-w-[140px]" title={applicant.kyc_feedback}>
                                                                    {applicant.kyc_feedback}
                                                                </p>
                                                            )}
                                                        </div>
                                                    ) : (
                                                        <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-200 animate-pulse">
                                                            PENDING
                                                        </span>
                                                    )}
                                                </td>

                                                {/* Actions */}
                                                <td className="py-3.5 px-4 text-right font-mono">
                                                    <div className="flex items-center justify-end gap-1.5">
                                                        <button
                                                            onClick={() => {
                                                                setInspectingApplicant(applicant);
                                                                setActiveDocTab('id');
                                                            }}
                                                            className="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-800 rounded-md font-bold text-[11px] flex items-center gap-1 transition"
                                                            title="Inspect Submitted Documents"
                                                        >
                                                            <Eye className="w-3.5 h-3.5" />
                                                            <span className="hidden sm:inline">Inspect</span>
                                                        </button>

                                                        {applicant.kyc_status !== 'approved' && (
                                                            <button
                                                                onClick={() => handleApprove(applicant)}
                                                                className="px-2.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md font-bold text-[11px] flex items-center gap-1 transition shadow-xs cursor-pointer"
                                                                title="1-Click Approve Applicant"
                                                            >
                                                                <Check className="w-3.5 h-3.5" />
                                                                <span className="hidden sm:inline">Approve</span>
                                                            </button>
                                                        )}

                                                        {applicant.kyc_status !== 'rejected' && (
                                                            <button
                                                                onClick={() => openRejectModal(applicant)}
                                                                className="px-2.5 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-md font-bold text-[11px] flex items-center gap-1 transition cursor-pointer"
                                                                title="Reject with Feedback"
                                                            >
                                                                <X className="w-3.5 h-3.5" />
                                                                <span className="hidden sm:inline">Reject</span>
                                                            </button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {applicants.links && applicants.links.length > 3 && (
                        <div className="p-4 border-t border-slate-100 flex items-center justify-between font-mono text-xs">
                            <span className="text-slate-500">
                                Showing {applicants.data.length} of {applicants.total} applicants
                            </span>
                            <div className="flex items-center gap-1">
                                {applicants.links.map((link, idx) => (
                                    <button
                                        key={idx}
                                        onClick={() => link.url && router.get(link.url)}
                                        disabled={!link.url}
                                        className={`px-3 py-1 rounded-md transition ${
                                            link.active
                                                ? 'bg-slate-900 text-white font-bold'
                                                : link.url
                                                ? 'bg-slate-100 hover:bg-slate-200 text-slate-700'
                                                : 'text-slate-300 cursor-not-allowed'
                                        }`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* 4. High-Resolution Document Inspection Modal (Portaled to root) */}
            {typeof document !== 'undefined' && inspectingApplicant && createPortal(
                <div className="fixed inset-0 z-[9999] bg-black/80 backdrop-blur-xs flex items-center justify-center p-4 sm:p-6 animate-fade-in font-sans">
                    <div className="bg-white rounded-2xl max-w-4xl w-full max-h-[92vh] flex flex-col shadow-2xl border border-slate-200 overflow-hidden">
                        {/* Modal Header */}
                        <div className="p-4 bg-slate-900 text-white flex items-center justify-between shrink-0">
                            <div className="flex items-center gap-2.5">
                                <div className="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-[#E00D42]">
                                    <Eye className="w-4 h-4" />
                                </div>
                                <div>
                                    <h3 className="font-bold text-sm">KYC Document Inspector</h3>
                                    <p className="text-xs text-slate-400">
                                        {inspectingApplicant.name} • {inspectingApplicant.email} ({inspectingApplicant.role.toUpperCase()})
                                    </p>
                                </div>
                            </div>

                            <button
                                onClick={() => setInspectingApplicant(null)}
                                className="p-1.5 text-slate-400 hover:text-white rounded-lg transition"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        {/* Modal Document Tabs */}
                        <div className="flex items-center gap-2 p-3 bg-slate-100 border-b border-slate-200 shrink-0 text-xs font-semibold">
                            <button
                                onClick={() => setActiveDocTab('id')}
                                className={`px-3 py-1.5 rounded-lg transition flex items-center gap-1.5 ${
                                    activeDocTab === 'id' 
                                        ? 'bg-white text-slate-900 shadow-xs border border-slate-300' 
                                        : 'text-slate-600 hover:text-slate-900'
                                }`}
                            >
                                <FileText className="w-3.5 h-3.5 text-[#E00D42]" />
                                <span>Government ID</span>
                            </button>

                            {(inspectingApplicant.role === 'seller' || inspectingApplicant.business_permit_path) && (
                                <button
                                    onClick={() => setActiveDocTab('permit')}
                                    className={`px-3 py-1.5 rounded-lg transition flex items-center gap-1.5 ${
                                        activeDocTab === 'permit' 
                                            ? 'bg-white text-slate-900 shadow-xs border border-slate-300' 
                                            : 'text-slate-600 hover:text-slate-900'
                                    }`}
                                >
                                    <Store className="w-3.5 h-3.5 text-purple-600" />
                                    <span>Business Permit</span>
                                </button>
                            )}

                            {(inspectingApplicant.role === 'courier' || inspectingApplicant.driver_license_path) && (
                                <button
                                    onClick={() => setActiveDocTab('license')}
                                    className={`px-3 py-1.5 rounded-lg transition flex items-center gap-1.5 ${
                                        activeDocTab === 'license' 
                                            ? 'bg-white text-slate-900 shadow-xs border border-slate-300' 
                                            : 'text-slate-600 hover:text-slate-900'
                                    }`}
                                >
                                    <Truck className="w-3.5 h-3.5 text-blue-600" />
                                    <span>Driver's License</span>
                                </button>
                            )}

                            {(inspectingApplicant.role === 'courier' || inspectingApplicant.or_cr_path) && (
                                <button
                                    onClick={() => setActiveDocTab('orcr')}
                                    className={`px-3 py-1.5 rounded-lg transition flex items-center gap-1.5 ${
                                        activeDocTab === 'orcr' 
                                            ? 'bg-white text-slate-900 shadow-xs border border-slate-300' 
                                            : 'text-slate-600 hover:text-slate-900'
                                    }`}
                                >
                                    <ShieldCheck className="w-3.5 h-3.5 text-emerald-600" />
                                    <span>Vehicle OR/CR</span>
                                </button>
                            )}
                        </div>

                        {/* Modal Body: Document Preview */}
                        <div className="flex-1 overflow-y-auto p-4 bg-slate-50 space-y-4">
                            {renderDocumentPreview(getDocPath(inspectingApplicant, activeDocTab))}

                            {/* Applicant Metadata Box */}
                            <div className="p-3 bg-white border border-slate-200 rounded-xl grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                                <div>
                                    <span className="text-[10px] text-slate-400 block uppercase font-medium">Name</span>
                                    <span className="font-semibold text-slate-900">{inspectingApplicant.name}</span>
                                </div>
                                <div>
                                    <span className="text-[10px] text-slate-400 block uppercase font-medium">Contact Phone</span>
                                    <span className="font-semibold text-slate-900">{inspectingApplicant.phone || 'N/A'}</span>
                                </div>
                                <div>
                                    <span className="text-[10px] text-slate-400 block uppercase font-medium">Address / Hub</span>
                                    <span className="font-semibold text-slate-900 truncate block">{inspectingApplicant.address || 'Metro Manila'}</span>
                                </div>
                                <div>
                                    <span className="text-[10px] text-slate-400 block uppercase font-medium">Current KYC Status</span>
                                    <span className="font-bold uppercase text-[#E00D42]">{inspectingApplicant.kyc_status}</span>
                                </div>
                            </div>
                        </div>

                        {/* Modal Footer Controls */}
                        <div className="p-4 bg-white border-t border-slate-200 flex items-center justify-between gap-3 shrink-0">
                            <button
                                type="button"
                                onClick={() => setInspectingApplicant(null)}
                                className="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-semibold transition cursor-pointer"
                            >
                                Close
                            </button>

                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => openRejectModal(inspectingApplicant)}
                                    className="px-4 py-2 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-lg text-xs font-semibold flex items-center gap-1.5 transition cursor-pointer"
                                >
                                    <X className="w-4 h-4" />
                                    <span>Reject with Reason</span>
                                </button>

                                <button
                                    type="button"
                                    onClick={() => handleApprove(inspectingApplicant)}
                                    className="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-semibold flex items-center gap-1.5 transition shadow-xs cursor-pointer"
                                >
                                    <Check className="w-4 h-4" />
                                    <span>Approve Account</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>,
                document.body
            )}

            {/* 5. Reject with Feedback Modal (Portaled to root) */}
            {typeof document !== 'undefined' && rejectingApplicant && createPortal(
                <div className="fixed inset-0 z-[9999] bg-black/80 backdrop-blur-xs flex items-center justify-center p-4 animate-fade-in font-sans">
                    <div className="bg-white rounded-2xl max-w-lg w-full p-5 shadow-2xl border border-slate-200 space-y-4">
                        <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                            <div className="flex items-center gap-2">
                                <div className="w-7 h-7 rounded-lg bg-rose-100 text-rose-600 flex items-center justify-center">
                                    <AlertTriangle className="w-4 h-4" />
                                </div>
                                <h3 className="font-bold text-sm text-slate-900">
                                    Reject Application & Provide Feedback
                                </h3>
                            </div>
                            <button
                                onClick={() => setRejectingApplicant(null)}
                                className="text-slate-400 hover:text-slate-700 p-1"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <p className="text-xs text-slate-600 leading-relaxed">
                            Specify the reason why <strong>{rejectingApplicant.name}</strong>'s KYC application is rejected. The user will be prompted to resubmit corrected documents on their pending gate screen.
                        </p>

                        {/* Quick Presets */}
                        <div className="space-y-1.5">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                                Quick Fill Presets:
                            </span>
                            <div className="flex flex-wrap gap-1">
                                {[
                                    "Blurry or unreadable document scan.",
                                    "Expired business permit or government ID.",
                                    "Name on ID does not match account applicant name.",
                                    "Vehicle OR/CR document is invalid or incomplete.",
                                    "Incomplete business address or store details.",
                                ].map((preset, idx) => (
                                    <button
                                        key={idx}
                                        type="button"
                                        onClick={() => fillPresetReason(preset)}
                                        className="text-[11px] px-2.5 py-1 rounded-md bg-slate-100 hover:bg-slate-200 text-slate-700 transition text-left cursor-pointer"
                                    >
                                        + {preset}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <form onSubmit={handleRejectSubmit} className="space-y-3">
                            <div>
                                <label className="block text-xs font-semibold text-slate-900 mb-1">
                                    Feedback & Instructions for Resubmission *
                                </label>
                                <textarea
                                    value={rejectData.reason}
                                    onChange={(e) => setRejectData('reason', e.target.value)}
                                    rows={4}
                                    className="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-xs text-slate-900 outline-hidden focus:border-rose-600 focus:bg-white transition"
                                    placeholder="Explain required corrections in detail..."
                                    required
                                />
                                {rejectErrors.reason && (
                                    <p className="text-[10px] text-rose-600 mt-1">{rejectErrors.reason}</p>
                                )}
                            </div>

                            <div className="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={() => setRejectingApplicant(null)}
                                    className="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-semibold transition cursor-pointer"
                                >
                                    Cancel
                                </button>

                                <button
                                    type="submit"
                                    disabled={rejectProcessing || rejectData.reason.length < 5}
                                    className="px-4 py-2 bg-rose-600 hover:bg-rose-700 disabled:opacity-50 text-white rounded-lg text-xs font-semibold transition flex items-center gap-1.5 cursor-pointer shadow-xs"
                                >
                                    <ShieldAlert className="w-3.5 h-3.5" />
                                    <span>{rejectProcessing ? 'Rejecting...' : 'Confirm Rejection'}</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>,
                document.body
            )}
        </DashboardLayout>
    );
}
