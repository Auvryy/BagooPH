import { AlertCircle, Package } from 'lucide-react';

interface Props {
    active: 'progress' | 'issues' | null;
    ordersCount: number;
    issueCount: number;
    disabled: boolean;
    onSelect: (group: 'progress' | 'issues') => void;
}

export default function BuyerPurchaseNavigation({ active, ordersCount, issueCount, disabled, onSelect }: Props) {
    return (
        <div className="space-y-1.5">
            <button
                type="button"
                disabled={disabled}
                aria-current={active === 'progress' ? 'page' : undefined}
                onClick={() => onSelect('progress')}
                className={`flex w-full items-center justify-between rounded-2xl px-4 py-3 text-left font-bold transition disabled:opacity-50 ${
                    active === 'progress' ? 'bg-[#E00D42] text-white shadow-xs' : 'text-slate-700 hover:bg-slate-50 hover:text-slate-900'
                }`}
            >
                <span className="flex items-center gap-3">
                    <Package className={`h-4 w-4 shrink-0 ${active === 'progress' ? 'text-white' : 'text-slate-400'}`} />
                    <span>My Purchases &amp; Orders</span>
                </span>
                <span className={`shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold ${active === 'progress' ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600'}`}>
                    {ordersCount}
                </span>
            </button>
            <button
                type="button"
                disabled={disabled}
                aria-current={active === 'issues' ? 'page' : undefined}
                onClick={() => onSelect('issues')}
                className={`ml-7 flex w-[calc(100%-1.75rem)] items-center justify-between rounded-xl px-4 py-2.5 text-left font-semibold transition disabled:opacity-50 ${
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
    );
}
