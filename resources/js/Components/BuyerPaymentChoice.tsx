import { Link } from '@inertiajs/react';
import { walletMoney } from '@/utils/wallet';

export default function BuyerPaymentChoice({ value, onChange, balanceCents, disabled = false }: {
    value: string; onChange: (method: 'cod' | 'wallet') => void; balanceCents: number | null; disabled?: boolean;
}) {
    return <fieldset className="space-y-3"><legend className="mb-3 text-sm font-bold text-slate-900">Payment method</legend>
        {(['cod', 'wallet'] as const).map(method => <label key={method} className={`flex cursor-pointer items-start gap-3 rounded-xl border p-4 ${value === method ? 'border-[#E00D42] bg-rose-50/40' : 'border-slate-300 bg-white'} ${disabled ? 'opacity-60' : ''}`}>
            <input type="radio" name="payment_method" value={method} checked={value === method} disabled={disabled || (method === 'wallet' && balanceCents === null)} onChange={() => onChange(method)} className="mt-0.5 border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" />
            <span className="min-w-0"><span className="block text-xs font-bold text-slate-900">{method === 'cod' ? 'Cash on delivery (COD)' : 'Bagoo Wallet'}</span><span className="mt-1 block text-xs leading-5 text-slate-600">{method === 'cod' ? 'Pay the assigned rider or pickup counter when you receive the parcel.' : `Available demo credits: ${balanceCents === null ? 'Unavailable' : walletMoney(balanceCents)}. Review and confirm payment after placing the order. Unpaid wallet orders expire after one hour.`}</span></span>
        </label>)}
        <p className="text-xs leading-5 text-slate-600">Wallet credits are virtual and usable only inside Bagoo. <Link href={route('buyer.profile', { tab: 'wallet' })} className="font-semibold text-[#C20836] underline underline-offset-2">View wallet or request credits</Link></p>
    </fieldset>;
}
