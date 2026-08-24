import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
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
    ChevronRight 
} from 'lucide-react';

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
    messages: {
        id: number;
        sender_id: number;
        message: string;
        created_at: string;
    }[];
}

interface Props {
    conversations: Conversation[];
}

export default function BuyerMessages({ conversations }: Props) {
    const [selectedConvIndex, setSelectedConvIndex] = useState(0);
    const [replyText, setReplyText] = useState('');
    const [sending, setSending] = useState(false);

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
                { id: 1, sender_id: 1, message: 'Hello! When will order #ORD-8891 be shipped?', created_at: '10:00 AM' },
                { id: 2, sender_id: 2, message: 'Mabuhay! Your order has been packed and handed over to Bagoo Express rider.', created_at: '10:15 AM' },
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
                { id: 3, sender_id: 3, message: 'Good day maam/sir, out for delivery na po ang parcel ninyo.', created_at: '09:30 AM' },
            ],
        },
    ];

    const currentConv = displayConversations[selectedConvIndex];

    const handleSendMessage = (e: React.FormEvent) => {
        e.preventDefault();
        if (!replyText.trim()) return;

        setSending(true);
        router.post(route('chat.send'), {
            receiver_id: currentConv.user.id,
            shop_id: currentConv.user.shop?.id,
            message: replyText.trim(),
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setReplyText('');
                setSending(false);
            },
            onError: () => {
                setSending(false);
            },
        });
    };

    return (
        <BuyerLayout>
            <Head title="Messages & Live Support — BagooPH" />

            <div className="space-y-4">
                <div className="bg-white rounded-3xl p-5 border border-slate-200 shadow-xs flex items-center justify-between font-mono">
                    <div className="flex items-center gap-2.5">
                        <MessageSquare className="w-5 h-5 text-[#E00D42]" />
                        <h1 className="font-black text-base text-slate-900">In-App Chat & Inquiries</h1>
                    </div>
                    <span className="text-xs text-slate-500">Live Merchant & Logistics Channel</span>
                </div>

                {/* 2. CHAT TWO-PANEL INTERFACE */}
                <div className="bg-white rounded-3xl border border-slate-200 shadow-xs overflow-hidden grid grid-cols-1 md:grid-cols-12 min-h-[550px]">
                    
                    {/* LEFT PANEL: CONVERSATION LIST */}
                    <div className="md:col-span-4 border-r border-slate-100 flex flex-col font-sans">
                        <div className="p-3.5 border-b border-slate-100 bg-slate-50/60">
                            <div className="relative">
                                <Search className="w-3.5 h-3.5 text-slate-400 absolute left-3 top-3" />
                                <input
                                    type="text"
                                    placeholder="Search chats, merchants, riders..."
                                    className="w-full pl-8 pr-3 py-1.5 rounded-xl bg-white border border-slate-200 text-xs focus:ring-[#E00D42] focus:border-[#E00D42]"
                                />
                            </div>
                        </div>

                        <div className="flex-1 overflow-y-auto divide-y divide-slate-100">
                            {displayConversations.map((conv, idx) => {
                                const isSelected = idx === selectedConvIndex;
                                return (
                                    <button
                                        key={idx}
                                        onClick={() => setSelectedConvIndex(idx)}
                                        className={`w-full text-left p-4 transition flex items-start gap-3 ${
                                            isSelected ? 'bg-rose-50/50 border-l-4 border-l-[#E00D42]' : 'hover:bg-slate-50'
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
                                                <span className="font-mono text-[10px] text-slate-400">{conv.last_time}</span>
                                            </div>
                                            <p className="text-xs text-slate-500 truncate mt-0.5">{conv.last_message}</p>
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    {/* RIGHT PANEL: CHAT WINDOW */}
                    <div className="md:col-span-8 flex flex-col justify-between bg-slate-50/30">
                        
                        {/* Chat Header */}
                        <div className="p-4 border-b border-slate-200 bg-white flex items-center justify-between font-mono text-xs">
                            <div className="flex items-center gap-3">
                                <div className="w-9 h-9 rounded-xl bg-slate-900 text-white flex items-center justify-center font-bold text-xs">
                                    {currentConv?.user?.role === 'seller' ? <Store className="w-4 h-4 text-emerald-400" /> : <Truck className="w-4 h-4 text-amber-400" />}
                                </div>
                                <div>
                                    <h3 className="font-bold text-slate-900 text-sm font-sans">
                                        {currentConv?.user?.shop ? currentConv.user.shop.name : currentConv?.user?.name}
                                    </h3>
                                    <span className="text-[10px] text-emerald-600 font-bold uppercase">● Online • Fast Response</span>
                                </div>
                            </div>
                        </div>

                        {/* Message Stream */}
                        <div className="p-5 flex-1 overflow-y-auto space-y-3 font-sans text-xs">
                            {currentConv?.messages?.map((msg) => {
                                const isMe = msg.sender_id === 1; // Assuming buyer id 1 for visual layout
                                return (
                                    <div
                                        key={msg.id}
                                        className={`flex ${isMe ? 'justify-end' : 'justify-start'}`}
                                    >
                                        <div
                                            className={`max-w-[75%] p-3.5 rounded-2xl shadow-2xs ${
                                                isMe
                                                    ? 'bg-[#E00D42] text-white rounded-br-none'
                                                    : 'bg-white text-slate-800 border border-slate-200 rounded-bl-none'
                                            }`}
                                        >
                                            <p className="leading-relaxed">{msg.message}</p>
                                            <span className={`block font-mono text-[9px] mt-1 text-right ${isMe ? 'text-white/70' : 'text-slate-400'}`}>
                                                {msg.created_at}
                                            </span>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>

                        {/* Chat Input Bar */}
                        <form onSubmit={handleSendMessage} className="p-3 bg-white border-t border-slate-200 flex items-center gap-2">
                            <input
                                type="text"
                                value={replyText}
                                onChange={(e) => setReplyText(e.target.value)}
                                placeholder="Type your message or inquiry..."
                                className="flex-1 rounded-xl bg-slate-100 border border-slate-200 text-xs py-2.5 px-3 focus:outline-hidden focus:bg-white focus:ring-2 focus:ring-[#E00D42]"
                            />
                            <button
                                type="submit"
                                disabled={sending || !replyText.trim()}
                                className="px-4 py-2.5 bg-[#E00D42] hover:bg-[#C20836] disabled:opacity-50 text-white rounded-xl font-mono text-xs font-bold uppercase transition flex items-center gap-1.5 shadow-xs"
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
