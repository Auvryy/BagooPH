import { useEffect, useRef } from 'react';

interface BarcodeWedgeOptions {
    onScan: (barcode: string) => void;
    enabled?: boolean;
    maxKeyIntervalMs?: number;
    minBarcodeLength?: number;
}

/**
 * Global Keyboard Wedge Listener for Industrial Handheld Barcode Guns
 * Captures rapid laser scanner bursts anywhere on the page without requiring input focus.
 */
export function useBarcodeWedge({
    onScan,
    enabled = true,
    maxKeyIntervalMs = 50,
    minBarcodeLength = 3,
}: BarcodeWedgeOptions) {
    const bufferRef = useRef<string>('');
    const lastKeyTimeRef = useRef<number>(0);

    useEffect(() => {
        if (!enabled || typeof window === 'undefined') return;

        const handleKeyDown = (event: KeyboardEvent) => {
            const now = Date.now();
            const timeSinceLastKey = now - lastKeyTimeRef.current;
            lastKeyTimeRef.current = now;

            // If user is actively typing in a multiline textarea, don't hijack unless it's a super-fast burst
            const target = event.target as HTMLElement | null;
            const isTextarea = target?.tagName === 'TEXTAREA';

            if (event.key === 'Enter') {
                const buffer = bufferRef.current.trim();
                if (buffer.length >= minBarcodeLength) {
                    // Successfully decoded barcode burst from scanner gun
                    event.preventDefault();
                    event.stopPropagation();
                    onScan(buffer);
                }
                bufferRef.current = '';
                return;
            }

            // Printable single-character keys only
            if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
                if (timeSinceLastKey > maxKeyIntervalMs && bufferRef.current.length > 0) {
                    // Interval too slow for scanner gun; likely human typing, reset buffer
                    bufferRef.current = '';
                }

                if (!isTextarea || timeSinceLastKey < maxKeyIntervalMs) {
                    bufferRef.current += event.key;
                }
            }
        };

        window.addEventListener('keydown', handleKeyDown, true);
        return () => {
            window.removeEventListener('keydown', handleKeyDown, true);
        };
    }, [enabled, maxKeyIntervalMs, minBarcodeLength, onScan]);
}
