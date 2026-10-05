import { FormEventHandler, useRef } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import ApplicationFields, { ApplicationDetails, ApplicationValues } from '@/Components/ApplicationFields';
import BirthDateInput, { BirthDateLimits } from '@/Components/BirthDateInput';
import InputError from '@/Components/InputError';
import GuestLayout from '@/Layouts/GuestLayout';
import { PageProps } from '@/types';

interface Props {
    user: { name: string; status: string; kyc_status: string; kyc_feedback: string | null };
    application: ApplicationDetails;
    identityDocumentUrl: string | null;
    birthDateLimits: BirthDateLimits;
    canViewOrders: boolean;
}

export default function BuyerApproval({ user, application, identityDocumentUrl, birthDateLimits, canViewOrders }: Props) {
    const { flash } = usePage<PageProps>().props;
    const input = useRef<HTMLInputElement>(null);
    const form = useForm<{ details: ApplicationValues; id_document: File | null }>({ details: application.values, id_document: null });
    const canCorrect = application.can_correct;
    const status = { none: 'Identity application needed', pending_approval: 'Waiting for review', rejected: 'Changes requested', approved: 'Identity approved', verified: 'Identity verified' }[user.kyc_status] || 'Review required';
    const changed = JSON.stringify(form.data.details) !== JSON.stringify(application.values) || form.data.id_document !== null;

    const submit: FormEventHandler = event => {
        event.preventDefault();
        form.transform(({ details, id_document }) => ({
            ...Object.fromEntries(Object.entries(details).filter(([key, value]) => JSON.stringify(value ?? '') !== JSON.stringify(application.values[key] ?? ''))),
            id_document,
        }));
        form.post(route('kyc.resubmit'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: page => {
                form.setData({ details: (page.props.application as ApplicationDetails).values, id_document: null });
                if (input.current) input.current.value = '';
            },
        });
    };

    return (
        <GuestLayout title="Your buyer application" subtitle="You can browse Bagoo while your identity is reviewed. Shopping Bag, checkout and account actions need an active approved account." headerBadge="BUYER ACCOUNT" showFooter={false} maxWidth="xl">
            <Head title="Buyer application — BagooPH" />
            <div className="space-y-5 text-sm font-sans text-slate-800">
                {flash?.success && <p role="status" className="rounded-xl border border-emerald-300 bg-emerald-50 p-3 text-emerald-800">{flash.success}</p>}
                {flash?.error && <p role="alert" className="rounded-xl border border-amber-300 bg-amber-50 p-3 text-amber-900">{flash.error}</p>}
                <div className="rounded-xl border border-slate-300 bg-white p-4 space-y-2">
                    <p className="font-bold">{status}</p>
                    <p>{user.name}</p>
                    <p className="text-slate-600">Account activity: {user.status.replaceAll('_', ' ')}</p>
                    {user.kyc_feedback && <p className="border-t border-slate-300 pt-2">Review feedback: {user.kyc_feedback}</p>}
                    {['inactive', 'suspended'].includes(user.status) && <p className="text-amber-900">New purchases are unavailable. Submitting an application does not remove this restriction.</p>}
                    {!canCorrect && <p className="text-slate-600">Your completed identity review cannot be changed here. Contact support about account restrictions or a reviewed identity correction.</p>}
                </div>

                {canCorrect && (
                    <form onSubmit={submit} className="space-y-4">
                        <ApplicationFields application={application} values={form.data.details} errors={form.errors as Record<string, string>} onChange={(key, value) => form.setData('details', { ...form.data.details, [key]: value })} />
                        <BirthDateInput adult={false} value={String(form.data.details.birthday || '')} maximum={birthDateLimits.past_maximum} onChange={value => form.setData('details', { ...form.data.details, birthday: value })} error={(form.errors as Record<string, string>).birthday} />
                        <div className="space-y-2">
                            <label htmlFor="buyer-identity-document" className="block font-semibold">Government identity document</label>
                            <p className="text-xs text-slate-600">JPEG, PNG, WebP or PDF, up to 5 MB. Uploading a document sends it for review; it does not approve your account.</p>
                            {identityDocumentUrl ? <a href={identityDocumentUrl} target="_blank" rel="noreferrer" className="font-semibold text-[#C20836] underline">View your current private document</a> : <p className="text-amber-900">An identity document is still needed before approval.</p>}
                            <input ref={input} id="buyer-identity-document" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={event => form.setData('id_document', event.target.files?.[0] ?? null)} className="block w-full rounded-xl border border-slate-300 p-2" />
                            <InputError message={form.errors.id_document || (form.errors as Record<string, string>).documents} />
                        </div>
                        <InputError message={(form.errors as Record<string, string>).application} />
                        <button disabled={form.processing || !changed} className="w-full min-h-12 rounded-xl bg-[#E00D42] px-4 py-3 font-semibold text-white hover:bg-[#C20836] disabled:opacity-50">{form.processing ? 'Submitting...' : 'Submit for review'}</button>
                    </form>
                )}

                <nav aria-label="Available buyer actions" className="flex flex-wrap items-center gap-4 border-t border-slate-300 pt-4">
                    <Link href={route('buyer.index')} className="font-semibold text-[#C20836]">Browse products</Link>
                    {canViewOrders && <Link href={route('buyer.orders.index')} className="font-semibold text-[#C20836]">View existing orders</Link>}
                    <button type="button" onClick={() => router.reload()} className="font-semibold">Check status</button>
                    <button type="button" onClick={() => router.post(route('logout'))} className="font-semibold">Sign out</button>
                </nav>
            </div>
        </GuestLayout>
    );
}
