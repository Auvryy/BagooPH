import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import CourierLayout from '@/Layouts/CourierLayout';
import { 
    MessageSquare, 
    Send, 
    Store, 
    User, 
    Search, 
    Truck, 
    MapPin, 
    Clock 
} from 'lucide-react';

interface Conversation {
    user: {
        id: number;
        name: string;
        role: string;
        shop?: {
            id: number;
            name: string;
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

export default function CourierMessages({ conversations }: Props) {
    const [selectedIdx, setSelectedIdx] = useState(0);
    const [replyText, setReplyText] = useState('');
    const [sending, setSending] = useState(false);

    // Fallback sample courier chats if newly registered
    const displayConversations = conversations.length > 0 ? conversations : [
        {
            user: {
                id: 2,
                name: 'Acro Tactical Merchant Store',
                role: 'seller',
                shop: {
                    id: 1,
                    name: 'Acro Tactical Gear Flagship',
                },
            },
            last_message: 'Hi Kuya, ready na po for pickup sa Level 2 Ground dispatch area.',
            last_time: '5m ago',
            unread_count: 1,
            messages: [
                { id: 1, sender_id: 2, message: 'Hi Kuya, ready na po for pickup sa Level 2 Ground dispatch area.', created_at: '10:05 AM' },
                { id: 2, sender_id: 1, message: 'Copy po boss, on the way na po ako with thermal box.', created_at: '10:08 AM' },
            ],
        },
        {
            user: {
                id: 4,
                name: 'Clarisse Valenzuela (Buyer)',
                role: 'buyer',
            },
            last_message: 'Kuya pakitawag na lang po pag andito na sa gate 3.',
            last_time: '25m ago',
            unread_count: 0,
            messages: [
                { id: 3, sender_id: 4, message: 'Kuya pakitawag na lang po pag andito na sa gate 3.', created_at: '09:40 AM' },
                { id: 4, sender_id: 1, message: 'Opo maam, preparing cash on delivery receipt na rin po.', created_at: '09:42 AM' },
            ],
        },
    ];

    const currentConv = displayConversations[selectedIdx];

    const handleSend = (e: React.FormEvent) => {
        e.preventDefault();
        if (!replyText.trim() || !currentConv) return;

        setSending(true);
        router.post(route('chat.send'), {
            receiver_id: currentConv.user.id,
            message: replyText.trim(),
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setReplyText('');
                setSending(false);
            },
            onError: () => setSending(false),
        });
    };

    return (
        <CourierLayout
            title="Courier Field Communication"
            subtitle="Coordinate pickup with merchants and delivery directions with buyers"
        >
            <Head title="Courier Messages — Bagoo Express" />

            <div className="bg-white rounded-3xl border border-slate-200 shadow-xs overflow-hidden grid grid-cols-1 md:grid-cols-12 min-h-[550px] font-sans">
                
                {/* Left Conversations Pane */}
                <div className="md:col-span-4 border-r border-slate-100 flex flex-col">
                    <div className="p-3.5 border-b border-slate-100 bg-slate-50/60">
                        <div className="relative">
                            <Search className="w-3.5 h-3.5 text-slate-400 absolute left-3 top-3" />
                            <input
                                type="text"
                                placeholder="Search store or recipient..."
                                className="w-full pl-8 pr-3 py-1.5 rounded-xl bg-white border border-slate-200 text-xs focus:ring-[#E00D42] focus:border-[#E00D42]"
                            />
                        </div>
                    </div>

                    <div className="flex-1 overflow-y-auto divide-y divide-slate-100">
                        {displayConversations.map((conv, idx) => {
                            const isSelected = idx === selectedIdx;
                            return (
                                <button
                                    key={idx}
                                    onClick={() => setSelectedIdx(idx)}
                                    className={`w-full text-left p-4 transition flex items-start gap-3 ${
                                        isSelected ? 'bg-amber-50/60 border-l-4 border-l-amber-500' : 'hover:bg-slate-50'
                                    }`}
                                >
                                    <div className="w-10 h-10 rounded-xl bg-slate-900 text-white flex items-center justify-center font-bold text-xs shrink-0 font-mono">
                                        {conv.user.role === 'seller' ? <Store className="w-4 h-4 text-emerald-400" /> : <User className="w-4 h-4 text-amber-400" />}
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

                {/* Right Chat Stream */}
                <div className="md:col-span-8 flex flex-col justify-between bg-slate-50/40">
                    
                    {/* Header */}
                    <div className="p-4 border-b border-slate-200 bg-white flex items-center justify-between font-mono text-xs">
                        <div className="flex items-center gap-3">
                            <div className="w-9 h-9 rounded-xl bg-slate-900 text-white flex items-center justify-center font-bold text-xs">
                                {currentConv?.user?.role === 'seller' ? <Store className="w-4 h-4 text-emerald-400" /> : <User className="w-4 h-4 text-amber-400" />}
                            </div>
                            <div>
                                <h3 className="font-bold text-slate-900 text-sm font-sans">
                                    {currentConv?.user?.shop ? currentConv.user.shop.name : currentConv?.user?.name}
                                </h3>
                                <span className="text-[10px] text-emerald-600 font-bold uppercase">
                                    ● Active Delivery Coordination
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* Messages */}
                    <div className="p-5 flex-1 overflow-y-auto space-y-3 font-sans text-xs">
                        {currentConv?.messages?.map((msg) => {
                            const isRider = msg.sender_id === 1; // Assuming rider id 1 in local session
                            return (
                                <div
                                    key={msg.id}
                                    className={`flex ${isRider ? 'justify-end' : 'justify-start'}`}
                                >
                                    <div
                                        className={`max-w-[75%] p-3.5 rounded-2xl shadow-2xs ${
                                            isRider
                                                ? 'bg-slate-900 text-white rounded-br-none'
                                                : 'bg-white text-slate-800 border border-slate-200 rounded-bl-none'
                                        }`}
                                    >
                                        <p className="leading-relaxed">{msg.message}</p>
                                        <span className={`block font-mono text-[9px] mt-1 text-right ${isRider ? 'text-slate-400' : 'text-slate-400'}`}>
                                            {msg.created_at}
                                        </span>
                                    </div>
                                </div>
                            );
                        })}
                    </div>

                    {/* Input */}
                    <form onSubmit={handleSend} className="p-3 bg-white border-t border-slate-200 flex items-center gap-2">
                        <input
                            type="text"
                            value={replyText}
                            onChange={(e) => setReplyText(e.target.value)}
                            placeholder="Type message to merchant or buyer..."
                            className="flex-1 rounded-xl bg-slate-100 border border-slate-200 text-xs py-2.5 px-3 focus:outline-hidden focus:bg-white focus:ring-2 focus:ring-slate-900"
                        />
                        <button
                            type="submit"
                            disabled={sending || !replyText.trim()}
                            className="px-4 py-2.5 bg-slate-900 hover:bg-black disabled:opacity-50 text-white rounded-xl font-mono text-xs font-bold uppercase transition flex items-center gap-1.5 shadow-xs"
                        >
                            <Send className="w-3.5 h-3.5" />
                            <span>Send</span>
                        </button>
                    </form>

                </div>

            </div>
        </CourierLayout>
    );
}
