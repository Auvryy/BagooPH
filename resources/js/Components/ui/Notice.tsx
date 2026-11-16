import { ReactNode } from 'react';
import { AlertTriangle, CheckCircle2, Info, XCircle } from 'lucide-react';

type Tone = 'info' | 'success' | 'warning' | 'danger' | 'neutral';

const tones: Record<Tone, string> = {
    info: 'border-info/20 bg-info-soft text-ink-900 [&_.notice-icon]:text-info',
    success: 'border-success/20 bg-success-soft text-ink-900 [&_.notice-icon]:text-success',
    warning: 'border-warning/25 bg-warning-soft text-ink-900 [&_.notice-icon]:text-warning',
    danger: 'border-brand-700/20 bg-brand-50 text-ink-900 [&_.notice-icon]:text-brand-700',
    neutral: 'border-line-200 bg-surface-100 text-ink-900 [&_.notice-icon]:text-ink-600',
};

const icons: Record<Tone, typeof Info> = { info: Info, success: CheckCircle2, warning: AlertTriangle, danger: XCircle, neutral: Info };

/** Inline message with icon and text so meaning never depends on color alone. */
export default function Notice({ tone = 'neutral', title, children, action, role, className = '' }: {
    tone?: Tone; title?: ReactNode; children?: ReactNode; action?: ReactNode; role?: 'alert' | 'status'; className?: string;
}) {
    const Icon = icons[tone];
    return <div role={role} className={`flex gap-3 rounded-control border px-3.5 py-3 text-sm ${tones[tone]} ${className}`}>
        <Icon className="notice-icon mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
        <div className="min-w-0 flex-1 space-y-1">
            {title && <p className="font-semibold">{title}</p>}
            {children && <div className="text-ink-700">{children}</div>}
            {action && <div className="pt-1">{action}</div>}
        </div>
    </div>;
}
