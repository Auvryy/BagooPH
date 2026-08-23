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
        camera.position.set(0, 0, 7.5);

        const renderer = new THREE.WebGLRenderer({ alpha: true, antialias: true, powerPreference: 'high-performance' });
        renderer.setSize(width, height);
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        renderer.shadowMap.enabled = true;
        renderer.shadowMap.type = THREE.PCFSoftShadowMap;
        container.appendChild(renderer.domElement);

        // 2. Lighting Rig
        const ambientLight = new THREE.AmbientLight(0xffffff, 1.2);
        scene.add(ambientLight);

        const mainLight = new THREE.DirectionalLight(0xffffff, 2.5);
        mainLight.position.set(5, 8, 5);
        scene.add(mainLight);

        const rimLight = new THREE.DirectionalLight(0xE00D42, 4.0);
        rimLight.position.set(-5, 3, -4);
        scene.add(rimLight);

        const pointLight = new THREE.PointLight(0xE00D42, 3, 10);
        pointLight.position.set(0, 0, 2);
        scene.add(pointLight);

        const bottomGlow = new THREE.PointLight(0xffa07a, 2, 8);
        bottomGlow.position.set(0, -3, 1);
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

        // Bag Body
        const bagBodyGeo = new THREE.BoxGeometry(2.4, 2.8, 1.2, 16, 16, 16);
        // Taper the top slightly like a luxury shopping bag
        const pos = bagBodyGeo.attributes.position;
        for (let i = 0; i < pos.count; i++) {
            const y = pos.getY(i);
            if (y > 0) {
                const factor = 1 - (y / 1.4) * 0.12;
                pos.setX(i, pos.getX(i) * factor);
                pos.setZ(i, pos.getZ(i) * factor);
            }
        }
        bagBodyGeo.computeVertexNormals();

        const bagBody = new THREE.Mesh(bagBodyGeo, bagMaterial);
        bagBody.position.y = -0.3;
        bagGroup.add(bagBody);

        // Bag Handles (Left & Right curves)
        const handleMaterial = new THREE.MeshStandardMaterial({
            color: new THREE.Color(0xE00D42),
            roughness: 0.25,
            metalness: 0.6,
        });

        const createHandle = (zOffset: number) => {
            const curve = new THREE.CubicBezierCurve3(
                new THREE.Vector3(-0.6, 1.1, zOffset),
                new THREE.Vector3(-0.5, 2.3, zOffset),
                new THREE.Vector3(0.5, 2.3, zOffset),
                new THREE.Vector3(0.6, 1.1, zOffset)
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
        coreSphere.position.y = -0.3;
        bagGroup.add(coreSphere);

        scene.add(bagGroup);

        // 4. Mouse & Scroll Interaction Controller
        let mouseX = 0;
        let mouseY = 0;
        let targetRotX = 0;
        let targetRotY = 0;
        let scrollY = 0;

        const onMouseMove = (e: MouseEvent) => {
            const rect = container.getBoundingClientRect();
            const x = (e.clientX - rect.left) / width - 0.5;
            const y = (e.clientY - rect.top) / height - 0.5;
            mouseX = x * 2;
            mouseY = y * 2;
        };

        const onScroll = () => {
            scrollY = window.scrollY;
        };

        window.addEventListener('mousemove', onMouseMove);
        window.addEventListener('scroll', onScroll, { passive: true });

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

            // Hover tracking + idle organic oscillation
            targetRotY = mouseX * 0.75 + Math.sin(time * 0.8) * 0.15 + (scrollY * 0.003);
            targetRotX = -mouseY * 0.45 + Math.cos(time * 0.6) * 0.08 + (scrollY * 0.001);

            // Smooth spring damping
            bagGroup.rotation.y += (targetRotY - bagGroup.rotation.y) * 0.08;
            bagGroup.rotation.x += (targetRotX - bagGroup.rotation.x) * 0.08;
            bagGroup.position.y = Math.sin(time * 1.4) * 0.12 - (scrollY * 0.0008);

            // Rotate inner core
            coreSphere.rotation.x += 0.015;
            coreSphere.rotation.y += 0.02;

            renderer.render(scene, camera);
            animationFrame = requestAnimationFrame(animate);
        };

        animate();

        return () => {
            window.removeEventListener('mousemove', onMouseMove);
            window.removeEventListener('scroll', onScroll);
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
            className={`w-full h-full flex items-center justify-center pointer-events-auto cursor-grab active:cursor-grabbing ${className}`}
        />
    );
}
