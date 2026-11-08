import type { ButtonHTMLAttributes, HTMLAttributes, ReactNode } from 'react';
import { ArrowRight, Check, Circle, MapPin, Package, Search, type LucideIcon } from 'lucide-react';

export const hubInput =
    'w-full rounded-lg border border-zinc-300/90 bg-white px-3.5 py-2 text-sm text-[#121316] shadow-2xs placeholder:text-zinc-400 focus:border-[#E00D42] focus:outline-none focus:ring-2 focus:ring-[#E00D42]/15 disabled:bg-zinc-100 disabled:text-zinc-400 transition-all';

const buttonBase =
    'inline-flex min-h-9 items-center justify-center gap-2 rounded-lg border px-3.5 py-2 text-xs font-semibold shadow-xs transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#E00D42] focus-visible:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50 active:scale-[0.99] cursor-pointer';

export const hubButton =
    buttonBase + ' border-rose-200 bg-rose-50/85 text-[#C20836] hover:bg-[#E00D42] hover:text-white hover:border-[#E00D42] hover:shadow-sm';

export const hubPrimary =
    buttonBase + ' border-[#E00D42] bg-[#E00D42] text-white hover:bg-[#C20836] hover:border-[#C20836] shadow-sm hover:shadow-md';

export function HubPanel({
    title,
    description,
    icon,
    children,
    className = '',
    ...props
}: HTMLAttributes<HTMLElement> & { title?: string; description?: string; icon?: ReactNode }) {
    return (
        <section
            {...props}
            className={`overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs transition-all duration-150 ${className}`}
        >
            {title && (
                <div className="flex items-start justify-between gap-3 border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5 sm:px-6">
                    <div className="flex items-start gap-2.5">
                        {icon && <span className="mt-0.5 text-zinc-500">{icon}</span>}
                        <div>
                            <h2 className="text-sm font-semibold tracking-tight text-[#121316]">{title}</h2>
                            {description && <p className="mt-0.5 text-xs leading-5 text-zinc-500">{description}</p>}
                        </div>
                    </div>
                </div>
            )}
            <div className="p-5 sm:p-6">{children}</div>
        </section>
    );
}

export function HubBadge({
    children,
    tone = 'neutral',
}: {
    children: ReactNode;
    tone?: 'neutral' | 'success' | 'warning' | 'brand';
}) {
    const tones = {
        neutral: 'border-zinc-200/90 bg-zinc-100/80 text-zinc-700',
        success: 'border-emerald-200/90 bg-emerald-50/90 text-emerald-800',
        warning: 'border-amber-200/90 bg-amber-50/90 text-amber-800',
        brand: 'border-rose-200/90 bg-rose-50/90 text-[#C20836]',
    };
    return (
        <span className={`inline-flex items-center rounded-md border px-2 py-0.5 text-[11px] font-semibold tracking-tight ${tones[tone]}`}>
            {children}
        </span>
    );
}

export function HubButton({
    children,
    primary = false,
    className = '',
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { primary?: boolean }) {
    return (
        <button {...props} className={`${primary ? hubPrimary : hubButton} ${className}`}>
            {children}
        </button>
    );
}

export function HubPageHeader({
    title,
    description,
    icon: Icon,
    actions,
}: {
    title: string;
    description: ReactNode;
    icon: LucideIcon;
    actions?: ReactNode;
}) {
    return (
        <div className="hub-page-heading flex flex-wrap items-end justify-between gap-4 border-b border-zinc-200/80 pb-5">
            <div className="min-w-0">
                <div className="mb-1.5 inline-flex items-center gap-1.5 rounded-md border border-zinc-200/80 bg-white px-2 py-0.5 text-[11px] font-semibold text-zinc-600 shadow-2xs">
                    <Icon className="h-3.5 w-3.5 text-[#E00D42]" />
                    <span>Logistics Workspace</span>
                </div>
                <h1 className="text-2xl font-bold tracking-tight text-[#121316] sm:text-[26px]">{title}</h1>
                {description && <p className="mt-1 max-w-2xl text-xs sm:text-sm leading-relaxed text-zinc-500">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}

export function HubNextStep({
    title,
    children,
    action,
    tone = 'brand',
    icon: Icon = ArrowRight,
}: {
    title: string;
    children?: ReactNode;
    action?: ReactNode;
    tone?: 'brand' | 'warning' | 'success';
    icon?: LucideIcon;
}) {
    const accents = {
        brand: 'border-l-[#E00D42]',
        warning: 'border-l-amber-500',
        success: 'border-l-emerald-600',
    };
    const iconTones = {
        brand: 'bg-rose-50 text-[#E00D42] border-rose-200/70',
        warning: 'bg-amber-50 text-amber-700 border-amber-200/70',
        success: 'bg-emerald-50 text-emerald-700 border-emerald-200/70',
    };
    return (
        <section
            aria-label="Next step"
            className={`flex flex-wrap items-center justify-between gap-4 rounded-xl border border-zinc-200/90 border-l-4 bg-white px-5 py-4 shadow-xs ${accents[tone]}`}
        >
            <div className="flex min-w-0 flex-1 items-start gap-3.5">
                <div className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border ${iconTones[tone]}`}>
                    <Icon className="h-4 w-4" />
                </div>
                <div className="min-w-0">
                    <h2 className="text-sm font-semibold text-[#121316]">{title}</h2>
                    {children && <div className="mt-0.5 text-xs sm:text-sm leading-relaxed text-zinc-500">{children}</div>}
                </div>
            </div>
            {action && (
                <div className="flex w-full shrink-0 flex-wrap gap-2 sm:w-auto [&>*]:flex-1 sm:[&>*]:flex-none">
                    {action}
                </div>
            )}
        </section>
    );
}

export type HubStep = {
    title: string;
    detail?: string;
    state: 'done' | 'current' | 'waiting' | 'blocked';
    icon?: LucideIcon;
};

export function HubStepRail({ steps, label = 'Transfer progress' }: { steps: HubStep[]; label?: string }) {
    const colClass =
        steps.length === 5
            ? 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-5'
            : steps.length === 3
            ? 'grid-cols-1 sm:grid-cols-3'
            : 'grid-cols-2 sm:grid-cols-4';
    return (
        <ol
            tabIndex={0}
            aria-label={label}
            className={`hub-step-rail grid gap-2 overflow-x-auto rounded-xl border border-zinc-200/90 bg-white p-2.5 shadow-xs ${colClass} focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]`}
        >
            {steps.map((step, index) => {
                const Icon = step.state === 'done' ? Check : step.icon ?? Circle;
                return (
                    <li
                        key={step.title}
                        aria-current={step.state === 'current' ? 'step' : undefined}
                        className={`relative flex items-center gap-3 rounded-lg px-3.5 py-2.5 transition-colors ${
                            step.state === 'current'
                                ? 'bg-rose-50 border border-rose-300 shadow-2xs'
                                : step.state === 'done'
                                ? 'bg-rose-50/40 border border-rose-200/70'
                                : 'bg-zinc-50/50 border border-zinc-100'
                        }`}
                    >
                        <span
                            className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border text-xs font-bold ${
                                step.state === 'done'
                                    ? 'border-[#E00D42] bg-[#E00D42] text-white shadow-2xs'
                                    : step.state === 'current'
                                    ? 'border-[#E00D42] bg-[#E00D42] text-white ring-4 ring-[#E00D42]/15 shadow-2xs'
                                    : step.state === 'blocked'
                                    ? 'border-amber-300 bg-amber-50 text-amber-700'
                                    : 'border-zinc-200 bg-white text-zinc-400'
                            }`}
                        >
                            {step.state === 'waiting' ? index + 1 : <Icon className="h-3.5 w-3.5" />}
                        </span>
                        <span className="min-w-0 flex-1">
                            <span
                                className={`block truncate text-xs font-semibold ${
                                    step.state === 'current' || step.state === 'done'
                                        ? 'text-[#C20836]'
                                        : 'text-zinc-500'
                                }`}
                            >
                                {step.title}
                                <span className="sr-only">
                                    {' '}
                                    · {{ done: 'Completed', current: 'Current step', waiting: 'Waiting', blocked: 'Blocked' }[step.state]}
                                </span>
                            </span>
                            {step.detail && <span className="block truncate text-[11px] text-zinc-500">{step.detail}</span>}
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}

export type HubPlace = {
    id?: number;
    logistics_company_id?: number;
    name: string;
    code?: string;
    tier?: string;
    city_municipality?: string;
    province?: string;
    address?: string;
    latitude?: number | null;
    longitude?: number | null;
    parent_hub_id?: number | null;
};

export function HubRoute({
    stops,
    label = 'Recorded hub route',
    currentHubId,
}: {
    stops: HubPlace[];
    label?: string;
    currentHubId?: number | null;
}) {
    return (
        <div aria-label={label} className="rounded-xl border border-zinc-200/90 bg-zinc-50/70 p-4 shadow-2xs">
            <ol className="flex flex-wrap items-center gap-x-3 gap-y-3">
                {stops.map((stop, index) => (
                    <li key={`${stop.id ?? stop.code}-${index}`} className="flex min-w-0 flex-1 basis-32 items-center gap-2.5">
                        <div className="flex min-w-0 flex-1 items-center gap-2.5 rounded-lg border border-zinc-200/80 bg-white px-3 py-2 shadow-2xs">
                            <span
                                className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-md border ${
                                    stop.id === currentHubId
                                        ? 'border-rose-200 bg-rose-50 text-[#E00D42]'
                                        : 'border-zinc-200 bg-zinc-50 text-zinc-500'
                                }`}
                            >
                                <MapPin className="h-3.5 w-3.5" />
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block text-[10px] font-semibold uppercase tracking-wider text-zinc-400">
                                    {index === 0 ? 'Origin' : index === stops.length - 1 ? 'Destination' : 'Transit'}
                                    {stop.id === currentHubId ? ' · Active' : ''}
                                </span>
                                <span className="block truncate text-xs font-semibold text-[#121316]">{stop.name}</span>
                                {stop.code && <span className="block text-[10px] font-semibold text-zinc-500">{stop.code}</span>}
                            </span>
                        </div>
                        {index < stops.length - 1 && <ArrowRight className="h-3.5 w-3.5 shrink-0 text-zinc-400" />}
                    </li>
                ))}
            </ol>
        </div>
    );
}

export function HubEmpty({
    title,
    description,
    icon: Icon = Package,
    action,
}: {
    title: string;
    description: string;
    icon?: LucideIcon;
    action?: ReactNode;
}) {
    return (
        <div className="flex min-h-44 flex-col items-center justify-center rounded-xl border border-dashed border-zinc-200 bg-zinc-50/40 px-6 py-10 text-center">
            <span className="flex h-10 w-10 items-center justify-center rounded-lg border border-zinc-200/90 bg-white text-zinc-500 shadow-2xs">
                <Icon className="h-4 w-4" />
            </span>
            <h3 className="mt-3 text-sm font-semibold text-[#121316]">{title}</h3>
            <p className="mt-1 max-w-sm text-xs leading-relaxed text-zinc-500">{description}</p>
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}

export function HubSearch({
    label,
    value,
    onChange,
    placeholder,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
}) {
    return (
        <label className="relative block min-w-0">
            <span className="sr-only">{label}</span>
            <Search className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-zinc-400" />
            <input
                type="search"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                placeholder={placeholder ?? label}
                className={`${hubInput} pl-9`}
            />
        </label>
    );
}
