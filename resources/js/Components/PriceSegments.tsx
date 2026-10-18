import React from 'react';
export interface PriceSegment { quantity: number; unit_price: number; subtotal: number; sale_kind?: 'flash' | 'store' | null }
interface Props { segments?: PriceSegment[]; quantity: number; unitPrice: number; detailed?: boolean }
const money = (amount: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(amount);
export default function PriceSegments({ segments, quantity, unitPrice, detailed = false }: Props) {
    if (!segments?.length) return <span>{quantity} × {money(unitPrice)}</span>;
    return <span className="inline-flex flex-col gap-1">{segments.map((segment, index) => <span key={index}>{segment.quantity} × {money(segment.unit_price)}{segment.sale_kind && <span className="ml-1 text-[10px] text-slate-500">{segment.sale_kind === 'flash' ? 'Flash' : 'Sale'}</span>}{detailed && <span className="ml-2 font-semibold">{money(segment.subtotal)}</span>}</span>)}</span>;
}
