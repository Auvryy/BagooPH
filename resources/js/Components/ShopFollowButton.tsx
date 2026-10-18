import { Link, useForm, usePage } from '@inertiajs/react';
import { Heart } from 'lucide-react';
import { useRef } from 'react';
import { PageProps } from '@/types';

export default function ShopFollowButton({ shopId, following }: { shopId: number; following: boolean }) {
    const { auth } = usePage<PageProps>().props;
    const form = useForm({ shop_id: shopId });
    const busy = useRef(false);
    const eligible = auth.user?.role === 'buyer' && auth.user.status === 'active'
        && ['approved', 'verified'].includes(auth.user.kyc_status ?? '');
    const classes = 'inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-xs font-semibold text-slate-800 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60';
    if (!auth.user) return <Link href={route('login')} className={classes}><Heart className="h-4 w-4" />Sign in to follow</Link>;

    const toggle = () => {
        if (busy.current || !eligible) return;
        busy.current = true;
        const options = { preserveScroll: true, onFinish: () => { busy.current = false; } };
        if (following) form.delete(route('buyer.followed-shops.destroy', shopId), options);
        else form.post(route('buyer.followed-shops.store', shopId), options);
    };
    return <div className="space-y-1">
        <button type="button" className={classes} disabled={form.processing || !eligible} aria-pressed={following} onClick={toggle}>
            <Heart className={`h-4 w-4 text-[#E00D42] ${following ? 'fill-current' : ''}`} />
            {form.processing ? 'Saving…' : !eligible ? 'Approved buyer required' : following ? 'Following' : 'Follow shop'}
        </button>
        {form.errors.shop_id && <p role="alert" className="max-w-xs text-xs text-red-700">{form.errors.shop_id}</p>}
    </div>;
}
