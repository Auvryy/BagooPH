import { OrderTracking } from '@/types';

type Event = OrderTracking['history'][number];
export interface BuyerMilestone {
    key: string;
    label: string;
    current: boolean;
    recorded: boolean;
    time?: string | null;
    location?: string;
}

const phases = [
    { key: 'seller', label: 'Seller preparation', types: ['order_placed', 'placed', 'confirmed', 'preparing', 'seller_pack', 'ready_for_pickup', 'handover_fulfillment_confirmed'] },
    { key: 'pickup', label: 'Pickup rider', types: ['assigned_pickup', 'courier_pickup', 'picked_up', 'handover_holder_acknowledged'] },
    { key: 'origin', label: 'Origin Bayan Hub', types: ['arrived_at_origin_hub', 'in_transit_to_mother_hub'] },
    { key: 'mother', label: 'Mother Hub', types: ['arrived_at_mother_hub', 'sorted_to_line_haul', 'in_transit_to_destination_hub'] },
    { key: 'destination', label: 'Destination Bayan Hub', types: ['arrived_at_destination_hub', 'sorted_to_barangay_bin', 'failed_delivery_hub_return'] },
    { key: 'last_mile', label: 'Delivery rider', types: ['assigned_to_rider', 'out_for_delivery', 'ready_for_hub_pickup'] },
    { key: 'handover', label: 'Delivered', types: ['delivered', 'doorstep_handover', 'customer_collected'] },
    { key: 'receipt', label: 'Receipt confirmed', types: ['completed', 'buyer_confirmed', 'buyer_completed'] },
];

export function originalBuyerJourney(tracking: OrderTracking): Event[] {
    const seen = new Set<number>();
    return (tracking.history ?? []).filter(event => {
        if (seen.has(event.id)) return false;
        seen.add(event.id); return true;
    });
}

export function buyerMilestones(tracking: OrderTracking): BuyerMilestone[] {
    const history = originalBuyerJourney(tracking);
    const status = tracking.commercial_status;
    const parcel = tracking.parcel_status ?? '';
    const terminal = status === 'cancelled' ? 'cancelled'
        : status === 'returned' || parcel === 'returned' ? 'returned'
            : ['return_to_sender', 'return_in_transit'].includes(parcel) ? 'returning'
                : status === 'delivery_failed' || ['failed', 'delivery_failed'].includes(parcel) ? 'issue' : null;
    let current = terminal ?? (status === 'completed' ? 'receipt' : status === 'delivered' ? 'handover'
        : ['ready_for_hub_pickup', 'assigned_to_rider', 'out_for_delivery'].includes(parcel) ? 'last_mile'
            : ['arrived_at_destination_hub', 'sorted', 'sorted_to_barangay_bin'].includes(parcel) ? 'destination'
                : ['arrived_at_mother_hub', 'sorted_to_line_haul', 'in_transit_to_destination_hub'].includes(parcel) ? 'mother'
                    : ['arrived_at_origin_hub', 'in_transit_to_mother_hub'].includes(parcel) ? 'origin'
                        : ['assigned', 'assigned_pickup', 'picked_up'].includes(parcel) ? 'pickup'
                            : ['placed', 'confirmed', 'preparing', 'ready_for_pickup'].includes(status) ? 'seller' : '');
    if (!current) {
        const latest = [...history].reverse().find(event => phases.some(phase => phase.types.includes(event.type ?? '')));
        current = phases.find(phase => phase.types.includes(latest?.type ?? ''))?.key ?? '';
    }
    const pickup = tracking.delivery_type === 'hub_self_pickup';
    const result = phases.map(phase => {
        const event = [...history].reverse().find(event => phase.types.includes(event.type ?? ''));
        const placed = phase.key === 'seller' ? tracking.placed_at : null;
        return { key: phase.key, label: phase.key === 'last_mile' && pickup ? 'Hub collection' : phase.key === 'handover' && pickup ? 'Collected' : phase.label,
            current: phase.key === current, recorded: Boolean(event || placed), time: event?.created_at ?? placed, location: event?.location };
    });
    if (terminal) {
        const types = terminal === 'cancelled' ? ['cancelled'] : terminal === 'returned' ? ['returned', 'parcel_returned']
            : terminal === 'returning' ? ['return_to_sender', 'return_in_transit', 'expired_pickup_return_started', 'seller_return_staged'] : ['delivery_failed'];
        const event = [...history].reverse().find(event => types.includes(event.type ?? ''));
        result.push({ key: terminal, label: terminal === 'cancelled' ? 'Cancelled' : terminal === 'returned' ? 'Returned to seller' : terminal === 'returning' ? 'Return in progress' : 'Delivery issue',
            current: true, recorded: Boolean(event), time: event?.created_at ?? null, location: event?.location });
    }
    return result;
}
