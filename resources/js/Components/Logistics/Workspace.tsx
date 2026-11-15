import type { ButtonHTMLAttributes, HTMLAttributes, ReactNode } from 'react';
import { ArrowRight, Check, Circle, Info, MapPin, Package, Search, type LucideIcon } from 'lucide-react';

export const hubInput =
    'w-full min-w-0 max-w-full min-h-10 rounded-control border border-line-300 bg-white px-3 py-2 text-sm text-ink-900 placeholder:text-ink-500 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/40 disabled:bg-surface-100 disabled:text-ink-500 transition-colors';

const buttonBase =
    'inline-flex min-h-10 items-center justify-center gap-2 rounded-control border px-4 py-2 text-sm font-semibold transition-colors duration-150 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 disabled:cursor-not-allowed disabled:opacity-50 cursor-pointer';

export const hubButton =
    buttonBase + ' border-line-300 bg-white text-ink-900 hover:bg-surface-100';

export const hubPrimary =
    buttonBase + ' border-brand-500 bg-brand-500 text-white hover:border-brand-600 hover:bg-brand-600';

/** Long explanations stay off the page: shown on hover or keyboard focus, always read by screen readers. */
export function InfoHint({ children, label = 'More information' }: { children: ReactNode; label?: string }) {
    return (
        <span className="group/hint relative inline-flex align-middle">
            <button type="button" aria-label={label} className="inline-flex h-6 w-6 items-center justify-center rounded-full text-ink-400 hover:bg-surface-100 hover:text-ink-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500">
                <Info className="h-4 w-4" aria-hidden="true" />
            </button>
            <span role="tooltip" className="pointer-events-none absolute left-0 top-full z-30 mt-1.5 hidden w-72 rounded-control border border-line-200 bg-white p-3 text-[13px] font-normal leading-5 text-ink-700 shadow-float group-hover/hint:block group-focus-within/hint:block">
                {children}
            </span>
        </span>
    );
}

/** Keep short context visible; move long explanations into an InfoHint. */
const isLong = (value: ReactNode) => typeof value === 'string' && value.length > 70;

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
            className={`overflow-hidden rounded-card border border-line-200 bg-white ${className}`}
        >
            {title && (
                <div className="flex items-start justify-between gap-3 px-5 pt-5 sm:px-6">
                    <div className="flex items-start gap-2.5">
                        {icon && <span className="mt-0.5 text-ink-500">{icon}</span>}
                        <div>
                            <h2 className="flex items-center gap-1 text-base font-semibold text-ink-900">{title}{description && isLong(description) && <InfoHint>{description}</InfoHint>}</h2>
                            {description && !isLong(description) && <p className="mt-0.5 text-[13px] leading-5 text-ink-600">{description}</p>}
                        </div>
                    </div>
                </div>
            )}
            <div className={title ? 'px-5 pb-5 pt-4 sm:px-6 sm:pb-6' : 'p-5 sm:p-6'}>{children}</div>
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
        neutral: 'bg-surface-100 text-ink-700',
        success: 'bg-success-soft text-success',
        warning: 'bg-warning-soft text-warning',
        brand: 'bg-brand-50 text-brand-600',
        danger: 'bg-brand-50 text-brand-700',
    };
    return (
        <span className={`inline-flex items-center rounded-chip px-2 py-0.5 text-xs font-medium ${tones[tone]}`}>
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
        <div className="hub-page-heading flex flex-wrap items-end justify-between gap-4 pb-1">
            <div className="min-w-0">
                <p className="mb-1 flex items-center gap-1.5 text-[13px] font-medium text-ink-600">
                    <Icon className="h-4 w-4 text-ink-500" aria-hidden="true" />
                    <span>{roleLabel ?? 'Logistics workspace'}</span>
                </p>
                <h1 className="flex items-center gap-1.5 text-[28px] font-bold leading-[34px] tracking-tight text-ink-900">{title}{description && isLong(description) && <InfoHint>{description}</InfoHint>}</h1>
                {description && !isLong(description) && <p className="mt-1 max-w-2xl text-sm leading-relaxed text-ink-600">{description}</p>}
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
    tone?: 'brand' | 'warning' | 'success' | 'danger';
    icon?: LucideIcon;
}) {
    const accents = {
        brand: 'border-brand-100 bg-brand-50/50',
        warning: 'border-warning/20 bg-warning-soft',
        success: 'border-success/20 bg-success-soft',
        danger: 'border-brand-100 bg-brand-50/50',
    };
    const iconTones = {
        brand: 'bg-white text-brand-500',
        warning: 'bg-white text-warning',
        success: 'bg-white text-success',
        danger: 'bg-white text-brand-700',
    };
    return (
        <section
            aria-label="Next step"
            className={`flex flex-wrap items-center justify-between gap-4 rounded-card border px-5 py-4 ${accents[tone]}`}
        >
            <div className="flex min-w-0 flex-1 items-start gap-3.5">
                <div className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${iconTones[tone]}`}>
                    <Icon className="h-4 w-4" />
                </div>
                <div className="min-w-0">
                    <h2 className="text-sm font-semibold text-ink-900">{title}</h2>
                    {children && <div className="mt-0.5 text-[13px] leading-relaxed text-ink-700">{children}</div>}
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
            className={`hub-step-rail grid gap-2 overflow-x-auto rounded-card border border-line-200 bg-white p-2 ${colClass} focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500`}
        >
            {steps.map((step, index) => {
                const Icon = step.state === 'done' ? Check : step.icon ?? Circle;
                return (
                    <li
                        key={step.title}
                        aria-current={step.state === 'current' ? 'step' : undefined}
                        className={`relative flex items-center gap-3 rounded-control px-3 py-2.5 transition-colors ${
 step.state === 'current'
 ? 'bg-brand-50'
 : step.state === 'blocked'
 ? 'bg-warning-soft'
 : ''
 }`}
                    >
                        <span
                            className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full border-2 text-xs font-semibold tabular-nums ${
 step.state === 'done'
 ? 'border-brand-500 bg-brand-500 text-white'
 : step.state === 'current'
 ? 'border-brand-500 bg-white text-brand-600'
 : step.state === 'blocked'
 ? 'border-warning bg-white text-warning'
 : 'border-line-300 bg-white text-ink-500'
 }`}
                        >
                            {step.state === 'waiting' ? index + 1 : <Icon className="h-3.5 w-3.5" />}
                        </span>
                        <span className="min-w-0 flex-1">
                            <span
                                className={`block truncate text-[13px] font-medium ${
 step.state === 'current'
 ? 'font-semibold text-brand-600'
 : step.state === 'done'
 ? 'text-ink-900'
 : step.state === 'blocked'
 ? 'text-warning'
 : 'text-ink-500'
 }`}
                            >
                                {step.title}
                                <span className="sr-only">
                                    {' '}
                                    · {{ done: 'Completed', current: 'Current step', waiting: 'Waiting', blocked: 'Blocked' }[step.state]}
                                </span>
                            </span>
                            {step.detail && <span className="block truncate text-xs text-ink-600">{step.detail}</span>}
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
        <div role="tablist" aria-label={label} className="flex items-center gap-x-1 overflow-x-auto border-b border-line-200 [scrollbar-width:none]">
            {tabs.map((tab) => {
                const selected = tab.id === activeId;
                return (
                    <button
                        key={tab.id}
                        role="tab"
                        type="button"
                        aria-selected={selected}
                        onClick={() => onChange(tab.id)}
                        className={`-mb-px inline-flex min-h-10 shrink-0 items-center gap-2 whitespace-nowrap border-x-0 border-t-0 border-b-2 px-3 py-2 focus-visible:-outline-offset-2 text-sm font-medium transition-colors cursor-pointer focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500 ${
 selected
 ? 'border-brand-500 text-brand-600'
 : 'border-transparent text-ink-600 hover:border-line-300 hover:text-ink-900'
 }`}
                    >
                        <span>{tab.label}</span>
                        {tab.count !== undefined && (
                            <span
                                className={`rounded-chip px-1.5 py-0.5 text-xs font-medium tabular-nums ${
 selected ? 'bg-brand-50 text-brand-600' : 'bg-surface-100 text-ink-600'
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
        <div aria-label={label} className="rounded-control bg-surface-100 p-3">
            <ol className="flex flex-wrap items-center gap-x-3 gap-y-3">
                {stops.map((stop, index) => (
                    <li key={`${stop.id ?? stop.code}-${index}`} className="flex min-w-0 flex-1 basis-32 items-center gap-2.5">
                        <div className="flex min-w-0 flex-1 items-center gap-2.5 rounded-control bg-white px-3 py-2">
                            <span
                                className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full ${
 stop.id === currentHubId
 ? 'bg-brand-500 text-white'
 : 'bg-surface-100 text-ink-500'
 }`}
                            >
                                <MapPin className="h-3.5 w-3.5" />
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block text-xs text-ink-500">
                                    {index === 0 ? 'Origin' : index === stops.length - 1 ? 'Destination' : 'Transit'}
                                    {stop.id === currentHubId ? ' · Active' : ''}
                                </span>
                                <span className="block truncate text-[13px] font-medium text-ink-900">{stop.name}</span>
                                {stop.code && <span className="block text-xs text-ink-500">{stop.code}</span>}
                            </span>
                        </div>
                        {index < stops.length - 1 && <ArrowRight className="h-3.5 w-3.5 shrink-0 text-ink-400" aria-hidden="true" />}
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
        <div className="flex min-h-36 flex-col items-center justify-center rounded-control bg-surface-50 px-6 py-8 text-center">
            <span className="flex h-10 w-10 items-center justify-center rounded-full bg-surface-100 text-ink-500">
                <Icon className="h-4 w-4" aria-hidden="true" />
            </span>
            <h3 className="mt-3 text-sm font-semibold text-ink-900">{title}</h3>
            {description && <p className="mt-1 max-w-sm text-[13px] leading-relaxed text-ink-600">{description}</p>}
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
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-500" aria-hidden="true" />
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
