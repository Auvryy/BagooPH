import { Link } from '@inertiajs/react';
import { Banknote, Wallet } from 'lucide-react';
import { walletMoney } from '@/utils/wallet';

export default function BuyerPaymentChoice({ value, onChange, balanceCents, disabled = false, compact = false, codDisabled = false, codDisabledReason = null }: {
    value: string; onChange: (method: 'cod' | 'wallet') => void; balanceCents: number | null; disabled?: boolean; compact?: boolean; codDisabled?: boolean; codDisabledReason?: string | null;
}) {
    return <fieldset className={compact ? 'grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2' : 'space-y-3'}><legend className={compact ? 'sr-only' : 'mb-3 text-sm font-bold text-slate-900'}>Payment method</legend>
        {(['cod', 'wallet'] as const).map(method => {
            const optionDisabled = disabled || (method === 'wallet' && balanceCents === null) || (method === 'cod' && codDisabled);
            return <label key={method} className={`flex cursor-pointer items-start gap-3 rounded-xl border p-4 ${value === method ? 'border-[#E00D42] bg-rose-50/40' : 'border-slate-300 bg-white'} ${optionDisabled ? 'opacity-60 cursor-not-allowed' : ''}`}>
            <input type="radio" name="payment_method" value={method} checked={value === method} disabled={optionDisabled} onChange={() => onChange(method)} className="mt-0.5 border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" />
            <span aria-hidden="true" className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${value === method ? 'bg-[#E00D42]/10 text-[#C20836]' : 'bg-slate-100 text-slate-600'}`}>
                {method === 'cod' ? <Banknote className="h-5 w-5" /> : <Wallet className="h-5 w-5" />}
            </span>
            <span className="min-w-0"><span className="block text-xs font-bold text-slate-900">{method === 'cod' ? 'Cash on delivery (COD)' : 'Bagoo Wallet'}</span>{(!compact || method === 'wallet') && <span className="mt-1 block text-xs leading-5 text-slate-600">{compact ? (method === 'wallet' ? `Virtual credits · ${balanceCents === null ? 'Unavailable' : walletMoney(balanceCents)}` : '') : method === 'cod' ? 'Pay the assigned rider or pickup counter when you receive the parcel.' : `Available demo credits: ${balanceCents === null ? 'Unavailable' : walletMoney(balanceCents)}. Review and confirm payment after placing the order. Unpaid wallet orders expire after one hour.`}</span>}</span>
        </label>;
        })}
        {!compact && <p className="text-xs leading-5 text-slate-600">Wallet credits are virtual and usable only inside Bagoo. <Link href={route('buyer.profile', { tab: 'wallet' })} className="font-semibold text-[#C20836] underline underline-offset-2">View wallet or request credits</Link></p>}
    </fieldset>;
}
