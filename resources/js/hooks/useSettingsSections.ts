import { useEffect, useRef, useState, type RefObject } from 'react';
import { usePage } from '@inertiajs/react';
import { scrollSettingsSection, settingsSectionFromUrl } from '@/utils/accountSections';

export default function useSettingsSections(active: boolean, sections: readonly string[], container?: RefObject<HTMLElement>) {
    const { url } = usePage();
    const [selected, setSelected] = useState(sections[0]);
    const [pending, setPending] = useState<string | null>(null);
    const sectionIds = useRef(sections);
    sectionIds.current = sections;

    useEffect(() => {
        const readHash = () => {
            const id = settingsSectionFromUrl(window.location.href, sectionIds.current);
            if (id) { setSelected(id); setPending(id); }
        };
        if (active) readHash();
        window.addEventListener('hashchange', readHash);
        return () => window.removeEventListener('hashchange', readHash);
    }, [url, active]);

    useEffect(() => {
        if (!active || !pending) return;
        const frame = window.requestAnimationFrame(() => {
            scrollSettingsSection(pending, container?.current ?? null);
            setPending(null);
        });
        return () => window.cancelAnimationFrame(frame);
    }, [active, pending, container]);

    return { selected, select: (id: string) => {
        if (!sectionIds.current.includes(id)) return;
        setSelected(id); setPending(id);
    } };
}
