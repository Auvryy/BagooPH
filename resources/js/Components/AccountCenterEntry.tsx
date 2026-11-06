import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { Link, router } from '@inertiajs/react';
import { ArrowRight, X } from 'lucide-react';
import { useState } from 'react';
import { openAccountCenter as chooseAccountCenterAction } from '@/utils/accountCenter';
import { reviewActionLabels, reviewStatusLabels, type ReviewRequest, type ReviewSource } from '@/types/accountReviews';

export type AccountCenterStatus = {
    title: string;
    message: string;
    sources: Array<Pick<ReviewSource, 'type' | 'id' | 'name' | 'status' | 'reason' | 'pending_request_id'>>;
    requests: ReviewRequest[];
};

export default function AccountCenterEntry({ statusLabel, statusDescription, statusDetails }: {
    statusLabel: string;
    statusDescription: string;
    statusDetails: AccountCenterStatus;
}) {
    const [open, setOpen] = useState(false);
    const show = () => chooseAccountCenterAction(
        typeof window !== 'undefined' && window.matchMedia('(max-width: 767px)').matches,
        () => setOpen(true),
        () => router.visit('/account/status'),
    );

    return <>
        <button type="button" onClick={show} aria-haspopup="dialog" className="flex min-h-12 w-full items-center justify-between gap-3 rounded-xl border border-slate-300 bg-white px-4 py-3 text-left text-sm font-semibold text-slate-900 shadow-sm hover:border-[#E00D42] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]">
            <span><span className="block">Account status, security & updates</span><span className="mt-1 block text-xs font-normal text-slate-600">Review requests, devices and account notifications</span></span><ArrowRight className="h-4 w-4 shrink-0 text-[#C20836]" aria-hidden="true" />
        </button>
        <Dialog open={open} onClose={setOpen} className="relative z-[80]">
            <div className="fixed inset-0 bg-slate-950/40" aria-hidden="true" />
            <DialogPanel as="aside" className="fixed inset-y-0 right-0 flex w-full max-w-md flex-col overflow-y-auto border-l border-slate-300 bg-white p-5 shadow-2xl sm:p-6">
                <div className="flex items-start justify-between gap-3 border-b border-slate-200 pb-4"><div><p className="text-xs font-semibold uppercase tracking-wide text-[#C20836]">Account center</p><DialogTitle className="mt-1 text-lg font-bold text-slate-900">Status, security & updates</DialogTitle></div>
                    <button type="button" onClick={() => setOpen(false)} aria-label="Close account center" className="rounded-lg p-2 text-slate-600 hover:bg-slate-100 focus-visible:ring-2 focus-visible:ring-[#E00D42]"><X className="h-4 w-4" aria-hidden="true" /></button></div>
                <section className="mt-5 rounded-xl border border-slate-300 bg-slate-50 p-4"><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Current status</p><p className="mt-2 text-sm font-bold text-slate-900">{statusLabel}</p><p className="mt-1 text-sm leading-relaxed text-slate-600">{statusDescription}</p></section>
                <section aria-label="Current review details" className="mt-4 space-y-3 rounded-xl border border-slate-300 bg-white p-4">
                    <div><h3 className="text-sm font-bold text-slate-900">{statusDetails.title}</h3><p className="mt-1 text-sm leading-relaxed text-slate-600">{statusDetails.message}</p></div>
                    <div className="space-y-2">
                        <h4 className="text-xs font-bold uppercase tracking-wide text-slate-500">Current feedback</h4>
                        {statusDetails.sources.length === 0 && <p className="text-sm text-slate-600">No current review feedback.</p>}
                        {statusDetails.sources.map(source => <article key={`${source.type}-${source.id}`} className="rounded-lg border border-slate-300 bg-slate-50 p-3">
                            <p className="text-sm font-semibold text-slate-900">{source.name}</p>
                            <p className="mt-1 text-xs font-semibold capitalize text-rose-800">{source.status === 'inactive' ? 'Deactivated' : source.status}</p>
                            <p className="mt-2 whitespace-pre-line text-sm leading-relaxed text-slate-700">{source.reason}</p>
                            {source.pending_request_id && <p role="status" className="mt-2 text-xs font-semibold text-amber-800">Request #{source.pending_request_id} is waiting for review.</p>}
                        </article>)}
                    </div>
                    <div className="space-y-2">
                        <h4 className="text-xs font-bold uppercase tracking-wide text-slate-500">Recent review requests</h4>
                        {statusDetails.requests.length === 0 && <p className="text-sm text-slate-600">No review requests on file.</p>}
                        {statusDetails.requests.map(item => <article key={item.id} className="rounded-lg border border-slate-300 p-3 text-sm">
                            <p className="font-semibold text-slate-900">Request #{item.id}: {reviewStatusLabels[item.status]}</p>
                            <p className="mt-1 whitespace-pre-line leading-relaxed text-slate-700">{item.message}</p>
                            {item.decision && <><p className="mt-2 font-semibold text-slate-900">{reviewActionLabels[item.decision.action]}</p><p className="mt-1 whitespace-pre-line text-slate-700">{item.decision.reason}</p></>}
                            {item.status === 'superseded' && !item.decision && <p className="mt-2 text-slate-600">Your status changed after this request. Check the current details above.</p>}
                            <p className="mt-2 text-xs text-slate-500">Sent {new Date(item.created_at).toLocaleString('en-PH', { timeZone: 'Asia/Manila' })}</p>
                        </article>)}
                    </div>
                </section>
                <nav aria-label="Account tools" className="mt-4 space-y-2">
                    <Link onClick={() => setOpen(false)} href="/account/status" className="block rounded-xl border border-slate-300 p-4 hover:border-[#E00D42]"><span className="block text-sm font-semibold text-slate-900">Account status and review requests</span><span className="mt-1 block text-xs text-slate-600">Current decision, feedback and submitted review requests</span></Link>
                    <Link onClick={() => setOpen(false)} href="/account/security" className="block rounded-xl border border-slate-300 p-4 hover:border-[#E00D42]"><span className="block text-sm font-semibold text-slate-900">Login activity and devices</span><span className="mt-1 block text-xs text-slate-600">Review active sessions and sign-in history</span></Link>
                    <Link onClick={() => setOpen(false)} href="/notifications" className="block rounded-xl border border-slate-300 p-4 hover:border-[#E00D42]"><span className="block text-sm font-semibold text-slate-900">Account updates</span><span className="mt-1 block text-xs text-slate-600">Read approval and other account notifications</span></Link>
                </nav>
            </DialogPanel>
        </Dialog>
    </>;
}
