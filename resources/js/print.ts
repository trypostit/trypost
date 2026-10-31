import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { i18nVue } from 'laravel-vue-i18n';
import { ConfigProvider } from 'reka-ui';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';

import { bootLocale, i18nConfig, textDirection } from './language';
import { syncPreferences } from './preferences';

/**
 * The entry Chromium loads to print an Insights PDF: the print pages only,
 * with the user's language and preferences, and none of the app's live
 * wiring (sockets, analytics, navigation tracking).
 */
createInertiaApp({
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/Print.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const locale = bootLocale(props.initialPage.props);
        syncPreferences(props.initialPage.props);

        let mounted = false;

        const app = createApp({
            render: () =>
                h(ConfigProvider, { dir: textDirection.value }, () =>
                    h(App, props),
                ),
        });

        app.use(i18nVue, {
            ...i18nConfig(locale),
            onLoad: () => {
                if (mounted) {
                    return;
                }

                mounted = true;
                app.mount(el);
            },
        }).use(plugin);
    },
    progress: false,
});
