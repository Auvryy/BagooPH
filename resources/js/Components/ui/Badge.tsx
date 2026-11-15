import { ReactNode } from 'react';

export type BadgeTone = 'info' | 'success' | 'warning' | 'danger' | 'neutral';

const tones: Record<BadgeTone, string> = {
    info: 'bg-info-soft text-info',
    success: 'bg-success-soft text-success',
    warning: 'bg-warning-soft text-warning',
    danger: 'bg-brand-50 text-brand-700',
    neutral: 'bg-surface-100 text-ink-600',
};

/** Status label: human wording plus a tone, never color alone. */
export default function Badge({ tone = 'neutral', children }: { tone?: BadgeTone; children: ReactNode }) {
    return <span className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded px-2 py-0.5 text-xs font-medium ${tones[tone]}`}>
        <span aria-hidden="true" className="h-1.5 w-1.5 rounded-full bg-current" />
        {children}
    </span>;
}
