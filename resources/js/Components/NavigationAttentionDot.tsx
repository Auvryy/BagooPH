export default function NavigationAttentionDot({ label = 'Needs attention' }: { label?: string }) {
    return <span title={label} className="inline-flex shrink-0 items-center">
        <span aria-hidden="true" className="h-2 w-2 rounded-full bg-[#E00D42] ring-2 ring-white" />
        <span className="sr-only">{label}</span>
    </span>;
}
