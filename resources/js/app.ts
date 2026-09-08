import '../css/app.css';
import './echo';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { getActiveLanguage, i18nVue, loadLanguageAsync } from 'laravel-vue-i18n';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';

import { clearGuestLocale, guestLocale } from '@/composables/useGuestLocale';

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

        // The locale is read once at boot, but it changes mid-session: logging
        // in swaps the guest default for the account's language over an Inertia
        // visit, which never re-runs this setup. While logged out the shared
        // prop is always the default, so a visitor's own pick wins instead.
        const applyLocale = (props: Record<string, unknown>): void => {
            const authenticated = Boolean((props.auth as Auth | undefined)?.user);

            if (authenticated) {
                clearGuestLocale();
            }

            const next = authenticated
                ? (props.locale as string | undefined)
                : (guestLocale() ?? (props.locale as string | undefined));

            if (!next || next === getActiveLanguage()) {
                return;
            }

            void loadLanguageAsync(next);
            dayjs.locale(next.toLowerCase());
            document.documentElement.lang = next;
        };

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
            applyLocale(event.detail.page.props);
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
