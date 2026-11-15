import { ReactNode } from 'react';

/** A titled content group. Use for real groupings, not as a default wrapper. */
export default function Panel({ step, title, description, action, children, className = '', 'aria-label': ariaLabel }: {
    step?: number;
    title: ReactNode;
    description?: ReactNode;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
    'aria-label'?: string;
}) {
    return <section aria-label={ariaLabel} className={`rounded-card border border-line-200 bg-white ${className}`}>
        <header className="flex items-start justify-between gap-4 px-5 pt-5 sm:px-6">
            <div className="flex min-w-0 items-start gap-3">
                {step !== undefined && <span aria-hidden="true" className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-ink-900 text-[13px] font-semibold tabular-nums text-white">{step}</span>}
                <div className="min-w-0">
                    <h2 className="text-lg font-semibold leading-6 text-ink-900">{title}</h2>
                    {description && <p className="mt-0.5 text-sm text-ink-600">{description}</p>}
                </div>
            </div>
            {action && <div className="shrink-0">{action}</div>}
        </header>
        <div className="px-5 pb-5 pt-4 sm:px-6 sm:pb-6">{children}</div>
    </section>;
}
