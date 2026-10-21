import { buyerOrderStages, isBuyerOrderIssue } from '@/utils/orderWorkspace';

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
        <div className="shrink-0 space-y-3">
            <div className="flex justify-end">
                <button
                    type="button"
                    disabled={disabled}
                    onClick={onRefresh}
                    className="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                >
                    {refreshing ? 'Refreshing…' : 'Refresh tracking'}
                </button>
            </div>
            <nav aria-label={showIssues ? 'Order issue status' : 'Purchase status'} tabIndex={0} data-buyer-profile-horizontal className="flex w-full min-w-0 flex-nowrap gap-1.5 overflow-x-auto overscroll-x-contain rounded-2xl border border-slate-300 bg-white p-1.5 shadow-xs focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]">
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
        </div>
    );
}
