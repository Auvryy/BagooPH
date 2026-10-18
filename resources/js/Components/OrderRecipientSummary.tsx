export type OrderRecipientSnapshot = {
    recipient_name?: string | null; recipient_phone?: string | null;
    shipping_address?: string | null; destination_barangay?: string | null;
    shipping_city?: string | null; shipping_province?: string | null; shipping_postal_code?: string | null;
};

export default function OrderRecipientSummary({ order }: { order: OrderRecipientSnapshot }) {
    const address = [order.shipping_address, order.destination_barangay, order.shipping_city, order.shipping_province, order.shipping_postal_code].filter(Boolean).join(', ');
    return <>
        <p className="font-bold text-slate-900">{order.recipient_name || 'Recipient not recorded'}</p>
        <p className="text-[10px] text-slate-600">{address || 'Delivery address not recorded'}</p>
        <p className="text-[10px] text-slate-600">{order.recipient_phone || 'Phone not recorded'}</p>
    </>;
}
