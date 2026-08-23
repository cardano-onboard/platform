import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Varela', 'Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: {
                    DEFAULT: '#FE5B24',
                    light: '#FF7A3D',
                    dark: '#DC3700',
                    50: '#FFF3ED',
                    100: '#FFE4D4',
                    500: '#FE5B24',
                    600: '#DC3700',
                    700: '#C03A00',
                },
                dark: {
                    DEFAULT: '#1A1A1A',
                    light: '#2D2D2D',
                    900: '#111111',
                },
            },
        },
    },

    plugins: [forms],
};
