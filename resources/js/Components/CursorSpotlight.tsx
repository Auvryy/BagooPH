import React, { useEffect, useState } from 'react';

export default function CursorSpotlight() {
    const [mousePos, setMousePos] = useState({ x: -100, y: -100 });
    const [dotPos, setDotPos] = useState({ x: -100, y: -100 });
    const [isHovered, setIsHovered] = useState(false);
    const [isClicked, setIsClicked] = useState(false);
    const [isVisible, setIsVisible] = useState(false);

    useEffect(() => {
        if (window.matchMedia('(pointer: coarse)').matches) {
            return;
        }

        let animationFrameId: number;
        let targetX = -100;
        let targetY = -100;
        let glowX = -100;
        let glowY = -100;
        let dotX = -100;
        let dotY = -100;

        const handleMouseMove = (e: MouseEvent) => {
            targetX = e.clientX;
            targetY = e.clientY;
            if (!isVisible) setIsVisible(true);

            const target = e.target as HTMLElement | null;
            if (target && target.closest('a, button, input, select, textarea, [role="button"], .interactive-card, .group')) {
                setIsHovered(true);
            } else {
                setIsHovered(false);
            }
        };

        const handleMouseDown = () => setIsClicked(true);
        const handleMouseUp = () => setIsClicked(false);
        const handleMouseLeave = () => setIsVisible(false);

        const animate = () => {
            // Fast lerp for center precision point
            dotX += (targetX - dotX) * 0.45;
            dotY += (targetY - dotY) * 0.45;

            // Fluid momentum lerp for ambient glow spotlight
            glowX += (targetX - glowX) * 0.12;
            glowY += (targetY - glowY) * 0.12;

            setDotPos({ x: dotX, y: dotY });
            setMousePos({ x: glowX, y: glowY });

            animationFrameId = requestAnimationFrame(animate);
        };

        window.addEventListener('mousemove', handleMouseMove);
        window.addEventListener('mousedown', handleMouseDown);
        window.addEventListener('mouseup', handleMouseUp);
        document.body.addEventListener('mouseleave', handleMouseLeave);
        animationFrameId = requestAnimationFrame(animate);

        return () => {
            window.removeEventListener('mousemove', handleMouseMove);
            window.removeEventListener('mousedown', handleMouseDown);
            window.removeEventListener('mouseup', handleMouseUp);
            document.body.removeEventListener('mouseleave', handleMouseLeave);
            cancelAnimationFrame(animationFrameId);
        };
    }, [isVisible]);

    if (!isVisible) return null;

    return (
        <div className="pointer-events-none fixed inset-0 z-50 overflow-hidden">
            {/* 1. Large Ambient Radial Crimson Spotlight */}
            <div
                className="absolute w-[500px] h-[500px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-gradient-to-tr from-[#E00D42]/12 via-[#E00D42]/6 to-transparent blur-3xl transition-opacity duration-300"
                style={{
                    left: `${mousePos.x}px`,
                    top: `${mousePos.y}px`,
                }}
            />

            {/* 2. Precision Magnetic Halo Ring */}
            <div
                className={`absolute -translate-x-1/2 -translate-y-1/2 rounded-full transition-all duration-200 ease-out border ${
                    isClicked
                        ? 'w-6 h-6 border-[#E00D42] bg-[#E00D42]/30 scale-75'
                        : isHovered
                        ? 'w-12 h-12 border-[#E00D42] bg-[#E00D42]/10 scale-110 shadow-sm shadow-[#E00D42]/20'
                        : 'w-7 h-7 border-[#E00D42]/40 bg-transparent'
                }`}
                style={{
                    left: `${dotPos.x}px`,
                    top: `${dotPos.y}px`,
                }}
            />

            {/* 3. Central Dot Needle */}
            <div
                className={`absolute w-1.5 h-1.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-[#E00D42] transition-transform duration-100 ${
                    isHovered ? 'scale-150' : 'scale-100'
                }`}
                style={{
                    left: `${dotPos.x}px`,
                    top: `${dotPos.y}px`,
                }}
            />
        </div>
    );
}
