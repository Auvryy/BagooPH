import type { LucideIcon } from 'lucide-react';

export default function AccountSectionHeading({ icon: Icon, title, description, as: Heading = 'h2', compact = false }: {
    icon: LucideIcon; title: string; description?: string; as?: 'h2' | 'h3'; compact?: boolean;
}) {
    return <div className="flex min-w-0 items-start gap-3" data-account-section-heading={title}>
        <span className={`flex shrink-0 items-center justify-center rounded-xl bg-rose-50 text-[#C20836] ${compact ? 'h-8 w-8' : 'h-10 w-10'}`}>
            <Icon className={compact ? 'h-4 w-4' : 'h-5 w-5'} aria-hidden="true" />
        </span>
        <div className="min-w-0"><Heading className={`${compact ? 'text-sm' : 'text-base'} font-bold text-slate-900`}>{title}</Heading>
            {description && <p className="mt-1 text-xs leading-relaxed text-slate-600">{description}</p>}
        </div>
    </div>;
}
