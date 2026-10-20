export const buyerOrderStages = [
    { id: 'all', label: 'All' },
    { id: 'to_pay', label: 'To pay' },
    { id: 'to_ship', label: 'To ship' },
    { id: 'in_transit', label: 'In transit' },
    { id: 'delivered', label: 'Delivered' },
    { id: 'completed', label: 'Completed' },
    { id: 'delivery_failed', label: 'Delivery issue' },
    { id: 'returned', label: 'Returned' },
    { id: 'cancelled', label: 'Cancelled' },
    { id: 'return_refund', label: 'Return/Refund' },
];

export const buyerOrderIssueStages = buyerOrderStages.filter(stage =>
    ['delivery_failed', 'returned', 'cancelled', 'return_refund'].includes(stage.id),
);

export const isBuyerOrderIssue = (status: string) => buyerOrderIssueStages.some(stage => stage.id === status);

export const sellerOrderStages = [
    { id: 'all', label: 'All orders' },
    { id: 'to_pack', label: 'To pack' },
    { id: 'to_pickup', label: 'Ready for pickup' },
    ...buyerOrderStages.filter(stage => !['all', 'to_pay', 'to_ship'].includes(stage.id)),
    { id: 'return_custody', label: 'Return parcels' },
];
