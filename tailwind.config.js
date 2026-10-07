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

    darkMode: 'class',

    theme: {
        extend: {
            colors: {
                // Palette from .agents/DESIGN.md
                primary: {
                    DEFAULT: '#6366F1',
                    hover: '#4F46E5',
                },
                brand: {
                    secondary: '#20970B',
                },
                surface: {
                    DEFAULT: '#FFFFFF',
                    muted: '#FAFAFA',
                },
                content: {
                    DEFAULT: '#0A0A0A',
                    secondary: '#6B6B6B',
                    muted: '#9C9C9C',
                },
                hairline: '#E8E8EC',
                danger: '#EF4444',
            },

            fontFamily: {
                display: ['General Sans', ...defaultTheme.fontFamily.sans],
                sans: ['DM Sans', ...defaultTheme.fontFamily.sans],
                mono: ['JetBrains Mono', ...defaultTheme.fontFamily.mono],
            },

            fontSize: {
                display: ['72px', { lineHeight: '1.05', letterSpacing: '-0.04em' }],
                headline: ['60px', { lineHeight: '1.08', letterSpacing: '-0.035em' }],
                section: ['32px', { lineHeight: '1.2', letterSpacing: '-0.03em' }],
                subhead: ['24px', { lineHeight: '1.3', letterSpacing: '-0.02em' }],
                body: ['15px', { lineHeight: '1.6' }],
                small: ['13px', { lineHeight: '1.5' }],
                caption: ['12px', { lineHeight: '1.45' }],
                overline: ['11px', { lineHeight: '1.4', letterSpacing: '0.08em' }],
            },

            borderRadius: {
                input: '6px',
                panel: '8px',
                card: '12px',
            },

            boxShadow: {
                'card-hover': '0 8px 30px rgba(0, 0, 0, 0.08)',
                'primary-glow': '0 4px 12px rgba(99, 102, 241, 0.35)',
                'focus-ring': '0 0 0 3px rgba(99, 102, 241, 0.12)',
            },

            maxWidth: {
                container: '1280px',
            },
        },
    },

    plugins: [forms],
};
