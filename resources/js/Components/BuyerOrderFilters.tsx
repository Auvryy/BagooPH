import { buyerOrderStages } from '@/utils/orderWorkspace';

const additionalStatuses = new Set(['delivery_failed', 'returned', 'cancelled']);

interface Props {
    selected: string;
    counts: Record<string, number>;
    disabled: boolean;
    onChange: (status: string) => void;
    onRefresh: () => void;
    refreshing: boolean;
}

export default function BuyerOrderFilters({ selected, counts, disabled, onChange, onRefresh, refreshing }: Props) {
    return (
        <div className="shrink-0 space-y-3">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="min-w-0">
                    <label htmlFor="buyer-more-statuses" className="mb-1.5 block text-xs font-semibold text-slate-700">
                        More statuses
                    </label>
                    <select
                        id="buyer-more-statuses"
                        value={additionalStatuses.has(selected) ? selected : ''}
                        disabled={disabled}
                        onChange={event => {
                            if (event.target.value) onChange(event.target.value);
                        }}
                        className={`max-w-full rounded-xl border bg-white py-2 pl-3 pr-9 text-xs font-semibold focus:border-[#E00D42] focus:ring-[#E00D42] disabled:opacity-50 ${
                            additionalStatuses.has(selected) ? 'border-[#E00D42] text-[#C20836]' : 'border-slate-300 text-slate-700'
                        }`}
                    >
                        <option value="" disabled>Choose a status</option>
                        {buyerOrderStages.filter(stage => additionalStatuses.has(stage.id)).map(stage => (
                            <option key={stage.id} value={stage.id}>{stage.label} ({counts[stage.id] ?? 0})</option>
                        ))}
                    </select>
                </div>
                <button
                    type="button"
                    disabled={disabled}
                    onClick={onRefresh}
                    className="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                >
                    {refreshing ? 'Refreshing…' : 'Refresh tracking'}
                </button>
            </div>
            <nav aria-label="Purchase status" className="grid grid-cols-2 gap-1.5 rounded-2xl border border-slate-300 bg-white p-1.5 shadow-xs sm:grid-cols-5">
                {buyerOrderStages.filter(stage => !additionalStatuses.has(stage.id)).map(stage => (
                    <button
                        key={stage.id}
                        type="button"
                        disabled={disabled}
                        aria-pressed={selected === stage.id}
                        onClick={() => onChange(stage.id)}
                        className={`min-w-0 rounded-xl px-2 py-2 text-center text-xs font-bold uppercase transition disabled:opacity-50 ${
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
