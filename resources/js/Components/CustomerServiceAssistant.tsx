import React, { useState } from 'react';
import { HelpCircle, Loader2, MessageCircle, Send, X } from 'lucide-react';

interface MessageItem {
    role: 'buyer' | 'assistant';
    text: string;
}

const quickQuestions = [
    'Where is my latest order?',
    'How do I complete checkout?',
    'How do I upload my verification document?',
];

export default function CustomerServiceAssistant() {
    const [open, setOpen] = useState(false);
    const [message, setMessage] = useState('');
    const [orderNumber, setOrderNumber] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [messages, setMessages] = useState<MessageItem[]>([]);

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
                body: JSON.stringify({ message: trimmed, order_number: orderNumber.trim() || null }),
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
            {!open && (
                <button
                    type="button"
                    onClick={() => setOpen(true)}
                    className="fixed bottom-16 right-4 sm:bottom-6 sm:right-40 z-50 inline-flex items-center gap-2 px-3.5 py-2.5 bg-slate-900 hover:bg-black text-white font-semibold rounded-xs shadow-xl border border-slate-700 text-xs"
                >
                    <MessageCircle className="w-4 h-4 text-[#E00D42]" />
                    Support
                </button>
            )}

            {open && (
                <section className="fixed bottom-4 right-4 sm:bottom-6 sm:right-6 z-[60] w-[calc(100vw-2rem)] max-w-sm bg-white border border-slate-200 rounded-lg shadow-2xl overflow-hidden">
                    <header className="flex items-center justify-between gap-3 px-4 py-3 bg-slate-900 text-white">
                        <div className="flex items-center gap-2">
                            <HelpCircle className="w-4 h-4 text-[#E00D42]" />
                            <div>
                                <h2 className="text-sm font-bold">Bagoo Support</h2>
                                <p className="text-[10px] text-slate-300">Shopping and order help</p>
                            </div>
                        </div>
                        <button type="button" onClick={() => setOpen(false)} className="p-1 text-slate-300 hover:text-white" aria-label="Close support">
                            <X className="w-4 h-4" />
                        </button>
                    </header>

                    <div className="max-h-72 overflow-y-auto p-3 space-y-2">
                        {messages.length === 0 && (
                            <div className="space-y-2">
                                <p className="text-xs leading-relaxed text-slate-600">Ask about shopping, checkout, verification, or an order.</p>
                                <div className="flex flex-wrap gap-1.5">
                                    {quickQuestions.map(question => (
                                        <button key={question} type="button" onClick={() => ask(question)} className="px-2 py-1 text-[10px] text-slate-700 border border-slate-200 rounded-xs hover:bg-slate-50 text-left">
                                            {question}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}
                        {messages.map((item, index) => (
                            <div key={`${item.role}-${index}`} className={`flex ${item.role === 'buyer' ? 'justify-end' : 'justify-start'}`}>
                                <p className={`max-w-[88%] px-2.5 py-2 text-xs leading-relaxed rounded-sm ${item.role === 'buyer' ? 'bg-[#E00D42] text-white' : 'bg-slate-100 text-slate-700'}`}>
                                    {item.text}
                                </p>
                            </div>
                        ))}
                        {loading && <Loader2 className="w-4 h-4 text-slate-400 animate-spin" />}
                        {error && <p className="text-[11px] text-rose-600">{error}</p>}
                    </div>

                    <div className="px-3 pb-3 space-y-2">
                        <input
                            value={orderNumber}
                            onChange={event => setOrderNumber(event.target.value)}
                            placeholder="Optional order number"
                            className="w-full px-2.5 py-2 text-[11px] border border-slate-200 rounded-xs focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42]"
                        />
                        <form onSubmit={event => { event.preventDefault(); void ask(); }} className="flex items-end gap-2">
                            <textarea
                                value={message}
                                onChange={event => setMessage(event.target.value)}
                                onKeyDown={event => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); void ask(); } }}
                                rows={2}
                                maxLength={1000}
                                placeholder="Type your question"
                                className="flex-1 resize-none px-2.5 py-2 text-xs border border-slate-200 rounded-xs focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42]"
                            />
                            <button type="submit" disabled={!message.trim() || loading} className="p-2.5 bg-[#E00D42] text-white rounded-xs disabled:opacity-50" aria-label="Send question">
                                <Send className="w-4 h-4" />
                            </button>
                        </form>
                    </div>
                </section>
            )}
        </>
    );
}
