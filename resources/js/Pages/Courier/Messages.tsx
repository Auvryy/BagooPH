import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import { ArrowLeft, MessageSquare, Send } from 'lucide-react';
import CourierLayout from '@/Layouts/CourierLayout';
import { CourierBadge, CourierEmpty, CourierFieldError, CourierPanel, courierButton, courierInput, courierPrimary } from '@/Components/CourierUI';
import { courierDate, courierPath, type CourierScope } from '@/utils/courier';
import useCourierRequestError from '@/hooks/useCourierRequestError';
import { PageProps } from '@/types';

interface Conversation {
    delivery_id: number;
    tracking_number: string;
    order_number: string | null;
    phase: 'pickup' | 'final_mile';
    can_send: boolean;
    participant: { id: number; name: string; role: string; shop_name: string | null };
    last_message: string | null;
    last_time: string | null;
    unread_count: number;
    messages: Array<{ id: number; sender_id: number; message: string; created_at: string | null }>;
}
interface Props {
    conversations: Conversation[];
    currentUserId: number;
    selectedDeliveryId: number | null;
    selectedPhase?: Conversation['phase'] | null;
    scope?: CourierScope;
    isOnline: boolean;
}
const keyOf = (conversation: Conversation) => `${conversation.delivery_id}-${conversation.phase}`;

export default function CourierMessages({ conversations, currentUserId, selectedDeliveryId, selectedPhase, scope, isOnline }: Props) {
    const initial = conversations.find((item) => item.delivery_id === selectedDeliveryId && (!selectedPhase || item.phase === selectedPhase)) ?? conversations[0];
    const [selectedKey, setSelectedKey] = useState<string | null>(initial ? keyOf(initial) : null);
    const [chatOpen, setChatOpen] = useState(Boolean(selectedDeliveryId && initial?.delivery_id === selectedDeliveryId));
    const [desktop, setDesktop] = useState(false);
    const [documentVisible, setDocumentVisible] = useState(true);
    const [search, setSearch] = useState('');
    const [drafts, setDrafts] = useState<Record<string, string>>({});
    const [sendError, setSendError] = useState('');
    const [sending, setSending] = useState(false);
    const [acknowledged, setAcknowledged] = useState<Record<string, number>>({});
    const [readError, setReadError] = useState('');
    const [readRetry, setReadRetry] = useState(0);
    const [atBottom, setAtBottom] = useState(true);
    const pending = useRef(false);
    const scroll = useRef<HTMLDivElement>(null);
    const heading = useRef<HTMLHeadingElement>(null);
    const listButtons = useRef<Record<string, HTMLButtonElement | null>>({});
    useCourierRequestError(pending, setSendError);
    const selected = conversations.find((item) => keyOf(item) === selectedKey) ?? null;
    const draft = selectedKey ? drafts[selectedKey] ?? '' : '';
    const lastMessageId = selected?.messages.reduce((latest, message) => Math.max(latest, message.id), 0);
    const visibleThread = desktop || chatOpen;
    const filtered = conversations.filter((item) => [item.tracking_number, item.order_number, item.participant.name, item.participant.shop_name].some((value) => value?.toLowerCase().includes(search.trim().toLowerCase())));

    useEffect(() => {
        const media = window.matchMedia('(min-width: 768px)');
        const resized = () => setDesktop(media.matches);
        const visibility = () => setDocumentVisible(document.visibilityState === 'visible');
        resized(); visibility();
        media.addEventListener('change', resized);
        document.addEventListener('visibilitychange', visibility);
        return () => { media.removeEventListener('change', resized); document.removeEventListener('visibilitychange', visibility); };
    }, []);

    useEffect(() => {
        setAtBottom(true);
        setReadError('');
        if (scroll.current) scroll.current.scrollTop = scroll.current.scrollHeight;
    }, [selectedKey]);
    useEffect(() => {
        if (atBottom && scroll.current) scroll.current.scrollTop = scroll.current.scrollHeight;
    }, [lastMessageId, visibleThread, atBottom]);

    useEffect(() => {
        if (!selected || !lastMessageId || !selected.unread_count || !visibleThread || !documentVisible || !atBottom || (acknowledged[keyOf(selected)] ?? 0) >= lastMessageId) return;
        const controller = new AbortController();
        const key = keyOf(selected);
        const acknowledge = async () => {
            try {
                const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
                if (!token) throw new Error('Session token unavailable');
                const response = await fetch(courierPath('/messages/read'), {
                    method: 'POST', credentials: 'same-origin', signal: controller.signal,
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({ delivery_id: selected.delivery_id, phase: selected.phase, through_message_id: lastMessageId }),
                });
                if (!response.ok || !(await response.json()).acknowledged) throw new Error('Acknowledgement unavailable');
                if (!controller.signal.aborted) { setAcknowledged((current) => ({ ...current, [key]: lastMessageId })); setReadError(''); }
            } catch {
                if (!controller.signal.aborted) setReadError('Could not update the read status. Your messages are still available.');
            }
        };
        void acknowledge();
        return () => controller.abort();
    }, [selected?.delivery_id, selected?.phase, selected?.unread_count, lastMessageId, visibleThread, documentVisible, atBottom, acknowledged, readRetry]);

    const openConversation = (item: Conversation) => {
        setSelectedKey(keyOf(item)); setChatOpen(true); setSendError('');
        requestAnimationFrame(() => heading.current?.focus());
    };
    const back = () => {
        setChatOpen(false);
        requestAnimationFrame(() => { if (selectedKey) listButtons.current[selectedKey]?.focus(); });
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!selected || !selected.can_send || !draft.trim() || pending.current) return;
        const key = keyOf(selected);
        pending.current = true; setSending(true); setSendError('');
        router.post(courierPath('/messages/send'), { delivery_id: selected.delivery_id, phase: selected.phase, message: draft.trim() }, {
            preserveScroll: true,
            onSuccess: (page) => {
                const error = (page.props as PageProps).flash?.error;
                if (error) setSendError(error);
                else { setDrafts((current) => ({ ...current, [key]: '' })); setAtBottom(true); }
            },
            onError: (errors) => setSendError(errors.message || errors.delivery_id || 'Message was not sent. Try again.'),
            onFinish: () => { pending.current = false; setSending(false); },
        });
    };

    return (
        <CourierLayout title="Delivery messages" subtitle="Coordinate with the seller or buyer linked to your assignment. Refresh for new messages; your draft stays here." scope={scope} isOnline={isOnline}>
            <Head title="Delivery messages — BagooPH" />
            {!conversations.length ? <CourierEmpty title="No delivery conversations" icon={MessageSquare}>Conversations appear for your assigned pickups and final-mile deliveries.</CourierEmpty> : (
                <CourierPanel className="grid min-w-0 overflow-hidden md:grid-cols-[minmax(14rem,18rem)_minmax(0,1fr)]">
                    <aside className={`${chatOpen ? 'hidden md:block' : ''} min-w-0 md:border-r md:border-slate-300`} aria-label="Conversation list">
                        <label className="block border-b border-slate-300 p-4 text-sm font-semibold">Search conversations<input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Tracking or contact" className={courierInput} /></label>
                        <div className="max-h-[60dvh] divide-y divide-slate-300 overflow-y-auto">
                            {filtered.map((item) => {
                                const key = keyOf(item);
                                const unread = (acknowledged[key] ?? 0) >= item.messages.reduce((latest, message) => Math.max(latest, message.id), 0) ? 0 : item.unread_count;
                                return <button ref={(node) => { listButtons.current[key] = node; }} key={key} type="button" disabled={sending} onClick={() => openConversation(item)} aria-current={selectedKey === key ? 'true' : undefined} className={`block min-h-12 w-full border-l-4 p-4 text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-[#E00D42] disabled:opacity-60 ${selectedKey === key ? 'border-l-[#E00D42] bg-[#FDF2F4]' : 'border-l-transparent hover:bg-slate-50'}`}>
                                    <span className="flex flex-wrap items-start justify-between gap-2"><span className="min-w-0 break-words text-base font-bold">{item.participant.shop_name || item.participant.name}</span>{unread > 0 && <CourierBadge tone="brand">{unread} unread</CourierBadge>}</span>
                                    <span className="mt-2 block break-all text-sm text-slate-700">{item.tracking_number}</span><span className="mt-1 block text-sm text-slate-600">{item.phase === 'pickup' ? 'Seller · Pickup' : 'Buyer · Final mile'}</span><span className="mt-2 block truncate text-sm text-slate-600">{item.last_message || 'No messages recorded'}</span>
                                </button>;
                            })}
                            {!filtered.length && <p className="p-5 text-base text-slate-600">No conversations match that search.</p>}
                        </div>
                    </aside>
                    <section className={`${chatOpen ? 'flex' : 'hidden md:flex'} min-w-0 flex-col`} aria-label="Selected conversation">
                        {selected ? <>
                            <header className="space-y-3 border-b border-slate-300 p-4">
                                <button type="button" onClick={back} className={`${courierButton} md:hidden`}><ArrowLeft className="h-5 w-5" aria-hidden="true" />Conversations</button>
                                <div className="flex flex-wrap items-start justify-between gap-2"><h2 ref={heading} tabIndex={-1} className="min-w-0 break-words text-xl font-bold outline-none">{selected.participant.shop_name || selected.participant.name}</h2><CourierBadge>{selected.phase === 'pickup' ? 'Seller · Pickup' : 'Buyer · Final mile'}</CourierBadge></div>
                                <p className="break-all text-sm text-slate-600">{selected.tracking_number}{selected.order_number ? ` · Order ${selected.order_number}` : ''}</p>
                            </header>
                            <div ref={scroll} onScroll={(event) => { const element = event.currentTarget; setAtBottom(element.scrollHeight - element.scrollTop - element.clientHeight < 32); }} className="h-[45dvh] min-h-48 space-y-4 overflow-y-auto bg-[#F8F6F2] p-4 sm:p-5">
                                {selected.messages.length ? selected.messages.map((item) => <div key={item.id} className={`flex ${item.sender_id === currentUserId ? 'justify-end' : 'justify-start'}`}><div className={`max-w-[90%] min-w-0 rounded-[16px] border p-3 ${item.sender_id === currentUserId ? 'border-rose-300 bg-[#FDF2F4]' : 'border-slate-300 bg-white'}`}><p className="whitespace-pre-wrap break-words text-base leading-relaxed text-slate-900">{item.message}</p><p className="mt-2 text-xs text-slate-600">{item.sender_id === currentUserId ? 'You' : selected.participant.name} · {courierDate(item.created_at)}</p></div></div>) : <p className="py-8 text-center text-base text-slate-600">No messages yet. Use this conversation for the linked delivery.</p>}
                            </div>
                            {readError && <div className="border-t border-slate-300 p-4"><p role="status" className="text-sm text-slate-600">{readError}</p><button type="button" onClick={() => { setReadError(''); setReadRetry((current) => current + 1); }} className={`${courierButton} mt-2`}>Retry read update</button></div>}
                            {selected.can_send ? <form onSubmit={submit} className="space-y-3 border-t border-slate-300 p-4">
                                <label htmlFor="rider-message" className="block text-sm font-semibold">Message<textarea id="rider-message" rows={3} maxLength={1000} value={draft} disabled={sending} onChange={(event) => { if (selectedKey) setDrafts((current) => ({ ...current, [selectedKey]: event.target.value })); }} aria-invalid={Boolean(sendError)} aria-describedby="message-error" className={`${courierInput} resize-y`} /></label>
                                <CourierFieldError id="message-error" message={sendError} />
                                <button type="submit" disabled={sending || !draft.trim()} className={`${courierPrimary} w-full`}><Send className="h-5 w-5" aria-hidden="true" />{sending ? 'Sending…' : 'Send message'}</button>
                            </form> : <p className="border-t border-slate-300 p-4 text-base leading-relaxed text-slate-600">This assignment is no longer active. The conversation is read-only.</p>}
                        </> : <p className="p-6 text-base text-slate-600">Select an available conversation.</p>}
                    </section>
                </CourierPanel>
            )}
        </CourierLayout>
    );
}
