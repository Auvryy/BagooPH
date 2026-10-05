import { Head, Link } from '@inertiajs/react';
import BuyerOrderAccessLayout from '@/Layouts/BuyerOrderAccessLayout';
import { Order, PaginatedData } from '@/types';

interface Props {
    orders: PaginatedData<Pick<Order, 'id' | 'order_number' | 'status' | 'total_amount' | 'created_at'>>;
    canUsePortal: boolean;
}

export default function BuyerOrders({ orders, canUsePortal }: Props) {
    return (
        <BuyerOrderAccessLayout canUsePortal={canUsePortal}>
            <Head title="My orders — BagooPH" />
            <div className="space-y-4">
                <h1 className="text-2xl font-bold">My orders</h1>
                {!orders.data.length && <p className="rounded-xl border border-slate-300 bg-white p-5 text-slate-600">You have no recorded orders.</p>}
                {orders.data.map(order => (
                    <Link key={order.id} href={route('buyer.orders.show', order.id)} className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-300 bg-white p-5 transition hover:border-[#E00D42] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]">
                        <div className="min-w-0 space-y-1">
                            <p className="break-words font-bold">Order {order.order_number}</p>
                            <p className="text-sm text-slate-600">{order.status.replaceAll('_', ' ')} · {new Date(order.created_at).toLocaleDateString('en-PH', { timeZone: 'Asia/Manila' })}</p>
                        </div>
                        <p className="font-semibold tabular-nums">{new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(order.total_amount))}</p>
                    </Link>
                ))}
                <nav aria-label="Order pages" className="flex flex-wrap gap-3 text-sm font-semibold">
                    {orders.prev_page_url && <Link href={orders.prev_page_url} className="rounded-lg border border-slate-300 px-4 py-2">Previous</Link>}
                    {orders.next_page_url && <Link href={orders.next_page_url} className="rounded-lg border border-slate-300 px-4 py-2">Next</Link>}
                </nav>
            </div>
        </BuyerOrderAccessLayout>
    );
}
