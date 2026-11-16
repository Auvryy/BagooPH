import { Link } from '@inertiajs/react';
import { Banknote, Wallet } from 'lucide-react';
import { walletMoney } from '@/utils/wallet';

export default function BuyerPaymentChoice({ value, onChange, balanceCents, disabled = false, compact = false, codDisabled = false, codDisabledReason = null }: {
    value: string; onChange: (method: 'cod' | 'wallet') => void; balanceCents: number | null; disabled?: boolean; compact?: boolean; codDisabled?: boolean; codDisabledReason?: string | null;
}) {
    return <fieldset className={compact ? 'grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2' : 'space-y-3'}><legend className="sr-only">Payment method</legend>
        {(['cod', 'wallet'] as const).map(method => {
            const optionDisabled = disabled || (method === 'wallet' && balanceCents === null) || (method === 'cod' && codDisabled);
            return <label key={method} className={`flex cursor-pointer items-start gap-3 rounded-control border p-4 transition-colors focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-brand-500 ${value === method ? 'border-brand-500 bg-brand-50/60' : 'border-line-300 bg-white hover:border-ink-400'} ${optionDisabled ? 'opacity-60 cursor-not-allowed' : ''}`}>
            <input type="radio" name="payment_method" value={method} checked={value === method} disabled={optionDisabled} onChange={() => onChange(method)} className="mt-0.5 border-line-300 text-brand-500 focus:ring-brand-500" />
            <span aria-hidden="true" className={"flex h-9 w-9 shrink-0 items-center justify-center rounded-control bg-surface-100 text-ink-600"}>
                {method === 'cod' ? <Banknote className="h-5 w-5" /> : <Wallet className="h-5 w-5" />}
            </span>
            <span className="min-w-0"><span className="block text-sm font-semibold text-ink-900">{method === 'cod' ? 'Cash on delivery (COD)' : 'Bagoo Wallet'}</span>{(!compact || method === 'wallet') && <span className="mt-1 block text-[13px] leading-5 text-ink-600">{compact ? (method === 'wallet' ? `Virtual credits · ${balanceCents === null ? 'Unavailable' : walletMoney(balanceCents)}` : '') : method === 'cod' ? 'Pay the rider or hub counter when you receive your parcel.' : `Balance: ${balanceCents === null ? 'Unavailable' : walletMoney(balanceCents)}. You confirm payment after placing the order; unpaid wallet orders expire after one hour.`}</span>}</span>
        </label>;
        })}
        {!compact && <p className="text-xs leading-5 text-ink-600">Wallet credits are virtual and can only be used on Bagoo. <Link href={route('buyer.profile', { tab: 'wallet' })} className="font-medium text-ink-900 underline decoration-line-300 underline-offset-4 hover:decoration-ink-900">View wallet</Link></p>}
    </fieldset>;
}
