export const buyerOrderStages = [
    { id: 'all', label: 'All' },
    { id: 'to_ship', label: 'To ship' },
    { id: 'in_transit', label: 'In transit' },
    { id: 'delivered', label: 'Delivered' },
    { id: 'completed', label: 'Completed' },
    { id: 'delivery_failed', label: 'Delivery issue' },
    { id: 'returned', label: 'Returned' },
    { id: 'cancelled', label: 'Cancelled' },
];

export const sellerOrderStages = [
    { id: 'all', label: 'All orders' },
    { id: 'to_pack', label: 'To pack' },
    { id: 'to_pickup', label: 'Ready for pickup' },
    ...buyerOrderStages.slice(2),
    { id: 'return_custody', label: 'Return parcels' },
];
