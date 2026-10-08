import React, { useEffect, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

type Correction = {
    id: number; version: number; shop_id: number | null; reason: string; requested_at: string;
    before: Record<string, string | number | null>; proposed: Record<string, string | number | null>;
    review_token: string; documents: Record<string, string | null>; current: boolean;
    provenance: { source: string; kyc_decision_id: number | null; shop_review_decision_id: number | null };
    decision: { id: number; action: string; reason: string; actor: string; decided_at: string; eligible: boolean } | null;
};
type Subject = {
    id: number; name: string; role: string; shop_id: number | null; source_token: string;
    values: Record<string, string | number | null>;
    fields: { key: string; label: string; type: string; options: string[] }[];
    requests: Correction[]; required_documents: string[]; provenance: Correction['provenance'];
};
type Props = {
    subject: Subject; categories: { id: number; name: string }[]; adminReview: boolean;
    baseUrl: string; decisionBaseUrl: string;
    affectedWork: { order_id: number; order_number: string; order_status: string }[];
    listings: { id: number; name: string; category_id: number | null; status: string }[];
};

function Review({ correction, endpoint }: { correction: Correction; endpoint: string }) {
    const [action, setAction] = useState('approve');
    const [reason, setReason] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<string[]>([]);
    const [conflict, setConflict] = useState(false);
    const submit = async (event: React.FormEvent) => {
        event.preventDefault(); setProcessing(true); setErrors([]);
        try {
            await axios.post(endpoint, { action, reason, affected_work_confirmed: confirmed, review_token: correction.review_token }, { headers: { Accept: 'application/json' } });
            router.reload();
        } catch (error) {
            if (axios.isAxiosError(error)) {
                setConflict(error.response?.status === 409);
                const details = error.response?.data?.errors as Record<string, string[]> | undefined;
                setErrors(details ? Object.values(details).flat() : [error.response?.data?.message ?? 'The decision could not be saved.']);
            } else setErrors(['The decision could not be saved. Try again.']);
        } finally { setProcessing(false); }
    };
    return <form onSubmit={submit} className="mt-4 space-y-3 border-t border-slate-300 pt-4">
        <label className="block text-sm font-medium">Decision<select value={action} onChange={event => setAction(event.target.value)} className="mt-1 w-full rounded-lg border-slate-300"><option value="approve">Approve correction</option><option value="reject">Reject correction</option></select></label>
        <label className="block text-sm font-medium">Decision reason<textarea required value={reason} onChange={event => setReason(event.target.value)} rows={3} maxLength={1000} className="mt-1 w-full rounded-lg border-slate-300" /></label>
        <label className="flex items-start gap-3 text-sm"><input type="checkbox" checked={confirmed} onChange={event => setConfirmed(event.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42]" />I inspected the current evidence and reviewed affected listings and ongoing responsibilities. This correction does not reactivate an account or transfer work.</label>
        {errors.map((error, index) => <p role="alert" key={index} className="text-sm text-rose-700">{error}</p>)}
        {conflict && <button type="button" onClick={() => router.reload()} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">Reload current review</button>}
        <button disabled={processing || conflict || !confirmed || reason.trim().length < 5} className="rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{processing ? 'Saving...' : 'Record decision'}</button>
    </form>;
}

export default function IdentityCorrection({ subject, categories, adminReview, baseUrl, decisionBaseUrl, affectedWork, listings }: Props) {
    const fields = [...subject.fields, { key: 'birthday', label: 'Birth date', type: 'date', options: [] }, ...(subject.role === 'seller' ? [{ key: 'root_category_id', label: 'Shop master category', type: 'category', options: [] }] : [])];
    const [field, setField] = useState('birthday');
    const selected = fields.find(item => item.key === field)!;
    const form = useForm({ source_token: subject.source_token, shop_id: subject.shop_id, changes: { birthday: subject.values.birthday ?? '' } as Record<string, string | number | null>, reason: '',
        id_document: null as File | null, business_permit: null as File | null, driver_license: null as File | null, or_cr_document: null as File | null, franchise_document: null as File | null });
    const fileInputs = { id: 'id_document', permit: 'business_permit', license: 'driver_license', orcr: 'or_cr_document', franchise: 'franchise_document' } as const;
    useEffect(() => {
        form.setData(previous => ({ ...previous, source_token: subject.source_token, shop_id: subject.shop_id, changes: { [field]: subject.values[field] ?? '' } }));
    }, [subject.source_token]);
    const display = (key: string, value: string | number | null) => key === 'root_category_id' ? categories.find(category => category.id === Number(value))?.name ?? (value ? `Category #${value}` : 'Not recorded') : value ?? 'Not recorded';
    const content = <div className="space-y-6">
        <section className="rounded-xl border border-slate-300 bg-white p-5">
            <h2 className="text-lg font-semibold">{subject.name}</h2>
            <p className="mt-2 text-sm text-slate-600">A correction needs supporting evidence and an admin decision. Account roles, separate restrictions and ongoing work stay in place.</p>
            {subject.provenance.source === 'legacy_without_recorded_review' && <p className="mt-2 text-sm text-amber-800">No earlier identity review is recorded. This request will retain that legacy provenance and record the current review.</p>}
        </section>
        {adminReview && <section className="rounded-xl border border-slate-300 bg-white p-5">
            <h2 className="font-semibold">Responsibilities and listings</h2>
            <p className="mt-2 text-sm text-slate-600">Cash custody and settlement records are not yet available. A correction never completes a handover or settles money.</p>
            <div className="mt-3 max-h-64 space-y-2 overflow-y-auto">{affectedWork.map(work => <p className="text-sm" key={work.order_id}>{work.order_number} · {work.order_status?.replaceAll('_', ' ') ?? 'Unknown state'}</p>)}{affectedWork.length === 0 && <p className="text-sm text-slate-600">No ongoing work was found in the available records.</p>}
            {listings.map(product => <p className="text-sm" key={product.id}>{product.name} · {product.status} · {display('root_category_id', product.category_id)}</p>)}</div>
            {listings.length > 0 && <p className="mt-3 text-sm text-amber-800">A shop category correction can make existing listings unavailable. Their categories and purchased-item history will be retained.</p>}
        </section>}
        <form onSubmit={event => { event.preventDefault(); form.post(baseUrl, { forceFormData: true, preserveScroll: true }); }} className="rounded-xl border border-slate-300 bg-white p-5">
            <h2 className="font-semibold">Request a correction</h2>
            <label className="mt-4 block text-sm font-medium">Detail to correct<select value={field} onChange={event => { const key = event.target.value; setField(key); form.setData('changes', { [key]: subject.values[key] ?? '' }); }} className="mt-1 w-full rounded-lg border-slate-300">{fields.map(item => <option key={item.key} value={item.key}>{item.label}</option>)}</select></label>
            <p className="mt-2 text-sm text-slate-600">Currently recorded: {display(field, subject.values[field] ?? null)}</p>
            <label className="mt-3 block text-sm font-medium">Corrected {selected.label.toLowerCase()}
                {selected.type === 'category' ? <select required value={String(form.data.changes[field] ?? '')} onChange={event => form.setData('changes', { [field]: event.target.value })} className="mt-1 w-full rounded-lg border-slate-300"><option value="">Choose a master category</option>{categories.map(category => <option key={category.id} value={category.id}>{category.name}</option>)}</select>
                    : selected.options.length > 0 ? <select value={String(form.data.changes[field] ?? '')} onChange={event => form.setData('changes', { [field]: event.target.value })} className="mt-1 w-full rounded-lg border-slate-300"><option value="">Choose a value</option>{selected.options.map(option => <option key={option}>{option}</option>)}</select>
                        : <input type={selected.type} value={String(form.data.changes[field] ?? '')} onChange={event => form.setData('changes', { [field]: event.target.value })} className="mt-1 w-full rounded-lg border-slate-300" />}
            </label>
            <label className="mt-4 block text-sm font-medium">Reason<textarea required value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} rows={3} maxLength={1000} className="mt-1 w-full rounded-lg border-slate-300" /></label>
            <p className="mt-3 text-sm text-slate-600">Upload replacement evidence if the current documents do not support the correction. Earlier reviewed files are retained.</p>
            <div className="mt-3 grid gap-3 sm:grid-cols-2">{subject.required_documents.map(kind => <label key={kind} className="text-sm font-medium capitalize">{kind === 'orcr' ? 'OR/CR' : kind} document<input type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" onChange={event => form.setData(fileInputs[kind as keyof typeof fileInputs], event.target.files?.[0] ?? null)} className="mt-1 block w-full rounded-lg border border-slate-300 p-2 text-sm" /></label>)}</div>
            {Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="mt-2 text-sm text-rose-700">{error}</p>)}
            <button disabled={form.processing || form.data.reason.trim().length < 5} className="mt-4 rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{form.processing ? 'Submitting...' : 'Submit correction request'}</button>
        </form>
        <section className="rounded-xl border border-slate-300 bg-white p-5">
            <h2 className="font-semibold">Requests and decisions</h2>
            {subject.requests.length === 0 && <p className="mt-3 text-sm text-slate-600">No correction requests have been recorded.</p>}
            {subject.requests.map(correction => <article key={correction.id} className="mt-4 border-t border-slate-300 pt-4">
                <p className="font-semibold">Request #{correction.id} · {correction.decision?.action === 'approve' ? 'Approved' : correction.decision?.action === 'reject' ? 'Rejected' : correction.current ? 'Awaiting review' : 'A new request is required'}</p>
                <p className="mt-2 whitespace-pre-line text-sm">{correction.reason}</p>
                <dl className="mt-3 space-y-2">{Object.entries(correction.proposed).map(([key, value]) => <div key={key} className="text-sm"><dt className="font-semibold">{fields.find(item => item.key === key)?.label ?? key.replaceAll('_', ' ')}</dt><dd>{display(key, correction.before[key] ?? null)} → {display(key, value)}</dd></div>)}</dl>
                <div className="mt-3 flex flex-wrap gap-3">{Object.entries(correction.documents).map(([kind, url]) => url && <a key={kind} href={url} target="_blank" rel="noopener noreferrer" className="rounded-lg border border-slate-300 px-3 py-2 text-sm">Open {kind === 'orcr' ? 'OR/CR' : kind} evidence</a>)}</div>
                {correction.decision ? <div className="mt-3 text-sm"><p>{correction.decision.reason}</p><p className="mt-1 text-slate-600">{correction.decision.actor} · {new Date(correction.decision.decided_at).toLocaleString('en-PH', { timeZone: 'Asia/Manila' })}</p>{correction.decision.action === 'approve' && !correction.decision.eligible && <p className="mt-2 text-amber-800">This correction does not make the account eligible for new work. Separate restrictions and identity requirements still apply.</p>}</div>
                    : correction.current ? adminReview && <Review key={correction.review_token} correction={correction} endpoint={`${decisionBaseUrl}/${correction.id}/decision`} /> : <p className="mt-3 text-sm text-amber-800">The identity or evidence changed after this request. Submit a new correction supported by current evidence.</p>}
            </article>)}
        </section>
    </div>;
    return adminReview ? <DashboardLayout title="Reviewed identity corrections" subtitle="Current evidence, preserved history and fixed account roles" actions={<Link href={`${decisionBaseUrl}`} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">Back to correction queue</Link>}><Head title="Identity review — BagooPH" />{content}</DashboardLayout>
        : <AuthenticatedLayout header={<h1 className="text-xl font-semibold">Identity corrections</h1>}><Head title="Identity corrections — BagooPH" /><main className="mx-auto max-w-4xl px-4 py-8">{content}</main></AuthenticatedLayout>;
}
