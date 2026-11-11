import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { Building2, CheckCircle2, CircleDollarSign, KeyRound, MapPin, Plus, Settings2, Users, Warehouse, UserRound } from 'lucide-react';
import { CoverageEditor, TemporaryLoginEditor } from '@/Components/Logistics/SetupEditors';
import DashboardLayout from '@/Layouts/DashboardLayout';
import HubMap from '@/Components/Logistics/HubMap';
import HubPicker from '@/Components/Logistics/HubPicker';
import HubLocationEditor from '@/Components/Logistics/HubLocationEditor';
import LocationPinPicker from '@/Components/LocationPinPicker';
import { HubBadge, HubButton, HubPanel, HubPageHeader, HubSearch, hubInput } from '@/Components/Logistics/Workspace';

type Hub = { latitude: number | null; longitude: number | null; address: string; id: number; name: string; code: string; tier: string; city_municipality: string; province: string; parent_hub_id: number | null; postal_codes: string[] | null; coverage_token: string; location_token: string; is_active: boolean };
type Props = { companies: { id: number; name: string }[]; company: { id: number; name: string; base_shipping_cents: number; included_weight_grams: number; additional_kg_cents: number; shipping_earnings_model: string; tariff_version: number }; canProvision: boolean; hubs: Hub[]; staff: { id: number; name: string; email: string; kind: string; hub_name: string; must_change_password: boolean; email_verified_at: string | null }[]; basePath: string };
function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return (
        <label className="block text-xs font-semibold text-[#121316]">
            {label}
            <div className="mt-1.5">{children}</div>
            {error && <span role="alert" className="mt-1.5 block text-xs font-normal text-rose-700">{error}</span>}
        </label>
    );
}

export function ShippingEarningsPreview({ baseFee, model }: { baseFee: string; model: string }) {
    const baseCents = /^\d{1,6}(?:\.\d{1,2})?$/.test(baseFee) ? Math.round(Number(baseFee) * 100) : null;
    const pickup = baseCents !== null && model === 'dedicated_fleet' ? Math.floor(baseCents / 6) : 0;
    const delivery = baseCents === null ? 0 : model === 'dedicated_fleet' ? Math.floor(baseCents * 5 / 12) : Math.floor(baseCents / 2);
    const peso = (cents: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(cents / 100);

    return <p aria-live="polite">{baseCents === null ? 'Enter a valid base fee to preview its shares.'
        : `${peso(baseCents)} base fee: ${peso(baseCents - pickup - delivery)} company, ${peso(delivery)} delivery rider${pickup > 0 ? ` and ${peso(pickup)} pickup rider` : ''}.`}</p>;
}

export default function CompanySetup({ company, companies, canProvision, hubs, staff, basePath }: Props) {
    const [tab, setTab] = useState('network');
    const [search, setSearch] = useState('');
    const [selectedHubId, setSelectedHubId] = useState<number | null>(null);
    const matching = (text: string) => text.toLowerCase().includes(search.trim().toLowerCase());
    const [editingHub, setEditingHub] = useState<Hub | null>(null);
    const [resetPerson, setResetPerson] = useState<Props['staff'][number] | null>(null);
    const [creating, setCreating] = useState(false);
    const [postalText, setPostalText] = useState('');
    const facility = useForm({ name: '', code: '', tier: 'local_bayan_hub', parent_hub_id: '', province: '', city_municipality: '', barangay: '', address: '', latitude: null as number | null, longitude: null as number | null, postal_codes: [] as string[], coverage_barangays: [] as string[], capacity: 500, allows_self_pickup: true, operating_hours: '' });
    const worker = useForm({ kind: 'operator', hub_id: '', first_name: '', last_name: '', birthday: '', email: '', phone: '' });
    const tariff = useForm({ version: company.tariff_version, base_fee: (company.base_shipping_cents / 100).toFixed(2), additional_kg_fee: (company.additional_kg_cents / 100).toFixed(2), included_weight_grams: company.included_weight_grams, shipping_earnings_model: company.shipping_earnings_model });
    const sharedErrors = [...Object.entries(facility.errors), ...Object.entries(worker.errors), ...Object.entries(tariff.errors)].filter(([key]) => !Object.keys(facility.data).includes(key) && !Object.keys(worker.data).includes(key) && !Object.keys(tariff.data).includes(key));
    const tabs = [{ key: 'network', label: 'Hub network', icon: Warehouse }, { key: 'staff', label: 'People & access', icon: Users }, { key: 'rates', label: 'Shipping & earnings', icon: CircleDollarSign }];
    const activeHubCount = hubs.filter(hub => hub.is_active).length;
    const riderCount = staff.filter(person => person.kind === 'rider').length;

    return (
        <DashboardLayout title="Company setup">
            <Head title="Company setup" />
            <div className="space-y-6">
                <HubPageHeader
                    title="Company setup"
                    icon={Building2}
                    description={`Manage ${company.name} facilities, named work accounts and future shipping quotes.`}
                    actions={companies.length > 1 && (
                        <select aria-label="Select company" className={`${hubInput} max-w-xs`} value={company.id} onChange={event => router.get(basePath, { company_id: event.target.value })}>
                            {companies.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
                        </select>
                    )}
                />

                <div className="grid grid-cols-2 divide-x divide-y divide-zinc-200/80 overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs lg:grid-cols-4 lg:divide-y-0">
                    {[
                        { label: 'Hubs', value: hubs.length, Icon: Warehouse },
                        { label: 'Active hubs', value: activeHubCount, Icon: CheckCircle2 },
                        { label: 'Work accounts', value: staff.length, Icon: Users },
                        { label: 'Riders', value: riderCount, Icon: UserRound },
                    ].map(({ label, value, Icon }) => (
                        <div key={label} className="p-4 sm:p-5">
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-xs font-semibold text-zinc-500">{label}</span>
                                <span className="flex h-7 w-7 items-center justify-center rounded-md border border-zinc-200/80 bg-zinc-50 text-zinc-600">
                                    <Icon className="h-3.5 w-3.5" />
                                </span>
                            </div>
                            <p className="mt-2 tabular-nums text-2xl font-bold tabular-nums text-[#121316]">{value}</p>
                        </div>
                    ))}
                </div>

                <div className="flex gap-1 overflow-x-auto rounded-xl border border-zinc-200/90 bg-zinc-100/70 p-1">
                    {tabs.map(({ key, label, icon: Icon }) => (
                        <button
                            key={key}
                            aria-pressed={tab === key}
                            onClick={() => { setTab(key); setCreating(false); setSearch(''); }}
                            className={`inline-flex flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-lg px-4 py-2 text-xs font-semibold transition ${
                                tab === key ? 'bg-white text-[#121316] shadow-2xs ring-1 ring-zinc-200/80' : 'text-zinc-500 hover:text-[#121316]'
                            }`}
                        >
                            <Icon className="h-4 w-4" />
                            {label}
                        </button>
                    ))}
                </div>

                {sharedErrors.map(([key, value]) => <p key={key} role="alert" className="rounded-xl border border-rose-200/90 bg-rose-50/80 p-4 text-sm text-rose-800">{value}</p>)}

                {tab === 'network' && (
                    <>
                        <HubPanel
                            title="Physical facilities"
                            description="Buyers can choose your approved company when active child hubs cover both the seller and delivery postal codes. Link each child to an active Mother Hub for transfers between hubs, and keep postal coverage distinct."
                            icon={<Building2 className="h-4 w-4" />}
                        >
                            <div className="mb-4 flex items-center justify-between gap-4">
                                <span className="text-xs font-semibold text-zinc-500">{hubs.length} facilities</span>
                                {canProvision && (
                                    <HubButton onClick={() => setCreating(!creating)}>
                                        <Plus className="h-4 w-4" />
                                        {creating ? 'Close form' : 'Add hub'}
                                    </HubButton>
                                )}
                            </div>
                            <div className="mb-5 grid gap-4 lg:grid-cols-[1fr_260px]">
                                <HubMap
                                    hubs={hubs}
                                    selectedId={selectedHubId}
                                    onSelect={hub => {
                                        setSelectedHubId(hub.id ?? null);
                                        document.getElementById(`setup-hub-${hub.id}`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                                    }}
                                    className="min-h-72"
                                />
                                <div className="space-y-3 rounded-xl border border-zinc-200/90 bg-zinc-50/60 p-4">
                                    <span className="flex h-8 w-8 items-center justify-center rounded-md border border-zinc-200/80 bg-white text-zinc-600 shadow-2xs">
                                        <Warehouse className="h-4 w-4" />
                                    </span>
                                    <h3 className="text-sm font-semibold text-[#121316]">One connected company</h3>
                                    <p className="text-xs leading-relaxed text-zinc-500">Mother Hubs connect the company network. Each child hub has its own Mother Hub parent and postal coverage.</p>
                                    <p className="text-xs leading-relaxed text-zinc-500">Choose a saved map pin to highlight its facility below. Hubs without coordinates stay in the address list.</p>
                                </div>
                            </div>

                            <HubSearch label="Find a company hub" value={search} onChange={setSearch} placeholder="Hub name, town or postal code" />

                            <div className="mt-4 grid gap-3 md:grid-cols-2">
                                {hubs.filter(hub => matching([hub.name, hub.code, hub.city_municipality, ...(hub.postal_codes ?? [])].join(' '))).map(hub => (
                                    <article
                                        key={hub.id}
                                        id={`setup-hub-${hub.id}`}
                                        className={`rounded-xl border p-4 transition-colors ${
                                            selectedHubId === hub.id ? 'border-[#E00D42] bg-rose-50/30 ring-1 ring-[#E00D42]/15' : 'border-zinc-200/90 bg-white'
                                        }`}
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="flex min-w-0 items-start gap-2.5">
                                                <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-zinc-200/80 bg-zinc-50 text-zinc-600">
                                                    {hub.tier === 'regional_mother_hub' ? <Warehouse className="h-4 w-4" /> : <Building2 className="h-4 w-4" />}
                                                </span>
                                                <div>
                                                    <h3 className="text-sm font-semibold text-[#121316]">{hub.name}</h3>
                                                    <p className="mt-0.5 text-xs text-zinc-500"><span className="tabular-nums">{hub.code}</span> · {hub.city_municipality}</p>
                                                </div>
                                            </div>
                                            <HubBadge tone={hub.is_active ? 'success' : 'warning'}>{hub.is_active ? 'Active' : 'Inactive'}</HubBadge>
                                        </div>
                                        <p className="mt-3 text-xs leading-5 text-zinc-600">
                                            {hub.tier === 'regional_mother_hub' ? 'Mother Hub' : `Child of ${hubs.find(item => item.id === hub.parent_hub_id)?.name ?? 'existing province route'}`}
                                        </p>
                                        <p className="mt-1.5 text-xs leading-5 text-zinc-500">Postal coverage: {hub.postal_codes?.join(', ') || 'Existing city coverage'}</p>
                                        {canProvision && (
                                            <div className="mt-4 flex flex-wrap gap-2">
                                                <HubButton onClick={() => setSelectedHubId(hub.id)}>Edit map pin</HubButton>
                                                {hub.tier === 'local_bayan_hub' && <HubButton onClick={() => setEditingHub(hub)}>Edit coverage</HubButton>}
                                            </div>
                                        )}
                                        {canProvision && selectedHubId === hub.id && <HubLocationEditor key={hub.location_token} hub={hub} url={`${basePath}/${company.id}/hubs/${hub.id}/location`} />}
                                    </article>
                                ))}
                            </div>
                            {hubs.length === 0 && <p className="py-12 text-center text-xs text-zinc-500">Create a Mother Hub first, then its local child hubs.</p>}
                            {hubs.length > 0 && !hubs.some(hub => matching([hub.name, hub.code, hub.city_municipality, ...(hub.postal_codes ?? [])].join(' '))) && (
                                <p className="py-8 text-center text-xs text-zinc-500">No facilities match your search.</p>
                            )}
                        </HubPanel>

                        {editingHub && canProvision && (
                            <CoverageEditor key={editingHub.id} hub={editingHub} hubs={hubs} url={`${basePath}/${company.id}/hubs/${editingHub.id}/coverage`} onClose={() => setEditingHub(null)} />
                        )}

                        {creating && canProvision && (
                            <HubPanel title="Create a physical hub" icon={<MapPin className="h-4 w-4" />}>
                                <form
                                    onSubmit={event => {
                                        event.preventDefault();
                                        facility.post(`${basePath}/${company.id}/hubs`, {
                                            preserveScroll: true,
                                            onSuccess: () => { facility.reset(); setPostalText(''); setCreating(false); },
                                        });
                                    }}
                                    className="grid gap-4 md:grid-cols-2"
                                >
                                    <Field label="Facility name" error={facility.errors.name}>
                                        <input required value={facility.data.name} onChange={event => facility.setData('name', event.target.value)} className={hubInput} />
                                    </Field>
                                    <Field label="Unique facility code" error={facility.errors.code}>
                                        <input required value={facility.data.code} onChange={event => facility.setData('code', event.target.value.toUpperCase())} className={hubInput} placeholder="CH-PAGS-01" />
                                    </Field>
                                    <Field label="Hub type" error={facility.errors.tier}>
                                        <select value={facility.data.tier} onChange={event => facility.setData('tier', event.target.value)} className={hubInput}>
                                            <option value="regional_mother_hub">Mother Hub</option>
                                            <option value="local_bayan_hub">Child Hub</option>
                                        </select>
                                    </Field>
                                    {facility.data.tier === 'local_bayan_hub' && (
                                        <HubPicker
                                            label="Parent Mother Hub"
                                            error={facility.errors.parent_hub_id}
                                            hubs={hubs.filter(hub => hub.tier === 'regional_mother_hub' && hub.is_active)}
                                            value={facility.data.parent_hub_id}
                                            onChange={value => facility.setData('parent_hub_id', value)}
                                        />
                                    )}
                                    {(['province', 'city_municipality', 'barangay', 'address'] as const).map(field => (
                                        <Field
                                            key={field}
                                            label={{ province: 'Province', city_municipality: 'City or municipality', barangay: 'Barangay', address: 'Physical street address' }[field]}
                                            error={facility.errors[field]}
                                        >
                                            <input
                                                required={field !== 'barangay'}
                                                value={facility.data[field]}
                                                onChange={event => {
                                                    const value = event.target.value;
                                                    facility.setData(previous => ({ ...previous, [field]: value, latitude: null, longitude: null }));
                                                }}
                                                className={hubInput}
                                            />
                                        </Field>
                                    ))}
                                    <div className="space-y-2 md:col-span-2">
                                        <p className="text-xs font-semibold text-[#121316]">Physical hub map pin *</p>
                                        <LocationPinPicker
                                            label="New hub location"
                                            value={facility.data.latitude !== null && facility.data.longitude !== null ? { latitude: facility.data.latitude, longitude: facility.data.longitude } : null}
                                            onChange={point => facility.setData(previous => ({ ...previous, ...point }))}
                                        />
                                        {(facility.errors.latitude || facility.errors.longitude) && <p role="alert" className="text-xs text-rose-700">{facility.errors.latitude || facility.errors.longitude}</p>}
                                    </div>
                                    <Field label="Served postal codes, separated by commas" error={facility.errors.postal_codes}>
                                        <input
                                            value={postalText}
                                            onChange={event => {
                                                setPostalText(event.target.value);
                                                facility.setData('postal_codes', event.target.value.split(',').map(value => value.trim()).filter(Boolean));
                                            }}
                                            className={hubInput}
                                            placeholder="4008, 4009"
                                        />
                                    </Field>
                                    <Field label="Parcel capacity" error={facility.errors.capacity}>
                                        <input required type="number" min={1} max={1000000} value={facility.data.capacity} onChange={event => facility.setData('capacity', Number(event.target.value))} className={hubInput} />
                                    </Field>
                                    <Field label="Actual collection hours" error={facility.errors.operating_hours}>
                                        <input value={facility.data.operating_hours} onChange={event => facility.setData('operating_hours', event.target.value)} className={hubInput} placeholder="Monday to Saturday, 8 AM to 5 PM" />
                                    </Field>
                                    <label className="flex items-center gap-2 self-end pb-3 text-xs font-medium text-zinc-700">
                                        <input type="checkbox" checked={facility.data.allows_self_pickup} onChange={event => facility.setData('allows_self_pickup', event.target.checked)} className="rounded border-zinc-300 text-[#E00D42]" />
                                        Allow buyer self-pickup
                                    </label>
                                    <div className="md:col-span-2">
                                        <HubButton primary disabled={facility.processing}>Create hub</HubButton>
                                    </div>
                                </form>
                            </HubPanel>
                        )}
                    </>
                )}

                {tab === 'staff' && (
                    <>
                        <HubPanel title="Named work accounts" description="Your company creates each account with one fixed role and an assigned physical hub." icon={<Users className="h-4 w-4" />}>
                            <div className="mb-4 flex items-center justify-between">
                                <span className="text-xs font-semibold text-zinc-500">{staff.length} company-created accounts</span>
                                {canProvision && (
                                    <HubButton onClick={() => setCreating(!creating)}>
                                        <Plus className="h-4 w-4" />
                                        {creating ? 'Close form' : 'Create account'}
                                    </HubButton>
                                )}
                            </div>
                            <HubSearch label="Find a work account" value={search} onChange={setSearch} placeholder="Person, email or hub" />
                            <div className="mt-4 grid gap-3 md:grid-cols-2">
                                {staff.filter(person => matching([person.name, person.email, person.hub_name].join(' '))).map(person => (
                                    <article key={person.id} className="rounded-xl border border-zinc-200/90 bg-white p-4">
                                        <div className="flex items-start gap-3">
                                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-zinc-200/80 bg-zinc-50 text-zinc-500">
                                                <UserRound className="h-4 w-4" />
                                            </span>
                                            <div className="min-w-0">
                                                <h3 className="text-sm font-semibold text-[#121316]">{person.name}</h3>
                                                <p className="mt-0.5 break-words text-xs text-zinc-500">{person.email}</p>
                                            </div>
                                        </div>
                                        <p className="mt-3 text-xs text-zinc-600">{person.kind === 'operator' ? 'Hub operator' : 'Rider'} · {person.hub_name}</p>
                                        <div className="mt-3">
                                            <HubBadge tone={person.must_change_password || !person.email_verified_at ? 'warning' : 'success'}>
                                                {person.must_change_password ? 'Invitation pending' : !person.email_verified_at ? 'Verify email' : 'Activated'}
                                            </HubBadge>
                                        </div>
                                        {canProvision && person.must_change_password && (
                                            <div className="mt-4">
                                                <HubButton onClick={() => setResetPerson(person)}>Resend invitation</HubButton>
                                            </div>
                                        )}
                                    </article>
                                ))}
                            </div>
                            {!staff.some(person => matching([person.name, person.email, person.hub_name].join(' '))) && (
                                <p className="py-10 text-center text-xs text-zinc-500">{staff.length ? 'No work accounts match your search.' : 'Create a named operator or rider for an active hub.'}</p>
                            )}
                        </HubPanel>

                        {resetPerson && canProvision && (
                            <TemporaryLoginEditor key={resetPerson.id} person={resetPerson} url={`${basePath}/${company.id}/staff/${resetPerson.id}/temporary-password`} onClose={() => setResetPerson(null)} />
                        )}

                        {creating && canProvision && (
                            <HubPanel title="Create a work account" description="A setup code is emailed directly to the worker. They review the assignment and set their own password." icon={<KeyRound className="h-4 w-4" />}>
                                <form
                                    onSubmit={event => {
                                        event.preventDefault();
                                        worker.post(`${basePath}/${company.id}/staff`, {
                                            preserveScroll: true,
                                            onSuccess: () => { worker.reset(); setCreating(false); },
                                        });
                                    }}
                                    className="grid gap-4 md:grid-cols-2"
                                >
                                    <Field label="Work role" error={worker.errors.kind}>
                                        <select value={worker.data.kind} onChange={event => worker.setData('kind', event.target.value)} className={hubInput}>
                                            <option value="operator">Hub operator</option>
                                            <option value="rider">Pickup / delivery rider</option>
                                        </select>
                                    </Field>
                                    <HubPicker
                                        label="Assigned physical hub"
                                        error={worker.errors.hub_id}
                                        hubs={hubs.filter(hub => hub.is_active && (worker.data.kind === 'operator' || hub.tier === 'local_bayan_hub'))}
                                        value={worker.data.hub_id}
                                        onChange={value => worker.setData('hub_id', value)}
                                    />
                                    {(['first_name', 'last_name', 'birthday', 'email', 'phone'] as const).map(field => (
                                        <Field
                                            key={field}
                                            label={{ first_name: 'First name', last_name: 'Last name', birthday: 'Birth date (18 or older)', email: 'Sign-in email', phone: 'Mobile number' }[field]}
                                            error={worker.errors[field]}
                                        >
                                            <input
                                                required
                                                type={field === 'birthday' ? 'date' : field === 'email' ? 'email' : 'text'}
                                                maxLength={field === 'email' ? 255 : field === 'phone' ? 20 : field === 'birthday' ? undefined : 100}
                                                autoComplete={field === 'email' ? 'email' : field === 'phone' ? 'tel' : 'off'}
                                                value={worker.data[field]}
                                                onChange={event => worker.setData(field, event.target.value)}
                                                className={hubInput}
                                            />
                                        </Field>
                                    ))}
                                    <p className="text-xs leading-5 text-zinc-600 md:col-span-2">Use an email the worker can access. Invitations last seven days; each setup code expires after 10 minutes.</p>
                                    <div className="md:col-span-2">
                                        <HubButton primary disabled={worker.processing}>Create account & email invitation</HubButton>
                                    </div>
                                </form>
                            </HubPanel>
                        )}
                    </>
                )}

                {tab === 'rates' && (
                    <div className="grid items-start gap-6 lg:grid-cols-[1fr_300px]">
                        <HubPanel title="Shipping tariff" description="New quotes use these terms. Existing purchased parcels retain their saved amounts." icon={<Settings2 className="h-4 w-4" />}>
                            <form
                                onSubmit={event => {
                                    event.preventDefault();
                                    tariff.post(`${basePath}/${company.id}/tariff`, {
                                        preserveScroll: true,
                                        onSuccess: () => tariff.setData('version', company.tariff_version + 1),
                                    });
                                }}
                                className="space-y-4"
                            >
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field label="Base shipping fee (PHP)" error={tariff.errors.base_fee}>
                                        <input required inputMode="decimal" value={tariff.data.base_fee} onChange={event => tariff.setData('base_fee', event.target.value)} className={hubInput} />
                                    </Field>
                                    <Field label="Included weight (grams)" error={tariff.errors.included_weight_grams}>
                                        <input required type="number" min={1} value={tariff.data.included_weight_grams} onChange={event => tariff.setData('included_weight_grams', Number(event.target.value))} className={hubInput} />
                                    </Field>
                                    <Field label="Each additional started kg (PHP)" error={tariff.errors.additional_kg_fee}>
                                        <input required inputMode="decimal" value={tariff.data.additional_kg_fee} onChange={event => tariff.setData('additional_kg_fee', event.target.value)} className={hubInput} />
                                    </Field>
                                    <Field label="Shipping earnings model" error={tariff.errors.shipping_earnings_model}>
                                        <select value={tariff.data.shipping_earnings_model} onChange={event => tariff.setData('shipping_earnings_model', event.target.value)} className={hubInput}>
                                            <option value="standard">Standard: 50% company / 50% delivery</option>
                                            <option value="dedicated_fleet">Dedicated: pickup, delivery and company shares</option>
                                        </select>
                                    </Field>
                                </div>
                                <HubButton primary disabled={tariff.processing}>Save future shipping rates</HubButton>
                            </form>
                        </HubPanel>

                        <HubPanel title="How amounts are calculated" icon={<CircleDollarSign className="h-4 w-4" />}>
                            <div className="space-y-3 text-xs leading-relaxed text-zinc-600">
                                <p>Volumetric kg = length × width × height ÷ 3,500.</p>
                                <p>Billable weight is the greater of actual and volumetric weight.</p>
                                <ShippingEarningsPreview baseFee={tariff.data.base_fee} model={tariff.data.shipping_earnings_model} />
                                <p>Product proceeds remain 90% seller and 10% Bagoo. COD cash stays separate from earned amounts.</p>
                            </div>
                        </HubPanel>
                    </div>
                )}
            </div>
        </DashboardLayout>
    );
}
