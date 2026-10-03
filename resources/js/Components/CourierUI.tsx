import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { Link } from '@inertiajs/react';
import { Copy, MapPin, MessageSquare, Package, Phone, X, type LucideIcon } from 'lucide-react';
import { useState, type PropsWithChildren } from 'react';
import { twMerge } from 'tailwind-merge';
import { CourierPlace, courierPath, directionsUrl, telephoneUrl } from '@/utils/courier';

export const courierButton = 'inline-flex min-h-12 items-center justify-center gap-2 rounded-[14px] border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-800 transition-colors duration-150 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42] disabled:cursor-not-allowed disabled:opacity-60 motion-reduce:transition-none';
export const courierPrimary = 'inline-flex min-h-[52px] items-center justify-center gap-2 rounded-[16px] bg-[#E00D42] px-5 py-3 text-base font-bold text-white shadow-[0_4px_12px_rgba(224,13,66,0.14)] transition duration-150 hover:bg-[#C20836] enabled:active:translate-y-px focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42] disabled:cursor-not-allowed disabled:opacity-60 motion-reduce:transform-none motion-reduce:transition-none';
export const courierInput = 'mt-2 min-h-12 w-full rounded-[14px] border border-slate-500 bg-white px-3 py-3 text-base text-slate-900 focus:border-[#E00D42] focus:ring-[#E00D42]';

export const courierClasses = twMerge;

export function CourierPanel({ children, className = '' }: PropsWithChildren<{ className?: string }>) {
    return <section className={twMerge('courier-enter rounded-[24px] border border-slate-300 bg-white shadow-[0_2px_0_rgba(15,23,42,0.04),0_8px_24px_rgba(15,23,42,0.06)]', className)}>{children}</section>;
}

export function CourierBadge({ children, tone = 'neutral' }: PropsWithChildren<{ tone?: 'neutral' | 'brand' | 'waiting' | 'success' }>) {
    const colors = { neutral: 'border-slate-300 bg-slate-50 text-slate-700', brand: 'border-rose-300 bg-[#FDF2F4] text-[#C20836]', waiting: 'border-amber-300 bg-[#FFF4DF] text-[#92400E]', success: 'border-emerald-300 bg-[#ECFDF5] text-[#047857]' };
    return <span className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold ${colors[tone]}`}>{children}</span>;
}

export function CourierEmpty({ title, children, icon: Icon = Package }: PropsWithChildren<{ title: string; icon?: LucideIcon }>) {
    return <CourierPanel className="px-5 py-10 text-center"><span className="mx-auto flex h-16 w-16 -rotate-6 items-center justify-center rounded-[22px] border border-rose-300 bg-[#FDF2F4] text-[#C20836]"><Icon className="h-8 w-8 rotate-6" aria-hidden="true" /></span><h2 className="mt-4 text-xl font-bold text-slate-900">{title}</h2><div className="mx-auto mt-2 max-w-md text-base leading-relaxed text-slate-600">{children}</div></CourierPanel>;
}

export function CourierDialog({ open, title, children, onClose, busy = false }: PropsWithChildren<{ open: boolean; title: string; onClose: () => void; busy?: boolean }>) {
    return <Dialog open={open} onClose={() => { if (!busy) onClose(); }} className="relative z-[80] font-sans">
        <div className="fixed inset-0 bg-slate-950/50" aria-hidden="true" />
        <div className="fixed inset-0 flex items-center justify-center overflow-y-auto p-3 sm:p-6">
            <DialogPanel className="courier-enter relative max-h-[calc(100dvh-1.5rem)] w-full max-w-lg overflow-y-auto rounded-[28px] border border-slate-300 bg-white shadow-2xl sm:max-h-[calc(100dvh-3rem)]">
                <header className="sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-slate-300 bg-white p-4 sm:p-5"><DialogTitle className="text-xl font-bold text-slate-900">{title}</DialogTitle><button type="button" aria-label="Close dialog" disabled={busy} onClick={onClose} className={`${courierButton} h-12 w-12 shrink-0 p-0`}><X className="h-5 w-5" aria-hidden="true" /></button></header>
                <div className="p-4 sm:p-5">{children}</div>
            </DialogPanel>
        </div>
    </Dialog>;
}

export function CourierFieldError({ message, id }: { message?: string; id?: string }) {
    return message ? <p id={id} role="alert" className="mt-2 text-sm font-medium text-rose-700">{message}</p> : null;
}

export function CourierStop({ place, label, phone, messageDeliveryId, messagePhase }: { place: CourierPlace; label: string; phone?: string | null; messageDeliveryId?: number; messagePhase?: 'pickup' | 'final_mile' }) {
    const [copyResult, setCopyResult] = useState('');
    const directions = directionsUrl(place);
    const telephone = telephoneUrl(phone);
    const copyAddress = async () => {
        try {
            if (!navigator.clipboard || !place.address) throw new Error('Clipboard unavailable');
            await navigator.clipboard.writeText(place.address);
            setCopyResult('Address copied');
        } catch { setCopyResult('Could not copy. Select the address to copy it.'); }
    };
    return <div className="space-y-3">
        <div><p className="text-sm font-semibold text-slate-600">{label}</p><h3 className="mt-1 break-words text-xl font-bold text-slate-900">{place.name || 'Name not provided'}</h3><p className="mt-2 whitespace-pre-line break-words text-base leading-relaxed text-slate-800">{place.address || (place.code ? `Facility ${place.code}. Address not provided.` : 'Address not provided.')}</p></div>
        <div className="flex flex-wrap gap-2">
            {directions && <a href={directions} target="_blank" rel="noopener noreferrer" className={courierButton} aria-label={`Directions to ${place.name || label}`}><MapPin className="h-4 w-4" aria-hidden="true" />Directions</a>}
            {telephone && <a href={telephone} className={courierButton} aria-label={`Call ${place.name || label}`}><Phone className="h-4 w-4" aria-hidden="true" />Call</a>}
            {messageDeliveryId !== undefined && <Link href={`${courierPath('/messages')}?delivery=${messageDeliveryId}${messagePhase ? `&phase=${messagePhase}` : ''}`} className={courierButton}><MessageSquare className="h-4 w-4" aria-hidden="true" />Message</Link>}
            {place.address && <button type="button" onClick={copyAddress} className={courierButton} aria-label={`Copy address for ${place.name || label}`}><Copy className="h-4 w-4" aria-hidden="true" />Copy address</button>}
        </div>
        {!directions && <p className="text-sm text-slate-600">Directions are unavailable until a destination address or location is provided.</p>}
        <p role="status" className="text-sm text-slate-600">{copyResult}</p>
    </div>;
}
