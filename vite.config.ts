import { execSync } from 'node:child_process';

import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import i18n from 'laravel-vue-i18n/vite';
import { defineConfig, type Plugin } from 'vite';

/** The full commit being built: the Forge deploy's, the Docker image's build arg, else git. */
const buildCommit = (): string => {
    if (process.env.FORGE_DEPLOY_COMMIT) {
        return process.env.FORGE_DEPLOY_COMMIT;
    }

    if (process.env.BUILD_COMMIT) {
        return process.env.BUILD_COMMIT;
    }

    try {
        return execSync('git rev-parse HEAD').toString().trim();
    } catch {
        return '';
    }
};

/** Bakes the commit and the build time into the bundle, shown at the bottom of the settings sidebar. */
const buildInfo = (): Plugin => ({
    name: 'build-info',
    config: () => ({
        define: {
            __BUILD_COMMIT__: JSON.stringify(buildCommit()),
            __BUILD_DATE__: JSON.stringify(new Date().toISOString()),
        },
    }),
});

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts', 'resources/css/app.css'],
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
        buildInfo(),
    ],
});
