/**
 * Zero-latency Synthesized Web Audio API for Industrial Warehouse Scanning
 * Produces crisp, immediate audio tones without external audio file requests.
 */

class ScannerAudioService {
    private ctx: AudioContext | null = null;
    private enabled: boolean = true;

    private getContext(): AudioContext | null {
        if (!this.enabled) return null;
        if (!this.ctx && typeof window !== 'undefined') {
            const AudioContextClass = window.AudioContext || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext;
            if (AudioContextClass) {
                this.ctx = new AudioContextClass();
            }
        }
        if (this.ctx && this.ctx.state === 'suspended') {
            this.ctx.resume().catch(() => {});
        }
        return this.ctx;
    }

    public setEnabled(enabled: boolean): void {
        this.enabled = enabled;
    }

    public isEnabled(): boolean {
        return this.enabled;
    }

    /**
     * Crisp double-tone chime (880Hz -> 1320Hz) for verified parcel/bag scans
     */
    public playSuccess(): void {
        const ctx = this.getContext();
        if (!ctx) return;

        const now = ctx.currentTime;
        const osc1 = ctx.createOscillator();
        const osc2 = ctx.createOscillator();
        const gain = ctx.createGain();

        gain.gain.setValueAtTime(0.15, now);
        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.18);
        gain.connect(ctx.destination);

        // Note 1: A5 (880Hz)
        osc1.type = 'sine';
        osc1.frequency.setValueAtTime(880, now);
        osc1.connect(gain);
        osc1.start(now);
        osc1.stop(now + 0.08);

        // Note 2: E6 (1320Hz)
        osc2.type = 'sine';
        osc2.frequency.setValueAtTime(1320, now + 0.08);
        osc2.connect(gain);
        osc2.start(now + 0.08);
        osc2.stop(now + 0.18);
    }

    /**
     * Standard optical scan beep (single crisp 880Hz tone)
     */
    public playScanBeep(): void {
        const ctx = this.getContext();
        if (!ctx) return;

        const now = ctx.currentTime;
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();

        gain.gain.setValueAtTime(0.12, now);
        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.12);
        gain.connect(ctx.destination);

        osc.type = 'sine';
        osc.frequency.setValueAtTime(880, now);
        osc.connect(gain);
        osc.start(now);
        osc.stop(now + 0.12);
    }

    /**
     * Short blip (1046Hz) for hardware command barcodes (CMD-CONFIRM, CMD-DISPATCH, CMD-CLEAR)
     */
    public playCommand(): void {
        const ctx = this.getContext();
        if (!ctx) return;

        const now = ctx.currentTime;
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();

        gain.gain.setValueAtTime(0.15, now);
        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.09);
        gain.connect(ctx.destination);

        osc.type = 'sine';
        osc.frequency.setValueAtTime(1046.5, now);
        osc.connect(gain);
        osc.start(now);
        osc.stop(now + 0.09);
    }

    /**
     * Low-frequency harsh buzzer (140Hz) for misroute, error, or unpermitted scan
     */
    public playError(): void {
        const ctx = this.getContext();
        if (!ctx) return;

        const now = ctx.currentTime;
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();

        osc.type = 'sawtooth';
        osc.frequency.setValueAtTime(140, now);

        gain.gain.setValueAtTime(0.22, now);
        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.32);

        osc.connect(gain);
        gain.connect(ctx.destination);

        osc.start(now);
        osc.stop(now + 0.32);

        if (typeof navigator !== 'undefined' && 'vibrate' in navigator) {
            navigator.vibrate([180, 80, 180]);
        }
    }

    /**
     * Mechanical latching tone for Mother Bag tamper seal lock
     */
    public playSealLock(): void {
        const ctx = this.getContext();
        if (!ctx) return;

        const now = ctx.currentTime;
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();

        osc.type = 'triangle';
        osc.frequency.setValueAtTime(600, now);
        osc.frequency.exponentialRampToValueAtTime(280, now + 0.15);

        gain.gain.setValueAtTime(0.2, now);
        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.15);

        osc.connect(gain);
        gain.connect(ctx.destination);

        osc.start(now);
        osc.stop(now + 0.15);
    }

    /**
     * Ascending 3-chord fanfare for vehicle manifest departure & batch transfer
     */
    public playDispatch(): void {
        const ctx = this.getContext();
        if (!ctx) return;

        const now = ctx.currentTime;
        const notes = [523.25, 659.25, 783.99]; // C5, E5, G5
        notes.forEach((freq, i) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            const start = now + (i * 0.08);
            const stop = start + 0.12;

            osc.type = 'sine';
            osc.frequency.setValueAtTime(freq, start);

            gain.gain.setValueAtTime(0.18, start);
            gain.gain.exponentialRampToValueAtTime(0.001, stop);

            osc.connect(gain);
            gain.connect(ctx.destination);

            osc.start(start);
            osc.stop(stop);
        });
    }
}

export const scannerAudio = new ScannerAudioService();
