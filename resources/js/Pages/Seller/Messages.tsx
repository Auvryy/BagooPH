import useMarketplaceComposer from '@/hooks/useMarketplaceComposer';
import ChatImageField from '@/Components/ChatImageField';
import ChatAttachment from '@/Components/ChatAttachment';
import React, { useState, useEffect, useRef } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { PageProps, Shop, User } from '@/types';
import { 
    ArrowLeft,
    MessageSquare, 
    Send, 
    Search, 
} from 'lucide-react';

interface MessageItem {
    id: number;
    sender_id: number;
    receiver_id: number;
    message: string;
    attachment_url?: string | null;
    created_at: string;
    product?: {
        id?: number;
        name: string;
        price: number;
        featured_image: string;
        slug?: string;
    };
}

interface Conversation {
    user: User;
    order_id?: number | null;
    last_message: string;
    last_time: string;
    unread_count: number;
    messages: MessageItem[];
}

interface Props {
    conversations: Conversation[];
    shop: Shop;
}

export default function SellerMessages({ conversations, shop }: Props) {
    const { auth } = usePage<PageProps>().props;
    const currentUser = auth.user;

    const [activeUser, setActiveUser] = useState<User | null>(conversations[0]?.user || null);
    const [messages, setMessages] = useState<MessageItem[]>(conversations[0]?.messages || []);
    const [drafts, setDrafts] = useState<Record<number, string>>({});
    const [chatOpen, setChatOpen] = useState(false);
    const [desktop, setDesktop] = useState(false);
    const [documentVisible, setDocumentVisible] = useState(true);
    const [searchQuery, setSearchQuery] = useState('');
    const messagesViewportRef = useRef<HTMLDivElement>(null);
    const conversationHeading = useRef<HTMLHeadingElement>(null);
    const conversationButtons = useRef<Record<number, HTMLButtonElement | null>>({});
    const [historyError, setHistoryError] = useState('');
    const [historyRetry, setHistoryRetry] = useState(0);
    const activeConversation = conversations.find((conversation) => conversation.user.id === activeUser?.id);
    const replyText = activeUser ? drafts[activeUser.id] ?? '' : '';

    const scrollToBottom = () => {
        const viewport = messagesViewportRef.current;
        viewport?.scrollTo({ top: viewport.scrollHeight, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
    };

    useEffect(() => {
        const media = window.matchMedia('(min-width: 1024px)');
        const resized = () => setDesktop(media.matches);
        const visibility = () => setDocumentVisible(document.visibilityState === 'visible');
        resized(); visibility();
        media.addEventListener('change', resized);
        document.addEventListener('visibilitychange', visibility);
        return () => {
            media.removeEventListener('change', resized);
            document.removeEventListener('visibilitychange', visibility);
        };
    }, []);

    useEffect(() => {
        setMessages(activeConversation?.messages || []); setHistoryError('');
        if (!activeUser || !(desktop || chatOpen) || !documentVisible) return;
        let active = true;
        const controller = new AbortController();
        let cancelUnreadRefresh: (() => void) | undefined;
        fetch(`/chat/messages/${activeUser.id}`, { signal: controller.signal, headers: { Accept: 'application/json' } })
            .then(async response => { if (!response.ok) throw new Error(); return response.json(); })
            .then(data => {
                if (!active || controller.signal.aborted || !Array.isArray(data.messages)) return;
                setMessages(data.messages);
                router.reload({
                    only: ['unreadMessagesCount', 'sidebarAttention'],
                    onCancelToken: token => {
                        cancelUnreadRefresh = () => token.cancel();
                        if (!active) cancelUnreadRefresh();
                    },
                });
            })
            .catch(() => { if (active && !controller.signal.aborted) setHistoryError('Could not update this conversation. Try again.'); });
        return () => {
            active = false;
            controller.abort();
            cancelUnreadRefresh?.();
        };
    }, [activeUser?.id, activeConversation?.messages, historyRetry, desktop, chatOpen, documentVisible]);

    useEffect(() => {
        scrollToBottom();
    }, [messages, chatOpen]);

    const composer = useMarketplaceComposer(activeConversation?.user?.id);
    const sending = composer.sending;
    const handleSend = async (event: React.FormEvent) => {
        event.preventDefault();
        if (!activeConversation?.user?.id) return;
        const message = await composer.send<MessageItem>({ receiver_id: activeConversation.user.id,
            shop_id: shop.id,
            order_id: activeConversation.order_id ?? null, message: replyText });
        if (message) {
            setMessages(previous => previous.some(row => row.id === message.id) ? previous : [...previous, message]);
            setDrafts(previous => ({ ...previous, [activeConversation.user.id]: '' }));
            activeConversation.last_message = message.message || 'Photo'; activeConversation.last_time = 'Just now';
        }
    };

    const filteredConversations = conversations.filter(c => 
        !searchQuery.trim() || 
        (c.user?.name && c.user.name.toLowerCase().includes(searchQuery.trim().toLowerCase()))
    );

    const openConversation = (user: User) => {
        if (sending) return;
        setActiveUser(user); setChatOpen(true);
        requestAnimationFrame(() => conversationHeading.current?.focus({ preventScroll: true }));
    };
    const backToConversations = () => {
        setChatOpen(false);
        requestAnimationFrame(() => { if (activeUser) conversationButtons.current[activeUser.id]?.focus({ preventScroll: true }); });
    };

    return (
        <DashboardLayout
            title="Messages"
            subtitle="Customer inquiries and live chat"
        >
            <Head title="Messages — BagooPH Seller" />

            <div className="flex h-[70dvh] min-h-0 w-full overflow-hidden rounded-3xl border border-slate-200 bg-white font-sans shadow-2xs lg:h-[75vh]">
                
                {/* Left: Conversations List */}
                <div role="region" aria-label="Conversation list" className={`${chatOpen ? 'hidden lg:flex' : 'flex'} min-h-0 w-full shrink-0 flex-col border-slate-200 bg-slate-50/50 lg:w-80 lg:border-r xl:w-96`}>
                    <div className="p-4 border-b border-slate-200 space-y-3 bg-white">
                        <div className="flex items-center justify-between">
                            <h3 className="font-bold text-sm text-slate-900">Conversations</h3>
                            <span className="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 font-sans text-[10px] font-bold">
                                {conversations.length} Active
                            </span>
                        </div>
                        <div className="relative font-sans text-xs">
                            <input
                                type="search"
                                aria-label="Search conversations"
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                placeholder="Search messages..."
                                className="min-h-12 w-full pl-8 pr-3 py-2 bg-slate-50 border border-slate-300 rounded-xl text-sm text-slate-800 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                            />
                            <Search aria-hidden="true" className="w-3.5 h-3.5 text-slate-500 absolute left-2.5 top-[1.1rem]" />
                        </div>
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto divide-y divide-slate-100">
                        {filteredConversations.length === 0 ? (
                            <p className="text-xs text-slate-400 py-12 text-center font-sans">No conversations found.</p>
                        ) : (
                            filteredConversations.map((c) => {
                                const isSelected = activeUser?.id === c.user?.id;
                                return (
                                    <button
                                        type="button"
                                        key={c.user.id}
                                        ref={node => { conversationButtons.current[c.user.id] = node; }}
                                        disabled={sending}
                                        aria-current={isSelected ? 'true' : undefined}
                                        onClick={() => openConversation(c.user)}
                                        className={`w-full p-4 text-left flex items-start gap-3 transition disabled:opacity-60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-[#E00D42] motion-reduce:transition-none ${
                                            isSelected ? 'bg-white shadow-xs border-l-4 border-l-[#E00D42]' : 'hover:bg-slate-100/70'
                                        }`}
                                    >
                                        <div className="w-10 h-10 rounded-full bg-slate-900 text-white flex items-center justify-center font-bold text-xs shrink-0">
                                            {c.user?.name?.charAt(0) || 'U'}
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center justify-between">
                                                <h4 className="font-bold text-xs text-slate-900 truncate">{c.user?.name || 'Contact'}</h4>
                                                <span className="text-[10px] font-sans text-slate-400">{c.last_time}</span>
                                            </div>
                                            <p className="text-xs text-slate-500 truncate mt-0.5">{c.last_message}</p>
                                        </div>
                                    </button>
                                );
                            })
                        )}
                    </div>
                </div>

                {/* Right: Active Chat View */}
                {activeUser ? (
                    <div role="region" aria-label="Selected conversation" className={`${chatOpen ? 'flex' : 'hidden lg:flex'} min-h-0 min-w-0 flex-1 flex-col bg-white`}>
                        
                        {/* Chat Header */}
                        <div className="shrink-0 p-3 sm:p-4 border-b border-slate-200 flex items-center justify-between bg-white">
                            <div className="flex min-w-0 items-center gap-3">
                                <button type="button" aria-label="Back to conversations" disabled={sending} onClick={backToConversations} className="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50 disabled:opacity-60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42] lg:hidden"><ArrowLeft aria-hidden="true" className="h-5 w-5" /></button>
                                <div className="w-10 h-10 shrink-0 rounded-full bg-slate-900 text-white flex items-center justify-center font-bold text-xs">
                                    {activeUser.name.charAt(0)}
                                </div>
                                <div className="min-w-0">
                                    <h3 ref={conversationHeading} tabIndex={-1} className="break-words font-bold text-sm text-slate-900 outline-none">{activeUser.name}</h3>
                                    <p className="text-[10px] font-sans text-slate-400">
                                        {activeUser.role === 'courier' ? 'Pickup rider' : 'Customer'}{activeUser.city ? ` · ${activeUser.city}` : ''}
                                    </p>
                                </div>
                            </div>
                        </div>

                        {/* Messages Thread */}
                        <div ref={messagesViewportRef} className="min-h-0 flex-1 overflow-y-auto overscroll-contain space-y-4 bg-[#F8FAFC] p-3 sm:p-6">
                            {messages.map((msg) => {
                                const isMe = msg.sender_id === currentUser?.id;
                                return (
                                    <div
                                        key={msg.id}
                                        className={`flex flex-col ${isMe ? 'items-end' : 'items-start'}`}
                                    >
                                        <div
                                            className={`min-w-0 max-w-[90%] sm:max-w-[75%] rounded-2xl px-4 py-2.5 text-sm shadow-2xs font-sans ${
                                                isMe 
                                                    ? 'bg-[#E00D42] text-white rounded-br-none' 
                                                    : 'bg-white text-slate-800 border border-slate-200 rounded-bl-none'
                                            }`}
                                        >
                                            {/* Rich Embedded Product Card in Message Bubble */}
                                            {msg.product && (
                                                <a
                                                    href={`/product/${(msg.product as any).slug || (msg.product as any).id}`}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className={`mb-2 p-2.5 rounded-xl flex items-center gap-3 transition block shadow-2xs border ${
                                                        isMe 
                                                            ? 'bg-white/95 border-red-200 text-slate-900' 
                                                            : 'bg-slate-50 border-slate-200 text-slate-900 hover:bg-slate-100'
                                                    }`}
                                                >
                                                    <img
                                                        src={msg.product.featured_image || ''}
                                                        alt={msg.product.name}
                                                        className="w-12 h-12 rounded-lg object-cover bg-white border border-slate-200 shrink-0"
                                                    />
                                                    <div className="min-w-0 flex-1 font-sans text-[11px]">
                                                        <div className="flex items-center gap-1">
                                                            <span className="px-1.5 py-0.5 rounded bg-red-100 text-[#E00D42] text-[8px] uppercase font-bold">
                                                                Product Reference
                                                            </span>
                                                        </div>
                                                        <p className="font-bold text-xs text-slate-900 truncate font-sans mt-0.5">
                                                            {msg.product.name}
                                                        </p>
                                                        <span className="text-[#E00D42] font-black">
                                                            PHP {Number(msg.product.price).toFixed(2)}
                                                        </span>
                                                    </div>
                                                </a>
                                            )}

                                            <ChatAttachment url={msg.attachment_url} />
                                                <p className="leading-relaxed whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{msg.message}</p>
                                        </div>
                                        <span className="text-[9px] font-sans text-slate-400 mt-1 px-1">
                                            {new Date(msg.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>

                        {/* Reply Form */}
                        {activeConversation && activeConversation.user.role !== 'courier' && <ChatImageField image={composer.image} onChange={composer.selectImage} disabled={sending} />}
                        {historyError && <p role="alert" className="px-3 pt-2 text-xs text-red-700">{historyError} <button type="button" onClick={() => setHistoryRetry(value => value + 1)} className="font-semibold underline">Retry</button></p>}
                        {composer.error && <p role="alert" className="px-3 pt-2 text-xs text-red-700">{composer.error}</p>}
                        <form onSubmit={handleSend} aria-busy={sending} className="shrink-0 p-3 border-t border-slate-200 flex items-center gap-2 bg-white">
                            <input
                                type="text"
                                aria-label={`Message to ${activeUser.name}`}
                                aria-describedby="seller-message-help"
                                maxLength={1000}
                                disabled={sending}
                                value={replyText}
                                onChange={(e) => setDrafts(previous => ({ ...previous, [activeUser.id]: e.target.value }))}
                                placeholder={`Reply to ${activeUser.name}...`}
                                className="min-h-12 min-w-0 flex-1 px-3 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42] font-sans"
                            />
                            <button
                                type="submit"
                                aria-label={sending ? 'Sending message' : 'Send message'}
                                disabled={(!replyText.trim() && !composer.image) || sending}
                                className="flex h-12 w-12 shrink-0 items-center justify-center bg-[#E00D42] hover:bg-[#C20836] disabled:opacity-50 text-white rounded-xl shadow-xs transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42] motion-reduce:transition-none"
                            >
                                <Send aria-hidden="true" className="w-5 h-5" />
                            </button>
                        </form>
                        <p id="seller-message-help" role="status" className="shrink-0 px-3 pb-3 text-xs text-slate-600">{sending ? 'Sending message…' : `${replyText.length}/1000 characters · Drafts stay in this page while you switch conversations.`}</p>
                    </div>
                ) : (
                    <div className="hidden min-w-0 flex-1 flex-col items-center justify-center p-6 text-center text-slate-600 font-sans text-xs lg:flex">
                        <MessageSquare className="w-12 h-12 text-slate-300 mb-2" />
                        <p className="font-bold text-slate-700 text-sm font-sans">No Conversation Selected</p>
                        <p className="text-slate-400">Choose a customer thread on the left to start messaging.</p>
                    </div>
                )}

            </div>
        </DashboardLayout>
    );
}
