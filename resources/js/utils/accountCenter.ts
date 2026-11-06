export function openAccountCenter(isMobile: boolean, openDesktopPanel: () => void, navigateToStatus: () => void): void {
    if (isMobile) {
        navigateToStatus();
        return;
    }

    openDesktopPanel();
}
