import { ChevronDown, Fingerprint, KeyRound, Mail, MonitorSmartphone, UserRound } from 'lucide-react';
import { buyerAccountSections, type BuyerAccountSection } from '@/utils/accountSections';

const icons = { 'account-personal': UserRound, 'account-emails': Mail, 'account-password': KeyRound, 'account-identity': Fingerprint, 'login-activity': MonitorSmartphone };

export default function BuyerAccountNavigation({ active, selected, disabled, onSelect }: {
    active: boolean; selected: string; disabled: boolean; onSelect: (section: BuyerAccountSection) => void;
}) {
    return <div className="space-y-1.5">
        <button type="button" disabled={disabled} aria-expanded={active} aria-controls="buyer-account-submenu" onClick={() => onSelect('account-personal')}
            className={`flex w-full items-center justify-between gap-3 rounded-2xl px-4 py-3 text-left font-bold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42] disabled:opacity-50 ${active ? 'bg-[#E00D42] text-white shadow-xs' : 'text-slate-700 hover:bg-slate-50 hover:text-slate-900'}`}>
            <span className="flex items-center gap-3"><UserRound className={`h-4 w-4 shrink-0 ${active ? 'text-white' : 'text-slate-400'}`} aria-hidden="true" /><span>My Account &amp; Security</span></span>
            <ChevronDown aria-hidden="true" className={`h-3.5 w-3.5 shrink-0 opacity-70 transition-transform motion-reduce:transition-none ${active ? 'rotate-180' : ''}`} />
        </button>
        <div id="buyer-account-submenu" hidden={!active} className="ml-6 space-y-1 border-l border-slate-300 pl-2">
            {buyerAccountSections.map(section => {
                const Icon = icons[section.id];
                return <a key={section.id} href={`${route('buyer.profile', { tab: 'account' })}#${section.id}`} aria-current={active && selected === section.id ? 'location' : undefined}
                    aria-disabled={disabled || undefined} tabIndex={disabled ? -1 : undefined} onClick={event => { if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return; event.preventDefault(); if (!disabled) onSelect(section.id); }}
                    className={`flex min-h-10 items-center gap-2.5 rounded-xl px-3 py-2.5 text-left font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42] ${disabled ? 'pointer-events-none opacity-50' : ''} ${active && selected === section.id ? 'bg-rose-50 text-[#C20836]' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'}`}>
                    <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />{section.label}
                </a>;
            })}
        </div>
    </div>;
}
