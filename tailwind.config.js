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
        },
    },

    plugins: [forms],
};
