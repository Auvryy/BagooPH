export default function ChatAttachment({ url }: { url?: string | null }) {
    return url ? <a href={url} target="_blank" rel="noreferrer" className="mb-2 block rounded-lg border border-slate-300 bg-white p-1 text-slate-700">
        <img src={url} alt="Photo attached to this message" loading="lazy" className="max-h-56 max-w-full rounded-lg object-contain" />
        <span className="block px-1 py-1 text-[10px]">Open photo</span>
    </a> : null;
}
