import { useState } from 'react';
import { Dialog, DialogBackdrop, DialogPanel, DialogTitle, Field, Label } from '@headlessui/react';
import { MapPinned, Search, X } from 'lucide-react';
import HubMap from './HubMap';
import { type HubPlace } from './Workspace';

export default function HubPicker({
    hubs,
    value,
    onChange,
    label,
    disabled = false,
    error,
    placeholder = 'Select facility…',
    map = true,
}: {
    hubs: (HubPlace & { id: number })[];
    value: string;
    onChange: (value: string) => void;
    label: string;
    disabled?: boolean;
    error?: string;
    placeholder?: string;
    map?: boolean;
}) {
    const [query, setQuery] = useState('');
    const [showMap, setShowMap] = useState(false);
    const selected = hubs.find(hub => String(hub.id) === value) ?? null;
    const filtered = hubs.filter(hub =>
        [hub.name, hub.code, hub.city_municipality, hub.province]
            .filter(Boolean)
            .join(' ')
            .toLowerCase()
            .includes(query.trim().toLowerCase())
    );

    const choose = (hub: HubPlace | null) => {
        if (hub?.id != null) onChange(String(hub.id));
        setQuery('');
    };

    return (
        <Field className="min-w-0 space-y-2">
            <div className="flex items-center justify-between gap-3">
                <Label className="text-xs font-semibold text-zinc-700">{label}</Label>
                {map && hubs.length > 0 && (
                    <button
                        type="button"
                        disabled={disabled}
                        onClick={() => setShowMap(true)}
                        className="inline-flex items-center gap-1 text-[11px] font-semibold text-[#C20836] hover:underline disabled:opacity-50 cursor-pointer"
                    >
                        <MapPinned className="h-3.5 w-3.5" />
                        Choose on map
                    </button>
                )}
            </div>

            <div className="relative">
                <select
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    disabled={disabled}
                    aria-invalid={Boolean(error)}
                    className="w-full truncate rounded-xl border border-zinc-300 bg-white pl-3.5 pr-9 py-2.5 text-sm font-medium text-[#121316] shadow-xs focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15 transition disabled:bg-zinc-100 disabled:text-zinc-500 cursor-pointer"
                >
                    <option value="">{placeholder || 'Select facility…'}</option>
                    {hubs.map((hub) => (
                        <option key={hub.id} value={String(hub.id)}>
                            {hub.name} ({hub.code}) · {hub.tier === 'regional_mother_hub' ? 'Mother Hub' : 'Bayan Hub'}
                        </option>
                    ))}
                </select>
            </div>

            {selected && (
                <p className="text-[11px] font-medium text-zinc-500">
                    {selected.tier === 'regional_mother_hub' ? 'Mother Hub' : 'Bayan Hub'} · <span className="font-mono text-zinc-700">{selected.code}</span>
                    {selected.city_municipality ? ` · ${selected.city_municipality}` : ''}
                </p>
            )}

            {error && <p role="alert" className="text-xs text-rose-700">{error}</p>}

            <Dialog open={showMap} onClose={() => setShowMap(false)} className="relative z-[90]">
                <DialogBackdrop className="fixed inset-0 bg-zinc-950/50" />
                <div className="fixed inset-0 flex items-center justify-center p-3 sm:p-6">
                    <DialogPanel className="flex max-h-[90dvh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-2xl">
                        <header className="flex items-center justify-between border-b border-zinc-200 px-6 py-4">
                            <div>
                                <DialogTitle className="text-base font-semibold text-zinc-950">{label}</DialogTitle>
                                <p className="mt-1 text-xs text-zinc-500">Select a pin or a hub card. Only available choices are shown.</p>
                            </div>
                            <button
                                type="button"
                                aria-label="Close hub map"
                                onClick={() => setShowMap(false)}
                                className="rounded-xl p-2 text-zinc-500 hover:bg-zinc-100 cursor-pointer"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </header>
                        <div className="grid min-h-0 gap-4 overflow-y-auto p-5 md:grid-cols-[1fr_280px]">
                            <HubMap
                                hubs={hubs}
                                selectedId={selected?.id}
                                onSelect={(hub) => {
                                    choose(hub);
                                    setShowMap(false);
                                }}
                                className="min-h-72 md:min-h-96 rounded-xl overflow-hidden border border-zinc-200"
                            />
                            <div className="space-y-3">
                                <div className="relative">
                                    <Search className="absolute left-3 top-3 h-4 w-4 text-zinc-400" />
                                    <input
                                        aria-label="Search map hubs"
                                        value={query}
                                        onChange={(event) => setQuery(event.target.value)}
                                        className="w-full rounded-xl border border-zinc-300 bg-white pl-9 pr-3 py-2 text-xs text-zinc-900 shadow-xs focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15"
                                        placeholder="Search town or hub"
                                    />
                                </div>
                                <div className="max-h-80 space-y-2 overflow-y-auto">
                                    {filtered.map((hub) => (
                                        <button
                                            key={hub.id}
                                            type="button"
                                            aria-pressed={selected?.id === hub.id}
                                            onClick={() => {
                                                choose(hub);
                                                setShowMap(false);
                                            }}
                                            className={`w-full rounded-xl border p-3.5 text-left transition cursor-pointer ${
                                                selected?.id === hub.id
                                                    ? 'border-rose-300 bg-rose-50/80'
                                                    : 'border-zinc-200 bg-white hover:bg-zinc-50'
                                            }`}
                                        >
                                            <span className="block text-sm font-semibold text-zinc-900">{hub.name}</span>
                                            <span className="mt-1 block text-xs text-zinc-500">
                                                {hub.code} · {hub.city_municipality ?? (hub.tier === 'regional_mother_hub' ? 'Mother Hub' : 'Bayan Hub')}
                                            </span>
                                            {hub.address && (
                                                <span className="mt-1.5 block text-xs leading-5 text-zinc-500">{hub.address}</span>
                                            )}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>
                        <footer className="flex justify-end border-t border-zinc-200 px-6 py-3.5">
                            <button
                                type="button"
                                className="inline-flex items-center justify-center rounded-xl border border-zinc-300 bg-white px-4 py-2 text-xs font-semibold text-zinc-800 hover:bg-zinc-50 shadow-xs transition cursor-pointer"
                                onClick={() => setShowMap(false)}
                            >
                                Back to transfer
                            </button>
                        </footer>
                    </DialogPanel>
                </div>
            </Dialog>
        </Field>
    );
}
