import { Link } from '@inertiajs/react';
import { ChevronDown, MessageSquare, Package, Truck } from 'lucide-react';
import BuyerSavedReview from '@/Components/BuyerSavedReview';
import BuyerVoucherReceipt from '@/Components/Vouchers/BuyerVoucherReceipt';
import { Order } from '@/types';

const money = (amount: number | string) => new Intl.NumberFormat('en-PH', {
    style: 'currency', currency: 'PHP', minimumFractionDigits: 2,
}).format(Number(amount));

export default function BuyerOrderPackage({ order, canUsePortal }: { order: Order; canUsePortal: boolean }) {
    const delivery = order.delivery;
    return <section aria-label="Package items" className="space-y-4 rounded-2xl border border-slate-300 bg-white p-4 shadow-xs sm:p-6">
        <header className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-300 pb-4">
            <h2 className="flex items-center gap-2 text-sm font-bold text-slate-900"><Package className="h-4 w-4 text-[#E00D42]" aria-hidden="true" />Package items</h2>
            {delivery?.tracking_number && <p className="break-all text-xs text-slate-600">Tracking: <span className="font-semibold text-slate-800">{delivery.tracking_number}</span></p>}
        </header>
        {(order.items ?? []).length === 0 ? <p className="py-8 text-center text-sm text-slate-500">No package items are recorded for this order.</p> : <ul className="divide-y divide-slate-200">
            {order.items?.map(item => <li key={item.id} className="flex items-start gap-3 py-3 sm:gap-4">
                {item.product?.featured_image ? <img src={item.product.featured_image} alt={item.product.name} className="h-16 w-16 shrink-0 rounded-xl border border-slate-300 bg-slate-50 object-cover" />
                    : <span className="flex h-16 w-16 shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-slate-50"><Package className="h-6 w-6 text-slate-400" aria-hidden="true" /></span>}
                <div className="min-w-0 flex-1">
                    <div className="flex flex-col justify-between gap-1 sm:flex-row sm:gap-4">
                        {item.product?.slug ? <Link href={route('buyer.products.show', item.product.slug)} className="min-w-0 break-words text-sm font-bold text-slate-900 hover:text-[#C20836]">{item.product.name}</Link>
                            : <p className="break-words text-sm font-bold text-slate-900">{item.product?.name || 'Purchased item'}</p>}
                        <p className="shrink-0 text-sm font-bold tabular-nums text-[#E00D42]">{money(Number(item.unit_price) * item.quantity)}</p>
                    </div>
                    <p className="mt-1 text-xs leading-relaxed text-slate-600">Quantity: {item.quantity} × {money(item.unit_price)}{[item.color, item.size].filter(Boolean).length > 0 && <span className="ml-2">{[item.color, item.size].filter(Boolean).join(' / ')}</span>}</p>
                    {item.review && canUsePortal && <div className="mt-2"><BuyerSavedReview review={item.review} /></div>}
                </div>
            </li>)}
        </ul>}
        {delivery && <details className="group border-t border-slate-300 pt-1">
            <summary className="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 rounded-lg py-3 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42] [&::-webkit-details-marker]:hidden">
                <div className="flex min-w-0 items-center gap-2"><Truck className="h-4 w-4 shrink-0 text-[#E00D42]" aria-hidden="true" /><div className="min-w-0"><p className="text-xs font-semibold text-slate-800">Delivery details</p><p className="mt-0.5 break-words text-xs text-slate-600">{delivery.logistics_partner || 'Courier service not assigned'}</p></div></div>
                <ChevronDown className="h-5 w-5 shrink-0 text-slate-500 transition-transform group-open:rotate-180 motion-reduce:transition-none" aria-hidden="true" />
            </summary>
            <dl className="grid gap-5 pb-4 pt-2 text-xs sm:grid-cols-2">
                <div><dt className="text-slate-500">Seller pickup</dt><dd className="mt-1 break-words font-semibold text-slate-900">{delivery.pickup_store_name || 'Seller name not recorded'}</dd><dd className="mt-1 break-words leading-relaxed text-slate-600">{delivery.pickup_address || 'Pickup address not recorded'}</dd></div>
                <div><dt className="text-slate-500">Recipient destination</dt><dd className="mt-1 break-words font-semibold text-slate-900">{delivery.delivery_recipient_name || order.recipient_name || 'Recipient not recorded'}</dd><dd className="mt-1 break-words leading-relaxed text-slate-600">{delivery.delivery_address || order.shipping_address || 'Delivery address not recorded'}</dd></div>
                {delivery.courier?.name && <div><dt className="text-slate-500">Assigned pickup rider</dt><dd className="mt-1 break-words font-semibold text-slate-900">{delivery.courier.name}</dd></div>}
                {delivery.courier_notes && <div className="sm:col-span-2"><dt className="flex items-center gap-1.5 text-slate-500"><MessageSquare className="h-3.5 w-3.5" aria-hidden="true" />Courier notes</dt><dd className="mt-1 break-words leading-relaxed text-slate-700">{delivery.courier_notes}</dd></div>}
            </dl>
        </details>}
        <div className="border-t border-slate-300 pt-4">
            {order.voucher_receipt ? <BuyerVoucherReceipt receipt={order.voucher_receipt} /> : <p className="flex flex-wrap justify-between gap-2 text-sm font-bold text-slate-900"><span>Total amount</span><span className="tabular-nums text-[#E00D42]">{money(order.total_amount)}</span></p>}
        </div>
    </section>;
}
