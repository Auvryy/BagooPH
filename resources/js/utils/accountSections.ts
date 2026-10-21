export const buyerAccountSections = [
    { id: 'account-personal', label: 'Personal info' },
    { id: 'account-emails', label: 'Email addresses' },
    { id: 'account-identity', label: 'Identity details' },
    { id: 'account-verification', label: 'Verification' },
    { id: 'account-password', label: 'Password' },
    { id: 'login-activity', label: 'Login activity' },
] as const;

export type BuyerAccountSection = typeof buyerAccountSections[number]['id'];

export function settingsSectionFromUrl(url: string, sections: readonly string[]): string | null {
    try {
        const id = decodeURIComponent(new URL(url, 'https://account.invalid').hash.slice(1));
        return sections.includes(id) ? id : null;
    } catch { return null; }
}

/** Desktop settings have their own scroll area; phones use the document. */
export function scrollSettingsSection(id: string, container: HTMLElement | null = null): boolean {
    const target = document.getElementById(id);
    if (!target || (container && !container.contains(target))) return false;
    const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
    if (container && container.scrollHeight > container.clientHeight && ['auto', 'scroll'].includes(window.getComputedStyle(container).overflowY)) {
        container.scrollTo({ top: Math.max(0, container.scrollTop + target.getBoundingClientRect().top - container.getBoundingClientRect().top - 12), behavior });
    } else target.scrollIntoView({ block: 'start', behavior });
    target.focus({ preventScroll: true });
    return true;
}
