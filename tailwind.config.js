import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.tsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: {
                    50: '#FDF2F4',
                    100: '#FCE7EA',
                    200: '#F9D0D8',
                    500: '#E00D42',
                    600: '#C20836',
                    700: '#A1052B',
                    900: '#570115',
                    DEFAULT: '#E00D42',
                },
            },
            keyframes: {
                float: {
                    '0%, 100%': { transform: 'translateY(0px)' },
                    '50%': { transform: 'translateY(-6px)' },
                },
                'pulse-slow': {
                    '0%, 100%': { opacity: '0.8', transform: 'scale(1)' },
                    '50%': { opacity: '1', transform: 'scale(1.03)' },
                },
                marquee: {
                    '0%': { transform: 'translateX(0%)' },
                    '100%': { transform: 'translateX(-50%)' },
                },
                'marquee-reverse': {
                    '0%': { transform: 'translateX(-50%)' },
                    '100%': { transform: 'translateX(0%)' },
                },
                shimmer: {
                    '0%': { backgroundPosition: '-200% 0' },
                    '100%': { backgroundPosition: '200% 0' },
                },
            },
            animation: {
                float: 'float 4s ease-in-out infinite',
                'float-delayed': 'float 4s ease-in-out 2s infinite',
                'pulse-slow': 'pulse-slow 3s ease-in-out infinite',
                marquee: 'marquee 25s linear infinite',
                'marquee-slow': 'marquee 40s linear infinite',
                'marquee-reverse': 'marquee-reverse 25s linear infinite',
                shimmer: 'shimmer 2.5s linear infinite',
            },
        },
    },

    plugins: [forms],
};
