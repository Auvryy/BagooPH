import type { ButtonHTMLAttributes, HTMLAttributes, ReactNode } from 'react';

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
