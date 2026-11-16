import { useMemo, useState } from 'react';
import { Head } from '@inertiajs/react';
import { ChevronRight, Search } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import HubMap from '@/Components/Logistics/HubMap';
import PortalLink from '@/Components/PortalLink';
import { Badge, inputClasses } from '@/Components/ui';

type Company = {
    id: number; name: string; code: string; status: string; is_active: boolean; owner: string | null; base_shipping_cents: number;
    hubs: number; active_hubs: number; mother_hubs: number; workers: number; riders: number;
};
type Hub = { id: number; logistics_company_id: number; name: string; code: string; tier: string; province: string | null; city_municipality: string | null; parent_hub_id: number | null; is_active: boolean; latitude: number | null; longitude: number | null };

// One colour per company on the map; red stays reserved for actions.
const PALETTE = ['#1D4ED8', '#0F766E', '#B45309', '#7C3AED', '#BE185D', '#4D7C0F', '#0E7490', '#9A3412'];
const peso = (cents: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(cents / 100);

export default function CompanyDirectory({ companies, hubs }: { companies: Company[]; hubs: Hub[] }) {
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<'all' | 'active' | 'inactive'>('all');
    const [shown, setShown] = useState<number[]>(companies.map(company => company.id));
    const colour = useMemo(() => new Map(companies.map((company, index) => [company.id, PALETTE[index % PALETTE.length]])), [companies]);
    const term = search.trim().toLowerCase();
    const visible = companies.filter(company => (status === 'all' || (status === 'active') === (company.is_active && company.status === 'active'))
        && (!term || [company.name, company.code, company.owner ?? ''].some(text => text.toLowerCase().includes(term))));
    const mapHubs = hubs.filter(hub => shown.includes(hub.logistics_company_id) && visible.some(company => company.id === hub.logistics_company_id));
    const toggle = (id: number) => setShown(current => current.includes(id) ? current.filter(value => value !== id) : [...current, id]);
    const totals = { hubs: hubs.length, active: hubs.filter(hub => hub.is_active).length };

    return (
        <DashboardLayout title="Logistics companies">
            <Head title="Logistics companies" />
            <div className="space-y-4">
                <div>
                    <h1 className="text-[28px] font-bold leading-[34px] text-ink-900">Logistics companies</h1>
                    <p className="mt-1 text-sm text-ink-600">
                        <span className="tabular-nums">{companies.length}</span> companies · <span className="tabular-nums">{totals.hubs}</span> hubs (<span className="tabular-nums">{totals.active}</span> active)
                    </p>
                </div>

                <div className="grid grid-cols-1 gap-3 rounded-card border border-line-200 bg-white p-3 sm:grid-cols-[minmax(0,1fr)_200px]">
                    <div className="relative">
                        <label htmlFor="company-search" className="sr-only">Search companies</label>
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" />
                        <input id="company-search" type="search" value={search} onChange={event => setSearch(event.target.value)} maxLength={100}
                            placeholder="Search by company, code or owner" className={`${inputClasses} pl-9`} />
                    </div>
                    <div>
                        <label htmlFor="company-status" className="sr-only">Status</label>
                        <select id="company-status" value={status} onChange={event => setStatus(event.target.value as typeof status)} className={inputClasses}>
                            <option value="all">All statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive or suspended</option>
                        </select>
                    </div>
                </div>

                <section aria-label="Hub map" className="space-y-3 rounded-card border border-line-200 bg-white p-4">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-[13px] font-medium text-ink-700">Show on map:</span>
                        {visible.map(company => (
                            <button key={company.id} type="button" onClick={() => toggle(company.id)} aria-pressed={shown.includes(company.id)}
                                className={`inline-flex h-8 items-center gap-2 rounded-full border px-3 text-[13px] font-medium ${shown.includes(company.id) ? 'border-ink-400 bg-white text-ink-900' : 'border-line-200 bg-surface-100 text-ink-500 line-through'}`}>
                                <span aria-hidden="true" className="h-2.5 w-2.5 rounded-full" style={{ background: colour.get(company.id) }} />
                                {company.code} <span className="tabular-nums text-ink-500">{company.hubs}</span>
                            </button>
                        ))}
                    </div>
                    <HubMap hubs={mapHubs} className="h-[420px]" colorFor={hub => hub.logistics_company_id ? colour.get(hub.logistics_company_id) : undefined} />
                    <p className="text-xs text-ink-600">Larger pins are mother hubs. Dashed lines link each hub to its mother hub.</p>
                </section>

                <div className="overflow-hidden rounded-card border border-line-200 bg-white">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[760px] text-left text-sm">
                            <thead className="border-b border-line-200 bg-surface-50 text-xs font-medium uppercase tracking-wide text-ink-600">
                                <tr>
                                    <th scope="col" className="px-4 py-3">Company</th>
                                    <th scope="col" className="px-4 py-3">Status</th>
                                    <th scope="col" className="px-4 py-3 text-right">Hubs</th>
                                    <th scope="col" className="px-4 py-3 text-right">Hub workers</th>
                                    <th scope="col" className="px-4 py-3 text-right">Riders</th>
                                    <th scope="col" className="px-4 py-3 text-right">Base shipping</th>
                                    <th scope="col" className="w-10 px-4 py-3"><span className="sr-only">Open</span></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line-200">
                                {visible.length === 0 ? (
                                    <tr><td colSpan={7} className="px-4 py-10 text-center text-sm text-ink-600">No companies match. Clear the search or status filter.</td></tr>
                                ) : visible.map(company => (
                                    <tr key={company.id} className="hover:bg-surface-50">
                                        <td className="px-4 py-3">
                                            <PortalLink href={`/hub/company-setup?company_id=${company.id}`} className="flex items-center gap-3 font-semibold text-ink-900 hover:underline">
                                                <span aria-hidden="true" className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: colour.get(company.id) }} />
                                                <span className="min-w-0">
                                                    <span className="block truncate">{company.name}</span>
                                                    <span className="block text-xs font-normal text-ink-600">{company.code}{company.owner ? ` · Owner ${company.owner}` : ''}</span>
                                                </span>
                                            </PortalLink>
                                        </td>
                                        <td className="px-4 py-3"><Badge tone={company.is_active && company.status === 'active' ? 'success' : 'neutral'}>{company.is_active && company.status === 'active' ? 'Active' : company.status.replace(/_/g, ' ')}</Badge></td>
                                        <td className="px-4 py-3 text-right tabular-nums text-ink-900">{company.hubs}<span className="block text-xs text-ink-500">{company.mother_hubs} mother · {company.active_hubs} active</span></td>
                                        <td className="px-4 py-3 text-right tabular-nums text-ink-900">{company.workers}</td>
                                        <td className="px-4 py-3 text-right tabular-nums text-ink-900">{company.riders}</td>
                                        <td className="px-4 py-3 text-right tabular-nums text-ink-900">{peso(company.base_shipping_cents)}</td>
                                        <td className="px-4 py-3 text-right">
                                            <PortalLink href={`/hub/company-setup?company_id=${company.id}`} aria-label={`Open ${company.name}`} className="inline-flex h-8 w-8 items-center justify-center rounded-control text-ink-500 hover:bg-surface-100 hover:text-ink-900">
                                                <ChevronRight className="h-4 w-4" />
                                            </PortalLink>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </DashboardLayout>
    );
}
