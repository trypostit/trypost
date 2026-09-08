import '../css/app.css';
import './echo';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { i18nVue } from 'laravel-vue-i18n';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';

import { initializeDataLayer } from './datalayer';
import { bootLocale, i18nConfig, syncLocale } from './language';
import { syncContentTypeMediaRules } from './lib/contentTypeMediaRules';
import { capturePageview, initializePostHog, syncPostHogContext } from './posthog';
import type { Auth } from './types';

const appName = import.meta.env.VITE_APP_NAME || 'TryPost.it';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const locale = bootLocale(props.initialPage.props);

        const auth = props.initialPage.props.auth as Auth | undefined;
        const flash = props.initialPage.props.flash as
            | { conversion_event?: string; [key: string]: unknown }
            | undefined;

        initializeDataLayer(
            auth,
            flash,
            props.initialPage.props.applicationUrl as string,
            props.initialPage.props.env as string,
        );

        // Initial PostHog identify + dual-group context + first pageview.
        // The same hooks fire on every Inertia navigation below so the
        // account group counts stay reactive and workspace switches
        // re-attach the right workspace group.
        initializePostHog();
        syncPostHogContext(props.initialPage);
        syncContentTypeMediaRules(props.initialPage);
        capturePageview();

        router.on('navigate', (event) => {
            syncLocale(event.detail.page.props);
            syncPostHogContext(event.detail.page);
            syncContentTypeMediaRules(event.detail.page);
            capturePageview();
        });

        createApp({ render: () => h(App, props) })
            .use(i18nVue, i18nConfig(locale))
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
