import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Heart } from 'lucide-react';
import type { PageProps, Product } from '@/types';
import { bagHeartAction } from '@/utils/bagHeart';

export default function BagHeartButton({ product }: { product: Product }) {
    const { auth, cartItemIdsByProduct = {} } = usePage<PageProps>().props;
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState('');
    const cartItemId = cartItemIdsByProduct[product.id];
    const inBag = Boolean(cartItemId);
    const canChange = !auth.user || (auth.user.role === 'buyer' && ['active', 'pending_approval'].includes(auth.user.status ?? ''));
    const combinations = product.variants?.combinations ?? [];
    const defaultCombination = combinations.find(row => Number(row.stock) > 0);
    const defaultColor = defaultCombination?.color ?? product.variants?.colors?.find(color => color.in_stock !== false)?.name;
    const defaultSize = defaultCombination?.size ?? product.variants?.sizes?.find(size => Number(size.stock ?? product.stock) > 0)?.name;
    const hasAvailableVariant = combinations.length > 0
        ? combinations.some(row => Number(row.stock) > 0)
        : (!product.variants?.colors?.length || Boolean(defaultColor)) && (!product.variants?.sizes?.length || Boolean(defaultSize));
    const unavailable = Number(product.stock) <= 0 || !hasAvailableVariant || Boolean(product.shop?.vacation_mode);

    const activate = (event: React.MouseEvent<HTMLButtonElement>) => {
        event.preventDefault();
        event.stopPropagation();
        setError('');
        if (processing) return;
        const action = bagHeartAction(cartItemId);
        if (action.type === 'open') {
            router.get(route('buyer.cart', { focus: action.itemId }));
            return;
        }
        if (!canChange || unavailable) return;
        setProcessing(true);
        const options: Record<string, unknown> = {
            preserveScroll: true,
            onError: (errors: Record<string, string>) => setError(Object.values(errors).flat().join(' ')),
            onFinish: () => setProcessing(false),
        };
        router.post(route('cart.store'), {
            product_id: product.id,
            quantity: 1,
            ...(defaultColor ? { color: defaultColor } : {}),
            ...(defaultSize ? { size: defaultSize } : {}),
        }, options);
    };

    const label = inBag ? `Already in Bag: ${product.name}. Open Bag.` : `Add one ${product.name} to Bag`;
    return <div className="flex flex-col items-end gap-1">
        <button type="button" onClick={activate} disabled={processing || (!inBag && (!canChange || unavailable))}
            aria-label={label} aria-pressed={inBag} title={inBag ? 'Already in your Bag' : unavailable ? 'Currently unavailable' : 'Add one to Bag'}
            className={`inline-flex h-10 w-10 items-center justify-center rounded-full transition hover:scale-105 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:cursor-not-allowed disabled:opacity-50 ${inBag ? 'text-[#E00D42]' : 'text-white hover:text-rose-200'}`}>
            <Heart className={`h-5 w-5 drop-shadow-[0_1px_2px_rgba(0,0,0,0.9)] ${inBag ? 'fill-current' : ''}`} />
        </button>
        {error && <span role="alert" className="max-w-48 rounded bg-white px-2 py-1 text-[10px] text-rose-700 shadow">{error}</span>}
    </div>;
}
