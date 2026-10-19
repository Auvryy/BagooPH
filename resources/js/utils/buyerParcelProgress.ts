import { Order, OrderTracking } from '@/types';

type Event = OrderTracking['history'][number];
export type BuyerProgressOrder = Pick<Order, 'status' | 'created_at' | 'items'>;
export interface BuyerOrderNotice {
    id: string;
    type?: string;
    data: { title: string; body?: string | null };
    milestone?: string | null;
    checkpoint_id?: number | null;
    read_at: string | null;
    created_at: string;
}
export interface BuyerMilestone {
    key: 'placed' | 'packing' | 'shipped' | 'received' | 'review';
    label: string;
    current: boolean;
    recorded: boolean;
    complete: boolean;
    missing: boolean;
}
export interface BuyerJourneyEntry {
    key: string;
    checkpoint: Event | null;
    label: string;
    location: string | null;
    created_at: string | null;
    notices: BuyerOrderNotice[];
}

const packing = ['confirmed', 'preparing', 'seller_pack', 'ready_for_pickup', 'handover_fulfillment_confirmed'];
const shipped = ['courier_pickup', 'picked_up', 'handover_holder_acknowledged', 'arrived_at_origin_hub',
    'in_transit_to_mother_hub', 'arrived_at_mother_hub', 'sorted_to_line_haul', 'in_transit_to_destination_hub',
    'arrived_at_destination_hub', 'sorted_to_barangay_bin', 'assigned_to_rider', 'out_for_delivery', 'ready_for_hub_pickup'];
const handover = ['delivered', 'doorstep_handover', 'customer_collected'];
const receipt = ['completed', 'buyer_confirmed', 'buyer_completed'];
const aliases: Record<string, string[]> = {
    placed: ['placed', 'order_placed'], preparing: ['preparing', 'seller_pack'], completed: receipt,
};
const time = (value: string | null) => {
    const parsed = value ? Date.parse(value) : NaN;
    return Number.isFinite(parsed) ? parsed : null;
};

export function originalBuyerJourney(tracking: OrderTracking): Event[] {
    const seen = new Set<number>();
    return (tracking.history ?? []).filter(event => {
        if (seen.has(event.id)) return false;
        seen.add(event.id);
        return true;
    }).sort((a, b) => (time(a.created_at) ?? Infinity) - (time(b.created_at) ?? Infinity) || a.id - b.id);
}

export function buyerJourneyException(tracking: OrderTracking): string | null {
    const status = tracking.commercial_status, parcel = tracking.parcel_status;
    if (status === 'cancelled' || parcel === 'cancelled') return 'Order cancelled';
    if (status === 'returned' || parcel === 'returned') return 'Returned to seller';
    if (parcel && ['return_to_sender', 'return_in_transit'].includes(parcel)) return 'Return in progress';
    if (status === 'delivery_failed' || parcel && ['failed', 'delivery_failed'].includes(parcel)) return 'Delivery issue';
    return null;
}

export function buyerMilestones(tracking: OrderTracking, order?: BuyerProgressOrder): BuyerMilestone[] {
    const history = originalBuyerJourney(tracking);
    const has = (types: string[]) => history.some(event => types.includes(event.type ?? ''));
    const status = tracking.commercial_status;
    const parcel = tracking.parcel_status ?? '';
    const items = order?.items ?? [];
    const reviewed = status === 'completed' && items.length > 0 && items.every(item => Boolean(item.review));
    const recorded = [time(tracking.placed_at ?? order?.created_at ?? null) !== null || has(['placed', 'order_placed']),
        has(packing), has(shipped), has(handover), reviewed];
    const confirmedReceipt = has(receipt);
    let current = buyerJourneyException(tracking) ? -1
        : status === 'completed' ? reviewed ? -1 : 4
            : status === 'delivered' || ['delivered', 'customer_collected'].includes(parcel) ? 3
                : ['picked_up', 'at_sorting_center', 'sorted', 'assigned_to_rider', 'out_for_delivery', 'shipped', 'in_transit'].includes(status)
                    || shipped.includes(parcel) ? 2
                    : ['confirmed', 'preparing', 'ready_for_pickup', 'processing', 'packaging'].includes(status)
                        || ['assigned', 'assigned_pickup'].includes(parcel) ? 1
                        : status === 'placed' || status === 'pending' ? 0 : -1;
    if (current === -1 && !buyerJourneyException(tracking) && status !== 'completed') {
        current = recorded[3] ? 3 : recorded[2] ? 2 : recorded[1] ? 1 : recorded[0] ? 0 : -1;
    }
    const phases = [
        { key: 'placed', label: 'Order placed' }, { key: 'packing', label: 'Confirmed' },
        { key: 'shipped', label: 'Shipped out' }, { key: 'received', label: 'Order received' },
        { key: 'review', label: reviewed ? 'Review saved' : 'Rate & review' },
    ] as const;
    return phases.map((phase, index) => ({ ...phase, recorded: recorded[index], current: index === current,
        complete: recorded[index] && (index === 4 ? reviewed
            : index === 3 ? confirmedReceipt : index < current || current === -1),
        missing: !recorded[index] && (current > index || status === 'completed'),
    }));
}

export function buyerJourneyEntries(tracking: OrderTracking, notices: BuyerOrderNotice[] = []): BuyerJourneyEntry[] {
    const entries: BuyerJourneyEntry[] = originalBuyerJourney(tracking).map(event => ({
        key: `checkpoint-${event.id}`, checkpoint: event, label: event.label, location: event.location,
        created_at: event.created_at, notices: [],
    }));
    const seen = new Set<string>();
    for (const notice of notices) {
        if (seen.has(notice.id)) continue;
        seen.add(notice.id);
        let target = notice.checkpoint_id ? entries.find(entry => entry.checkpoint?.id === notice.checkpoint_id) : undefined;
        if (!target && notice.type === 'order-event' && notice.milestone) {
            const types = Object.hasOwn(aliases, notice.milestone) ? aliases[notice.milestone] : [notice.milestone];
            const candidates = entries.filter(entry => entry.checkpoint && types.includes(entry.checkpoint.type ?? ''));
            // Repeated scans cannot be collapsed just because they share a milestone.
            if (candidates.length === 1) target = candidates[0];
        }
        if (target) target.notices.push(notice);
        else entries.push({ key: `notice-${notice.id}`, checkpoint: null, label: notice.data.title,
            location: null, created_at: notice.created_at, notices: [notice] });
    }
    return entries.sort((a, b) => {
        const left = time(a.created_at), right = time(b.created_at);
        return left !== null && right !== null ? left - right : left === null && right !== null ? 1 : left !== null ? -1 : 0;
    });
}
