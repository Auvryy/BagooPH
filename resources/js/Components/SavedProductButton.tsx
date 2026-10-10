import { Link, useForm, usePage } from '@inertiajs/react';
import { Heart } from 'lucide-react';
import { useRef } from 'react';
import { PageProps } from '@/types';

export default function SavedProductButton({ productId }: { productId: number }) {
    const { auth, savedProductIds = [] } = usePage<PageProps>().props;
    const form = useForm({ product_id: productId });
    const busy = useRef(false);
    const saved = savedProductIds.includes(productId);
    const eligible = auth.user?.role === 'buyer' && auth.user.status === 'active'
        && ['approved', 'verified'].includes(auth.user.kyc_status ?? '');
    const classes = 'inline-flex w-full items-center justify-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-[#E00D42] hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60';

    if (!auth.user) return <Link href={route('login')} className={classes}><Heart className="h-4 w-4" />Sign in to save</Link>;

    const toggle = () => {
        if (busy.current || !eligible) return;
        busy.current = true;
        const options = { preserveScroll: true, onFinish: () => { busy.current = false; } };
        if (saved) form.delete(route('buyer.saved-products.destroy', productId), options);
        else form.post(route('buyer.saved-products.store', productId), options);
    };

    return <div className="space-y-1">
        <button type="button" disabled={form.processing || !eligible} aria-pressed={saved} onClick={toggle} className={classes}>
            <Heart className={`h-4 w-4 ${saved ? 'fill-current' : ''}`} />
            {form.processing ? 'Saving…' : !eligible ? 'Approved buyer required' : saved ? 'Remove from saved' : 'Save product'}
        </button>
        {form.errors.product_id && <p role="alert" className="text-xs text-red-700">{form.errors.product_id}</p>}
    </div>;
}
