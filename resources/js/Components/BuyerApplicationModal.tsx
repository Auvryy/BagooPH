import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { RefreshCw, ShieldCheck, X } from 'lucide-react';
import ApplicationFields, { type ApplicationDetails, type ApplicationValues } from './ApplicationFields';
import BirthDateInput, { type BirthDateLimits } from './BirthDateInput';
import InputError from './InputError';
import UploadHint from './UploadHint';

export interface BuyerApplicationData {
    user: { id: number; name: string; status: string; kyc_status: string; kyc_feedback: string | null; kyc_reviewed_at: string | null };
    can_use_portal: boolean;
    application: ApplicationDetails | null;
    identity_document_url: string | null;
    birth_date_limits: BirthDateLimits;
}

export function buyerApplicationLabel(data: BuyerApplicationData): string {
    if (data.can_use_portal) return 'Your buyer account is approved';
    if (['inactive', 'suspended'].includes(data.user.status)) return 'Your account is restricted';
    return { none: 'Complete your buyer application', pending_approval: 'Your application is under review', rejected: 'Your application needs changes' }[data.user.kyc_status] ?? 'Check your buyer application';
}

export default function BuyerApplicationModal({ data, open, onClose }: { data: BuyerApplicationData; open: boolean; onClose: () => void }) {
    const application = data.application;
    const upload = useRef<HTMLInputElement>(null);
    const [checking, setChecking] = useState(false);
    const [feedback, setFeedback] = useState('');
    const form = useForm<{ details: ApplicationValues; id_document: File | null }>({ details: application?.values ?? {}, id_document: null });
    const source = JSON.stringify(application?.values ?? {});
    useEffect(() => {
        form.setData({ details: application?.values ?? {}, id_document: null });
        if (upload.current) upload.current.value = '';
    }, [source]);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!application) return;
        setFeedback('');
        form.transform(({ details, id_document }) => ({
            ...Object.fromEntries(Object.entries(details).filter(([key, value]) => JSON.stringify(value ?? '') !== JSON.stringify(application.values[key] ?? ''))),
            id_document,
        }));
        form.post(route('kyc.resubmit'), {
            forceFormData: true, preserveScroll: true,
            onSuccess: page => {
                const saved = page.props.buyerApplication as BuyerApplicationData;
                form.setData({ details: saved?.application?.values ?? {}, id_document: null });
                if (upload.current) upload.current.value = '';
                setFeedback('Your application has been sent for review. You can keep browsing while you wait.');
            },
        });
    };
    const checkStatus = () => {
        setChecking(true); setFeedback('');
        let received = false;
        router.reload({
            only: ['auth', 'buyerApplication', 'notificationSummary'],
            onSuccess: page => {
                received = true;
                const current = page.props.buyerApplication as BuyerApplicationData | null;
                setFeedback(current?.can_use_portal ? 'Your identity is approved. You can now continue to checkout.' : 'Status checked. ' + (current ? buyerApplicationLabel(current) + '.' : 'Your account status is unavailable.'));
            },
            onFinish: () => {
                setChecking(false);
                if (!received) setFeedback('We could not check your status. Please try again.');
            },
        });
    };
    return <Dialog open={open} onClose={() => !form.processing && onClose()} className="relative z-50 font-sans">
        <div className="fixed inset-0 bg-slate-950/45" aria-hidden="true" />
        <div className="fixed inset-0 flex items-center justify-center p-4 sm:p-6">
            <DialogPanel className="max-h-[85dvh] w-full max-w-xl overflow-y-auto rounded-3xl border border-slate-300 bg-white p-5 text-slate-800 shadow-xl sm:p-7">
                <div className="flex items-start justify-between gap-4">
                    <div><ShieldCheck className="mb-3 h-7 w-7 text-[#E00D42]" aria-hidden="true" /><DialogTitle className="text-xl font-bold">{buyerApplicationLabel(data)}</DialogTitle></div>
                    <button type="button" onClick={onClose} disabled={form.processing} aria-label="Close application" className="rounded-xl p-2 text-slate-500 hover:bg-slate-100 focus-visible:ring-2 focus-visible:ring-[#E00D42]"><X className="h-5 w-5" /></button>
                </div>
                <p className="mt-3 text-sm text-slate-600">Browse products, manage your Bag and view your profile while you wait. Checkout needs an active approved account.</p>
                {data.user.kyc_feedback && <p className="mt-4 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">Review feedback: {data.user.kyc_feedback}</p>}
                {['inactive', 'suspended'].includes(data.user.status) && <p className="mt-4 text-sm text-amber-900">New purchases and Bag changes are unavailable while your account is restricted. A review does not remove this restriction.</p>}
                {feedback && <p role="status" className="mt-4 rounded-2xl border border-slate-300 bg-slate-50 p-4 text-sm">{feedback}</p>}
                {application?.can_correct && <form onSubmit={submit} className="mt-5 space-y-4">
                    <details open={data.user.kyc_status !== 'pending_approval'} className="rounded-2xl border border-slate-300 p-4">
                        <summary className="cursor-pointer text-sm font-semibold">Application details</summary>
                        <div className="mt-4 space-y-4">
                            <ApplicationFields application={application} values={form.data.details} errors={form.errors as Record<string, string>} onChange={(key, value) => form.setData('details', { ...form.data.details, [key]: value })} />
                            <BirthDateInput value={String(form.data.details.birthday ?? '')} maximum={data.birth_date_limits.adult_maximum} onChange={value => form.setData('details', { ...form.data.details, birthday: value })} error={(form.errors as Record<string, string>).birthday} />
                            <label className="block text-sm font-semibold" htmlFor="buyer-identity-document">Government identity document</label>
                            {data.identity_document_url && <a href={data.identity_document_url} target="_blank" rel="noreferrer" className="block text-xs font-semibold text-[#C20836] underline">View your current private document</a>}
                            <input ref={upload} id="buyer-identity-document" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={event => form.setData('id_document', event.target.files?.[0] ?? null)} className="block w-full rounded-xl border border-slate-300 p-2 text-sm" />
                            <UploadHint />
                            <InputError message={form.errors.id_document || (form.errors as Record<string, string>).documents} />
                            <InputError message={(form.errors as Record<string, string>).application} />
                            <button disabled={form.processing} className="min-h-11 w-full rounded-xl bg-[#E00D42] px-4 py-3 text-sm font-bold text-white hover:bg-[#C20836] disabled:opacity-50">{form.processing ? 'Submitting...' : 'Submit for review'}</button>
                        </div>
                    </details>
                </form>}
                <div className="mt-5 flex flex-wrap gap-3 border-t border-slate-300 pt-5">
                    <button type="button" onClick={checkStatus} disabled={checking || form.processing} className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-slate-300 px-4 text-sm font-semibold disabled:opacity-50"><RefreshCw className="h-4 w-4" aria-hidden="true" />{checking ? 'Checking...' : 'Check status'}</button>
                    <button type="button" onClick={onClose} disabled={form.processing} className="min-h-11 rounded-xl bg-[#E00D42] px-4 text-sm font-semibold text-white">Continue browsing</button>
                </div>
            </DialogPanel>
        </div>
    </Dialog>;
}
