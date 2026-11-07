import React, { useEffect, useRef, useState } from 'react';
import { Loader2, MessageCircle, Send, UserRound, X } from 'lucide-react';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import BagooLogo from '@/Components/BagooLogo';
import { router } from '@inertiajs/react';

interface MessageItem {
    role: 'buyer' | 'assistant';
    text: string;
}

interface Props {
    onContactHuman?: () => void;
    onOpenMessages?: () => void;
}

const quickQuestions = [
    'Where is my latest order?',
    'How do I complete checkout?',
    'How do I upload my verification document?',
];

export default function CustomerServiceAssistant({ onContactHuman, onOpenMessages }: Props) {
    const [open, setOpen] = useState(false);
    const [message, setMessage] = useState('');
    const [orderNumber, setOrderNumber] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [messages, setMessages] = useState<MessageItem[]>([]);
    const messagesEndRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }, [messages, loading]);

    const handleHumanContact = () => {
        setOpen(false);
        if (onOpenMessages) {
            onOpenMessages();
        } else if (onContactHuman) {
            onContactHuman();
        } else if (typeof window !== 'undefined' && (window as any).route) {
            router.visit(route('buyer.messages'));
        }
    };

    const ask = async (question = message) => {
        const trimmed = question.trim();
        if (!trimmed || loading) return;
        setMessage('');
        setError(null);
        setMessages(previous => [...previous, { role: 'buyer', text: trimmed }]);
        setLoading(true);

        try {
            const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';
            const response = await fetch(route('buyer.support.assistant'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    message: trimmed,
                    order_number: orderNumber.trim() || null,
                }),
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.message || 'Support is unavailable right now.');
            setMessages(previous => [...previous, { role: 'assistant', text: payload.reply }]);
        } catch (assistantError) {
            setError(assistantError instanceof Error ? assistantError.message : 'Support is unavailable right now.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="inline-flex min-h-9 items-center gap-2 rounded-lg text-xs text-slate-500 transition hover:text-[#E00D42] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-200 cursor-pointer"
                aria-expanded={open}
                aria-controls="buyer-support-panel"
            >
                <MessageCircle className="w-4 h-4 text-[#E00D42]" />
                Support
            </button>

            {open && (
                <Dialog open={open} onClose={() => setOpen(false)} className="relative z-[60]">
                    <DialogPanel
                        id="buyer-support-panel"
                        aria-label="Bagoo Support"
                        className="fixed bottom-24 right-4 sm:right-6 z-[60] flex max-h-[calc(100dvh-8rem)] w-[calc(100vw-2rem)] max-w-sm flex-col overflow-hidden rounded-2xl border border-red-100 bg-white shadow-[0_12px_40px_rgba(224,13,66,0.18)]"
                    >
                        {/* Header in Red Theme - No Black */}
                        <header className="flex items-center justify-between gap-3 px-4 py-3 bg-[#E00D42] text-white">
                            <div className="flex min-w-0 items-center gap-2">
                                <BagooLogo className="w-7 h-7" rounded="rounded-md" />
                                <div className="min-w-0">
                                    <DialogTitle className="block text-sm font-bold leading-5 text-white">Bagoo Support</DialogTitle>
                                    <p className="text-[10px] text-white/80 font-medium">Shopping and order help</p>
                                </div>
                            </div>
                            <div className="flex items-center gap-1.5">
                                <button
                                    type="button"
                                    onClick={handleHumanContact}
                                    className="inline-flex items-center gap-1.5 px-2.5 py-1 text-[10px] font-bold text-white bg-white/20 hover:bg-white/30 rounded-lg transition-colors cursor-pointer"
                                    title="Open buyer messages"
                                    aria-label="Open buyer messages"
                                >
                                    <UserRound className="w-3.5 h-3.5 text-white" />
                                    <span className="hidden sm:inline">Messages</span>
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setOpen(false)}
                                    className="p-1 text-white/80 hover:text-white rounded-md hover:bg-white/20 transition cursor-pointer"
                                    aria-label="Close support"
                                >
                                    <X className="w-4 h-4" />
                                </button>
                            </div>
                        </header>

                        {/* Order context */}
                        <div className="p-3 bg-red-50/60 border-b border-red-100">
                            <label className="block text-[10px] font-bold uppercase tracking-wider text-red-900 mb-1">
                                Order context (optional)
                            </label>
                            <input
                                value={orderNumber}
                                onChange={event => setOrderNumber(event.target.value)}
                                aria-label="Order number (optional)"
                                placeholder="e.g. BGO-XXXXX"
                                className="w-full text-xs font-mono rounded-lg border border-red-200 bg-white px-2.5 py-1.5 text-slate-800 placeholder:text-slate-400 focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42]"
                            />
                        </div>

                        {/* Messages Body */}
                        <div className="min-h-0 max-h-72 overflow-y-auto p-3 space-y-2 bg-slate-50/50">
                            {messages.length === 0 && (
                                <div className="space-y-2">
                                    <div className="rounded-xl bg-white border border-slate-200/80 p-3 text-xs leading-relaxed text-slate-700 shadow-2xs">
                                        Mabuhay! I am your Bagoo shopping assistant. Ask about shopping, checkout, orders, returns, or seller tracking.
                                    </div>
                                    <div className="flex flex-wrap gap-1.5 pt-1">
                                        {quickQuestions.map(question => (
                                            <button
                                                key={question}
                                                type="button"
                                                onClick={() => ask(question)}
                                                className="px-2.5 py-1 text-[10px] font-bold text-amber-900 bg-amber-50 hover:bg-amber-100 border border-amber-200/80 rounded-lg transition-colors cursor-pointer text-left"
                                            >
                                                {question}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {messages.map((item, index) => (
                                <div key={`${item.role}-${index}`} className={`flex ${item.role === 'buyer' ? 'justify-end' : 'justify-start'}`}>
                                    <p className={`max-w-[85%] px-3 py-2 text-xs leading-relaxed rounded-xl shadow-2xs ${
                                        item.role === 'buyer' 
                                            ? 'bg-[#E00D42] text-white font-medium' 
                                            : 'bg-white border border-slate-200/80 text-slate-800'
                                    }`}>
                                        {item.text}
                                    </p>
                                </div>
                            ))}

                            {loading && (
                                <div className="flex items-center gap-2 text-xs text-[#E00D42] bg-white border border-red-100 p-2.5 rounded-xl shadow-2xs font-medium">
                                    <Loader2 className="w-3.5 h-3.5 animate-spin text-[#E00D42]" />
                                    <span>Searching marketplace knowledge base…</span>
                                </div>
                            )}

                            {error && (
                                <div role="alert" className="rounded-xl bg-red-50 border border-red-200 p-2 text-xs text-red-700">
                                    {error}
                                </div>
                            )}
                            <div ref={messagesEndRef} />
                        </div>

                        {/* Textarea & Submit */}
                        <div className="p-3 border-t border-slate-100 bg-white">
                            <form onSubmit={event => { event.preventDefault(); void ask(); }} className="flex items-center gap-2">
                                <input
                                    type="text"
                                    value={message}
                                    onChange={event => setMessage(event.target.value)}
                                    placeholder="Type your question…"
                                    className="flex-1 text-xs rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-slate-800 placeholder:text-slate-400 focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] focus:bg-white"
                                />
                                <button
                                    type="submit"
                                    disabled={!message.trim() || loading}
                                    className="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-[#E00D42] hover:bg-[#C20836] disabled:opacity-40 text-white transition-colors cursor-pointer shadow-xs active:scale-95"
                                    aria-label="Send question"
                                >
                                    <Send className="w-4 h-4" />
                                </button>
                            </form>
                        </div>
                    </DialogPanel>
                </Dialog>
            )}
        </>
    );
}
