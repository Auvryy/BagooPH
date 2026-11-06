import { useEffect } from 'react';

let activeLocks = 0;
let previousBodyOverflow = '';
let previousRootOverflow = '';

export default function useBodyScrollLock(active: boolean): void {
    useEffect(() => {
        if (!active || typeof document === 'undefined') return;

        if (activeLocks === 0) {
            previousBodyOverflow = document.body.style.overflow;
            previousRootOverflow = document.documentElement.style.overflow;
        }
        activeLocks += 1;
        document.body.style.overflow = 'hidden';
        document.documentElement.style.overflow = 'hidden';

        return () => {
            activeLocks = Math.max(0, activeLocks - 1);
            if (activeLocks === 0) {
                document.body.style.overflow = previousBodyOverflow;
                document.documentElement.style.overflow = previousRootOverflow;
            }
        };
    }, [active]);
}
