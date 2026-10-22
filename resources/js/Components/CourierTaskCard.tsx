import { MapPin, Package } from 'lucide-react';
import { type ReactNode } from 'react';
import { CourierBadge, CourierParcelIdentifiers, courierButton } from '@/Components/CourierUI';

export interface CourierTaskItem {
    name: string;
    quantity: number;
}

interface Props {
    id: string;
    title: string;
    stage: string;
    tone?: 'brand' | 'waiting' | 'success';
    trackingNumber: string;
    orderNumber: string | null;
    items: CourierTaskItem[];
    itemCount: number;
    stopLabel: string;
    stopName: string | null;
    stopAddress: string | null;
    instruction: string;
    paymentSummary?: string;
    selected: boolean;
    preview?: boolean;
    onView: () => void;
    children?: ReactNode;
}

export default function CourierTaskCard({ id, title, stage, tone = 'brand', trackingNumber, orderNumber, items, itemCount, stopLabel, stopName, stopAddress, instruction, paymentSummary, selected, preview = false, onView, children }: Props) {
    const unitLabel = `${itemCount} ${itemCount === 1 ? 'item' : 'items'}`;
    return <article aria-labelledby={`${id}-title`} className={`min-w-0 overflow-hidden rounded-[8px] border bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)] ${selected ? 'border-[#E00D42] ring-2 ring-[#FCE7EA]' : 'border-slate-300'}`}>
        <div className="border-l-4 border-[#E00D42] p-4 sm:p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-start gap-3">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-[8px] bg-[#FFF6F8] text-[#C20836]"><Package className="h-5 w-5" aria-hidden="true" /></span>
                    <div className="min-w-0"><h3 id={`${id}-title`} className="break-words text-base font-semibold text-slate-900">{title}</h3><p className="mt-1 text-xs leading-relaxed text-slate-600">{preview ? 'Pickup preview · Claim before collecting' : unitLabel}</p></div>
                </div>
                <CourierBadge tone={tone}>{stage}</CourierBadge>
            </div>

            <div className="mt-4">
                <p className="text-xs font-semibold text-slate-600">Products · {unitLabel}</p>
                {items.length ? <ul className="mt-2 space-y-1 text-sm text-slate-800">{items.slice(0, 2).map((item, index) => <li key={`${item.name}-${index}`} className="flex items-start justify-between gap-3"><span className="break-words font-medium">{item.name || 'Product name unavailable'}</span><span className="shrink-0 tabular-nums">× {item.quantity}</span></li>)}</ul> : <p className="mt-2 text-sm text-slate-600">Product details are not available.</p>}
                {items.length > 2 && <details className="mt-1 text-sm text-slate-700"><summary className="min-h-12 cursor-pointer py-3 font-semibold text-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]">View {items.length - 2} more {items.length - 2 === 1 ? 'product' : 'products'}</summary><ul className="space-y-2 pb-3">{items.slice(2).map((item, index) => <li key={`${item.name}-${index + 2}`} className="flex items-start justify-between gap-3"><span className="break-words">{item.name || 'Product name unavailable'}</span><span className="shrink-0 font-medium tabular-nums">× {item.quantity}</span></li>)}</ul></details>}
            </div>

            <div className="mt-4">
                <CourierParcelIdentifiers trackingNumber={trackingNumber} orderNumber={orderNumber} />
            </div>

            <div className="mt-4 flex items-start gap-3">
                <MapPin className="mt-0.5 h-5 w-5 shrink-0 text-[#C20836]" aria-hidden="true" />
                <div className="min-w-0"><p className="text-xs font-semibold text-slate-600">{stopLabel}</p><p className="mt-1 break-words text-base font-semibold text-slate-900">{stopName || 'Name not provided'}</p><p className="mt-1 whitespace-pre-line break-words text-sm leading-relaxed text-slate-700">{stopAddress || 'Address not provided.'}</p></div>
            </div>

            <div className={`mt-4 rounded-[8px] border p-3 sm:p-4 ${tone === 'waiting' ? 'border-amber-300 bg-[#FFF4DF]' : tone === 'success' ? 'border-emerald-300 bg-[#ECFDF5]' : 'border-slate-300 bg-[#FFFAFB]'}`}><p className={`text-xs font-semibold ${tone === 'waiting' ? 'text-[#92400E]' : tone === 'success' ? 'text-emerald-800' : 'text-slate-600'}`}>Next step</p><p className="mt-1 text-sm leading-relaxed text-slate-800">{instruction}</p></div>

            {paymentSummary && <p className="mt-3 rounded-[8px] border border-rose-200 bg-[#FFF6F8] px-3 py-2 text-sm font-semibold text-slate-800">{paymentSummary}</p>}

            <div className="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center">
                <button type="button" onClick={onView} aria-pressed={selected} className={`${courierButton} ${selected ? 'border-[#E00D42] bg-[#FFF6F8] text-[#C20836]' : ''}`}>{selected ? 'Selected task details' : 'View task details and map'}</button>
                <div className="flex min-w-0 flex-1 flex-col gap-2 sm:flex-row sm:justify-end">{children}</div>
            </div>
        </div>
    </article>;
}
