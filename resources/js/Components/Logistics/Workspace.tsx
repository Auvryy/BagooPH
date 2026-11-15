import type { ButtonHTMLAttributes, HTMLAttributes, ReactNode } from 'react';
import { ArrowRight, Check, Circle, MapPin, Package, Search, type LucideIcon } from 'lucide-react';

export const hubInput =
    'w-full min-w-0 max-w-full rounded border border-zinc-400 bg-white px-3.5 py-2 text-sm text-[#121316] shadow-2xs placeholder:text-zinc-400 focus:border-[#E00D42] focus:outline-none focus:ring-2 focus:ring-[#E00D42]/15 disabled:bg-zinc-100 disabled:text-zinc-400 transition-all';

const buttonBase =
    'inline-flex min-h-9 items-center justify-center gap-2 rounded border px-3.5 py-2 text-xs font-semibold shadow-xs transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#E00D42] focus-visible:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50 active:scale-[0.99] cursor-pointer';

export const hubButton =
    buttonBase + ' border-zinc-400 bg-white text-zinc-800 hover:bg-zinc-50 hover:text-[#121316] hover:border-zinc-600 shadow-2xs';

export const hubPrimary =
    buttonBase + ' border-[#C20836] bg-[#E00D42] text-white hover:bg-[#C20836] hover:border-[#9F1239] shadow-sm hover:shadow-md';

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
            className={`overflow-hidden rounded border border-zinc-300 bg-white shadow-xs transition-all duration-150 ${className}`}
        >
            {title && (
                <div className="flex items-start justify-between gap-3 border-b border-zinc-300 bg-zinc-50/60 px-5 py-4 sm:px-6">
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
    tone?: 'neutral' | 'success' | 'warning' | 'brand' | 'danger';
}) {
    const tones = {
        neutral: 'border-zinc-300 bg-zinc-100 text-zinc-800',
        success: 'border-emerald-300 bg-emerald-50 text-emerald-800',
        warning: 'border-amber-300 bg-amber-50 text-amber-800',
        brand: 'border-rose-300 bg-rose-50 text-[#C20836]',
        danger: 'border-rose-300 bg-rose-50 text-[#C20836]',
    };
    return (
        <span className={`inline-flex items-center rounded-sm border px-2.5 py-0.5 text-xs font-semibold tracking-tight ${tones[tone]}`}>
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
    roleLabel,
}: {
    title: string;
    description?: ReactNode;
    icon: LucideIcon;
    actions?: ReactNode;
    roleLabel?: string;
}) {
    return (
        <div className="hub-page-heading flex flex-wrap items-end justify-between gap-4 border-b border-zinc-300 pb-5">
            <div className="min-w-0">
                <div className="mb-1.5 inline-flex items-center gap-1.5 rounded-sm border border-zinc-300 bg-zinc-50 px-2.5 py-0.5 text-xs font-semibold text-zinc-700">
                    <Icon className="h-3.5 w-3.5 text-zinc-600" />
                    <span>{roleLabel ?? 'Logistics Workspace'}</span>
                </div>
                <h1 className="text-2xl font-bold tracking-tight text-[#121316] sm:text-[26px]">{title}</h1>
                {description && <p className="mt-1.5 max-w-2xl text-xs sm:text-sm leading-relaxed text-zinc-600">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2.5">{actions}</div>}
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
    tone?: 'brand' | 'warning' | 'success' | 'danger';
    icon?: LucideIcon;
}) {
    const accents = {
        brand: 'border-l-[#E00D42]',
        warning: 'border-l-amber-500',
        success: 'border-l-emerald-600',
        danger: 'border-l-[#E00D42]',
    };
    const iconTones = {
        brand: 'bg-rose-50 text-[#E00D42] border-rose-300',
        warning: 'bg-amber-50 text-amber-700 border-amber-300',
        success: 'bg-emerald-50 text-emerald-700 border-emerald-300',
        danger: 'bg-rose-50 text-[#E00D42] border-rose-300',
    };
    return (
        <section
            aria-label="Next step"
            className={`flex flex-wrap items-center justify-between gap-4 rounded border border-zinc-300 border-l-4 bg-white px-5 py-4 shadow-xs ${accents[tone]}`}
        >
            <div className="flex min-w-0 flex-1 items-start gap-3.5">
                <div className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded border ${iconTones[tone]}`}>
                    <Icon className="h-4 w-4" />
                </div>
                <div className="min-w-0">
                    <h2 className="text-sm font-semibold text-[#121316]">{title}</h2>
                    {children && <div className="mt-0.5 text-xs sm:text-sm leading-relaxed text-zinc-600">{children}</div>}
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
            className={`hub-step-rail grid gap-2.5 overflow-x-auto rounded border border-zinc-300 bg-white p-3 shadow-xs ${colClass} focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]`}
        >
            {steps.map((step, index) => {
                const Icon = step.state === 'done' ? Check : step.icon ?? Circle;
                return (
                    <li
                        key={step.title}
                        aria-current={step.state === 'current' ? 'step' : undefined}
                        className={`relative flex items-center gap-3 rounded px-3.5 py-2.5 transition-colors ${
                            step.state === 'current'
                                ? 'bg-rose-50/40 border border-[#E00D42] shadow-2xs'
                                : step.state === 'done'
                                ? 'bg-emerald-50/70 border border-emerald-300'
                                : step.state === 'blocked'
                                ? 'bg-amber-50/80 border border-amber-300'
                                : 'bg-zinc-50 border border-zinc-300'
                        }`}
                    >
                        <span
                            className={`flex h-7 w-7 shrink-0 items-center justify-center rounded border text-xs font-bold ${
                                step.state === 'done'
                                    ? 'border-emerald-700 bg-emerald-600 text-white shadow-2xs'
                                    : step.state === 'current'
                                    ? 'border-[#C20836] bg-[#E00D42] text-white ring-2 ring-[#E00D42]/20 shadow-2xs'
                                    : step.state === 'blocked'
                                    ? 'border-amber-400 bg-amber-50 text-amber-800'
                                    : 'border-zinc-300 bg-white text-zinc-600'
                            }`}
                        >
                            {step.state === 'waiting' ? index + 1 : <Icon className="h-3.5 w-3.5" />}
                        </span>
                        <span className="min-w-0 flex-1">
                            <span
                                className={`block truncate text-xs font-semibold ${
                                    step.state === 'current'
                                        ? 'text-[#121316]'
                                        : step.state === 'done'
                                        ? 'text-emerald-900'
                                        : step.state === 'blocked'
                                        ? 'text-amber-900'
                                        : 'text-zinc-600'
                                }`}
                            >
                                {step.title}
                                <span className="sr-only">
                                    {' '}
                                    · {{ done: 'Completed', current: 'Current step', waiting: 'Waiting', blocked: 'Blocked' }[step.state]}
                                </span>
                            </span>
                            {step.detail && <span className="block truncate text-xs text-zinc-500">{step.detail}</span>}
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}

export type LogisticsCategoryTab = {
    id: string;
    label: string;
    count?: number;
    description?: string;
};

export function HubCategoryTabs({
    tabs,
    activeId,
    onChange,
    label = 'Work categories',
}: {
    tabs: LogisticsCategoryTab[];
    activeId: string;
    onChange: (id: string) => void;
    label?: string;
}) {
    return (
        <div role="tablist" aria-label={label} className="flex flex-wrap items-center gap-2 rounded border border-zinc-300 bg-white p-1.5 shadow-2xs">
            {tabs.map((tab) => {
                const selected = tab.id === activeId;
                return (
                    <button
                        key={tab.id}
                        role="tab"
                        type="button"
                        aria-selected={selected}
                        onClick={() => onChange(tab.id)}
                        className={`inline-flex min-h-9 items-center gap-2 rounded px-3.5 py-1.5 text-xs font-semibold transition cursor-pointer ${
                            selected
                                ? 'border border-[#C20836] bg-[#E00D42] text-white shadow-2xs'
                                : 'border border-transparent text-zinc-700 hover:border-zinc-300 hover:bg-zinc-100 hover:text-[#121316]'
                        }`}
                    >
                        <span>{tab.label}</span>
                        {tab.count !== undefined && (
                            <span
                                className={`rounded-sm px-1.5 py-0.5 text-xs font-bold tabular-nums ${
                                    selected ? 'bg-white/15 text-white' : 'bg-zinc-100 border border-zinc-300 text-zinc-700'
                                }`}
                            >
                                {tab.count}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}

export function RoleNextStepCard(_props: {
    roleTitle: string;
    summary: string;
    steps: string[];
    action?: ReactNode;
}) {
    return null;
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
        <div aria-label={label} className="rounded border border-zinc-300 bg-zinc-50 p-4 shadow-2xs">
            <ol className="flex flex-wrap items-center gap-x-3 gap-y-3">
                {stops.map((stop, index) => (
                    <li key={`${stop.id ?? stop.code}-${index}`} className="flex min-w-0 flex-1 basis-32 items-center gap-2.5">
                        <div className="flex min-w-0 flex-1 items-center gap-2.5 rounded border border-zinc-300 bg-white px-3 py-2 shadow-2xs">
                            <span
                                className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-sm border ${
                                    stop.id === currentHubId
                                        ? 'border-zinc-400 bg-zinc-100 text-[#121316]'
                                        : 'border-zinc-300 bg-zinc-50 text-zinc-600'
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
    description?: string;
    icon?: LucideIcon;
    action?: ReactNode;
}) {
    return (
        <div className="flex min-h-40 flex-col items-center justify-center rounded border border-dashed border-zinc-300 bg-zinc-50/50 px-6 py-8 text-center">
            <span className="flex h-10 w-10 items-center justify-center rounded border border-zinc-300 bg-white text-zinc-600 shadow-2xs">
                <Icon className="h-4 w-4" />
            </span>
            <h3 className="mt-3 text-sm font-semibold text-[#121316]">{title}</h3>
            {description && <p className="mt-1 max-w-sm text-xs leading-relaxed text-zinc-500">{description}</p>}
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
