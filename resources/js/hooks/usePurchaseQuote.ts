import { useEffect, useState } from 'react';
import axios from 'axios';

export interface VoucherOffer {
    id: number;
    revision_id?: number;
    code: string;
    name: string | null;
    description: string | null;
    discount_type: 'fixed' | 'percent' | 'free_shipping';
    discount_value: number | string;
    min_spend: number | string;
    max_discount: number | string | null;
    shop_id: number | null;
    shop_name: string | null;
    issuer: 'shop' | 'platform';
    expires_at: string | null;
    eligible?: boolean | null;
    unavailable_reason?: string | null;
    saving?: number | null;
    saved_id?: number | null;
    use_url?: string;
    starts_at?: string;
    uses?: { id: number; used_at: string; saving: number; code: string; name: string }[];
}

export interface PurchaseQuote {
    subtotal: number;
    shipping_fee: number;
    doorstep_shipping_fee: number;
    discount: number;
    shipping_discount: number;
    total: number;
    shops: { shop_id: number; shop_name: string; subtotal: number; shipping: number; doorstep_shipping: number; discount: number; product_discount?: number; shipping_discount?: number; total: number }[];
    items: { id: number; quantity: number; unit_price: number; subtotal: number; price_segments?: import('@/Components/PriceSegments').PriceSegment[] }[];
    voucher: VoucherOffer | null;
    voucher_error: string | null;
    vouchers?: VoucherOffer[];
    redemptions?: { revision_id: number; discount_cents: number; allocations?: { shop_id: number; product_discount_cents: number; shipping_discount_cents: number }[] }[];
}

interface QuoteInput {
    item_ids: number[];
    item_quantities?: Record<number, number>;
    delivery_type?: 'doorstep' | 'hub_self_pickup';
    code?: string;
    platform_voucher_revision_id?: number | null;
    shop_voucher_revision_ids?: Record<number, number | null>;
    shipping_province?: string;
    shipping_city?: string;
    pickup_hub_id?: string | number;
    bag?: boolean;
    revision: string;
}

export const emptyPurchaseQuote: PurchaseQuote = {
    subtotal: 0, shipping_fee: 0, doorstep_shipping_fee: 0, discount: 0,
    shipping_discount: 0, total: 0, shops: [], items: [], voucher: null, voucher_error: null,
};

export default function usePurchaseQuote(input: QuoteInput, initialQuote = emptyPurchaseQuote, initialOffers: VoucherOffer[] = []) {
    const key = JSON.stringify(input);
    const [snapshot, setSnapshot] = useState<{ key: string | null; quote: PurchaseQuote; offers: VoucherOffer[]; validSelection?: { voucher_code: string | null; platform_voucher_revision_id: number | null; shop_voucher_revision_ids: Record<number, number | null> }; selectionErrors?: Record<string, string> }>({
        key: null, quote: initialQuote, offers: initialOffers,
    });
    const [fetching, setFetching] = useState(false);
    const [failure, setFailure] = useState('');

    useEffect(() => {
        const { bag, revision, ...body } = JSON.parse(key) as QuoteInput;
        if (body.item_ids.length === 0) {
            setSnapshot({ key, quote: emptyPurchaseQuote, offers: [] });
            setFailure('');
            setFetching(false);
            return;
        }
        const controller = new AbortController();
        setFetching(true);
        setFailure('');
        const accept = (data: { quote?: PurchaseQuote; availableVouchers?: VoucherOffer[]; validSelection?: typeof snapshot.validSelection; selectionErrors?: Record<string, string> }) => {
            if (!controller.signal.aborted) {
                const quote = data?.quote;
                if (!quote || !Array.isArray(quote.shops) || !Array.isArray(quote.items) || !['subtotal', 'shipping_fee', 'doorstep_shipping_fee', 'discount', 'shipping_discount', 'total']
                    .every(field => {
                        const amount = quote[field as keyof PurchaseQuote];
                        return typeof amount === 'number' && Number.isFinite(amount) && amount >= 0;
                    })) {
                    setFailure('Could not update your total. Reload to try again.');
                    return;
                }
                setSnapshot(current => ({ key, quote, offers: data.availableVouchers ?? current.offers, validSelection: data.validSelection, selectionErrors: data.selectionErrors }));
            }
        };
        const timer = window.setTimeout(() => {
            const options = { signal: controller.signal, headers: { Accept: 'application/json' } };
            const request = bag
                ? axios.get(route('cart.index'), { ...options, params: { item_ids: body.item_ids } })
                : axios.post(route('buyer.vouchers.apply'), body, options);
            request.then(response => accept(response.data)).catch(error => {
                if (controller.signal.aborted) return;
                if (error.response?.data?.quote) {
                    accept(error.response.data);
                } else {
                    const data = error.response?.data;
                    const fieldError = Object.values(data?.errors ?? {}).flat()[0];
                    setFailure(error.response?.status === 419 ? 'Your session has expired. Reload the page and try again.'
                        : typeof fieldError === 'string' ? fieldError : error.response?.status >= 500 || !data?.message
                            ? 'Could not update your total. Change your selection or reload to try again.' : data.message);
                }
            }).finally(() => {
                if (!controller.signal.aborted) setFetching(false);
            });
        }, 150);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [key]);

    const empty = input.item_ids.length === 0;
    const pending = !empty && (fetching || (snapshot.key !== key && !failure));
    const current = snapshot.key === key;
    return {
        quote: empty ? emptyPurchaseQuote : snapshot.quote,
        offers: snapshot.offers, pending, failure, current,
        validSelection: current ? snapshot.validSelection : undefined,
        selectionErrors: current ? snapshot.selectionErrors : undefined,
        ready: !empty && current && !pending && !failure && !snapshot.quote.voucher_error,
    };
}
