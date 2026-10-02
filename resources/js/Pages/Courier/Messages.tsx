import React, { useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { MessageSquare, Search, Send, Store, Truck, UserRound } from 'lucide-react';
import CourierLayout from '@/Layouts/CourierLayout';

interface Conversation {
    delivery_id: number;
    tracking_number: string;
    order_number: string | null;
    phase: 'pickup' | 'final_mile';
    can_send: boolean;
    participant: {
        id: number;
        name: string;
        role: string;
        shop_name: string | null;
    };
    last_message: string | null;
    last_time: string | null;
    unread_count: number;
    messages: Array<{
        id: number;
        sender_id: number;
        message: string;
        created_at: string | null;
    }>;
}

interface Props {
    conversations: Conversation[];
    currentUserId: number;
    selectedDeliveryId: number | null;
    isOnline: boolean;
}

const conversationKey = (conversation: Conversation) =>
    `${conversation.delivery_id}-${conversation.phase}`;

export default function CourierMessages({
    conversations,
    currentUserId,
    selectedDeliveryId,
    isOnline,
}: Props) {
    const initialConversation =
        conversations.find((conversation) => conversation.delivery_id === selectedDeliveryId) ??
        conversations[0] ??
        null;
    const [selectedKey, setSelectedKey] = useState<string | null>(
        initialConversation ? conversationKey(initialConversation) : null,
    );
    const [query, setQuery] = useState('');
    const [message, setMessage] = useState('');
    const [sending, setSending] = useState(false);

    const filteredConversations = useMemo(() => {
        const normalized = query.trim().toLowerCase();
        if (!normalized) return conversations;

        return conversations.filter((conversation) =>
            [
                conversation.tracking_number,
                conversation.order_number,
                conversation.participant.name,
                conversation.participant.shop_name,
            ]
                .filter(Boolean)
                .some((value) => value!.toLowerCase().includes(normalized)),
        );
    }, [conversations, query]);

    const selectedConversation =
        conversations.find((conversation) => conversationKey(conversation) === selectedKey) ??
        initialConversation;

    const formatTime = (value: string | null) => {
        if (!value) return '';
        return new Intl.DateTimeFormat('en-PH', {
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        }).format(new Date(value));
    };

    const submitMessage = (event: React.FormEvent) => {
        event.preventDefault();
        if (!selectedConversation || !selectedConversation.can_send || !message.trim()) return;

        setSending(true);
        router.post(
            route('courier.messages.send'),
            {
                delivery_id: selectedConversation.delivery_id,
                message: message.trim(),
            },
            {
                preserveScroll: true,
                onSuccess: () => setMessage(''),
                onFinish: () => setSending(false),
            },
        );
    };

    return (
        <CourierLayout
            title="Delivery messages"
            subtitle="Order-linked coordination with the assigned merchant or buyer"
            isOnline={isOnline}
        >
            <Head title="Delivery messages — BagooPH" />

            {conversations.length === 0 ? (
                <div className="rounded-lg border border-slate-300 bg-white p-10 text-center">
                    <MessageSquare className="mx-auto h-10 w-10 text-slate-300" />
                    <h2 className="mt-3 text-base font-bold text-slate-900">No delivery conversations</h2>
                    <p className="mx-auto mt-1 max-w-md text-sm leading-6 text-slate-500">
                        Merchant and buyer conversations appear only while they are connected to your
                        assigned pickup or final-mile delivery.
                    </p>
                </div>
            ) : (
                <div className="grid min-h-[620px] overflow-hidden rounded-lg border border-slate-300 bg-white md:grid-cols-[320px_minmax(0,1fr)]">
                    <aside className="border-b border-slate-300 md:border-b-0 md:border-r">
                        <div className="border-b border-slate-200 p-3">
                            <label className="relative block">
                                <Search className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                                <span className="sr-only">Search conversations</span>
                                <input
                                    type="search"
                                    value={query}
                                    onChange={(event) => setQuery(event.target.value)}
                                    placeholder="Search tracking or contact"
                                    className="w-full rounded-sm border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]"
                                />
                            </label>
                        </div>

                        <div className="max-h-72 divide-y divide-slate-200 overflow-y-auto md:max-h-[560px]">
                            {filteredConversations.map((conversation) => {
                                const active = conversationKey(conversation) === selectedKey;
                                return (
                                    <button
                                        type="button"
                                        key={conversationKey(conversation)}
                                        onClick={() => {
                                            setSelectedKey(conversationKey(conversation));
                                            setMessage('');
                                        }}
                                        className={`w-full border-l-2 p-4 text-left transition ${
                                            active
                                                ? 'border-l-[#E00D42] bg-rose-50'
                                                : 'border-l-transparent hover:bg-slate-50'
                                        }`}
                                    >
                                        <div className="flex items-start gap-3">
                                            <span className="mt-0.5 rounded-sm border border-slate-300 bg-white p-2 text-slate-600">
                                                {conversation.phase === 'pickup' ? (
                                                    <Store className="h-4 w-4" />
                                                ) : (
                                                    <UserRound className="h-4 w-4" />
                                                )}
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="flex items-start justify-between gap-2">
                                                    <span className="truncate text-sm font-semibold text-slate-900">
                                                        {conversation.participant.shop_name ??
                                                            conversation.participant.name}
                                                    </span>
                                                    {conversation.unread_count > 0 && (
                                                        <span className="rounded-sm bg-[#E00D42] px-1.5 py-0.5 text-[10px] font-bold text-white">
                                                            {conversation.unread_count}
                                                        </span>
                                                    )}
                                                </span>
                                                <span className="mt-1 block text-xs text-slate-500">
                                                    {conversation.tracking_number}
                                                </span>
                                                <span className="mt-1 block truncate text-xs text-slate-500">
                                                    {conversation.last_message ??
                                                        (conversation.can_send
                                                            ? 'Start delivery coordination'
                                                            : 'No messages recorded')}
                                                </span>
                                            </span>
                                        </div>
                                    </button>
                                );
                            })}

                            {filteredConversations.length === 0 && (
                                <p className="p-5 text-center text-sm text-slate-500">
                                    No conversation matches that search.
                                </p>
                            )}
                        </div>
                    </aside>

                    {selectedConversation ? (
                        <section className="flex min-h-[440px] min-w-0 flex-col">
                            <header className="flex items-start justify-between gap-3 border-b border-slate-200 p-4">
                                <div className="flex min-w-0 items-start gap-3">
                                    <span className="rounded-sm border border-slate-300 bg-slate-50 p-2 text-slate-600">
                                        {selectedConversation.phase === 'pickup' ? (
                                            <Store className="h-4 w-4" />
                                        ) : (
                                            <UserRound className="h-4 w-4" />
                                        )}
                                    </span>
                                    <div className="min-w-0">
                                        <h2 className="truncate text-sm font-bold text-slate-950">
                                            {selectedConversation.participant.shop_name ??
                                                selectedConversation.participant.name}
                                        </h2>
                                        <p className="mt-1 text-xs text-slate-500">
                                            {selectedConversation.tracking_number} · Order{' '}
                                            {selectedConversation.order_number}
                                        </p>
                                    </div>
                                </div>
                                <span className="flex shrink-0 items-center gap-1.5 rounded-sm border border-slate-300 bg-slate-50 px-2 py-1 text-[11px] font-bold text-slate-700">
                                    {selectedConversation.phase === 'pickup' ? (
                                        <Store className="h-3.5 w-3.5" />
                                    ) : (
                                        <Truck className="h-3.5 w-3.5" />
                                    )}
                                    {selectedConversation.phase === 'pickup' ? 'PICKUP' : 'FINAL MILE'}
                                </span>
                            </header>

                            <div className="flex-1 space-y-3 overflow-y-auto bg-slate-50 p-4 sm:p-5">
                                {selectedConversation.messages.length === 0 ? (
                                    <div className="flex h-full min-h-56 items-center justify-center text-center">
                                        <div>
                                            <MessageSquare className="mx-auto h-9 w-9 text-slate-300" />
                                            <p className="mt-3 text-sm font-semibold text-slate-800">
                                                No messages yet
                                            </p>
                                            <p className="mt-1 text-sm text-slate-500">
                                                Use this conversation only for the linked delivery.
                                            </p>
                                        </div>
                                    </div>
                                ) : (
                                    selectedConversation.messages.map((item) => {
                                        const isMine = item.sender_id === currentUserId;
                                        return (
                                            <div
                                                key={item.id}
                                                className={`flex ${isMine ? 'justify-end' : 'justify-start'}`}
                                            >
                                                <div
                                                    className={`max-w-[85%] rounded-md border px-3 py-2.5 text-sm leading-6 sm:max-w-[70%] ${
                                                        isMine
                                                            ? 'border-slate-900 bg-slate-900 text-white'
                                                            : 'border-slate-300 bg-white text-slate-800'
                                                    }`}
                                                >
                                                    <p className="whitespace-pre-wrap break-words">
                                                        {item.message}
                                                    </p>
                                                    <p
                                                        className={`mt-1 text-right text-[10px] ${
                                                            isMine ? 'text-slate-400' : 'text-slate-500'
                                                        }`}
                                                    >
                                                        {formatTime(item.created_at)}
                                                    </p>
                                                </div>
                                            </div>
                                        );
                                    })
                                )}
                            </div>

                            {selectedConversation.can_send ? (
                                <form
                                    onSubmit={submitMessage}
                                    className="flex items-end gap-2 border-t border-slate-300 bg-white p-3"
                                >
                                    <label className="min-w-0 flex-1">
                                        <span className="sr-only">Message</span>
                                        <textarea
                                            rows={2}
                                            maxLength={1000}
                                            value={message}
                                            onChange={(event) => setMessage(event.target.value)}
                                            placeholder="Write a delivery coordination message"
                                            className="w-full resize-none rounded-sm border border-slate-300 px-3 py-2 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]"
                                        />
                                    </label>
                                    <button
                                        type="submit"
                                        disabled={sending || !message.trim()}
                                        className="flex h-10 items-center gap-2 rounded-sm bg-[#E00D42] px-4 text-sm font-semibold text-white hover:bg-[#C50B39] disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <Send className="h-4 w-4" />
                                        <span className="hidden sm:inline">
                                            {sending ? 'Sending...' : 'Send'}
                                        </span>
                                    </button>
                                </form>
                            ) : (
                                <div className="border-t border-slate-300 bg-slate-50 p-3 text-center text-sm text-slate-600">
                                    This assignment is no longer active. The conversation is read-only.
                                </div>
                            )}
                        </section>
                    ) : (
                        <div className="flex min-h-96 items-center justify-center text-sm text-slate-500">
                            Select a conversation.
                        </div>
                    )}
                </div>
            )}
        </CourierLayout>
    );
}
