import '../css/app.css';
import './echo';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { i18nVue } from 'laravel-vue-i18n';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';

import { initializeDataLayer } from './datalayer';
import dayjs from './dayjs';
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
        // Get locale from shared Inertia props
        const locale = (props.initialPage.props as { locale?: string })?.locale || 'en';

        // Set dayjs locale based on user's language
        dayjs.locale(locale.toLowerCase());

        const auth = props.initialPage.props.auth as Auth | undefined;

        initializeDataLayer(
            auth,
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
            syncPostHogContext(event.detail.page);
            syncContentTypeMediaRules(event.detail.page);
            capturePageview();
        });

        createApp({ render: () => h(App, props) })
            .use(i18nVue, {
                lang: locale,
                resolve: async (lang: string) => {
                    const langs = import.meta.glob('../../lang/*.json');
                    return await langs[`../../lang/php_${lang}.json`]();
                },
            })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
