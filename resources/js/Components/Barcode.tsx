import React, { useEffect, useRef } from 'react';
import JsBarcode from 'jsbarcode';

interface BarcodeProps {
    value: string;
    format?: string;
    width?: number;
    height?: number;
    displayValue?: boolean;
    fontSize?: number;
    margin?: number;
    background?: string;
    lineColor?: string;
    className?: string;
}

export default function Barcode({
    value,
    format = 'CODE128',
    width = 1.6,
    height = 36,
    displayValue = false,
    fontSize = 12,
    margin = 0,
    background = 'transparent',
    lineColor = '#000000',
    className = '',
}: BarcodeProps) {
    const svgRef = useRef<SVGSVGElement | null>(null);

    useEffect(() => {
        if (svgRef.current && value) {
            try {
                JsBarcode(svgRef.current, String(value).trim(), {
                    format,
                    width,
                    height,
                    displayValue,
                    fontSize,
                    margin,
                    background,
                    lineColor,
                });
            } catch (err) {
                console.error('Failed to render barcode:', err);
            }
        }
    }, [value, format, width, height, displayValue, fontSize, margin, background, lineColor]);

    if (!value) return null;

    return <svg ref={svgRef} className={className} />;
}
