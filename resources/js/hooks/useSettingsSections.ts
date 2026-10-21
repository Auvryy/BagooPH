import { useEffect, useRef, useState, type RefObject } from 'react';
import { usePage } from '@inertiajs/react';
import { scrollSettingsSection, settingsSectionFromUrl, visibleSettingsSection } from '@/utils/accountSections';

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

    useEffect(() => {
        if (!active) return;
        const root = container?.current ?? null;
        let frame: number | null = null;
        const update = () => {
            frame = null;
            const current = visibleSettingsSection(sectionIds.current, root);
            if (current) setSelected(previous => previous === current ? previous : current);
        };
        const schedule = () => { if (frame === null) frame = window.requestAnimationFrame(update); };
        root?.addEventListener('scroll', schedule, { passive: true });
        window.addEventListener('scroll', schedule, { passive: true });
        window.addEventListener('resize', schedule);
        const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(schedule);
        if (root) observer?.observe(root);
        for (const id of sectionIds.current) {
            const section = document.getElementById(id);
            if (section) observer?.observe(section);
        }
        schedule();
        return () => {
            if (frame !== null) window.cancelAnimationFrame(frame);
            root?.removeEventListener('scroll', schedule);
            window.removeEventListener('scroll', schedule);
            window.removeEventListener('resize', schedule);
            observer?.disconnect();
        };
    }, [active, container, url]);

    return { selected, select: (id: string) => {
        if (!sectionIds.current.includes(id)) return;
        setSelected(id); setPending(id);
    } };
}
