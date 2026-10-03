import { FormEvent, useEffect, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertCircle, ArrowRight, Check, Clock, Copy, MapPin, Package, Search, ShieldCheck } from 'lucide-react';
import BagooLogo from '@/Components/BagooLogo';
import { PageProps } from '@/types';

interface Checkpoint {
    checkpoint_type: string;
    location_name: string;
    created_at: string;
}

interface ParcelData {
    tracking_number: string;
    status: string;
    delivery_recipient_name: string;
    delivery_phone: string;
    delivery_address: string;
    estimated_delivery_at: string | null;
    delivered_at: string | null;
    checkpoints: Checkpoint[];
}

interface Props {
    parcel: ParcelData | null;
    searchedNumber: string;
    notFound: boolean;
    validationMessage: string | null;
}

const statusLabels: Record<string, string> = {
    placed: 'Order placed',
    confirmed: 'Seller confirmed',
    preparing: 'Seller preparing',
    ready_for_pickup: 'Ready for pickup',
    picked_up: 'Picked up from seller',
    at_sorting_center: 'Moving through the hub network',
    sorted: 'Sorted at destination hub',
    assigned_to_rider: 'Assigned to delivery rider',
    out_for_delivery: 'Out for delivery',
    delivered: 'Delivered',
    completed: 'Buyer confirmed receipt',
    delivery_failed: 'Delivery attempt failed',
    returned: 'Returned to seller',
    cancelled: 'Order cancelled',
};

const checkpointLabels: Record<string, string> = {
    ...statusLabels,
    order_placed: 'Order placed',
    seller_pack: 'Seller packed the parcel',
    assigned_pickup: 'Pickup rider assigned',
    courier_pickup: 'Seller pickup recorded',
    arrived_at_origin_hub: 'Received at origin Bayan Hub',
    in_transit_to_mother_hub: 'Departed for Mother Hub',
    arrived_at_mother_hub: 'Received at Mother Hub',
    sorted_to_line_haul: 'Sorted at Mother Hub',
    in_transit_to_destination_hub: 'Departed for destination Bayan Hub',
    arrived_at_destination_hub: 'Received at destination Bayan Hub',
    sorted_to_barangay_bin: 'Sorted for the delivery area',
    ready_for_hub_pickup: 'Ready for hub self-pickup',
    customer_collected: 'Collected at destination hub',
    doorstep_handover: 'Recipient handoff recorded',
    buyer_confirmed: 'Buyer confirmed receipt',
    buyer_completed: 'Buyer confirmed receipt',
    return_to_sender: 'Return to seller in progress',
};

function formatDate(value: string | null): string {
    if (!value) return 'Not recorded';
    return new Intl.DateTimeFormat('en-PH', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Manila',
    }).format(new Date(value));
}

export default function Tracking({ parcel, searchedNumber, notFound, validationMessage }: Props) {
    const { auth } = usePage<PageProps>().props;
    const [inputNumber, setInputNumber] = useState(searchedNumber);
    const [searching, setSearching] = useState(false);
    const [copyMessage, setCopyMessage] = useState('');

    useEffect(() => {
        setInputNumber(searchedNumber);
        setCopyMessage('');
    }, [searchedNumber]);

    const handleSearch = (event: FormEvent) => {
        event.preventDefault();
        if (!inputNumber.trim() || searching) return;
        router.get(route('track.show'), { number: inputNumber.trim().toUpperCase() }, {
            preserveScroll: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });
    };

    const handleCopy = async () => {
        if (!parcel) return;
        try {
            await navigator.clipboard.writeText(parcel.tracking_number);
            setCopyMessage('Tracking number copied.');
        } catch {
            setCopyMessage('Select the tracking number above to copy it.');
        }
    };

    const exception = parcel && ['delivery_failed', 'returned', 'cancelled'].includes(parcel.status);
    const delivered = parcel && ['delivered', 'completed'].includes(parcel.status);
    const statusStyle = exception
        ? 'border-amber-300 bg-amber-50 text-amber-900'
        : delivered
            ? 'border-emerald-300 bg-emerald-50 text-emerald-900'
            : 'border-slate-300 bg-slate-100 text-slate-800';

    return (
        <div className="min-h-screen bg-slate-50 font-sans text-slate-800">
            <Head title="Track your parcel | BagooPH" />
            <header className="border-b border-slate-300 bg-white">
                <div className="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                    <Link href={route('marketplace')} className="flex items-center gap-3" aria-label="BagooPH marketplace">
                        <BagooLogo className="h-9 w-9" />
                        <span className="text-sm font-bold text-slate-900">BagooPH</span>
                    </Link>
                    <Link href={route(auth.user ? 'dashboard' : 'login')} className="inline-flex items-center gap-2 rounded-sm border border-slate-300 px-3 py-2 text-xs font-semibold hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]">
                        {auth.user ? 'Open your portal' : 'Sign in'}
                        <ArrowRight className="h-4 w-4" aria-hidden="true" />
                    </Link>
                </div>
            </header>

            <main className="mx-auto max-w-5xl space-y-5 px-4 py-6 sm:px-6 sm:py-10">
                <section className="rounded-lg border border-slate-300 bg-white p-5 sm:p-7" aria-labelledby="tracking-title">
                    <p className="mb-2 text-xs font-semibold text-[#E00D42]">Parcel tracking</p>
                    <h1 id="tracking-title" className="text-2xl font-extrabold tracking-tight text-slate-900">Follow your parcel</h1>
                    <p id="tracking-help" className="mt-2 text-sm text-slate-500">Enter the tracking number from your waybill or order details.</p>
                    <form onSubmit={handleSearch} className="mt-5 space-y-2">
                        <label htmlFor="tracking-number" className="block text-xs font-semibold">Tracking number</label>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <div className="relative flex-1">
                                <Search className="pointer-events-none absolute left-3 top-3 h-4 w-4 text-slate-500" aria-hidden="true" />
                                <input id="tracking-number" name="number" type="text" required maxLength={64} autoCapitalize="characters" autoComplete="off" spellCheck={false}
                                    value={inputNumber} onChange={(event) => setInputNumber(event.target.value)}
                                    aria-describedby={validationMessage ? 'tracking-help tracking-error' : 'tracking-help'} aria-invalid={!!validationMessage}
                                    className="w-full rounded-sm border border-slate-300 py-2.5 pl-10 pr-3 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]" />
                            </div>
                            <button type="submit" disabled={searching || !inputNumber.trim()} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-sm bg-[#E00D42] px-5 py-2.5 text-sm font-bold text-white hover:bg-[#C20836] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42] disabled:opacity-50">
                                <Search className="h-4 w-4" aria-hidden="true" />{searching ? 'Searching...' : 'Track parcel'}
                            </button>
                        </div>
                        {validationMessage && <p id="tracking-error" role="alert" className="text-xs text-[#E00D42]">{validationMessage}</p>}
                    </form>
                </section>

                {notFound && (
                    <div role="status" className="flex gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4">
                        <AlertCircle className="mt-0.5 h-5 w-5 shrink-0 text-amber-800" aria-hidden="true" />
                        <div><h2 className="text-sm font-bold text-amber-950">Parcel not found</h2><p className="mt-1 text-sm text-amber-900">Check the tracking number on your waybill and try again. Order numbers are available in your account.</p></div>
                    </div>
                )}

                <div className="flex items-start gap-3 rounded-lg border border-slate-300 bg-white p-4 text-sm">
                    <ShieldCheck className="mt-0.5 h-5 w-5 shrink-0 text-slate-600" aria-hidden="true" />
                    <p className="text-slate-600">Recipient details are masked for privacy. For delivery actions or receipt confirmation, open your account portal.</p>
                </div>

                {parcel && (
                    <div className="grid gap-5 lg:grid-cols-3">
                        <section className="space-y-5 rounded-lg border border-slate-300 bg-white p-5 sm:p-6 lg:col-span-3" aria-labelledby="parcel-status">
                            <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                                <div>
                                    <p className="text-xs text-slate-500">Waybill tracking number</p>
                                    <div className="mt-1 flex flex-wrap items-center gap-2">
                                        <h2 id="parcel-status" className="break-all text-lg font-bold text-slate-900">{parcel.tracking_number}</h2>
                                        <button type="button" onClick={handleCopy} aria-label="Copy tracking number" className="rounded-sm border border-slate-300 p-2 text-slate-600 hover:bg-slate-50">
                                            {copyMessage === 'Tracking number copied.' ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                                        </button>
                                    </div>
                                    <p aria-live="polite" className="mt-1 text-xs text-slate-500">{copyMessage}</p>
                                </div>
                                <span className={`self-start rounded-xs border px-2.5 py-1.5 text-xs font-bold ${statusStyle}`}>{statusLabels[parcel.status] ?? 'Status recorded'}</span>
                            </div>
                            {exception && <p className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">{parcel.status === 'delivery_failed' ? 'The latest delivery attempt was unsuccessful. Check your account for follow-up details.' : parcel.status === 'returned' ? 'The parcel has been returned to the seller.' : 'This order was cancelled.'}</p>}
                            {parcel.status === 'delivered' && <p className="rounded-md border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-900">Delivery is recorded. The buyer can confirm receipt from their orders page.</p>}
                            <dl className="grid gap-4 border-t border-slate-300 pt-4 sm:grid-cols-3">
                                <div><dt className="flex items-center gap-1.5 text-xs text-slate-500"><MapPin className="h-4 w-4" aria-hidden="true" />Delivery area</dt><dd className="mt-1 text-sm font-semibold">{parcel.delivery_address}</dd></div>
                                <div><dt className="flex items-center gap-1.5 text-xs text-slate-500"><Clock className="h-4 w-4" aria-hidden="true" />Estimated delivery</dt><dd className="mt-1 text-sm font-semibold">{parcel.estimated_delivery_at ? formatDate(parcel.estimated_delivery_at) : 'Not yet scheduled'}</dd></div>
                                <div><dt className="flex items-center gap-1.5 text-xs text-slate-500"><Package className="h-4 w-4" aria-hidden="true" />Delivered at</dt><dd className="mt-1 text-sm font-semibold">{formatDate(parcel.delivered_at)}</dd></div>
                            </dl>
                        </section>

                        <section className="rounded-lg border border-slate-300 bg-white p-5 sm:p-6 lg:col-span-2" aria-labelledby="milestones-title">
                            <h2 id="milestones-title" className="text-sm font-bold text-slate-900">Recorded milestones</h2>
                            <p className="mt-1 text-xs text-slate-500">Times shown in Philippine time.</p>
                            {parcel.checkpoints.length === 0 ? <p className="mt-5 text-sm text-slate-500">No milestones recorded yet. Check back after the next parcel handoff.</p> : (
                                <ol className="mt-5 space-y-0">
                                    {parcel.checkpoints.map((checkpoint, index) => (
                                        <li key={`${checkpoint.checkpoint_type}-${index}`} className="relative border-l border-slate-300 pb-5 pl-5 last:pb-0">
                                            <span className={`absolute -left-1.5 top-1 h-3 w-3 rounded-xs border ${index === parcel.checkpoints.length - 1 ? 'border-[#E00D42] bg-[#E00D42]' : 'border-slate-400 bg-white'}`} aria-hidden="true" />
                                            <div className="flex flex-col justify-between gap-1 sm:flex-row sm:gap-3"><h3 className="text-sm font-semibold">{checkpointLabels[checkpoint.checkpoint_type] ?? 'Parcel milestone'}</h3><time dateTime={checkpoint.created_at} className="shrink-0 text-xs text-slate-500">{formatDate(checkpoint.created_at)}</time></div>
                                            <p className="mt-1 text-xs text-slate-500">{checkpoint.location_name}</p>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </section>

                        <aside className="self-start rounded-lg border border-slate-300 bg-white p-5 sm:p-6" aria-labelledby="recipient-title">
                            <h2 id="recipient-title" className="text-sm font-bold text-slate-900">Recipient</h2>
                            <dl className="mt-4 space-y-3 text-sm"><div><dt className="text-xs text-slate-500">Name</dt><dd className="mt-1 font-semibold">{parcel.delivery_recipient_name}</dd></div><div><dt className="text-xs text-slate-500">Contact</dt><dd className="mt-1 font-semibold">{parcel.delivery_phone}</dd></div></dl>
                            <p className="mt-4 border-t border-slate-300 pt-4 text-xs leading-relaxed text-slate-500">Open your account for full delivery details and receipt confirmation.</p>
                        </aside>
                    </div>
                )}
                {!parcel && !notFound && !validationMessage && <div className="py-8 text-center"><Package className="mx-auto h-8 w-8 text-slate-400" aria-hidden="true" /><p className="mt-3 text-sm text-slate-500">Your parcel milestones will appear here.</p></div>}
            </main>
        </div>
    );
}
