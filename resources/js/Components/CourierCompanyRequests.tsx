import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { Building2, Check, MapPin, Search, Send } from 'lucide-react';
import { CourierAvatar, CourierBadge, CourierDialog, CourierEmpty, CourierFieldError, CourierPanel, courierButton, courierInput, courierPrimary } from '@/Components/CourierUI';
import CompanyRequestPagination from '@/Components/CompanyRequestPagination';
import useCourierRequestError from '@/hooks/useCourierRequestError';
import { courierDate, courierPath } from '@/utils/courier';
import { companyAreas, companyTextError } from '@/utils/riderCompany';
import type { CompanyJoining, CompanyJoinRequest, RiderCompany, SharedRiderProfile } from '@/types/riderCompany';

export function SharedCompanyProfile({ profile }: { profile: SharedRiderProfile }) {
    return <dl className="grid gap-3 text-sm sm:grid-cols-2">
        {[
            ['Name', profile.name], ['Sign-in email', profile.email], ['Contact number', profile.phone],
            ['Locality', [profile.city, profile.province].filter(Boolean).join(', ')],
            ['Vehicle type', profile.vehicle_type?.replaceAll('_', ' ')], ['Identity review', 'Approved'],
        ].map(([label, value]) => <div key={label}><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 break-words font-medium text-slate-900">{value || 'Not provided'}</dd></div>)}
    </dl>;
}

export default function CourierCompanyRequests({ workspace }: { workspace?: CompanyJoining }) {
    if (!workspace?.available) return <CourierPanel className="mt-5 p-6 text-center"><h2 className="font-semibold">Company requests unavailable</h2><p className="mt-2 text-sm text-slate-600">Please try again later. Your current assignment stays available above.</p></CourierPanel>;
    return <AvailableCompanyRequests workspace={workspace} />;
}

function AvailableCompanyRequests({ workspace }: { workspace: Extract<CompanyJoining, { available: true }> }) {
    const [company, setCompany] = useState<RiderCompany | null>(null);
    const [withdrawing, setWithdrawing] = useState<CompanyJoinRequest | null>(null);
    const [search, setSearch] = useState(workspace.filters.company_search);
    const [province, setProvince] = useState(workspace.filters.company_province);
    const [filterBusy, setFilterBusy] = useState(false);
    const [filterError, setFilterError] = useState('');
    const [unconfirmed, setUnconfirmed] = useState(false);
    const [withdrawalUnconfirmed, setWithdrawalUnconfirmed] = useState(false);
    const submissionPending = useRef(false);
    const withdrawalPending = useRef(false);
    const application = useForm({ company_id: '', intake_version: 0, note: '', sharing_acknowledged: false, request_token: workspace.request_token });
    const withdrawal = useForm({ expected_version: 1, request_token: '' });
    useCourierRequestError(submissionPending, (message) => { setUnconfirmed(true); application.setError('company_id', message); });
    useCourierRequestError(withdrawalPending, (message) => { setWithdrawalUnconfirmed(true); withdrawal.setError('expected_version', message); });
    useEffect(() => { setSearch(workspace.filters.company_search); setProvince(workspace.filters.company_province); }, [workspace.filters.company_search, workspace.filters.company_province]);
    const busy = application.processing || withdrawal.processing || filterBusy;
    const noteError = companyTextError(application.data.note, 5, 500);
    const applicationErrors = application.errors as Record<string, string>;
    const withdrawalErrors = withdrawal.errors as Record<string, string>;
    const historyUrl = `${courierPath('/profile')}?tab=assignment`;
    const openApplication = (choice: RiderCompany) => {
        if (busy) return;
        application.clearErrors();
        application.setData({ company_id: choice.id, intake_version: choice.intake_version, note: '', sharing_acknowledged: false, request_token: workspace.request_token });
        setUnconfirmed(false);
        setCompany(choice);
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (submissionPending.current || !company || noteError || !application.data.sharing_acknowledged) return;
        submissionPending.current = true;
        application.post(courierPath('/company-requests'), {
            preserveScroll: true,
            onSuccess: () => { setCompany(null); setUnconfirmed(false); application.reset(); },
            onError: () => setUnconfirmed(false),
            onFinish: () => { submissionPending.current = false; },
        });
    };
    const openWithdrawal = (request: CompanyJoinRequest) => {
        if (busy || !request.withdrawal_token) return;
        withdrawal.clearErrors();
        withdrawal.setData({ expected_version: request.version, request_token: request.withdrawal_token });
        setWithdrawalUnconfirmed(false);
        setWithdrawing(request);
    };
    const withdraw = (event: FormEvent) => {
        event.preventDefault();
        if (withdrawalPending.current || !withdrawing) return;
        withdrawalPending.current = true;
        withdrawal.post(courierPath(`/company-requests/${withdrawing.id}/withdraw`), {
            preserveScroll: true,
            onSuccess: () => { setWithdrawing(null); setWithdrawalUnconfirmed(false); },
            onError: () => setWithdrawalUnconfirmed(false),
            onFinish: () => { withdrawalPending.current = false; },
        });
    };
    const filterCompanies = (event: FormEvent) => {
        event.preventDefault();
        const error = companyTextError(search, 1, 100);
        setFilterError(error ?? '');
        if (error || busy) return;
        setFilterBusy(true);
        router.get(courierPath('/profile'), { tab: 'assignment', company_search: search, company_province: province,
            company_page: 1, request_page: workspace.requests.current_page }, {
            preserveScroll: true, preserveState: true,
            onError: (errors) => setFilterError(errors.company_search || errors.company_province || 'Choose an available service area and try again.'),
            onFinish: () => setFilterBusy(false),
        });
    };
    const checkHistory = !busy && <Link href={historyUrl} className={`${courierButton} mt-3 w-full`}>Check request history</Link>;

    return <div className="mt-5 space-y-5">
        <CourierPanel className="p-4 sm:p-6">
            <h2 className="text-lg font-semibold">Join a company</h2>
            <p className="mt-2 text-sm leading-relaxed text-slate-600">Choose a company accepting rider requests. A request shares your profile for consideration; your working assignment changes only after a separate placement.</p>
            {!workspace.can_apply ? <p role="status" className="mt-4 rounded-[8px] bg-[#FFF4DF] p-4 text-sm leading-relaxed text-[#92400E]">{workspace.blocked_reason}</p> : <>
                <form onSubmit={filterCompanies} className="mt-5 space-y-3">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div><label htmlFor="rider-company-search" className="text-sm font-semibold">Company name</label><input id="rider-company-search" type="search" maxLength={200} value={search} disabled={busy} onChange={(event) => { setSearch(event.target.value); setFilterError(companyTextError(event.target.value, 1, 100) ?? ''); }} aria-describedby="rider-company-filter-error" aria-invalid={Boolean(filterError)} placeholder="Search companies" className={courierInput} /></div>
                        <div><label htmlFor="rider-company-province" className="text-sm font-semibold">Service province</label><select id="rider-company-province" value={province} disabled={busy} onChange={(event) => setProvince(event.target.value)} className={courierInput}><option value="">All available provinces</option>{workspace.provinces.map((place) => <option key={place} value={place}>{place}</option>)}</select></div>
                    </div>
                    <CourierFieldError id="rider-company-filter-error" message={filterError} />
                    <div className="flex flex-wrap gap-2"><button type="submit" disabled={busy || Boolean(companyTextError(search, 1, 100))} className={courierButton}><Search className="h-4 w-4" aria-hidden="true" />{filterBusy ? 'Searching…' : 'Search companies'}</button>{!busy && (search || province) && <Link href={historyUrl} className={courierButton}>Clear filters</Link>}</div>
                </form>
                {workspace.companies.data.length ? <div className="mt-5 grid gap-3 lg:grid-cols-2">{workspace.companies.data.map((choice) => <article key={choice.id} className="flex min-w-0 flex-col rounded-[8px] bg-[#FFFAFB] p-4 shadow-[0_1px_3px_rgba(15,23,42,0.035)]">
                    <div className="flex items-center gap-3"><CourierAvatar name={choice.name} src={choice.logo} className="rounded-[8px]" /><h3 className="min-w-0 break-words text-base font-semibold">{choice.name}</h3></div>
                    <p className="mt-3 flex items-start gap-2 text-sm leading-relaxed text-slate-600"><MapPin className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" /><span className="break-words">{companyAreas(choice.areas)}</span></p>
                    <button type="button" disabled={busy} onClick={() => openApplication(choice)} className={`${courierButton} mt-4 w-full`}>Request to join</button>
                </article>)}</div> : <div className="mt-5"><CourierEmpty title={search || province ? 'No matching companies' : 'No companies accepting requests'} icon={Building2}><p>{search || province ? 'Try another company name or clear the service province filter.' : 'Please check again later. Only companies with open requests and an active facility appear here.'}</p></CourierEmpty></div>}
                <CompanyRequestPagination page={workspace.companies} label="Companies" courier busy={busy} />
            </>}
        </CourierPanel>
        <CourierPanel className="p-4 sm:p-6">
            <h2 className="text-lg font-semibold">My requests</h2>
            <p className="mt-2 text-sm leading-relaxed text-slate-600">Your submitted details stay with each request. Withdrawing a request keeps its history.</p>
            {workspace.requests.data.length ? <div className="mt-4 space-y-3">{workspace.requests.data.map((request) => <article key={request.id} className="rounded-[8px] bg-slate-50/70 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3"><div className="min-w-0"><h3 className="break-words font-semibold">{request.company.name}</h3><p className="mt-1 break-all text-xs text-slate-500">{request.reference}</p></div><CourierBadge tone={request.status === 'pending' ? 'waiting' : 'neutral'}>{request.status === 'pending' ? 'Request submitted' : 'Withdrawn'}</CourierBadge></div>
                <p className="mt-3 text-sm text-slate-600">Submitted {courierDate(request.submitted_at)}{request.withdrawn_at && <span className="block mt-1">Withdrawn {courierDate(request.withdrawn_at)}</span>}</p>
                {request.note && <p className="mt-3 whitespace-pre-wrap break-words text-sm leading-relaxed text-slate-700">{request.note}</p>}
                <details className="mt-3 text-sm"><summary className="cursor-pointer rounded-[8px] py-2 font-medium text-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]">Shared profile</summary><div className="mt-2 rounded-[8px] bg-white p-4"><SharedCompanyProfile profile={request.shared_profile} /></div></details>
                {request.can_withdraw && <button type="button" disabled={busy} onClick={() => openWithdrawal(request)} className={`${courierButton} mt-3`}>Withdraw request</button>}
            </article>)}</div> : <div className="py-8 text-center"><Building2 className="mx-auto h-7 w-7 text-slate-400" aria-hidden="true" /><p className="mt-3 text-sm font-medium text-slate-600">No company requests yet</p></div>}
            <CompanyRequestPagination page={workspace.requests} label="Request history" courier busy={busy} />
        </CourierPanel>
        <CourierDialog open={Boolean(company)} title="Request to join" busy={application.processing} onClose={() => { if (unconfirmed) router.get(historyUrl); else setCompany(null); }}>
            {company && <form onSubmit={submit} className="space-y-4">
                <p className="break-words text-base font-semibold">{company.name}</p>
                <p className="text-sm leading-relaxed text-slate-600">This company receives the details below and your optional note. Your identity files, licence documents and birth date remain private.</p>
                <div className="rounded-[8px] bg-[#FFFAFB] p-4"><SharedCompanyProfile profile={workspace.sharing_summary} /></div>
                <div><label htmlFor="company-join-note" className="text-sm font-semibold">Availability or preferred area (optional)</label><textarea id="company-join-note" rows={3} maxLength={1000} value={application.data.note} disabled={application.processing || unconfirmed} onChange={(event) => { application.setData('note', event.target.value); application.clearErrors('note'); }} aria-invalid={Boolean(noteError || application.errors.note)} aria-describedby="company-note-help company-note-error" className={courierInput} /><p id="company-note-help" className="mt-2 text-xs text-slate-500">5–500 characters if supplied. {Array.from(application.data.note).length}/500</p><CourierFieldError id="company-note-error" message={noteError || application.errors.note} /></div>
                <label className="flex min-h-12 cursor-pointer items-start gap-3 rounded-[8px] bg-slate-50 p-3 text-sm leading-relaxed"><input type="checkbox" required checked={application.data.sharing_acknowledged} disabled={application.processing || unconfirmed} onChange={(event) => application.setData('sharing_acknowledged', event.target.checked)} aria-describedby="company-sharing-error" className="mt-1 h-5 w-5 shrink-0 rounded-[8px] border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" /><span>I agree to share these details with {company.name} for my rider request.</span></label>
                <CourierFieldError id="company-sharing-error" message={application.errors.sharing_acknowledged} />
                <CourierFieldError message={applicationErrors.company_request || application.errors.company_id || application.errors.intake_version || application.errors.request_token} />
                {unconfirmed && <p role="status" className="text-sm text-slate-600">The result is unconfirmed. Check your history or retry this same request before changing its details.</p>}
                <button type="submit" disabled={application.processing || Boolean(noteError) || !application.data.sharing_acknowledged} className={`${courierPrimary} w-full`}><Send className="h-4 w-4" aria-hidden="true" />{application.processing ? 'Submitting…' : unconfirmed ? 'Retry same request' : 'Submit request'}</button>
                {checkHistory}
            </form>}
        </CourierDialog>
        <CourierDialog open={Boolean(withdrawing)} title="Withdraw request?" busy={withdrawal.processing} onClose={() => { if (withdrawalUnconfirmed) router.get(historyUrl); else setWithdrawing(null); }}>
            {withdrawing && <form onSubmit={withdraw} className="space-y-4"><p className="text-sm leading-relaxed text-slate-600">Withdraw your request to <strong className="break-words text-slate-900">{withdrawing.company.name}</strong>? It stays in your history, and you can choose another company afterward.</p><CourierFieldError message={withdrawalErrors.company_request || withdrawal.errors.expected_version || withdrawal.errors.request_token} />{withdrawalUnconfirmed && <p role="status" className="text-sm text-slate-600">The result is unconfirmed. Check your history or retry the same withdrawal.</p>}<button type="submit" disabled={withdrawal.processing} className={`${courierPrimary} w-full`}><Check className="h-4 w-4" aria-hidden="true" />{withdrawal.processing ? 'Withdrawing…' : withdrawalUnconfirmed ? 'Retry withdrawal' : 'Withdraw request'}</button>{checkHistory}</form>}
        </CourierDialog>
    </div>;
}
