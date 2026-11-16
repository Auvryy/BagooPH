import { FormEvent, useEffect, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRight, Check, Copy, RefreshCw, Search, ShieldCheck } from 'lucide-react';
import BagooLogo from '@/Components/BagooLogo';
import { Notice, buttonClasses, inputClasses } from '@/Components/ui';
import { EtaPresentation, PageProps } from '@/types';

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
    eta?: EtaPresentation | null;
    delivered_at: string | null;
    checkpoints: Checkpoint[];
}

interface Props {
    parcel: ParcelData | null;
    searchedNumber: string;
    notFound: boolean;
    validationMessage: string | null;
    statusLabels: Record<string, string>;
    checkpointLabels: Record<string, string>;
    observedAt?: string | null;
}

/** Buyer-facing journey stages; each server status maps onto one of them. */
const STAGES = ['Ordered', 'Picked up', 'In transit', 'Out for delivery', 'Delivered'] as const;

const STAGE_BY_STATUS: Record<string, number> = {
    placed: 0, confirmed: 0, preparing: 0, ready_for_pickup: 0,
    picked_up: 1,
    at_sorting_center: 2, in_transit: 2, sorted: 2, assigned_to_rider: 2,
    out_for_delivery: 3,
    delivered: 4, completed: 4,
};

const EXCEPTION_STATUSES = ['delivery_failed', 'returned', 'cancelled'];

function formatDate(value: string | null): string {
    if (!value) return 'Not recorded';
    return new Intl.DateTimeFormat('en-PH', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Manila',
    }).format(new Date(value));
}

function JourneyProgress({ stage }: { stage: number }) {
    return (
        <ol aria-label="Delivery progress" className="grid grid-cols-5 gap-1">
            {STAGES.map((label, index) => {
                const done = index < stage;
                const current = index === stage;
                return (
                    <li key={label} aria-current={current ? 'step' : undefined} className="min-w-0">
                        <span aria-hidden="true" className={`block h-1.5 rounded-full ${done || current ? 'bg-brand-500' : 'bg-line-200'}`} />
                        <span className={`mt-2 flex items-center gap-1 text-xs leading-4 sm:text-[13px] ${current ? 'font-semibold text-brand-600' : done ? 'text-ink-700' : 'text-ink-500'}`}>
                            {done && <Check className="hidden h-3.5 w-3.5 shrink-0 text-brand-500 sm:block" aria-hidden="true" />}
                            <span className="truncate">{label}</span>
                        </span>
                        <span className="sr-only">{done ? ' (done)' : current ? ' (current)' : ' (upcoming)'}</span>
                    </li>
                );
            })}
        </ol>
    );
}

export default function Tracking({ parcel, searchedNumber, notFound, validationMessage, statusLabels = {}, checkpointLabels = {}, observedAt }: Props) {
    const { auth } = usePage<PageProps>().props;
    const [inputNumber, setInputNumber] = useState(searchedNumber);
    const [searching, setSearching] = useState(false);
    const [copyMessage, setCopyMessage] = useState('');

    useEffect(() => {
        setInputNumber(searchedNumber);
        setCopyMessage('');
    }, [searchedNumber]);

    const lookUp = (number: string) => router.get(route('track.show'), { number }, {
        preserveScroll: true,
        onStart: () => setSearching(true),
        onFinish: () => setSearching(false),
    });

    const handleSearch = (event: FormEvent) => {
        event.preventDefault();
        if (!inputNumber.trim() || searching) return;
        lookUp(inputNumber.trim().toUpperCase());
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

    const exception = parcel ? EXCEPTION_STATUSES.includes(parcel.status) : false;
    const delivered = parcel ? ['delivered', 'completed'].includes(parcel.status) : false;
    const stage = parcel ? STAGE_BY_STATUS[parcel.status] : undefined;
    const history = parcel ? [...parcel.checkpoints].reverse() : [];
    const etaLabel = parcel ? (parcel.eta?.label ?? (parcel.estimated_delivery_at ? formatDate(parcel.estimated_delivery_at) : null)) : null;

    return (
        <div className="min-h-screen bg-surface-50 font-sans text-ink-900">
            <Head title="Track your parcel | BagooPH" />
            <header className="border-b border-line-200 bg-white">
                <div className="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
                    <Link href={route('marketplace')} className="flex items-center gap-2.5" aria-label="BagooPH marketplace">
                        <BagooLogo className="h-8 w-8" />
                        <span className="text-sm font-semibold text-ink-900">BagooPH</span>
                    </Link>
                    <Link href={route(auth.user ? 'dashboard' : 'login')} className={buttonClasses('secondary', 'sm')}>
                        {auth.user ? 'Open your account' : 'Sign in'}
                        <ArrowRight className="h-4 w-4" aria-hidden="true" />
                    </Link>
                </div>
            </header>

            <main className="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 sm:py-12">
                <section aria-labelledby="tracking-title">
                    <h1 id="tracking-title" className="text-[28px] font-bold leading-[34px] tracking-tight text-ink-900">Track your parcel</h1>
                    <p id="tracking-help" className="mt-1 text-sm text-ink-600">Enter the tracking number from your waybill or order details.</p>
                    <form onSubmit={handleSearch} className="mt-5">
                        <label htmlFor="tracking-number" className="sr-only">Tracking number</label>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <div className="relative flex-1">
                                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-500" aria-hidden="true" />
                                <input id="tracking-number" name="number" type="text" required maxLength={64} autoCapitalize="characters" autoComplete="off" spellCheck={false}
                                    placeholder="e.g. BGO-1A2B3C"
                                    value={inputNumber} onChange={(event) => setInputNumber(event.target.value)}
                                    aria-describedby={validationMessage ? 'tracking-help tracking-error' : 'tracking-help'} aria-invalid={!!validationMessage}
                                    className={`${inputClasses} h-12 pl-10 font-mono uppercase tracking-wide placeholder:normal-case placeholder:tracking-normal placeholder:font-sans`} />
                            </div>
                            <button type="submit" disabled={searching || !inputNumber.trim()} className={buttonClasses('primary', 'lg', 'sm:w-auto')}>
                                {searching ? 'Searching…' : 'Track parcel'}
                            </button>
                        </div>
                        {validationMessage && <p id="tracking-error" role="alert" className="mt-2 text-[13px] text-brand-700">{validationMessage}</p>}
                    </form>
                </section>

                {notFound && (
                    <Notice tone="warning" role="status" title="We couldn't find that parcel">
                        Check the tracking number on your waybill and try again. You can also find it in your orders after signing in.
                    </Notice>
                )}

                {parcel && (
                    <>
                        <section aria-labelledby="parcel-status" className="rounded-card border border-line-200 bg-white">
                            <div className="space-y-5 p-5 sm:p-6">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div className="flex min-w-0 items-center gap-2">
                                        <span className="text-[13px] text-ink-600">Tracking number</span>
                                        <span className="break-all font-mono text-sm font-medium text-ink-900">{parcel.tracking_number}</span>
                                        <button type="button" onClick={handleCopy} aria-label="Copy tracking number" className="rounded-control p-1.5 text-ink-500 hover:bg-surface-100 hover:text-ink-900">
                                            {copyMessage === 'Tracking number copied.' ? <Check className="h-4 w-4" aria-hidden="true" /> : <Copy className="h-4 w-4" aria-hidden="true" />}
                                        </button>
                                    </div>
                                    <p aria-live="polite" className="text-xs text-ink-600">{copyMessage}</p>
                                </div>

                                <div>
                                    <h2 id="parcel-status" className={`text-2xl font-bold leading-8 ${exception ? 'text-warning' : 'text-ink-900'}`}>
                                        {statusLabels[parcel.status] ?? 'Status recorded'}
                                    </h2>
                                    {delivered && parcel.delivered_at
                                        ? <p className="mt-1 text-sm text-ink-700">Delivered {formatDate(parcel.delivered_at)}</p>
                                        : !exception && etaLabel && <p className="mt-1 text-sm text-ink-700">Estimated arrival <span className="font-semibold text-ink-900">{etaLabel}</span></p>}
                                </div>

                                {!exception && stage !== undefined && <JourneyProgress stage={stage} />}

                                {exception && (
                                    <Notice tone="warning">
                                        {parcel.status === 'delivery_failed' ? 'The latest delivery attempt was unsuccessful. Sign in to see what happens next.' : parcel.status === 'returned' ? 'This parcel has been returned to the seller.' : 'This order was cancelled.'}
                                    </Notice>
                                )}
                                {parcel.eta?.pause_notice && <Notice tone="warning" role="status">{parcel.eta.pause_notice}</Notice>}
                                {parcel.eta?.delay_notice && !parcel.eta?.pause_notice && <Notice tone="info" role="status">{parcel.eta.delay_notice}</Notice>}
                                {parcel.status === 'delivered' && <Notice tone="success">Delivery is recorded. The buyer can confirm receipt from their orders page.</Notice>}

                                <dl className="grid gap-4 border-t border-line-200 pt-5 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt className="text-[13px] text-ink-600">Delivering to</dt>
                                        <dd className="mt-0.5 font-medium text-ink-900">{parcel.delivery_address}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-[13px] text-ink-600">Recipient</dt>
                                        <dd className="mt-0.5 font-medium text-ink-900">{parcel.delivery_recipient_name} <span className="font-normal text-ink-600">· {parcel.delivery_phone}</span></dd>
                                    </div>
                                </dl>
                            </div>
                            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line-200 px-5 py-3 sm:px-6">
                                <p className="text-xs text-ink-600">Checked {formatDate(observedAt ?? null)} · Philippine time</p>
                                <button type="button" disabled={searching} onClick={() => lookUp(parcel.tracking_number)} className={buttonClasses('ghost', 'sm', '-mr-2')}>
                                    <RefreshCw className={`h-4 w-4 ${searching ? 'animate-spin motion-reduce:animate-none' : ''}`} aria-hidden="true" />
                                    {searching ? 'Refreshing…' : 'Refresh tracking'}
                                </button>
                            </div>
                        </section>

                        <section aria-labelledby="milestones-title" className="rounded-card border border-line-200 bg-white p-5 sm:p-6">
                            <h2 id="milestones-title" className="text-lg font-semibold text-ink-900">Parcel history</h2>
                            {history.length === 0 ? (
                                <p className="mt-3 text-sm text-ink-600">No updates yet. The first update appears when the parcel is handed over or scanned.</p>
                            ) : (
                                <ol className="mt-5">
                                    {history.map((checkpoint, index) => (
                                        <li key={`${checkpoint.checkpoint_type}-${checkpoint.created_at}-${index}`} className="relative flex gap-4 pb-6 last:pb-0">
                                            {index < history.length - 1 && <span aria-hidden="true" className="absolute left-[5px] top-4 h-full w-px bg-line-200" />}
                                            <span aria-hidden="true" className={`relative mt-1.5 h-[11px] w-[11px] shrink-0 rounded-full ${index === 0 ? 'bg-brand-500 ring-4 ring-brand-50' : 'border-2 border-line-300 bg-white'}`} />
                                            <div className="min-w-0 flex-1 sm:flex sm:items-baseline sm:justify-between sm:gap-4">
                                                <div className="min-w-0">
                                                    <h3 className={`text-sm ${index === 0 ? 'font-semibold text-ink-900' : 'font-medium text-ink-700'}`}>{checkpointLabels[checkpoint.checkpoint_type] ?? 'Parcel update'}</h3>
                                                    <p className="mt-0.5 text-[13px] text-ink-600">{checkpoint.location_name}</p>
                                                </div>
                                                <time dateTime={checkpoint.created_at} className="mt-0.5 block shrink-0 text-xs tabular-nums text-ink-500 sm:mt-0">{formatDate(checkpoint.created_at)}</time>
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </section>
                    </>
                )}

                {!parcel && !notFound && !validationMessage && (
                    <p className="text-sm text-ink-600">Your parcel's progress and history will appear here.</p>
                )}

                <p className="flex items-start gap-2 text-xs text-ink-600">
                    <ShieldCheck className="mt-px h-4 w-4 shrink-0" aria-hidden="true" />
                    <span>Recipient details are partly hidden for privacy. Sign in to your account for full delivery details and to confirm receipt.</span>
                </p>
            </main>
        </div>
    );
}
