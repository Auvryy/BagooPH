import { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { DialogTitle } from '@headlessui/react';
import Modal from '@/Components/Modal';
import { Campaign, campaignMoney } from './campaignTypes';

export type CampaignAction = 'publish' | 'pause' | 'resume' | 'archive' | 'increase_budget' | 'suspend' | 'reinstate';
const titles: Record<CampaignAction, string> = { publish: 'Publish voucher', pause: 'Pause voucher', resume: 'Resume voucher', archive: 'End campaign', increase_budget: 'Increase budget', suspend: 'Suspend shop voucher', reinstate: 'Clear suspension' };
export default function CampaignActionDialog({ campaign, action, platform, onClose }: { campaign: Campaign; action: CampaignAction; platform: boolean; onClose: () => void }) {
    const form = useForm({ action, reason: '', budget_increase: '', request_key: crypto.randomUUID(), source_version: campaign.version });
    const reasonRequired = ['increase_budget', 'suspend', 'reinstate'].includes(action);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route(`${platform ? 'admin' : 'seller'}.vouchers.action`, campaign.id), { preserveScroll: true, onSuccess: onClose });
    };
    return <Modal show onClose={onClose} closeable={!form.processing} maxWidth="md">
        <form onSubmit={submit} className="space-y-4 p-5">
            <DialogTitle className="text-lg font-bold text-slate-900">{titles[action]}</DialogTitle>
            <p className="text-sm text-slate-600"><strong>{campaign.code}</strong> · {campaign.name}</p>
            {action === 'publish' && <p className="text-sm text-slate-600">Publish the saved draft with a {campaignMoney(campaign.draft_terms?.budget_cents)} spending ceiling. A future start stays scheduled. This revision has its own limits; earlier orders keep their original terms.</p>}
            {action === 'archive' && <p className="text-sm text-slate-600">End new use permanently. Placed orders, original retries and campaign history remain available.</p>}
            {action === 'pause' && <p className="text-sm text-slate-600">Stop new use until you resume. Placed orders and original retries keep their savings.</p>}
            {action === 'resume' && <p className="text-sm text-slate-600">Resume within the current dates, use limits and approved budget. Expired or exhausted offers cannot resume.</p>}
            {action === 'suspend' && <p className="text-sm text-slate-600">Stop new use and notify the seller. Only Platform Admin can clear this suspension.</p>}
            {action === 'reinstate' && <p className="text-sm text-slate-600">Clear the admin suspension. The seller still chooses whether to resume an eligible offer.</p>}
            {action === 'increase_budget' && <label className="block text-xs font-semibold text-slate-700">Additional spending ceiling (₱)
                <input required type="number" min="0.01" step="0.01" value={form.data.budget_increase} onChange={e => form.setData('budget_increase', e.target.value)} className="mt-1.5 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]" />
                <span className="mt-2 block font-normal">Current ceiling: {campaignMoney(campaign.approved_budget_cents)}. Discount terms and recorded uses stay unchanged.</span>
            </label>}
            {reasonRequired && <label className="block text-xs font-semibold text-slate-700">Reason
                <textarea required minLength={5} maxLength={1000} value={form.data.reason} onChange={e => form.setData('reason', e.target.value)} className="mt-1.5 w-full rounded-xl border border-slate-300 p-3 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]" />
            </label>}
            {Object.entries(form.errors).map(([key, message]) => <p key={key} role="alert" className="text-sm text-rose-700">{message}</p>)}
            <div className="flex justify-end gap-3 border-t border-slate-300 pt-4">
                <button type="button" onClick={onClose} disabled={form.processing} className="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold">Cancel</button>
                <button disabled={form.processing} className="rounded-xl bg-[#E00D42] px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50">{form.processing ? 'Saving…' : 'Confirm'}</button>
            </div>
        </form>
    </Modal>;
}
