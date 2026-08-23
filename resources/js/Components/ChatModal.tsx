import React, { useState, useEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import { PageProps, Product, Shop } from '@/types';
import { 
    MessageSquare, 
    X, 
    Send, 
    Store, 
    Check, 
    Clock, 
    Package, 
    Sparkles,
    User
} from 'lucide-react';

interface Props {
    isOpen: boolean;
    onClose: () => void;
    receiverId?: number;
    receiverName?: string;
    shopName?: string;
    product?: Product;
}

interface MessageItem {
    id: number;
    sender_id: number;
    receiver_id: number;
    message: string;
    created_at: string;
    sender?: {
        name: string;
    };
    product?: {
        name: string;
        price: number;
        featured_image: string;
    };
}

export default function ChatModal({ isOpen, onClose, receiverId = 2, receiverName = 'Merchant Support', shopName = 'Bagoo Prime Store', product }: Props) {
    const { auth } = usePage<PageProps>().props;
    const currentUser = auth.user;

    const [messages, setMessages] = useState<MessageItem[]>([]);
    const [newMessage, setNewMessage] = useState('');
    const [loading, setLoading] = useState(false);
    const [sending, setSending] = useState(false);
    const messagesEndRef = useRef<HTMLDivElement>(null);

    const scrollToBottom = () => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    };

    useEffect(() => {
        if (isOpen && currentUser) {
            setLoading(true);
            fetch(`/chat/messages/${receiverId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.messages) {
                        setMessages(data.messages);
                    }
                    setLoading(false);
                    setTimeout(scrollToBottom, 100);
                })
                .catch(() => setLoading(false));
        }
    }, [isOpen, receiverId, currentUser]);

    useEffect(() => {
        scrollToBottom();
    }, [messages]);

    const handleSend = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!newMessage.trim() || sending) return;

        setSending(true);
        const text = newMessage.trim();
        setNewMessage('');

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
                    receiver_id: receiverId,
                    product_id: product?.id || null,
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

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4 bg-black/40 backdrop-blur-xs animate-fade-in font-sans">
            <div className="bg-white w-full sm:max-w-md h-[85vh] sm:h-[600px] rounded-t-3xl sm:rounded-3xl shadow-2xl flex flex-col overflow-hidden border border-slate-200">
                
                {/* Header */}
                <div className="p-4 bg-slate-900 text-white flex items-center justify-between shadow-xs">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-[#E00D42] text-white flex items-center justify-center font-bold">
                            <Store className="w-5 h-5" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <h3 className="font-bold text-sm text-white truncate max-w-[200px]">{shopName}</h3>
                                <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                            </div>
                            <p className="text-[11px] text-slate-400 font-mono">Typically replies within 5 mins</p>
                        </div>
                    </div>
                    <button
                        onClick={onClose}
                        className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition"
                    >
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {/* Attached Product Stage (if chatting from Product Page) */}
                {product && (
                    <div className="p-3 bg-slate-50 border-b border-slate-200 flex items-center justify-between gap-3 text-xs font-mono">
                        <div className="flex items-center gap-2.5 min-w-0">
                            <img
                                src={product.featured_image || ''}
                                alt=""
                                className="w-10 h-10 rounded-lg object-cover bg-white border border-slate-200 shrink-0"
                            />
                            <div className="truncate">
                                <p className="font-bold text-slate-900 truncate font-sans">{product.name}</p>
                                <span className="font-black text-[#E00D42]">₱{Number(product.price).toFixed(2)}</span>
                            </div>
                        </div>
                        <span className="px-2 py-0.5 rounded bg-slate-200 text-slate-700 font-bold uppercase text-[9px]">Item Reference</span>
                    </div>
                )}

                {/* Messages Body */}
                <div className="flex-1 p-4 overflow-y-auto space-y-3 bg-[#F8FAFC]">
                    {loading ? (
                        <div className="flex items-center justify-center h-full text-slate-400 font-mono text-xs">
                            Loading conversation history...
                        </div>
                    ) : messages.length === 0 ? (
                        <div className="text-center py-16 space-y-2 font-mono text-xs text-slate-400">
                            <MessageSquare className="w-10 h-10 mx-auto text-slate-300" />
                            <p className="font-bold text-slate-700 font-sans text-sm">Direct Merchant Messaging</p>
                            <p className="text-[11px]">Inquire about sizing, custom inquiries, or delivery details.</p>
                        </div>
                    ) : (
                        messages.map((msg) => {
                            const isMe = msg.sender_id === currentUser?.id;
                            return (
                                <div
                                    key={msg.id}
                                    className={`flex flex-col ${isMe ? 'items-end' : 'items-start'}`}
                                >
                                    <div
                                        className={`max-w-[80%] rounded-2xl px-4 py-2.5 text-xs shadow-2xs font-sans ${
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
                        })
                    )}
                    <div ref={messagesEndRef} />
                </div>

                {/* Input Footer */}
                <form onSubmit={handleSend} className="p-3 bg-white border-t border-slate-200 flex items-center gap-2">
                    <input
                        type="text"
                        value={newMessage}
                        onChange={(e) => setNewMessage(e.target.value)}
                        placeholder="Type your message to merchant..."
                        className="flex-1 px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42] font-sans"
                    />
                    <button
                        type="submit"
                        disabled={!newMessage.trim() || sending}
                        className="p-2.5 bg-[#E00D42] hover:bg-[#C20836] disabled:opacity-50 text-white rounded-xl shadow-xs transition"
                    >
                        <Send className="w-4 h-4" />
                    </button>
                </form>

            </div>
        </div>
    );
}
