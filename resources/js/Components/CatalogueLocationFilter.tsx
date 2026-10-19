interface Props {
    id: string;
    locations: { value: string; label: string }[];
    value?: string;
    onChange: (value: string | undefined) => void;
}

export default function CatalogueLocationFilter({ id, locations, value, onChange }: Props) {
    return <div className="space-y-2.5 border-t border-slate-200 pt-4">
        <label htmlFor={id} className="block text-[11px] font-bold uppercase tracking-wider text-slate-900">Ships from</label>
        <select id={id} value={value || 'all'} onChange={event => onChange(event.target.value === 'all' ? undefined : event.target.value)} className="w-full rounded-lg border-slate-300 bg-white py-2 pl-3 pr-8 text-xs focus:border-[#E00D42] focus:ring-[#E00D42]">
            <option value="all">All provinces and areas</option>
            {locations.map(location => <option key={location.value} value={location.value}>{location.label}</option>)}
        </select>
        <p className="text-[11px] leading-relaxed text-slate-500">Based on the shop’s pickup city. Shops without a confirmed area appear under All provinces and areas.</p>
    </div>;
}
