import React, { useEffect, useRef } from 'react';
import * as THREE from 'three';

interface Props {
    className?: string;
}

export default function ThreeShoppingBag({ className = '' }: Props) {
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const container = containerRef.current;
        if (!container) return;

        // 1. Scene & Camera Setup
        const scene = new THREE.Scene();
        const width = container.clientWidth || 500;
        const height = container.clientHeight || 500;

        const camera = new THREE.PerspectiveCamera(45, width / height, 0.1, 1000);
        camera.position.set(0, 0.1, 7.2);
        camera.lookAt(0, 0.1, 0);

        const renderer = new THREE.WebGLRenderer({ alpha: true, antialias: true, powerPreference: 'high-performance' });
        renderer.setSize(width, height);
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        renderer.shadowMap.enabled = true;
        renderer.shadowMap.type = THREE.PCFSoftShadowMap;
        container.appendChild(renderer.domElement);

        // 2. Lighting Rig
        const ambientLight = new THREE.AmbientLight(0xffffff, 1.3);
        scene.add(ambientLight);

        const mainLight = new THREE.DirectionalLight(0xffffff, 2.8);
        mainLight.position.set(5, 8, 5);
        scene.add(mainLight);

        const rimLight = new THREE.DirectionalLight(0xE00D42, 4.2);
        rimLight.position.set(-5, 3, -4);
        scene.add(rimLight);

        const pointLight = new THREE.PointLight(0xE00D42, 3.5, 10);
        pointLight.position.set(0, 0.2, 2);
        scene.add(pointLight);

        const bottomGlow = new THREE.PointLight(0xffa07a, 2, 8);
        bottomGlow.position.set(0, -2.5, 1);
        scene.add(bottomGlow);

        // 3. 3D Shopping Bag Construction
        const bagGroup = new THREE.Group();

        // Material: High-End Translucent Iridescent Glossy Glass/Resin in #E00D42 Crimson Tint
        const bagMaterial = new THREE.MeshPhysicalMaterial({
            color: new THREE.Color(0xFF6B8B),
            emissive: new THREE.Color(0x3A000E),
            roughness: 0.18,
            metalness: 0.05,
            transmission: 0.82,
            ior: 1.45,
            thickness: 1.6,
            specularIntensity: 1.0,
            specularColor: new THREE.Color(0xffffff),
            clearcoat: 1.0,
            clearcoatRoughness: 0.1,
            transparent: true,
            opacity: 0.92,
            side: THREE.DoubleSide,
        });

        // Bag Body (centered)
        const bagBodyGeo = new THREE.BoxGeometry(2.4, 2.7, 1.2, 16, 16, 16);
        // Taper top slightly
        const pos = bagBodyGeo.attributes.position;
        for (let i = 0; i < pos.count; i++) {
            const y = pos.getY(i);
            if (y > 0) {
                const factor = 1 - (y / 1.35) * 0.12;
                pos.setX(i, pos.getX(i) * factor);
                pos.setZ(i, pos.getZ(i) * factor);
            }
        }
        bagBodyGeo.computeVertexNormals();

        const bagBody = new THREE.Mesh(bagBodyGeo, bagMaterial);
        bagBody.position.y = -0.15;
        bagGroup.add(bagBody);

        // Bag Handles
        const handleMaterial = new THREE.MeshStandardMaterial({
            color: new THREE.Color(0xE00D42),
            roughness: 0.25,
            metalness: 0.6,
        });

        const createHandle = (zOffset: number) => {
            const curve = new THREE.CubicBezierCurve3(
                new THREE.Vector3(-0.55, 1.2, zOffset),
                new THREE.Vector3(-0.45, 2.3, zOffset),
                new THREE.Vector3(0.45, 2.3, zOffset),
                new THREE.Vector3(0.55, 1.2, zOffset)
            );
            const tubeGeo = new THREE.TubeGeometry(curve, 32, 0.045, 12, false);
            return new THREE.Mesh(tubeGeo, handleMaterial);
        };

        const frontHandle = createHandle(0.48);
        const backHandle = createHandle(-0.48);
        bagGroup.add(frontHandle);
        bagGroup.add(backHandle);

        // Internal Floating Core Glyph/Logo Sphere
        const coreGeo = new THREE.IcosahedronGeometry(0.55, 3);
        const coreMat = new THREE.MeshStandardMaterial({
            color: new THREE.Color(0xE00D42),
            emissive: new THREE.Color(0xE00D42),
            emissiveIntensity: 0.6,
            roughness: 0.3,
            metalness: 0.8,
            wireframe: true,
        });
        const coreSphere = new THREE.Mesh(coreGeo, coreMat);
        coreSphere.position.y = -0.15;
        bagGroup.add(coreSphere);

        // Overall Bag Group Center Offset (adjusted so handles + body are perfectly centered)
        bagGroup.position.y = -0.1;
        scene.add(bagGroup);

        // 4. Mouse Move Only Inside Container
        let mouseX = 0;
        let mouseY = 0;
        let isHovered = false;
        let isDragging = false;
        let prevMouseX = 0;
        let prevMouseY = 0;
        let dragRotX = 0;
        let dragRotY = 0;

        const onMouseMove = (e: MouseEvent) => {
            const rect = container.getBoundingClientRect();
            const x = (e.clientX - rect.left) / rect.width - 0.5;
            const y = (e.clientY - rect.top) / rect.height - 0.5;
            mouseX = x * 2;
            mouseY = y * 2;
            isHovered = true;

            if (isDragging) {
                const deltaX = e.clientX - prevMouseX;
                const deltaY = e.clientY - prevMouseY;
                dragRotY += deltaX * 0.01;
                dragRotX += deltaY * 0.01;
                prevMouseX = e.clientX;
                prevMouseY = e.clientY;
            }
        };

        const onMouseEnter = () => {
            isHovered = true;
        };

        const onMouseLeave = () => {
            isHovered = false;
            isDragging = false;
            mouseX = 0;
            mouseY = 0;
        };

        const onMouseDown = (e: MouseEvent) => {
            isDragging = true;
            prevMouseX = e.clientX;
            prevMouseY = e.clientY;
        };

        const onMouseUp = () => {
            isDragging = false;
        };

        container.addEventListener('mousemove', onMouseMove);
        container.addEventListener('mouseenter', onMouseEnter);
        container.addEventListener('mouseleave', onMouseLeave);
        container.addEventListener('mousedown', onMouseDown);
        window.addEventListener('mouseup', onMouseUp);

        // Handle Resize
        const onResize = () => {
            if (!container) return;
            const newWidth = container.clientWidth || 500;
            const newHeight = container.clientHeight || 500;
            camera.aspect = newWidth / newHeight;
            camera.updateProjectionMatrix();
            renderer.setSize(newWidth, newHeight);
        };
        window.addEventListener('resize', onResize);

        // 5. Animation Loop
        let clock = new THREE.Clock();
        let animationFrame: number;

        const animate = () => {
            const time = clock.getElapsedTime();

            // Hover tilt only when hovered inside container
            const targetRotY = dragRotY + (isHovered ? mouseX * 0.8 : Math.sin(time * 0.8) * 0.12);
            const targetRotX = dragRotX + (isHovered ? -mouseY * 0.5 : Math.cos(time * 0.6) * 0.08);

            // Smooth spring damping
            bagGroup.rotation.y += (targetRotY - bagGroup.rotation.y) * 0.08;
            bagGroup.rotation.x += (targetRotX - bagGroup.rotation.x) * 0.08;
            bagGroup.position.y = -0.1 + Math.sin(time * 1.5) * 0.08;

            // Rotate inner core
            coreSphere.rotation.x += 0.015;
            coreSphere.rotation.y += 0.02;

            renderer.render(scene, camera);
            animationFrame = requestAnimationFrame(animate);
        };

        animate();

        return () => {
            container.removeEventListener('mousemove', onMouseMove);
            container.removeEventListener('mouseenter', onMouseEnter);
            container.removeEventListener('mouseleave', onMouseLeave);
            container.removeEventListener('mousedown', onMouseDown);
            window.removeEventListener('mouseup', onMouseUp);
            window.removeEventListener('resize', onResize);
            cancelAnimationFrame(animationFrame);
            renderer.dispose();
            if (container && renderer.domElement) {
                container.removeChild(renderer.domElement);
            }
        };
    }, []);

    return (
        <div 
            ref={containerRef} 
            className={`w-full h-full flex items-center justify-center pointer-events-auto cursor-grab active:cursor-grabbing select-none ${className}`}
        />
    );
}
