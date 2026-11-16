import { useState, type FormEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { Bike, Plus, UserRound, Users, X } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import InviteExistingRider from '@/Components/InviteExistingRider';
import CompanyRiderIntake from '@/Components/CompanyRiderIntake';
import { HubBadge, HubButton, HubCategoryTabs, HubEmpty, HubPageHeader, hubInput } from '@/Components/Logistics/Workspace';
import type { CompanyRiderIntake as Intake } from '@/types/riderCompany';

type JobRole = 'all' | 'intake' | 'pack_dispatch';
type Worker = { id: number; name: string; email: string; job_role: JobRole; is_you: boolean; invite_pending: boolean };
type Rider = { id: number; name: string; email: string; on_duty: boolean; barangay: string | null; invite_pending: boolean };
type Props = {
    hub: { id: number; name: string; code: string; is_bayan: boolean; company_id: number };
    workers: Worker[];
    riders: Rider[];
    riderIntake?: Intake | null;
};

export const JOBS: { value: JobRole; label: string }[] = [
    { value: 'intake', label: 'Intake' },
    { value: 'pack_dispatch', label: 'Bagging & dispatch' },
    { value: 'all', label: 'Full access' },
];

export default function HubTeam({ hub, workers, riders, riderIntake }: Props) {
    const [tab, setTab] = useState(new URLSearchParams(window.location.search).get('tab') === 'applications' ? 'applications' : 'workers');
    const [adding, setAdding] = useState<'operator' | 'rider' | 'new_rider' | null>(null);
    const applications = riderIntake && riderIntake.available ? riderIntake.requests.total : 0;
    const tabs = [
        { id: 'workers', label: 'Workers', count: workers.length },
        ...[{ id: 'riders', label: 'Riders', count: riders.length }, { id: 'applications', label: 'Rider applications', count: applications }],
    ];

    return (
        <DashboardLayout title="Team">
            <Head title="Team" />
            <div className="space-y-5">
                <HubPageHeader
                    title="Team"
                    icon={Users}
                    roleLabel={`${hub.name} · ${hub.code}`}
                    actions={tab !== 'applications' && (
                        <HubButton primary type="button" onClick={() => setAdding(tab === 'riders' ? 'rider' : 'operator')}>
                            <Plus className="h-4 w-4" aria-hidden="true" /> {tab === 'riders' ? 'Add rider' : 'Add worker'}
                        </HubButton>
                    )}
                />
                <HubCategoryTabs tabs={tabs} activeId={tab} onChange={(id) => { setTab(id); setAdding(null); }} label="Team sections" />

                {adding === 'rider' && riderIntake?.available && <>
                    <InviteExistingRider workspace={riderIntake} onClose={() => setAdding(null)} />
                    {hub.is_bayan && <button type="button" className="text-sm font-semibold text-brand-700 underline" onClick={() => setAdding('new_rider')}>Create a new rider account instead</button>}
                </>}
                {(adding === 'operator' || adding === 'new_rider') && <AddPerson kind={adding === 'operator' ? 'operator' : 'rider'} onClose={() => setAdding(null)} />}

                {tab === 'workers' && (
                    workers.length ? (
                        <ul className="divide-y divide-line-200 overflow-hidden rounded-card border border-line-200 bg-white">
                            {workers.map((worker) => <WorkerRow key={worker.id} worker={worker} />)}
                        </ul>
                    ) : <HubEmpty title="No workers yet" icon={UserRound} />
                )}

                {tab === 'riders' && (
                    riders.length ? (
                        <ul className="divide-y divide-line-200 overflow-hidden rounded-card border border-line-200 bg-white">
                            {riders.map((rider) => (
                                <li key={rider.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold text-ink-900">{rider.name}</p>
                                        <p className="truncate text-xs text-ink-600">{rider.email}{rider.barangay ? ` · ${rider.barangay}` : ''}</p>
                                    </div>
                                    <div className="flex gap-1.5">
                                        {rider.invite_pending && <HubBadge tone="warning">Invite sent</HubBadge>}
                                        <HubBadge tone={rider.on_duty ? 'success' : 'neutral'}>{rider.on_duty ? 'On duty' : 'Off duty'}</HubBadge>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ) : <HubEmpty title="No riders yet" icon={Bike} />
                )}

                {tab === 'applications' && <CompanyRiderIntake workspace={riderIntake} />}
            </div>
        </DashboardLayout>
    );
}

function WorkerRow({ worker }: { worker: Worker }) {
    const [saving, setSaving] = useState(false);
    const change = (job: JobRole) => {
        if (job === worker.job_role || saving) return;
        setSaving(true);
        router.patch(route('hub.handlers.job-role', worker.id), { job_role: job }, { preserveScroll: true, onFinish: () => setSaving(false) });
    };

    return (
        <li className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
            <div className="min-w-0">
                <p className="flex items-center gap-2 truncate text-sm font-semibold text-ink-900">
                    {worker.name}
                    {worker.is_you && <HubBadge tone="brand">You</HubBadge>}
                    {worker.invite_pending && <HubBadge tone="warning">Invite sent</HubBadge>}
                </p>
                <p className="truncate text-xs text-ink-600">{worker.email}</p>
            </div>
            {worker.is_you ? (
                <span className="text-sm font-medium text-ink-600">Full access</span>
            ) : (
                <JobPicker value={worker.job_role} onChange={change} disabled={saving} label={`Job for ${worker.name}`} />
            )}
        </li>
    );
}

function JobPicker({ value, onChange, disabled, label }: { value: JobRole; onChange: (job: JobRole) => void; disabled?: boolean; label: string }) {
    return (
        <div role="radiogroup" aria-label={label} className="inline-flex rounded-control border border-line-300 bg-white p-0.5">
            {JOBS.map((job) => (
                <button
                    key={job.value}
                    type="button"
                    role="radio"
                    aria-checked={value === job.value}
                    disabled={disabled}
                    onClick={() => onChange(job.value)}
                    className={`min-h-9 cursor-pointer rounded-[6px] px-3 text-[13px] font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500 disabled:cursor-wait ${value === job.value ? 'bg-brand-500 text-white' : 'text-ink-700 hover:bg-surface-100'}`}
                >
                    {job.label}
                </button>
            ))}
        </div>
    );
}

function AddPerson({ kind, onClose }: { kind: 'operator' | 'rider'; onClose: () => void }) {
    const form = useForm({ kind, job_role: 'intake' as JobRole, first_name: '', last_name: '', email: '', phone: '', birthday: '' });
    const errors = form.errors as Record<string, string>;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('hub.team.staff'), { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } });
    };
    const field = (name: 'first_name' | 'last_name' | 'email' | 'phone' | 'birthday', label: string, type = 'text', extra: Record<string, string> = {}) => (
        <label className="space-y-1.5 text-sm font-medium text-ink-900">
            <span>{label}</span>
            <input required type={type} value={form.data[name]} onChange={(event) => form.setData(name, event.target.value)} className={hubInput} aria-invalid={Boolean(errors[name])} {...extra} />
            {errors[name] && <span role="alert" className="block text-xs text-brand-700">{errors[name]}</span>}
        </label>
    );

    return (
        <form onSubmit={submit} className="rounded-card border border-line-200 bg-white p-5">
            <div className="mb-4 flex items-center justify-between gap-3">
                <h2 className="text-base font-semibold text-ink-900">{kind === 'rider' ? 'New rider' : 'New worker'}</h2>
                <button type="button" aria-label="Close" onClick={onClose} className="rounded-control p-2 text-ink-600 hover:bg-surface-100"><X className="h-4 w-4" /></button>
            </div>
            {kind === 'operator' && (
                <div className="mb-4 space-y-1.5">
                    <p className="text-sm font-medium text-ink-900">Job</p>
                    <JobPicker value={form.data.job_role} onChange={(job) => form.setData('job_role', job)} label="Job for the new worker" />
                </div>
            )}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {field('first_name', 'First name')}
                {field('last_name', 'Last name')}
                {field('birthday', 'Birthday', 'date')}
                {field('email', 'Email', 'email')}
                {field('phone', 'Mobile number', 'tel', { placeholder: '09XX XXX XXXX' })}
            </div>
            {Object.entries(errors).filter(([key]) => !['first_name', 'last_name', 'email', 'phone', 'birthday'].includes(key)).map(([key, message]) => (
                <p key={key} role="alert" className="mt-3 text-sm text-brand-700">{message}</p>
            ))}
            <div className="mt-5 flex gap-2">
                <HubButton primary type="submit" disabled={form.processing}>{form.processing ? 'Adding…' : kind === 'rider' ? 'Add rider' : 'Add worker'}</HubButton>
                <HubButton type="button" onClick={onClose}>Cancel</HubButton>
            </div>
        </form>
    );
}
