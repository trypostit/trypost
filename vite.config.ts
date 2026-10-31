import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import i18n from 'laravel-vue-i18n/vite';
import { defineConfig } from 'vite';

export default defineConfig({
    // The Insights PDF loads the print entry from an HTML document with no
    // origin (Chromium's setHtml), so the dev server answers any origin.
    server: {
        cors: true,
    },
    plugins: [
        laravel({
            input: ['resources/js/app.ts', 'resources/js/print.ts', 'resources/css/app.css'],
            ssr: 'resources/js/ssr.ts',
            assets: ['resources/changelog/**/*.png'],
            refresh: true,
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        i18n(),
    ],
});
