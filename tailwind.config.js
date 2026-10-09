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

            // Semua ukuran dalam `rem`, BUKAN `px`.
            //
            // Alasannya aksesibilitas, bukan selera. Dengan `px`, mengatur "Font size"
            // di pengaturan tampilan browser -- langkah yang paling sering dilakukan
            // pengguna yang matanya bermasalah -- tidak mengubah apa pun di aplikasi
            // ini. Dengan `rem`, seluruh skala ikut mengikuti, termasuk pengatur ukuran
            // teks yang ada di menu user.
            //
            // Skala juga naik satu langkah dari sebelumnya: body 15 ke 16, small 13 ke
            // 14, caption 12 ke 13, overline 11 ke 12. Bagian atas justru dipangkas,
            // karena 72px adalah skala halamanç¬¬å››æ¬¡ dan `display` sendiri tidak
            // terpakai di mana pun.
            //
            // Catatan: `maxWidth.container` SENGAJA tetap `px`. Itu batas tata letak,
            // bukan teks. Kalau ikut skala, memperbesar teks akan ikut melebarkan
            // wadah dan mendorong konten keluar dari layar.
            fontSize: {
                display: ['3.5rem', { lineHeight: '1.05', letterSpacing: '-0.03em' }],
                headline: ['3rem', { lineHeight: '1.1', letterSpacing: '-0.025em' }],
                section: ['1.75rem', { lineHeight: '1.25', letterSpacing: '-0.02em' }],
                subhead: ['1.375rem', { lineHeight: '1.35', letterSpacing: '-0.015em' }],
                body: ['1rem', { lineHeight: '1.6' }],
                small: ['0.875rem', { lineHeight: '1.5' }],
                caption: ['0.8125rem', { lineHeight: '1.45' }],
                // `overline` dipakai untuk 44 header tabel, jadi ini bukan teks
                // hiasan. 12px sudah cukup kecil; huruf besar dengan spasi 0.08em
                // di bawah itu membuat kata terfragmentasi karena huruf kehilangan
                // ascender dan descender.
                overline: ['0.75rem', { lineHeight: '1.45', letterSpacing: '0.06em' }],
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
