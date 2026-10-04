import { Check, Package } from 'lucide-react';
import { type ReactNode } from 'react';
import CourierJobMap from '@/Components/CourierJobMap';
import { CourierBadge, CourierPanel, CourierStopActions } from '@/Components/CourierUI';
import { type CourierMapJob, type CourierPlace } from '@/utils/courier';

interface JobStop {
    place: CourierPlace;
    label: string;
}

export default function CourierCurrentJob({ job, stops, currentStop, parcelLabel, phone, messageDeliveryId, messagePhase, children }: {
    job: CourierMapJob;
    stops: [JobStop, JobStop];
    currentStop: 0 | 1;
    parcelLabel: string;
    phone?: string | null;
    messageDeliveryId?: number;
    messagePhase: 'pickup' | 'final_mile';
    children?: ReactNode;
}) {
    return <CourierPanel className="min-w-0 p-4 sm:p-5">
        <header className="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div className="flex flex-wrap items-center gap-3"><h2 id="rider-current-job" tabIndex={-1} className="text-lg font-semibold focus:outline-none">{job.preview ? 'Pickup preview' : messagePhase === 'pickup' ? 'Active pickup' : 'Active delivery'}</h2><CourierBadge tone={currentStop === 1 && messagePhase === 'pickup' ? 'waiting' : 'brand'}>{job.stage}</CourierBadge></div>
            <p className="break-all text-sm font-medium text-slate-600">{job.trackingNumber}</p>
        </header>
        <div className="grid items-start gap-5 min-[860px]:grid-cols-2">
            <div className="min-w-0">
                <div className="flex items-start gap-3"><span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-[8px] border border-rose-200 bg-[#FFF2F4] text-[#C20836]"><Package className="h-5 w-5" aria-hidden="true" /></span><div className="min-w-0"><h3 className="break-words text-base font-medium">{messagePhase === 'pickup' ? stops[0].place.name || 'Seller pickup' : stops[1].place.name || 'Assigned recipient'}</h3><p className="mt-1 text-sm leading-relaxed text-slate-600">{parcelLabel}</p></div></div>
                <ol aria-label={messagePhase === 'pickup' ? 'Pickup leg stops' : 'Delivery leg stops'} className="my-5">
                    {stops.map((stop, index) => {
                        const reached = !job.preview && index < currentStop;
                        const current = index === currentStop;
                        return <li key={stop.label} className={`relative flex gap-3 ${index === 0 ? 'pb-5' : ''}`}>
                            {index === 0 && <span className="absolute bottom-0 left-[7px] top-4 border-l border-dashed border-slate-300" aria-hidden="true" />}
                            <span className={`relative mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border ${current ? 'border-[#E00D42] bg-[#FFF2F4]' : reached ? 'border-[#E00D42] bg-[#E00D42] text-white' : 'border-slate-300 bg-white'}`} aria-hidden="true">{reached && <Check className="h-3 w-3" />}{current && <span className="h-1.5 w-1.5 rounded-full bg-[#E00D42]" />}</span>
                            <div className="min-w-0"><div className="flex flex-wrap items-center gap-x-2 gap-y-1"><p className="text-xs font-medium text-slate-600">{stop.label}</p><span className={`text-xs font-medium ${current || reached ? 'text-[#C20836]' : 'text-slate-500'}`}>{reached ? 'Collected' : current ? job.preview ? 'Preview stop' : 'Current stop' : 'Next stop'}</span></div><p className="mt-1 break-words text-sm font-medium text-slate-900">{stop.place.name || 'Name not provided'}</p><p className="mt-1 whitespace-pre-line break-words text-sm leading-relaxed text-slate-600">{stop.place.address || (stop.place.code ? `Facility ${stop.place.code}. Address not provided.` : 'Address not provided.')}</p></div>
                        </li>;
                    })}
                </ol>
                <p className="mb-4 text-sm leading-relaxed text-slate-600">{job.instruction}</p>
                <CourierStopActions key={job.key} compact place={job.place} label={job.stopLabel} phone={phone} messageDeliveryId={messageDeliveryId} messagePhase={messagePhase} />
                {children}
            </div>
            <CourierJobMap job={job} embedded />
        </div>
    </CourierPanel>;
}
