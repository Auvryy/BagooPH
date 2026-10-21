export const buyerAccountSections = [
    { id: 'account-personal', label: 'Personal info' },
    { id: 'account-emails', label: 'Email addresses' },
    { id: 'account-password', label: 'Password' },
    { id: 'account-identity', label: 'Identity correction' },
    { id: 'login-activity', label: 'Login activity' },
] as const;

export type BuyerAccountSection = typeof buyerAccountSections[number]['id'];

export function settingsSectionFromUrl(url: string, sections: readonly string[]): string | null {
    try {
        let id = decodeURIComponent(new URL(url, 'https://account.invalid').hash.slice(1));
        if (id === 'account-verification' && sections.includes('account-identity')) id = 'account-identity';
        return sections.includes(id) ? id : null;
    } catch { return null; }
}

function scrollsInternally(container: HTMLElement | null): container is HTMLElement {
    return Boolean(container && container.scrollHeight > container.clientHeight && ['auto', 'scroll'].includes(window.getComputedStyle(container).overflowY));
}

/** Read the section at the viewing edge without moving focus, scrolling or history. */
export function visibleSettingsSection(sections: readonly string[], container: HTMLElement | null = null): string | null {
    const targets = sections.map(id => document.getElementById(id)).filter((target): target is HTMLElement => Boolean(target && (!container || container.contains(target)) && target.getClientRects().length));
    if (!targets.length) return null;
    const internal = scrollsInternally(container);
    const margin = Number.parseFloat(window.getComputedStyle(targets[0]).scrollMarginTop) || 0;
    const edge = internal ? container.getBoundingClientRect().top + 24 : margin + 24;
    const bottom = internal ? container.getBoundingClientRect().top + container.clientHeight : window.innerHeight;
    const page = document.scrollingElement;
    const atEnd = internal ? container.scrollTop + container.clientHeight >= container.scrollHeight - 2
        : Boolean(page && page.scrollHeight > window.innerHeight && page.scrollTop + window.innerHeight >= page.scrollHeight - 2);
    if (atEnd && targets.at(-1)!.getBoundingClientRect().top < bottom) return targets.at(-1)!.id;
    let selected = targets[0].id;
    for (const target of targets) {
        if (target.getBoundingClientRect().top > edge) break;
        selected = target.id;
    }
    return selected;
}

/** Desktop settings have their own scroll area; phones use the document. */
export function scrollSettingsSection(id: string, container: HTMLElement | null = null): boolean {
    const target = document.getElementById(id);
    if (!target || (container && !container.contains(target))) return false;
    const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
    if (scrollsInternally(container)) {
        container.scrollTo({ top: Math.max(0, container.scrollTop + target.getBoundingClientRect().top - container.getBoundingClientRect().top - 12), behavior });
    } else target.scrollIntoView({ block: 'start', behavior });
    target.focus({ preventScroll: true });
    return true;
}
