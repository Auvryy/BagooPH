import { Check, MapPin } from 'lucide-react';
import { OrderTracking } from '@/types';
import { buyerMilestones, originalBuyerJourney } from '@/utils/buyerParcelProgress';
import { trackingDate } from '@/utils/orderTracking';

export default function BuyerParcelProgress({ tracking }: { tracking: OrderTracking }) {
    const milestones = buyerMilestones(tracking);
    const history = originalBuyerJourney(tracking);
    const last = history.at(-1);
    return <div className="space-y-5">
        <div className="flex items-start gap-3 rounded-xl border border-slate-300 bg-slate-50 p-4">
            <MapPin className="mt-0.5 h-5 w-5 shrink-0 text-[#E00D42]" aria-hidden="true" />
            <div className="min-w-0"><p className="text-xs font-semibold text-slate-500">Last recorded location</p><p className="mt-1 break-words text-sm font-bold text-slate-900">{last?.location || 'Location not recorded'}</p><p className="mt-1 text-xs text-slate-500">{trackingDate(last?.created_at)} · Philippine time. This shows the latest recorded update.</p></div>
        </div>
        <ol aria-label="Parcel milestones" className={`grid gap-0 ${milestones.length > 8 ? 'lg:grid-cols-9' : 'lg:grid-cols-8'}`}>
            {milestones.map((step, index) => <li key={step.key} aria-current={step.current ? 'step' : undefined} data-recorded={step.recorded} className="relative flex min-w-0 gap-3 pb-6 last:pb-0 lg:flex-col lg:items-center lg:gap-2 lg:px-1 lg:pb-0 lg:text-center">
                {index < milestones.length - 1 && <span aria-hidden="true" className="absolute left-[15px] top-8 h-[calc(100%-2rem)] w-px bg-slate-300 lg:left-[calc(50%+1rem)] lg:top-4 lg:h-px lg:w-[calc(100%-2rem)]" />}
                <span aria-hidden="true" className={`relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 ${step.current ? 'border-[#E00D42] bg-[#E00D42] text-white ring-4 ring-rose-100' : step.recorded ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300 bg-white text-slate-400'}`}>
                    {step.recorded ? <Check className="h-4 w-4" /> : <span className="h-2 w-2 rounded-full bg-current" />}
                </span>
                <div className="min-w-0 space-y-1"><p className={`text-xs font-bold ${step.current ? 'text-[#C20836]' : 'text-slate-700'}`}>{step.label}</p>
                    <p className={`text-[11px] ${step.current ? 'font-semibold text-[#C20836]' : 'text-slate-500'}`}>{step.current ? 'Current stage' : step.recorded ? 'Recorded' : 'Not recorded'}</p>
                    {step.time && <p className="text-[10px] leading-relaxed text-slate-500">{trackingDate(step.time)}</p>}
                    {step.current && !step.recorded && <p className="text-[10px] text-slate-500">Checkpoint not recorded</p>}
                </div>
            </li>)}
        </ol>
        <details className="border-t border-slate-300 pt-4">
            <summary className="cursor-pointer rounded-lg py-2 text-sm font-semibold text-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]">Journey checkpoints ({history.length})</summary>
            {history.length === 0 ? <p className="py-5 text-center text-sm text-slate-500">No journey checkpoints are recorded yet.</p> : <ol aria-label="Original parcel journey" className="mt-3 space-y-0">
                {history.map((event, index) => <li key={event.id} className="relative flex gap-3 pb-5 last:pb-0">
                    {index < history.length - 1 && <span aria-hidden="true" className="absolute bottom-0 left-[5px] top-3 w-px bg-slate-300" />}
                    <span aria-hidden="true" className={`relative mt-1.5 h-3 w-3 shrink-0 rounded-full border-2 ${index === history.length - 1 ? 'border-[#E00D42] bg-[#E00D42]' : 'border-slate-300 bg-white'}`} />
                    <div><p className="text-sm font-semibold text-slate-800">{event.label}</p><p className="mt-1 break-words text-xs text-slate-600">{event.location}</p><p className="mt-1 text-xs text-slate-500">{trackingDate(event.created_at)}</p></div>
                </li>)}
            </ol>}
        </details>
    </div>;
}
