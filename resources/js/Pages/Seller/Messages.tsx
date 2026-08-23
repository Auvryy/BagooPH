import React, { useState, useEffect, useRef } from 'react';
import { Head, usePage } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { PageProps, Shop, User } from '@/types';
import { 
    MessageSquare, 
    Send, 
    User as UserIcon, 
    Search, 
    Clock, 
    Check, 
    Store,
    ShoppingBag
} from 'lucide-react';

interface MessageItem {
    id: number;
    sender_id: number;
    receiver_id: number;
    message: string;
    created_at: string;
    product?: {
        name: string;
        price: number;
        featured_image: string;
    };
}

interface Conversation {
    user: User;
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
    const [replyText, setReplyText] = useState('');
    const [sending, setSending] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');
    const messagesEndRef = useRef<HTMLDivElement>(null);

    const scrollToBottom = () => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    };

    useEffect(() => {
        if (activeUser) {
            fetch(`/chat/messages/${activeUser.id}`)
                .then(res => res.json())
                .then(data => {
                    if (data.messages) {
                        setMessages(data.messages);
                    }
                    setTimeout(scrollToBottom, 50);
                });
        }
    }, [activeUser]);

    useEffect(() => {
        scrollToBottom();
    }, [messages]);

    const handleSend = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!replyText.trim() || !activeUser || sending) return;

        setSending(true);
        const text = replyText.trim();
        setReplyText('');

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
                    receiver_id: activeUser.id,
                    shop_id: shop.id,
                    message: text,
                }),
            });

            const data = await res.json();
            if (data.success && data.message) {
                setMessages(prev => [...prev, data.message]);
            }
        } catch (err) {
            console.error(err);
        } finally {
            setSending(false);
        }
    };

    const filteredConversations = conversations.filter(c => 
        !searchQuery.trim() || 
        (c.user?.name && c.user.name.toLowerCase().includes(searchQuery.toLowerCase()))
    );

    return (
        <DashboardLayout
            title="Customer Inquiries & Live Chat Hub"
            subtitle={`Direct shopper communication portal for ${shop.name}`}
        >
            <Head title="Customer Messages — BagooPH Seller" />

            <div className="bg-white rounded-3xl border border-slate-200 shadow-2xs h-[75vh] flex overflow-hidden font-sans">
                
                {/* Left: Conversations List */}
                <div className="w-80 sm:w-96 border-r border-slate-200 flex flex-col bg-slate-50/50">
                    <div className="p-4 border-b border-slate-200 space-y-3 bg-white">
                        <div className="flex items-center justify-between">
                            <h3 className="font-bold text-sm text-slate-900">Conversations</h3>
                            <span className="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 font-mono text-[10px] font-bold">
                                {conversations.length} Active
                            </span>
                        </div>
                        <div className="relative font-mono text-xs">
                            <input
                                type="text"
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                placeholder="Search customer name..."
                                className="w-full pl-8 pr-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                            />
                            <Search className="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-2" />
                        </div>
                    </div>

                    <div className="flex-1 overflow-y-auto divide-y divide-slate-100">
                        {filteredConversations.length === 0 ? (
                            <p className="text-xs text-slate-400 py-12 text-center font-mono">No conversations found.</p>
                        ) : (
                            filteredConversations.map((c) => {
                                const isSelected = activeUser?.id === c.user?.id;
                                return (
                                    <button
                                        key={c.user?.id || Math.random()}
                                        onClick={() => setActiveUser(c.user)}
                                        className={`w-full p-4 text-left flex items-start gap-3 transition ${
                                            isSelected ? 'bg-white shadow-xs border-l-4 border-l-[#E00D42]' : 'hover:bg-slate-100/70'
                                        }`}
                                    >
                                        <div className="w-10 h-10 rounded-full bg-slate-900 text-white flex items-center justify-center font-bold text-xs shrink-0">
                                            {c.user?.name?.charAt(0) || 'U'}
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center justify-between">
                                                <h4 className="font-bold text-xs text-slate-900 truncate">{c.user?.name || 'Customer'}</h4>
                                                <span className="text-[10px] font-mono text-slate-400">{c.last_time}</span>
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
                    <div className="flex-1 flex flex-col bg-white min-w-0">
                        
                        {/* Chat Header */}
                        <div className="p-4 border-b border-slate-200 flex items-center justify-between bg-white">
                            <div className="flex items-center gap-3">
                                <div className="w-10 h-10 rounded-full bg-slate-900 text-white flex items-center justify-center font-bold text-xs">
                                    {activeUser.name.charAt(0)}
                                </div>
                                <div>
                                    <h3 className="font-bold text-sm text-slate-900">{activeUser.name}</h3>
                                    <p className="text-[10px] font-mono text-slate-400">Customer • {activeUser.city || 'Metro Manila'}</p>
                                </div>
                            </div>
                        </div>

                        {/* Messages Thread */}
                        <div className="flex-1 p-6 overflow-y-auto space-y-4 bg-[#F8FAFC]">
                            {messages.map((msg) => {
                                const isMe = msg.sender_id === currentUser?.id;
                                return (
                                    <div
                                        key={msg.id}
                                        className={`flex flex-col ${isMe ? 'items-end' : 'items-start'}`}
                                    >
                                        <div
                                            className={`max-w-[75%] rounded-2xl px-4 py-2.5 text-xs shadow-2xs font-sans ${
                                                isMe 
                                                    ? 'bg-[#E00D42] text-white rounded-br-none' 
                                                    : 'bg-white text-slate-800 border border-slate-200 rounded-bl-none'
                                            }`}
                                        >
                                            <p className="leading-relaxed whitespace-pre-wrap">{msg.message}</p>
                                        </div>
                                        <span className="text-[9px] font-mono text-slate-400 mt-1 px-1">
                                            {new Date(msg.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                        </span>
                                    </div>
                                );
                            })}
                            <div ref={messagesEndRef} />
                        </div>

                        {/* Reply Form */}
                        <form onSubmit={handleSend} className="p-3 border-t border-slate-200 flex items-center gap-2 bg-white">
                            <input
                                type="text"
                                value={replyText}
                                onChange={(e) => setReplyText(e.target.value)}
                                placeholder={`Reply to ${activeUser.name}...`}
                                className="flex-1 px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42] font-sans"
                            />
                            <button
                                type="submit"
                                disabled={!replyText.trim() || sending}
                                className="p-2.5 bg-[#E00D42] hover:bg-[#C20836] disabled:opacity-50 text-white rounded-xl shadow-xs transition"
                            >
                                <Send className="w-4 h-4" />
                            </button>
                        </form>
                    </div>
                ) : (
                    <div className="flex-1 flex flex-col items-center justify-center p-12 text-center text-slate-400 font-mono text-xs">
                        <MessageSquare className="w-12 h-12 text-slate-300 mb-2" />
                        <p className="font-bold text-slate-700 text-sm font-sans">No Conversation Selected</p>
                        <p className="text-slate-400">Choose a customer thread on the left to start messaging.</p>
                    </div>
                )}

            </div>
        </DashboardLayout>
    );
}
