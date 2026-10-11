import { AlertCircle, ChevronDown, Package } from 'lucide-react';

interface Props {
    active: 'progress' | 'issues' | null;
    ordersCount: number;
    issueCount: number;
    disabled: boolean;
    onSelect: (group: 'progress' | 'issues') => void;
}

export default function BuyerPurchaseNavigation({ active, ordersCount, issueCount, disabled, onSelect }: Props) {
    const expanded = active !== null;

    return (
        <div className="space-y-1.5">
            <button
                type="button"
                disabled={disabled}
                aria-current={active === 'progress' ? 'page' : undefined}
                aria-expanded={expanded}
                aria-controls="buyer-order-submenu"
                onClick={() => onSelect('progress')}
                className={`flex w-full items-center justify-between rounded-2xl px-4 py-3 text-left font-bold transition disabled:opacity-50 ${
                    active === 'progress' ? 'bg-[#E00D42] text-white shadow-xs' : 'text-slate-700 hover:bg-slate-50 hover:text-slate-900'
                }`}
            >
                <span className="flex items-center gap-3">
                    <Package className={`h-4 w-4 shrink-0 ${active === 'progress' ? 'text-white' : 'text-slate-400'}`} />
                    <span>My Purchases &amp; Orders</span>
                </span>
                <span className="flex shrink-0 items-center gap-2">
                    <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold ${active === 'progress' ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600'}`}>
                        {ordersCount}
                    </span>
                    <ChevronDown aria-hidden="true" className={`h-3.5 w-3.5 opacity-70 transition-transform motion-reduce:transition-none ${expanded ? 'rotate-180' : ''}`} />
                </span>
            </button>
            <div id="buyer-order-submenu" hidden={!expanded} className="ml-6 border-l border-slate-300 pl-2">
                <button
                    type="button"
                    disabled={disabled}
                    aria-current={active === 'issues' ? 'page' : undefined}
                    onClick={() => onSelect('issues')}
                    className={`flex w-full items-center justify-between rounded-xl px-4 py-2.5 text-left font-semibold transition disabled:opacity-50 ${
                        active === 'issues' ? 'bg-[#E00D42] text-white shadow-xs' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'
                    }`}
                >
                    <span className="flex items-center gap-2.5">
                        <AlertCircle className={`h-4 w-4 shrink-0 ${active === 'issues' ? 'text-white' : 'text-slate-400'}`} />
                        <span>Order issues</span>
                    </span>
                    <span className={`shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold ${active === 'issues' ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600'}`}>
                        {issueCount}
                    </span>
                </button>
            </div>
        </div>
    );
}
