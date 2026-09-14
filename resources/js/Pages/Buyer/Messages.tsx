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
    const [selectedConvIndex, setSelectedConvIndex] = useState(0);
    const [replyText, setReplyText] = useState('');
    const [sending, setSending] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');
    const [mobileChatOpen, setMobileChatOpen] = useState(true);
    const messagesEndRef = useRef<HTMLDivElement>(null);

    // Fallback sample conversation if buyer has no messages yet
    const displayConversations = conversations.length > 0 ? conversations : [
        {
            user: {
                id: 2,
                name: 'Acro Tactical Merchant Support',
                role: 'seller',
                shop: {
                    id: 1,
                    name: 'Acro Tactical Gear Official',
                },
            },
            last_message: 'Mabuhay! Your order has been packed and handed over to Bagoo Express rider.',
            last_time: '10m ago',
            unread_count: 0,
            messages: [
                { id: 1, sender_id: 1, message: 'Hello! When will order #ORD-8891 be shipped?', created_at: '2026-09-14T01:10:00.000000Z' },
                { id: 2, sender_id: 2, message: 'Mabuhay! Your order has been packed and handed over to Bagoo Express rider.', created_at: '2026-09-14T01:15:00.000000Z' },
            ],
        },
        {
            user: {
                id: 3,
                name: 'Kuya Ronald (Barangay Rider)',
                role: 'courier',
            },
            last_message: 'Good day maam/sir, out for delivery na po ang parcel ninyo.',
            last_time: '1h ago',
            unread_count: 1,
            messages: [
                { id: 3, sender_id: 3, message: 'Good day maam/sir, out for delivery na po ang parcel ninyo.', created_at: '2026-09-14T01:00:00.000000Z' },
            ],
        },
    ];

    const filteredConversations = displayConversations.filter(c => {
        const q = searchQuery.toLowerCase().trim();
        if (!q) return true;
        const name = c.user.name?.toLowerCase() || '';
        const shopName = c.user.shop?.name?.toLowerCase() || '';
        const lastMsg = c.last_message?.toLowerCase() || '';
        return name.includes(q) || shopName.includes(q) || lastMsg.includes(q);
    });

    const currentConv = filteredConversations[selectedConvIndex] || displayConversations[0];

    const [activeMessages, setActiveMessages] = useState<MessageItem[]>(
        currentConv?.messages || []
    );

    const scrollToBottom = () => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    };

    useEffect(() => {
        if (currentConv?.messages) {
            setActiveMessages(currentConv.messages);
        }
        if (currentConv?.user?.id && auth.user) {
            fetch(`/chat/messages/${currentConv.user.id}`)
                .then(res => res.json())
                .then(data => {
                    if (data.messages && Array.isArray(data.messages)) {
                        setActiveMessages(data.messages);
                    }
                    setTimeout(scrollToBottom, 60);
                })
                .catch(() => {});
        }
    }, [selectedConvIndex, currentConv?.user?.id]);

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

    const handleSendMessage = async (e: React.FormEvent) => {
        e.preventDefault();
        const text = replyText.trim();
        if (!text || sending || !currentConv?.user?.id) return;

        setSending(true);

        try {
            const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';
            const res = await fetch('/chat/send', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    receiver_id: currentConv.user.id,
                    shop_id: currentConv.user.shop?.id || null,
                    message: text,
                }),
            });

            const data = await res.json();
            if (data.success && data.message) {
                setActiveMessages(prev => {
                    if (prev.some(m => m.id === data.message.id)) return prev;
                    return [...prev, data.message];
                });
                setReplyText('');
                currentConv.last_message = text;
                currentConv.last_time = 'Just now';
            }
        } catch (err) {
            console.error(err);
        } finally {
            setSending(false);
        }
    };

    return (
        <BuyerLayout hideFooter={true} hideCustomerCare={true} fullHeight={true}>
            <Head title="Messages & Live Support — BagooPH" />

            <div className="h-full flex flex-col min-h-0">
                {/* 1. COMPACT TOP HEADER */}
                <div className="bg-white rounded-2xl px-4 py-2 sm:px-5 sm:py-2.5 border border-slate-200 shadow-2xs flex items-center justify-between font-mono shrink-0 mb-2 sm:mb-2.5">
                    <div className="flex items-center gap-2.5">
                        <div className="w-8 h-8 rounded-xl bg-red-50 text-[#E00D42] flex items-center justify-center font-bold shrink-0">
                            <MessageSquare className="w-4 h-4" />
                        </div>
                        <div>
                            <h1 className="font-black text-sm text-slate-900 leading-none">In-App Chat & Inquiries</h1>
                            <p className="text-[10px] text-slate-400 font-sans mt-0.5 hidden sm:block">Direct channel with verified merchants & logistics fleet</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2 text-xs">
                        <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span className="text-slate-500 text-[11px] font-sans">Live Support Active</span>
                    </div>
                </div>

                {/* 2. TWO-PANEL INTERFACE WITH ISOLATED INTERNAL SCROLLING */}
                <div className="bg-white rounded-2xl sm:rounded-3xl border border-slate-200 shadow-xs overflow-hidden flex-1 min-h-0 flex">
                    
                    {/* LEFT PANEL: CONVERSATION LIST */}
                    <div className={`w-full md:w-80 lg:w-96 border-r border-slate-100 flex flex-col h-full min-h-0 font-sans shrink-0 ${mobileChatOpen ? 'hidden md:flex' : 'flex'}`}>
                        <div className="p-3 border-b border-slate-100 bg-slate-50/60 shrink-0">
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
                                <div className="p-6 text-center text-xs font-mono text-slate-400">
                                    No conversations found
                                </div>
                            ) : (
                                filteredConversations.map((conv, idx) => {
                                    const isSelected = idx === selectedConvIndex;
                                    return (
                                        <button
                                            key={conv.user.id || idx}
                                            onClick={() => {
                                                setSelectedConvIndex(idx);
                                                setMobileChatOpen(true);
                                            }}
                                            className={`w-full text-left p-3.5 sm:p-4 transition flex items-start gap-3 cursor-pointer ${
                                                isSelected ? 'bg-rose-50/60 border-l-4 border-l-[#E00D42]' : 'hover:bg-slate-50'
                                            }`}
                                        >
                                            <div className="w-10 h-10 rounded-xl bg-slate-900 text-white flex items-center justify-center font-bold text-xs shrink-0 font-mono">
                                                {conv.user.role === 'seller' ? <Store className="w-4 h-4 text-emerald-400" /> : <Truck className="w-4 h-4 text-amber-400" />}
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center justify-between">
                                                    <h4 className="font-bold text-xs text-slate-900 truncate">
                                                        {conv.user.shop ? conv.user.shop.name : conv.user.name}
                                                    </h4>
                                                    <span className="font-mono text-[10px] text-slate-400 shrink-0 ml-1">{conv.last_time}</span>
                                                </div>
                                                <p className="text-xs text-slate-500 truncate mt-0.5">{conv.last_message}</p>
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
                        <div className="p-3 sm:p-4 border-b border-slate-200 bg-white flex items-center justify-between font-mono text-xs shrink-0">
                            <div className="flex items-center gap-3">
                                <button
                                    type="button"
                                    onClick={() => setMobileChatOpen(false)}
                                    className="md:hidden p-1.5 -ml-1 text-slate-500 hover:text-slate-800 rounded-lg hover:bg-slate-100 cursor-pointer"
                                    title="Back to conversations"
                                >
                                    <ChevronLeft className="w-5 h-5" />
                                </button>
                                <div className="w-9 h-9 rounded-xl bg-slate-900 text-white flex items-center justify-center font-bold text-xs shrink-0">
                                    {currentConv?.user?.role === 'seller' ? <Store className="w-4 h-4 text-emerald-400" /> : <Truck className="w-4 h-4 text-amber-400" />}
                                </div>
                                <div className="min-w-0">
                                    <h3 className="font-bold text-slate-900 text-sm font-sans truncate">
                                        {currentConv?.user?.shop ? currentConv.user.shop.name : currentConv?.user?.name}
                                    </h3>
                                    <span className="text-[10px] text-emerald-600 font-bold uppercase flex items-center gap-1">
                                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                        <span>Online • Fast Response</span>
                                    </span>
                                </div>
                            </div>
                        </div>

                        {/* Message Stream: ONLY this scrolls */}
                        <div className="p-4 sm:p-5 flex-1 min-h-0 overflow-y-auto space-y-3 font-sans text-xs">
                            {activeMessages.length === 0 ? (
                                <div className="h-full flex flex-col items-center justify-center text-center p-6 text-slate-400 space-y-2 font-mono">
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
                                                        <div className="min-w-0 flex-1 font-mono text-[11px]">
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

                                                <p className="leading-relaxed whitespace-pre-wrap">{msg.message}</p>
                                                <span className={`block font-mono text-[9px] mt-1 text-right ${isMe ? 'text-white/70' : 'text-slate-400'}`}>
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
                        <form onSubmit={handleSendMessage} className="p-3 bg-white border-t border-slate-200 flex items-center gap-2 shrink-0">
                            <input
                                type="text"
                                value={replyText}
                                onChange={(e) => setReplyText(e.target.value)}
                                placeholder="Type your message or inquiry..."
                                className="flex-1 rounded-xl bg-slate-100 border border-slate-200 text-xs py-2.5 px-3 focus:outline-hidden focus:bg-white focus:ring-2 focus:ring-[#E00D42] font-sans"
                            />
                            <button
                                type="submit"
                                disabled={sending || !replyText.trim()}
                                className="px-4 py-2.5 bg-[#E00D42] hover:bg-[#C20836] disabled:opacity-50 text-white rounded-xl font-mono text-xs font-bold uppercase transition flex items-center gap-1.5 shadow-xs cursor-pointer"
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
