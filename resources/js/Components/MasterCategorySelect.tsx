import InputError from '@/Components/InputError';

export interface MasterCategoryChoice {
    id: number;
    name: string;
}

interface Props {
    choices: MasterCategoryChoice[];
    value: string;
    onChange: (value: string) => void;
    error?: string;
    currentName?: string | null;
    id?: string;
}

export default function MasterCategorySelect({ choices, value, onChange, error, currentName, id = 'root-category' }: Props) {
    const unavailable = value !== '' && !choices.some(category => String(category.id) === value);

    return (
        <div className="space-y-1 font-sans">
            <label htmlFor={id} className="block text-xs font-semibold text-slate-800">What does your shop sell? *</label>
            <select
                id={id}
                name="root_category_id"
                value={value}
                onChange={event => onChange(event.target.value)}
                required
                className="w-full rounded-[12px] border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42]"
            >
                <option value="">Choose your shop's main category</option>
                {unavailable && <option value={value} disabled>{currentName || 'Previous category'} — choose an available category</option>}
                {choices.map(category => <option key={category.id} value={String(category.id)}>{category.name}</option>)}
            </select>
            <p className="text-xs text-slate-600">One seller account has one shop. Choose the master category that covers its products; an admin reviews it with your application.</p>
            {choices.length === 0 && <p className="text-xs text-amber-800">Categories are unavailable. Please contact support before submitting.</p>}
            <InputError message={error} />
        </div>
    );
}
