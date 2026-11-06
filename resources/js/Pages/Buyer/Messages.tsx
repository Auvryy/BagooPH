import useMarketplaceComposer from '@/hooks/useMarketplaceComposer';
import ChatImageField from '@/Components/ChatImageField';
import ChatAttachment from '@/Components/ChatAttachment';
import React, { useState, useEffect, useRef } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import { PageProps } from '@/types';
import { 
    MessageSquare, 
    Send, 
    Store, 
    Truck, 
    Search, 
    CheckCheck, 
    User as UserIcon, 
    Clock, 
    ShoppingBag, 
    ChevronRight,
    ChevronLeft
} from 'lucide-react';

interface MessageItem {
    id: number;
    sender_id: number;
    message: string;
    attachment_url?: string | null;
    created_at: string;
    product_id?: number | null;
    product?: {
        id?: number;
        name: string;
        price: number;
        featured_image: string;
        slug?: string;
    };
}

interface Conversation {
    user: {
        id: number;
        name: string;
        role: string;
        shop?: {
            id: number;
            name: string;
            logo_url?: string;
        };
    };
    order_id?: number | null;
    last_message: string;
    last_time: string;
    unread_count: number;
    messages: MessageItem[];
}

interface Props {
    conversations: Conversation[];
}

export default function BuyerMessages({ conversations }: Props) {
    const { auth } = usePage<PageProps>().props;
    const [selectedConvUserId, setSelectedConvUserId] = useState<number | null>(conversations[0]?.user.id ?? null);
    const [replyText, setReplyText] = useState('');
    const [searchQuery, setSearchQuery] = useState('');
    const [mobileChatOpen, setMobileChatOpen] = useState(conversations.length > 0);
    const messagesEndRef = useRef<HTMLDivElement>(null);
    const [historyError, setHistoryError] = useState('');
    const [historyRetry, setHistoryRetry] = useState(0);

    const displayConversations = conversations;

    const filteredConversations = displayConversations.filter(c => {
        const q = searchQuery.toLowerCase().trim();
        if (!q) return true;
        const name = c.user.name?.toLowerCase() || '';
        const shopName = c.user.shop?.name?.toLowerCase() || '';
        const lastMsg = c.last_message?.toLowerCase() || '';
        return name.includes(q) || shopName.includes(q) || lastMsg.includes(q);
    });

    const currentConv = filteredConversations.find(conversation => conversation.user.id === selectedConvUserId)
        ?? filteredConversations[0]
        ?? null;
    const unreadCount = displayConversations.reduce((total, conversation) => total + conversation.unread_count, 0);

    const [activeMessages, setActiveMessages] = useState<MessageItem[]>(
        currentConv?.messages || []
    );

    const scrollToBottom = () => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    };

    useEffect(() => {
        setActiveMessages(currentConv?.messages || []); setHistoryError('');
        if (!currentConv?.user?.id || !auth.user) return;
        const controller = new AbortController();
        fetch(`/chat/messages/${currentConv.user.id}`, { signal: controller.signal, headers: { Accept: 'application/json' } })
            .then(async response => { if (!response.ok) throw new Error(); return response.json(); })
            .then(data => { if (!controller.signal.aborted && Array.isArray(data.messages)) setActiveMessages(data.messages); })
            .catch(() => { if (!controller.signal.aborted) setHistoryError('Could not update this conversation. Try again.'); });
        return () => controller.abort();
    }, [currentConv?.user?.id, currentConv?.messages, historyRetry]);

    useEffect(() => {
        if (currentConv?.user.id && currentConv.user.id !== selectedConvUserId) {
            setSelectedConvUserId(currentConv.user.id);
        }
    }, [currentConv?.user.id, selectedConvUserId]);

    useEffect(() => {
        scrollToBottom();
    }, [activeMessages]);

    const formatMessageTime = (timeStr?: string) => {
        if (!timeStr) return '';
        try {
            const date = new Date(timeStr);
            if (isNaN(date.getTime())) return timeStr;
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        } catch {
            return timeStr;
        }
    };

    const composer = useMarketplaceComposer(currentConv?.user?.id);
    const sending = composer.sending;
    const handleSendMessage = async (event: React.FormEvent) => {
        event.preventDefault();
        if (!currentConv?.user?.id) return;
        const message = await composer.send<MessageItem>({ receiver_id: currentConv.user.id,
            shop_id: currentConv.user.shop?.id || null,
            order_id: currentConv.order_id ?? null, message: replyText });
        if (message) {
            setActiveMessages(previous => previous.some(row => row.id === message.id) ? previous : [...previous, message]);
            setReplyText('');
            currentConv.last_message = message.message || 'Photo'; currentConv.last_time = 'Just now';
        }
    };

    return (
        <BuyerLayout hideFooter={true} hideCustomerCare={true} fullHeight={true}>
            <Head title="Messages & Live Support — BagooPH" />

            <div className="h-full flex flex-col min-h-0">
                {/* 1. COMPACT TOP HEADER */}
                <div className="mb-2.5 flex shrink-0 flex-wrap items-center justify-between gap-3 rounded-2xl border border-rose-100 bg-gradient-to-r from-white via-white to-rose-50 px-4 py-3 shadow-sm sm:mb-3 sm:px-5">
                    <div className="flex min-w-0 items-center gap-3">
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#E00D42] text-white shadow-sm"><MessageSquare className="h-5 w-5" aria-hidden="true" /></div>
                        <div className="min-w-0">
                            <h1 className="truncate text-sm font-black leading-tight text-slate-900 sm:text-base">In-App Chat &amp; Inquiries</h1>
                            <p className="mt-0.5 hidden text-[11px] text-slate-500 sm:block">Product questions and marketplace conversations</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2 text-[11px] font-semibold">
                        <span className="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-slate-600">{displayConversations.length} {displayConversations.length === 1 ? 'conversation' : 'conversations'}</span>
                        {unreadCount > 0 && <span className="rounded-full bg-rose-100 px-3 py-1.5 text-[#C20836]">{unreadCount} unread</span>}
                    </div>
                </div>

                {/* 2. TWO-PANEL INTERFACE WITH ISOLATED INTERNAL SCROLLING */}
                <div className="bg-white rounded-2xl sm:rounded-3xl border border-slate-200 shadow-xs overflow-hidden flex-1 min-h-0 flex">
                    
                    {/* LEFT PANEL: CONVERSATION LIST */}
                    <div className={`w-full md:w-80 lg:w-96 border-r border-slate-100 flex flex-col h-full min-h-0 font-sans shrink-0 ${mobileChatOpen ? 'hidden md:flex' : 'flex'}`}>
                        <div className="shrink-0 border-b border-slate-100 bg-white p-3">
                            <div className="mb-2 flex items-center justify-between gap-2 px-1"><h2 className="text-[11px] font-bold uppercase tracking-wider text-slate-600">Conversations</h2><span className="text-[10px] text-slate-400">{filteredConversations.length} shown</span></div>
                            <div className="relative">
                                <Search className="w-3.5 h-3.5 text-slate-400 absolute left-3 top-3" />
                                <input
                                    type="text"
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    placeholder="Search chats, merchants, riders..."
                                    className="w-full pl-8 pr-3 py-1.5 rounded-xl bg-white border border-slate-200 text-xs focus:ring-[#E00D42] focus:border-[#E00D42]"
                                />
                            </div>
                        </div>

                        {/* ONLY contacts scroll */}
                        <div className="flex-1 min-h-0 overflow-y-auto divide-y divide-slate-100">
                            {filteredConversations.length === 0 ? (
                                <div className="p-6 text-center text-xs font-sans text-slate-400">
                                    No conversations found
                                </div>
                            ) : (
                                filteredConversations.map((conv, idx) => {
                                    const isSelected = conv.user.id === currentConv?.user.id;
                                    return (
                                        <button
                                            key={conv.user.id || idx}
                                            onClick={() => {
                                                setSelectedConvUserId(conv.user.id);
                                                setMobileChatOpen(true);
                                            }}
                                            className={`w-full text-left p-3.5 sm:p-4 transition flex items-start gap-3 cursor-pointer ${
                                                isSelected ? 'bg-rose-50/60 border-l-4 border-l-[#E00D42]' : 'hover:bg-slate-50'
                                            }`}
                                        >
                                            <div className={`flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-xl font-sans text-xs font-bold ${isSelected ? 'bg-white text-[#E00D42]' : 'bg-slate-900 text-white'}`}>
                                                {conv.user.shop?.logo_url ? <img src={conv.user.shop.logo_url} alt="" className="h-full w-full object-cover" /> : conv.user.role === 'seller' ? <Store className="h-4 w-4 text-emerald-400" aria-hidden="true" /> : <Truck className="h-4 w-4 text-amber-400" aria-hidden="true" />}
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center justify-between">
                                                    <h4 className="font-bold text-xs text-slate-900 truncate">
                                                        {conv.user.shop ? conv.user.shop.name : conv.user.name}
                                                    </h4>
                                                    <span className="font-sans text-[10px] text-slate-400 shrink-0 ml-1">{conv.last_time}</span>
                                                </div>
                                                <p className="text-xs text-slate-500 truncate mt-0.5">{conv.last_message}</p>
                                                {conv.unread_count > 0 && <span className="mt-1 inline-flex min-w-5 items-center justify-center rounded-full bg-[#E00D42] px-1.5 py-0.5 text-[9px] font-bold text-white">{conv.unread_count}</span>}
                                            </div>
                                        </button>
                                    );
                                })
                            )}
                        </div>
                    </div>

                    {/* RIGHT PANEL: ACTIVE CHAT WINDOW */}
                    <div className={`flex-1 flex flex-col h-full min-h-0 min-w-0 bg-slate-50/30 ${mobileChatOpen ? 'flex' : 'hidden md:flex'}`}>
                        
                        {/* Chat Header */}
                        <div className="p-3 sm:p-4 border-b border-slate-200 bg-white flex items-center justify-between font-sans text-xs shrink-0">
                            <div className="flex items-center gap-3">
                                <button
                                    type="button"
                                    onClick={() => setMobileChatOpen(false)}
                                    className="md:hidden p-1.5 -ml-1 text-slate-500 hover:text-slate-800 rounded-lg hover:bg-slate-100 cursor-pointer"
                                    title="Back to conversations"
                                >
                                    <ChevronLeft className="w-5 h-5" />
                                </button>
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-slate-900 text-xs font-bold text-white">
                                    {currentConv?.user?.shop?.logo_url ? <img src={currentConv.user.shop.logo_url} alt="" className="h-full w-full object-cover" /> : currentConv?.user?.role === 'seller' ? <Store className="h-4 w-4 text-emerald-400" aria-hidden="true" /> : <Truck className="h-4 w-4 text-amber-400" aria-hidden="true" />}
                                </div>
                                <div className="min-w-0">
                                    <h3 className="font-bold text-slate-900 text-sm font-sans truncate">
                                        {currentConv?.user?.shop ? currentConv.user.shop.name : currentConv?.user?.name ?? 'No conversation selected'}
                                    </h3>
                                    <span className="flex items-center gap-1 text-[10px] font-medium text-slate-500">{currentConv ? <><span className="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true" /><span>{currentConv.user.role === 'seller' ? 'Shop conversation' : 'Marketplace support'}</span></> : <span>Choose a conversation from the list</span>}</span>
                                </div>
                            </div>
                            {currentConv?.order_id && <span className="hidden rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-[10px] font-semibold text-slate-600 sm:inline-flex">Order conversation</span>}
                        </div>

                        {/* Message Stream: ONLY this scrolls */}
                        <div className="p-4 sm:p-5 flex-1 min-h-0 overflow-y-auto space-y-3 font-sans text-xs">
                            {!currentConv ? (
                                <div className="flex h-full min-h-48 flex-col items-center justify-center rounded-2xl border border-dashed border-slate-200 bg-white/70 p-6 text-center text-slate-500">
                                    <Search className="h-7 w-7 text-slate-300" aria-hidden="true" />
                                    <p className="mt-3 text-sm font-semibold text-slate-700">No matching conversation</p>
                                    <p className="mt-1 max-w-xs text-xs leading-5">Clear the search or choose another conversation to continue.</p>
                                    {searchQuery && <button type="button" onClick={() => setSearchQuery('')} className="mt-3 rounded-full border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Clear search</button>}
                                </div>
                            ) : activeMessages.length === 0 ? (
                                <div className="h-full flex flex-col items-center justify-center text-center p-6 text-slate-400 space-y-2 font-sans">
                                    <MessageSquare className="w-8 h-8 text-slate-300" />
                                    <p className="text-xs">No messages yet in this conversation.</p>
                                </div>
                            ) : (
                                activeMessages.map((msg) => {
                                    const isMe = auth.user?.id ? msg.sender_id === auth.user.id : msg.sender_id === 1;
                                    return (
                                        <div
                                            key={msg.id}
                                            className={`flex ${isMe ? 'justify-end' : 'justify-start'}`}
                                        >
                                            <div
                                                className={`max-w-[85%] sm:max-w-[75%] p-3.5 rounded-2xl shadow-2xs ${
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
                                                                ? 'bg-white/95 border-red-200 text-slate-900 hover:bg-white' 
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
                                                <p className="leading-relaxed whitespace-pre-wrap">{msg.message}</p>
                                                <span className={`block font-sans text-[9px] mt-1 text-right ${isMe ? 'text-white/70' : 'text-slate-400'}`}>
                                                    {formatMessageTime(msg.created_at)}
                                                </span>
                                            </div>
                                        </div>
                                    );
                                })
                            )}
                            <div ref={messagesEndRef} />
                        </div>

                        {/* Chat Input Bar: Fixed at bottom */}
                        {currentConv && currentConv.user.role !== 'courier' && <ChatImageField image={composer.image} onChange={composer.selectImage} disabled={sending} />}
                        {historyError && <p role="alert" className="px-3 pt-2 text-xs text-red-700">{historyError} <button type="button" onClick={() => setHistoryRetry(value => value + 1)} className="font-semibold underline">Retry</button></p>}
                        {composer.error && <p role="alert" className="px-3 pt-2 text-xs text-red-700">{composer.error}</p>}
                        <form onSubmit={handleSendMessage} className="p-3 bg-white border-t border-slate-200 flex items-center gap-2 shrink-0">
                            <input
                                type="text"
                                maxLength={1000}
                                disabled={sending}
                                value={replyText}
                                onChange={(e) => setReplyText(e.target.value)}
                                placeholder="Type your message or inquiry..."
                                className="flex-1 rounded-xl bg-slate-100 border border-slate-200 text-xs py-2.5 px-3 focus:outline-hidden focus:bg-white focus:ring-2 focus:ring-[#E00D42] font-sans"
                            />
                            <button
                                type="submit"
                                disabled={sending || (!replyText.trim() && !composer.image) || !currentConv}
                                className="px-4 py-2.5 bg-[#E00D42] hover:bg-[#C20836] disabled:opacity-50 text-white rounded-xl font-sans text-xs font-bold uppercase transition flex items-center gap-1.5 shadow-xs cursor-pointer"
                            >
                                <Send className="w-3.5 h-3.5" />
                                <span>Send</span>
                            </button>
                        </form>

                    </div>

                </div>

            </div>
        </BuyerLayout>
    );
}
