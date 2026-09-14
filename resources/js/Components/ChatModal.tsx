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
    shopId?: number;
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
        id?: number;
        name: string;
        price: number;
        featured_image: string;
        slug?: string;
    };
}

export default function ChatModal({ 
    isOpen, 
    onClose, 
    receiverId = 2, 
    receiverName = 'Merchant Support', 
    shopId,
    shopName = 'Bagoo Prime Store', 
    product 
}: Props) {
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

    const sendMessagePayload = async (text: string, prodId?: number | null) => {
        if (!text.trim() || sending || !currentUser) return;

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
                    receiver_id: receiverId,
                    shop_id: shopId || product?.shop_id || product?.shop?.id || null,
                    product_id: prodId !== undefined ? prodId : (product?.id || null),
                    message: text.trim(),
                }),
            });

            const data = await res.json();
            if (data.success && data.message) {
                setMessages(prev => [...prev, data.message]);
                setNewMessage('');
            }
        } catch (err) {
            console.error(err);
        } finally {
            setSending(false);
        }
    };

    const handleSendProductInquiry = async () => {
        if (!product || sending || !currentUser) return;
        const text = `Hello! I would like to inquire about this product: ${product.name} (PHP ${Number(product.price).toFixed(2)}).`;
        await sendMessagePayload(text, product.id);
    };

    const handleSend = (e: React.FormEvent) => {
        e.preventDefault();
        sendMessagePayload(newMessage, product?.id || null);
    };

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4 bg-black/40 backdrop-blur-xs animate-fade-in font-sans">
            <div className="bg-white w-full sm:max-w-md h-[85vh] sm:h-[600px] rounded-t-3xl sm:rounded-3xl shadow-2xl flex flex-col overflow-hidden border border-slate-200">
                
                {/* Header */}
                <div className="p-4 bg-slate-900 text-white flex items-center justify-between shadow-xs shrink-0">
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
                        className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition cursor-pointer"
                    >
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {/* Staged Product Header Banner with 1-Click Send Button */}
                {product && (
                    <div className="p-3 bg-slate-50 border-b border-slate-200 flex items-center justify-between gap-3 text-xs font-mono shrink-0">
                        <div className="flex items-center gap-2.5 min-w-0">
                            <img
                                src={product.featured_image || ''}
                                alt={product.name}
                                className="w-10 h-10 rounded-lg object-cover bg-white border border-slate-200 shrink-0"
                            />
                            <div className="truncate">
                                <p className="font-bold text-slate-900 truncate font-sans">{product.name}</p>
                                <span className="font-black text-[#E00D42]">PHP {Number(product.price).toFixed(2)}</span>
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={handleSendProductInquiry}
                            disabled={sending || !currentUser}
                            className="px-2.5 py-1.5 rounded-lg bg-[#E00D42] hover:bg-[#C20836] text-white font-mono text-[10px] font-bold uppercase tracking-wider transition shrink-0 flex items-center gap-1 shadow-2xs cursor-pointer disabled:opacity-50"
                            title="Send product details to seller"
                        >
                            <Send className="w-3 h-3" />
                            <span>Send Item</span>
                        </button>
                    </div>
                )}

                {/* Guest / Unauthenticated State */}
                {!currentUser ? (
                    <div className="flex-1 p-6 flex flex-col items-center justify-center text-center space-y-3 bg-[#F8FAFC]">
                        <div className="w-12 h-12 rounded-full bg-red-50 text-[#E00D42] flex items-center justify-center">
                            <MessageSquare className="w-6 h-6" />
                        </div>
                        <h4 className="font-bold text-sm text-slate-900 font-sans">Sign in to message {shopName}</h4>
                        <p className="text-xs text-slate-500 max-w-xs font-sans">
                            You must be signed in with a BagooPH account to send direct messages and inquire about items with merchants.
                        </p>
                        <a
                            href="/login"
                            className="px-5 py-2.5 bg-[#E00D42] hover:bg-[#C20836] text-white font-bold rounded-xl text-xs font-mono uppercase tracking-wider transition shadow-xs cursor-pointer"
                        >
                            Sign In to Chat
                        </a>
                    </div>
                ) : (
                    <>
                        {/* Messages Body */}
                        <div className="flex-1 p-4 overflow-y-auto space-y-3 bg-[#F8FAFC]">
                            {loading ? (
                                <div className="flex items-center justify-center h-full text-slate-400 font-mono text-xs">
                                    Loading conversation history...
                                </div>
                            ) : messages.length === 0 ? (
                                <div className="text-center py-12 space-y-2 font-mono text-xs text-slate-400">
                                    <MessageSquare className="w-10 h-10 mx-auto text-slate-300" />
                                    <p className="font-bold text-slate-700 font-sans text-sm">Direct Merchant Messaging</p>
                                    <p className="text-[11px]">Inquire about sizing, custom orders, or delivery details.</p>
                                    {product && (
                                        <div className="pt-3">
                                            <button
                                                type="button"
                                                onClick={handleSendProductInquiry}
                                                disabled={sending}
                                                className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white border border-slate-200 hover:border-[#E00D42] text-slate-700 hover:text-[#E00D42] font-mono text-xs font-bold transition shadow-2xs cursor-pointer"
                                            >
                                                <Send className="w-3.5 h-3.5" />
                                                <span>Send Product Inquiry to Merchant</span>
                                            </button>
                                        </div>
                                    )}
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
                                                className={`max-w-[85%] rounded-2xl px-4 py-2.5 text-xs shadow-2xs font-sans ${
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
                                                        className={`mb-2 p-2 rounded-xl flex items-center gap-2.5 transition block shadow-2xs border ${
                                                            isMe 
                                                                ? 'bg-white/95 border-red-200 text-slate-900' 
                                                                : 'bg-slate-50 border-slate-200 text-slate-900'
                                                        }`}
                                                    >
                                                        <img
                                                            src={msg.product.featured_image || ''}
                                                            alt={msg.product.name}
                                                            className="w-11 h-11 rounded-lg object-cover bg-white border border-slate-200 shrink-0"
                                                        />
                                                        <div className="min-w-0 flex-1 font-mono text-[11px]">
                                                            <div className="flex items-center gap-1">
                                                                <span className="px-1.5 py-0.2 rounded bg-red-100 text-[#E00D42] text-[8px] uppercase font-bold">
                                                                    Product Inquiry
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

                        {/* Quick Inquiry Suggestion Chips (when chatting about product) */}
                        {product && (
                            <div className="px-3 py-1.5 bg-slate-50 border-t border-slate-200/80 flex items-center gap-1.5 overflow-x-auto scrollbar-none shrink-0">
                                {['Is this available?', 'When can you ship?', 'Are there other sizes/colors?'].map((quickText, idx) => (
                                    <button
                                        key={idx}
                                        type="button"
                                        onClick={() => sendMessagePayload(quickText, product.id)}
                                        disabled={sending}
                                        className="px-2 py-1 bg-white hover:bg-slate-100 text-slate-700 border border-slate-200 rounded-lg text-[10px] font-mono whitespace-nowrap transition cursor-pointer disabled:opacity-50"
                                    >
                                        {quickText}
                                    </button>
                                ))}
                            </div>
                        )}

                        {/* Input Footer */}
                        <form onSubmit={handleSend} className="p-3 bg-white border-t border-slate-200 flex items-center gap-2 shrink-0">
                            <input
                                type="text"
                                value={newMessage}
                                onChange={(e) => setNewMessage(e.target.value)}
                                placeholder={product ? `Ask seller about ${product.name}...` : "Type your message to merchant..."}
                                className="flex-1 px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42] font-sans"
                            />
                            <button
                                type="submit"
                                disabled={!newMessage.trim() || sending}
                                className="p-2.5 bg-[#E00D42] hover:bg-[#C20836] disabled:opacity-50 text-white rounded-xl shadow-xs transition cursor-pointer"
                            >
                                <Send className="w-4 h-4" />
                            </button>
                        </form>
                    </>
                )}

            </div>
        </div>
    );
}

