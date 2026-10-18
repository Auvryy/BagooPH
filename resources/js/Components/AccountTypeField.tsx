const labels: Record<string, string> = {
    buyer: 'Buyer', seller: 'Seller', courier: 'Rider', logistics: 'Logistics', admin: 'Admin',
};

export default function AccountTypeField({ value, onChange, roles = Object.keys(labels), disabled = false }: {
    value: string;
    onChange: (role: string) => void;
    roles?: string[];
    disabled?: boolean;
}) {
    return <div>
        <label htmlFor="account-type" className="mb-1.5 block text-xs font-semibold text-slate-800">Account type</label>
        <select id="account-type" name="role" value={value} onChange={event => onChange(event.target.value)} disabled={disabled}
            className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-[#E00D42] focus:ring-[#E00D42]">
            {roles.map(role => <option key={role} value={role}>{labels[role] ?? role}</option>)}
        </select>
        <p className="mt-1 text-xs leading-relaxed text-slate-600">Choose the account you want to use. Each account type has its own password and approval.</p>
    </div>;
}
