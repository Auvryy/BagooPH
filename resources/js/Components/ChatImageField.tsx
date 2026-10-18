import { ImagePlus, X } from 'lucide-react';

export default function ChatImageField({ image, onChange, disabled = false }: { image: File | null; onChange: (file: File | null) => void; disabled?: boolean }) {
    return <div className="flex flex-wrap items-center gap-2 px-3 pt-2 text-xs text-slate-500">
        <label className={`inline-flex min-h-9 cursor-pointer items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-1 font-semibold text-slate-700 focus-within:ring-2 focus-within:ring-[#E00D42] ${disabled ? 'opacity-50' : 'hover:bg-slate-50'}`}>
            <ImagePlus className="h-4 w-4" aria-hidden="true" />Photo<input type="file" aria-label="Attach a photo" accept="image/jpeg,image/png,image/webp" disabled={disabled} className="sr-only" onChange={event => { onChange(event.target.files?.[0] ?? null); event.target.value = ''; }} />
        </label>
        {image ? <span className="inline-flex max-w-full items-center gap-2"><span className="max-w-48 truncate">{image.name}</span><button type="button" disabled={disabled} onClick={() => onChange(null)} aria-label="Remove attached photo" className="rounded-lg p-2 text-slate-600 hover:bg-slate-100"><X className="h-4 w-4" /></button></span> : <span>JPG, PNG or WEBP · Max 5 MB</span>}
    </div>;
}
