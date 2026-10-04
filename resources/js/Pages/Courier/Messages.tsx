import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import { ArrowLeft, MessageSquare, Search, Send } from 'lucide-react';
import CourierLayout from '@/Layouts/CourierLayout';
import { CourierAvatar, CourierBadge, CourierFieldError, courierButton, courierInput, courierPrimary } from '@/Components/CourierUI';
import { courierDate, courierPath, type CourierScope } from '@/utils/courier';
import useCourierRequestError from '@/hooks/useCourierRequestError';
import { PageProps } from '@/types';

interface Conversation {
    delivery_id: number;
    tracking_number: string;
    order_number: string | null;
    phase: 'pickup' | 'final_mile';
    can_send: boolean;
    participant: { id: number; name: string; role: string; shop_name: string | null; avatar?: string | null };
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
        const element = scroll.current;
        if (!element) return;
        const observer = new ResizeObserver(() => {
            if (atBottom) element.scrollTop = element.scrollHeight;
        });
        observer.observe(element);
        return () => observer.disconnect();
    }, [selected?.delivery_id, selected?.phase, visibleThread, atBottom]);

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
        requestAnimationFrame(() => heading.current?.focus({ preventScroll: true }));
    };
    const back = () => {
        setChatOpen(false);
        requestAnimationFrame(() => { if (selectedKey) listButtons.current[selectedKey]?.focus({ preventScroll: true }); });
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
        <CourierLayout title="Messages" contentMode="chat" scope={scope} isOnline={isOnline}>
            <Head title="Messages — BagooPH" />
            <div className="grid min-h-0 min-w-0 flex-1 overflow-hidden md:grid-cols-[minmax(12rem,28%)_minmax(0,1fr)] lg:grid-cols-[minmax(14rem,20rem)_minmax(0,1fr)]" data-chat-workspace="true">
                    <aside className={`${chatOpen ? 'hidden md:flex' : 'flex'} min-h-0 min-w-0 flex-col md:border-r md:border-slate-100/80`} aria-label="Conversation list">
                        <div className="shrink-0 space-y-4 p-4 sm:p-5">
                            <div className="flex items-center justify-between gap-2"><h1 className="text-lg font-semibold">Conversations</h1><span className="text-xs tabular-nums text-slate-500">{conversations.length}</span></div>
                            <label className="relative block"><span className="sr-only">Search conversations</span><Search className="pointer-events-none absolute left-3 top-4 h-4 w-4 text-slate-500" aria-hidden="true" /><input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Tracking or contact" className={`${courierInput} mt-0 pl-10 text-sm`} /></label>
                        </div>
                        <div className="min-h-0 flex-1 space-y-1 overflow-y-auto overscroll-contain px-2 pb-3" data-chat-scroll="contacts">
                            {filtered.map((item) => {
                                const key = keyOf(item);
                                const unread = (acknowledged[key] ?? 0) >= item.messages.reduce((latest, message) => Math.max(latest, message.id), 0) ? 0 : item.unread_count;
                                return <button ref={(node) => { listButtons.current[key] = node; }} key={key} type="button" disabled={sending} onClick={() => openConversation(item)} aria-current={selectedKey === key ? 'true' : undefined} className={`flex min-h-12 w-full items-start gap-3 rounded-[8px] p-3 text-left transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-[#E00D42] disabled:opacity-60 motion-reduce:transition-none ${selectedKey === key ? 'bg-white shadow-[0_2px_10px_rgba(15,23,42,0.04)]' : 'hover:bg-white/80'}`}>
                                    <CourierAvatar name={item.participant.name} src={item.participant.avatar} />
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-start justify-between gap-2"><span className="min-w-0 break-words text-sm font-semibold">{item.participant.shop_name || item.participant.name}</span>{unread > 0 && <span aria-label={`${unread} unread messages`} className="flex min-h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-[#E00D42] px-1.5 text-[11px] font-semibold text-white">{unread}</span>}</span>
                                        <span className="mt-1 block text-xs text-slate-500">{item.phase === 'pickup' ? 'Seller · Pickup' : 'Buyer · Final mile'}</span>
                                        <span className="mt-2 block truncate text-sm text-slate-600">{item.last_message || 'No messages recorded'}</span><span className="mt-1 block break-all text-[11px] text-slate-500">{item.tracking_number}</span>
                                    </span>
                                </button>;
                            })}
                            {!filtered.length && <p className="p-4 text-sm leading-relaxed text-slate-600">{conversations.length ? 'No conversations match that search.' : 'Conversations appear for your assigned pickups and final-mile deliveries.'}</p>}
                        </div>
                        <p className="shrink-0 px-5 py-3 text-xs leading-relaxed text-slate-500">Refresh for new messages. Drafts stay in this page while you switch conversations.</p>
                    </aside>
                    <section className={`${chatOpen ? 'flex' : 'hidden md:flex'} min-h-0 min-w-0 flex-col overflow-hidden`} aria-label="Selected conversation">
                        {selected ? <>
                            <header className="shrink-0 border-b border-slate-100/80 bg-white/60 px-4 py-3 sm:px-5" data-chat-header="true">
                                <div className="flex items-center gap-3">
                                    <button type="button" onClick={back} className={`${courierButton} h-12 w-12 shrink-0 border-transparent bg-transparent p-0 md:hidden`} aria-label="Back to conversations"><ArrowLeft className="h-5 w-5" aria-hidden="true" /><span className="sr-only">Conversations</span></button>
                                    <CourierAvatar name={selected.participant.name} src={selected.participant.avatar} />
                                    <div className="min-w-0 flex-1"><h2 ref={heading} tabIndex={-1} className="break-words text-base font-semibold outline-none">{selected.participant.shop_name || selected.participant.name}</h2><p className="mt-1 break-all text-xs text-slate-600">{selected.tracking_number}{selected.order_number ? ` · Order ${selected.order_number}` : ''}</p></div>
                                    <span className="hidden sm:block"><CourierBadge>{selected.phase === 'pickup' ? 'Seller · Pickup' : 'Buyer · Final mile'}</CourierBadge></span>
                                </div>
                            </header>
                            <div ref={scroll} onScroll={(event) => { const element = event.currentTarget; setAtBottom(element.scrollHeight - element.scrollTop - element.clientHeight < 32); }} className="min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain p-4 sm:p-5" data-chat-scroll="messages">
                                {selected.messages.length ? selected.messages.map((item) => <div key={item.id} className={`flex items-end gap-2 ${item.sender_id === currentUserId ? 'justify-end' : 'justify-start'}`}>
                                    {item.sender_id !== currentUserId && <CourierAvatar name={selected.participant.name} src={selected.participant.avatar} className="h-7 w-7 text-[10px]" />}
                                    <div className={`max-w-[85%] min-w-0 rounded-[8px] px-4 py-3 ${item.sender_id === currentUserId ? 'bg-[#FBEAF0]' : 'bg-white shadow-[0_2px_8px_rgba(15,23,42,0.035)]'}`}><p className="whitespace-pre-wrap break-words text-base leading-relaxed text-slate-900">{item.message}</p><p className="mt-2 text-[11px] leading-relaxed text-slate-600">{item.sender_id === currentUserId ? 'You' : selected.participant.name} · {courierDate(item.created_at)}</p></div>
                                </div>) : <div className="flex min-h-full flex-col items-center justify-center gap-3 px-4 text-center"><CourierAvatar name={selected.participant.name} src={selected.participant.avatar} className="h-16 w-16 text-xl" /><p className="text-sm leading-relaxed text-slate-600">No messages yet. Use this conversation for the linked delivery.</p></div>}
                            </div>
                            {readError && <div className="flex shrink-0 flex-wrap items-center gap-2 bg-white px-4 py-2"><p role="status" className="min-w-0 flex-1 text-xs text-slate-600">{readError}</p><button type="button" onClick={() => { setReadError(''); setReadRetry((current) => current + 1); }} className={courierButton}>Retry read update</button></div>}
                            {selected.can_send ? <form onSubmit={submit} className="shrink-0 border-t border-slate-100/80 bg-white/80 p-3 sm:p-4" data-chat-composer="true">
                                <div className="flex items-end gap-2 sm:gap-3">
                                    <label htmlFor="rider-message" className="min-w-0 flex-1"><span className="sr-only">Message</span><textarea id="rider-message" rows={1} maxLength={1000} value={draft} disabled={sending} placeholder="Write a message…" onChange={(event) => { if (selectedKey) setDrafts((current) => ({ ...current, [selectedKey]: event.target.value })); }} aria-invalid={Boolean(sendError)} aria-describedby="message-help message-error" className={`${courierInput} mt-0 block max-h-24 resize-none`} /></label>
                                    <button type="submit" disabled={sending || !draft.trim()} className={`${courierPrimary} shrink-0 px-4`} aria-label={sending ? 'Sending message' : 'Send message'}><Send className="h-5 w-5" aria-hidden="true" /><span className="hidden sm:inline">{sending ? 'Sending…' : 'Send message'}</span></button>
                                </div>
                                <CourierFieldError id="message-error" message={sendError} />
                                <p id="message-help" className="mt-2 text-xs text-slate-500" data-chat-help="true">{draft.length}/1000 characters · {selected.phase === 'pickup' ? 'Seller · Pickup' : 'Buyer · Final mile'}</p>
                            </form> : <p className="shrink-0 border-t border-slate-100/80 bg-white/80 p-4 text-sm leading-relaxed text-slate-600">This assignment is no longer active. The conversation is read-only.</p>}
                        </> : <div className="flex min-h-0 flex-1 flex-col items-center justify-center gap-3 p-6 text-center"><span className="flex h-16 w-16 items-center justify-center rounded-[8px] bg-[#FFF6F8] text-[#C20836]"><MessageSquare className="h-8 w-8" aria-hidden="true" /></span><h2 className="text-lg font-semibold">{conversations.length ? 'Select a conversation' : 'No delivery conversations'}</h2><p className="max-w-sm text-sm leading-relaxed text-slate-600">{conversations.length ? 'Choose a contact to coordinate the linked parcel.' : 'Conversations appear for your assigned pickups and final-mile deliveries.'}</p></div>}
                    </section>
            </div>
        </CourierLayout>
    );
}
