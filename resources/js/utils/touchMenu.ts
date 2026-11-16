import type { MouseEvent, PointerEvent } from 'react';

/**
 * Account menus open on hover with a mouse. Touch screens have no hover, so a tap on the account
 * button opens the menu first instead of leaving the page; a second tap (or a menu item) navigates.
 */
export function isMousePointer(event: PointerEvent | MouseEvent): boolean {
    const type = (event.nativeEvent as globalThis.PointerEvent).pointerType;
    if (type) return type === 'mouse';
    return typeof window === 'undefined' || !window.matchMedia?.('(hover: none)').matches;
}

/** Use as the account link's onClick: on touch, the first tap opens the menu instead of navigating. */
export function openMenuOnFirstTap(event: MouseEvent, open: boolean, setOpen: (open: boolean) => void): void {
    if (!open && !isMousePointer(event)) {
        event.preventDefault();
        setOpen(true);
    }
}
