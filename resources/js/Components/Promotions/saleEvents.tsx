import { Badge } from '@/Components/ui';

export interface SaleEventSummary {
    id: number; slug: string; title: string; badge: string; banner_url: string | null;
    starts_at: string; ends_at: string; status: 'upcoming' | 'live' | 'ended';
}

export interface EventVoucherRow {
    id: number; voucher_id: number; code: string; name: string; discount_type: 'fixed' | 'percent' | 'free_shipping'; discount_value: number;
    min_spend: number; max_discount: number; shop_name: string | null; issuer: 'platform' | 'shop'; claim_limit: number | null;
    claimed_count: number; sold_out: boolean; usable: boolean;
}

export interface EventProductRow {
    sale_id: number; id: number; name: string; slug: string; image: string | null; regular_price: number; price: number;
    discount_pct: number; live: boolean; shop: { id: number; name: string } | null;
}

export const eventDate = (value: string) => new Date(value).toLocaleString('en-GB', {
    timeZone: 'Asia/Manila', day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true,
});

export const peso = (value: number) => `₱${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export function EventStatus({ status }: { status: SaleEventSummary['status'] }) {
    return status === 'live' ? <Badge tone="success">Live</Badge> : status === 'upcoming' ? <Badge tone="info">Upcoming</Badge> : <Badge tone="neutral">Ended</Badge>;
}

export function voucherLabel(voucher: Pick<EventVoucherRow, 'discount_type' | 'discount_value'>) {
    const amount = `₱${voucher.discount_value.toLocaleString('en-PH', { maximumFractionDigits: 2 })}`;
    return voucher.discount_type === 'percent' ? `${voucher.discount_value}% off` : voucher.discount_type === 'free_shipping' ? `${amount} off shipping` : `${amount} off`;
}

/** Converts an ISO time to the value a datetime-local input expects, in Philippine time. */
export function manilaInput(value: string | null | undefined): string {
    if (!value) return '';
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
        .formatToParts(new Date(value)).reduce<Record<string, string>>((all, part) => ({ ...all, [part.type]: part.value }), {});
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}
