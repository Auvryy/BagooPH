import { useState, useEffect } from 'react';

interface AmbientColorResult {
    rgb: string;
    ambientGlow: string;
    subtleBackground: string;
    accentColor: string;
}

export function useAmbientColor(
    imageUrl?: string | null,
    fallbackHex?: string | null,
    intensity: number = 0.12
): AmbientColorResult {
    const [color, setColor] = useState<{ r: number; g: number; b: number }>({ r: 224, g: 13, b: 66 }); // Bagoo red default

    useEffect(() => {
        // If a direct fallback hex is provided (e.g. from a selected variation color), prioritize its converted RGB
        if (fallbackHex && fallbackHex.startsWith('#') && (fallbackHex.length === 7 || fallbackHex.length === 4)) {
            const hex = fallbackHex.replace('#', '');
            const fullHex = hex.length === 3 ? hex.split('').map(c => c + c).join('') : hex;
            const num = parseInt(fullHex, 16);
            if (!isNaN(num)) {
                const r = (num >> 16) & 255;
                const g = (num >> 8) & 255;
                const b = num & 255;
                setColor({ r, g, b });
                return;
            }
        }

        if (!imageUrl) return;

        const img = new Image();
        img.crossOrigin = 'Anonymous';
        img.src = imageUrl;

        img.onload = () => {
            try {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d', { willReadFrequently: true });
                if (!ctx) return;

                // Sample image at small scale for high performance
                const sampleSize = 40;
                canvas.width = sampleSize;
                canvas.height = sampleSize;
                ctx.drawImage(img, 0, 0, sampleSize, sampleSize);

                const imgData = ctx.getImageData(0, 0, sampleSize, sampleSize).data;
                let rSum = 0, gSum = 0, bSum = 0, count = 0;

                for (let i = 0; i < imgData.length; i += 16) {
                    const r = imgData[i];
                    const g = imgData[i + 1];
                    const b = imgData[i + 2];
                    const a = imgData[i + 3];

                    // Skip near-white or near-black pixels to get vibrant midtones
                    const brightness = (r * 299 + g * 587 + b * 114) / 1000;
                    if (a > 200 && brightness > 25 && brightness < 240) {
                        rSum += r;
                        gSum += g;
                        bSum += b;
                        count++;
                    }
                }

                if (count > 0) {
                    setColor({
                        r: Math.round(rSum / count),
                        g: Math.round(gSum / count),
                        b: Math.round(bSum / count),
                    });
                }
            } catch (e) {
                // Ignore cross-origin canvas errors and keep fallback
            }
        };
    }, [imageUrl, fallbackHex]);

    const { r, g, b } = color;

    return {
        rgb: `${r}, ${g}, ${b}`,
        ambientGlow: `radial-gradient(ellipse at 50% 25%, rgba(${r}, ${g}, ${b}, ${intensity}) 0%, rgba(${r}, ${g}, ${b}, 0.03) 60%, transparent 85%)`,
        subtleBackground: `rgba(${r}, ${g}, ${b}, 0.06)`,
        accentColor: `rgb(${r}, ${g}, ${b})`,
    };
}
