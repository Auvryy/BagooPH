import type { ButtonHTMLAttributes, HTMLAttributes, ReactNode } from 'react';
import { ArrowRight, Check, Circle, MapPin, Package, Search, type LucideIcon } from 'lucide-react';

export const hubInput = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 shadow-sm placeholder:text-slate-400 focus:border-[#E00D42] focus:outline-none focus:ring-2 focus:ring-[#E00D42]/15 disabled:bg-slate-100 disabled:text-slate-500';
const buttonBase = 'inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border px-3.5 py-2 text-sm font-semibold shadow-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#E00D42] focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50';
export const hubButton = buttonBase + ' border-slate-300 bg-white text-slate-800 hover:bg-slate-50';
export const hubPrimary = buttonBase + ' border-[#E00D42] bg-[#E00D42] text-white hover:bg-[#c70b3a]';

export function HubPanel({ title, description, icon, children, className = '', ...props }: HTMLAttributes<HTMLElement> & { title?: string; description?: string; icon?: ReactNode }) {
    return <section {...props} className={`overflow-hidden rounded-xl border border-slate-300/90 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04),0_5px_16px_rgba(15,23,42,0.035)] ${className}`}>
        {title && <div className="flex items-start gap-3 border-b border-slate-200 px-5 py-4"><span className="mt-0.5 text-slate-500">{icon}</span><div><h2 className="text-sm font-semibold tracking-tight text-slate-950">{title}</h2>{description && <p className="mt-1 text-xs leading-5 text-slate-500">{description}</p>}</div></div>}
        <div className="p-5">{children}</div>
    </section>;
}
export function HubBadge({ children, tone = 'neutral' }: { children: ReactNode; tone?: 'neutral' | 'success' | 'warning' | 'brand' }) {
    const tones = { neutral: 'border-slate-300 bg-slate-50 text-slate-700', success: 'border-emerald-200 bg-emerald-50 text-emerald-800', warning: 'border-amber-200 bg-amber-50 text-amber-800', brand: 'border-rose-200 bg-rose-50 text-[#E00D42]' };
    return <span className={`inline-flex rounded-md border px-2 py-1 text-[11px] font-semibold ${tones[tone]}`}>{children}</span>;
}
export function HubButton({ children, primary = false, className = '', ...props }: ButtonHTMLAttributes<HTMLButtonElement> & { primary?: boolean }) {
    return <button {...props} className={`${primary ? hubPrimary : hubButton} ${className}`}>{children}</button>;
}

export function HubPageHeader({ title, description, icon: Icon, actions }: { title: string; description: ReactNode; icon: LucideIcon; actions?: ReactNode }) {
    return <div className="hub-page-heading flex flex-wrap items-center justify-between gap-4">
        <div className="flex min-w-0 items-start gap-3.5"><div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-rose-100 bg-rose-50 text-[#E00D42]"><Icon className="h-5 w-5" /></div><div><h1 className="text-xl font-bold tracking-tight text-slate-950 sm:text-2xl">{title}</h1><p className="mt-1 max-w-2xl text-sm leading-6 text-slate-500">{description}</p></div></div>
        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>;
}

export function HubNextStep({ title, children, action, tone = 'brand', icon: Icon = ArrowRight }: { title: string; children?: ReactNode; action?: ReactNode; tone?: 'brand' | 'warning' | 'success'; icon?: LucideIcon }) {
    const colors = { brand: 'border-rose-200/80 bg-[#FFFAFB] text-[#C20836]', warning: 'border-amber-200 bg-amber-50/70 text-amber-800', success: 'border-emerald-200 bg-emerald-50/60 text-emerald-800' };
    return <section aria-label="Next step" className={`flex flex-wrap items-center justify-between gap-4 rounded-xl border px-4 py-4 sm:px-5 ${colors[tone]}`}><div className="flex min-w-0 flex-1 items-start gap-3"><div className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-current/10 bg-white/80"><Icon className="h-4 w-4" /></div><div><h2 className="text-sm font-semibold">{title}</h2>{children && <div className="mt-1 text-sm leading-6 text-slate-600">{children}</div>}</div></div>{action && <div className="flex w-full shrink-0 flex-wrap gap-2 sm:w-auto [&>*]:flex-1 sm:[&>*]:flex-none">{action}</div>}</section>;
}

export type HubStep = { title: string; detail?: string; state: 'done' | 'current' | 'waiting' | 'blocked'; icon?: LucideIcon };
export function HubStepRail({ steps, label = 'Transfer progress' }: { steps: HubStep[]; label?: string }) {
    return <ol tabIndex={0} aria-label={label} className="hub-step-rail flex gap-0 overflow-x-auto rounded-xl border border-slate-200 bg-white p-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42] sm:p-5">{steps.map((step, index) => {
        const Icon = step.state === 'done' ? Check : step.icon ?? Circle;
        return <li key={step.title} aria-current={step.state === 'current' ? 'step' : undefined} className="relative flex min-w-[132px] flex-1 items-start gap-2.5 pr-5 last:pr-0">
            <span className={`relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border ${step.state === 'done' ? 'border-[#E00D42] bg-[#E00D42] text-white' : step.state === 'current' ? 'border-rose-300 bg-rose-50 text-[#E00D42] ring-4 ring-rose-50' : step.state === 'blocked' ? 'border-amber-300 bg-amber-50 text-amber-700' : 'border-slate-200 bg-slate-50 text-slate-400'}`}><Icon className="h-3.5 w-3.5" /></span>
            <span className="pt-0.5"><span className={`block text-xs font-semibold ${step.state === 'current' ? 'text-[#C20836]' : step.state === 'waiting' ? 'text-slate-500' : 'text-slate-800'}`}>{step.title}<span className="sr-only"> · {{ done: 'Completed', current: 'Current step', waiting: 'Waiting', blocked: 'Blocked' }[step.state]}</span></span>{step.detail && <span className="mt-1 block text-[11px] leading-4 text-slate-500">{step.detail}</span>}</span>
            {index < steps.length - 1 && <ArrowRight aria-hidden="true" className="absolute right-1 top-2 h-3.5 w-3.5 text-slate-300" />}
        </li>;
    })}</ol>;
}

export type HubPlace = { id?: number; logistics_company_id?: number; name: string; code?: string; tier?: string; city_municipality?: string; province?: string; address?: string; latitude?: number | null; longitude?: number | null; parent_hub_id?: number | null };
export function HubRoute({ stops, label = 'Recorded hub route', currentHubId }: { stops: HubPlace[]; label?: string; currentHubId?: number | null }) {
    return <div aria-label={label} className="rounded-lg border border-slate-200 bg-slate-50/70 p-4"><ol className="flex flex-wrap items-start gap-x-3 gap-y-4">{stops.map((stop, index) => <li key={`${stop.id ?? stop.code}-${index}`} className="flex min-w-0 flex-1 basis-32 items-start gap-3"><div className="flex min-w-0 flex-1 items-start gap-2.5"><span className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border ${stop.id === currentHubId ? 'border-rose-200 bg-rose-50 text-[#E00D42]' : 'border-slate-200 bg-white text-slate-500'}`}><MapPin className="h-4 w-4" /></span><span className="min-w-0"><span className="block text-[10px] font-medium text-slate-500">{index === 0 ? 'From' : index === stops.length - 1 ? 'To' : 'Via'}{stop.id === currentHubId ? ' · Current hub' : ''}</span><span className="mt-0.5 block text-xs font-semibold leading-5 text-slate-900">{stop.name}</span>{stop.code && <span className="mt-0.5 block text-[11px] text-slate-500">{stop.code}</span>}</span></div>{index < stops.length - 1 && <ArrowRight className="mt-3 h-3.5 w-3.5 shrink-0 text-slate-400" />}</li>)}</ol></div>;
}

export function HubEmpty({ title, description, icon: Icon = Package, action }: { title: string; description: string; icon?: LucideIcon; action?: ReactNode }) {
    return <div className="flex min-h-52 flex-col items-center justify-center px-5 py-10 text-center"><span className="flex h-12 w-12 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-slate-400"><Icon className="h-5 w-5" /></span><h3 className="mt-4 text-sm font-semibold text-slate-900">{title}</h3><p className="mt-2 max-w-sm text-sm leading-6 text-slate-500">{description}</p>{action && <div className="mt-5">{action}</div>}</div>;
}

export function HubSearch({ label, value, onChange, placeholder }: { label: string; value: string; onChange: (value: string) => void; placeholder?: string }) {
    return <label className="relative block min-w-0"><span className="sr-only">{label}</span><Search className="pointer-events-none absolute left-3 top-3 h-4 w-4 text-slate-400" /><input type="search" value={value} onChange={event => onChange(event.target.value)} placeholder={placeholder ?? label} className={`${hubInput} pl-9`} /></label>;
}
