import { useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { PageProps } from '@/types';
import { VoucherOffer } from '@/hooks/usePurchaseQuote';

export default function SavedVoucherButton({ offer }: { offer: VoucherOffer }) {
    const { auth } = usePage<PageProps>().props;
    const [saved, setSaved] = useState(offer.saved_id ?? null);
    const [pending, setPending] = useState(false);
    const [error, setError] = useState('');
    const busy = useRef(false);
    useEffect(() => { setSaved(offer.saved_id ?? null); }, [offer.saved_id, offer.revision_id]);
    if (!auth.user) return <Link href={route('login')} className="rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold">Sign in to save</Link>;
    if (auth.user.role !== 'buyer') return null;
    const toggle = async () => {
        if (busy.current || !offer.revision_id) return;
        busy.current = true; setPending(true); setError('');
        try {
            const response = saved ? await axios.delete(route('buyer.vouchers.remove', saved))
                : await axios.post(route('buyer.vouchers.save', offer.revision_id));
            setSaved(response.data.saved_id);
            router.reload({ only: ['voucherCollection', 'availableVoucherCount', 'shopOffers'] });
        } catch (failure) {
            setError(axios.isAxiosError(failure) && failure.response?.status === 419 ? 'Reload to renew your session.'
                : axios.isAxiosError(failure) && failure.response?.status === 422 ? 'This voucher is no longer available to save.' : 'Could not update your saved voucher. Try again.');
        } finally { busy.current = false; setPending(false); }
    };
    return <div className="space-y-1">
        <button type="button" onClick={toggle} aria-pressed={saved !== null} disabled={pending || (!saved && offer.eligible === false)}
            className="min-h-10 rounded-xl border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-[#E00D42] disabled:opacity-50">
            {pending ? 'Saving…' : saved ? 'Remove saved' : 'Save voucher'}
        </button>
        {error && <p role="alert" className="max-w-48 text-xs text-rose-700">{error}</p>}
    </div>;
}
