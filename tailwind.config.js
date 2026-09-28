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
                // Inter carries body text: it has the weights Varela lacks and the ada
                // sign at U+20B3, which neither Varela nor Figtree contains.
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                // Varela is the brand face and sets headings only. See brand/brand.json.
                display: ['Varela', 'Inter', ...defaultTheme.fontFamily.sans],
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
