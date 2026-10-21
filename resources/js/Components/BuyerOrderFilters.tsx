import { buyerOrderStages, isBuyerOrderIssue } from '@/utils/orderWorkspace';
import { RefreshCw } from 'lucide-react';

interface Props {
    selected: string;
    counts: Record<string, number>;
    disabled: boolean;
    onChange: (status: string) => void;
    onRefresh: () => void;
    refreshing: boolean;
}

export default function BuyerOrderFilters({ selected, counts, disabled, onChange, onRefresh, refreshing }: Props) {
    const showIssues = isBuyerOrderIssue(selected);
    const stages = buyerOrderStages.filter(stage => isBuyerOrderIssue(stage.id) === showIssues);

    return (
        <div className="flex min-w-0 shrink-0 items-center gap-1.5 rounded-2xl border border-slate-300 bg-white p-1.5 shadow-xs">
            <nav aria-label={showIssues ? 'Order issue status' : 'Purchase status'} tabIndex={0} data-buyer-profile-horizontal className="flex min-w-0 flex-1 flex-nowrap gap-1.5 overflow-x-auto overscroll-x-contain rounded-xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]">
                {stages.map(stage => (
                    <button
                        key={stage.id}
                        type="button"
                        disabled={disabled}
                        aria-pressed={selected === stage.id}
                        onClick={() => onChange(stage.id)}
                        className={`flex-[1_0_auto] whitespace-nowrap rounded-xl px-3 py-2 text-center text-xs font-bold uppercase transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42] disabled:opacity-50 ${
                            selected === stage.id ? 'bg-[#E00D42] text-white shadow-xs' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'
                        }`}
                    >
                        {stage.label} <span className="whitespace-nowrap">({counts[stage.id] ?? 0})</span>
                    </button>
                ))}
            </nav>
            <button
                type="button"
                disabled={disabled}
                onClick={onRefresh}
                aria-label={refreshing ? 'Refreshing tracking' : 'Refresh tracking'}
                title="Refresh tracking"
                className="inline-flex min-h-9 shrink-0 items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42] disabled:opacity-50"
            >
                <RefreshCw className="h-4 w-4" aria-hidden="true" />
                <span className="sr-only sm:not-sr-only">{refreshing ? 'Refreshing…' : 'Refresh tracking'}</span>
            </button>
        </div>
    );
}
