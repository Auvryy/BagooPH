export function setupBuyerProfileWorkspace(workspace: HTMLElement) {
    let frame: number | undefined;
    const viewport = window.visualViewport;
    const desktop = window.matchMedia('(min-width: 1024px)');
    const measure = () => {
        const top = workspace.getBoundingClientRect().top + window.scrollY;
        const height = Math.max(320, (viewport?.height ?? window.innerHeight) - top - 24);
        workspace.style.setProperty('--buyer-profile-height', `${height}px`);
    };
    const scheduleMeasure = () => {
        if (frame !== undefined) window.cancelAnimationFrame(frame);
        frame = window.requestAnimationFrame(() => {
            frame = undefined;
            measure();
        });
    };
    const containWheel = (event: WheelEvent) => {
        if (!desktop.matches || event.ctrlKey || event.defaultPrevented) return;
        const target = event.target instanceof Element ? event.target : null;
        const region = target?.closest<HTMLElement>('[data-buyer-profile-scroll]');
        if (!region || region.scrollHeight <= region.clientHeight) event.preventDefault();
    };

    measure();
    const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(scheduleMeasure);
    const main = workspace.closest('main');
    if (main) observer?.observe(main);
    observer?.observe(workspace);
    window.addEventListener('resize', scheduleMeasure);
    viewport?.addEventListener('resize', scheduleMeasure);
    workspace.addEventListener('wheel', containWheel, { passive: false });

    return () => {
        observer?.disconnect();
        window.removeEventListener('resize', scheduleMeasure);
        viewport?.removeEventListener('resize', scheduleMeasure);
        workspace.removeEventListener('wheel', containWheel);
        if (frame !== undefined) window.cancelAnimationFrame(frame);
    };
}
