import { getActiveLanguage, loadLanguageAsync } from 'laravel-vue-i18n';
import { ref } from 'vue';

import dayjs from './dayjs';
import type { Auth, Language } from './types';

interface LocaleProps {
    locale?: string;
    auth?: Auth;
}

const chosen = ref<string | null>(null);

export const guestLocale = (): string | null => chosen.value;

/**
 * Align everything outside the translation strings themselves.
 */
const align = (locale: string, direction?: string): void => {
    dayjs.locale(locale.toLowerCase());
    document.documentElement.lang = locale;

    if (direction) {
        document.documentElement.dir = direction;
    }
};

/**
 * The locale the app starts in. The i18n plugin loads the strings itself from
 * the value returned here, so this only aligns dayjs and the document.
 */
export const bootLocale = (props: LocaleProps): string => {
    const locale = props.locale ?? 'en';

    align(locale);

    return locale;
};

/**
 * Record a logged-out visitor's pick and apply it. It has to be remembered: the
 * server renders every guest page in the default, so nothing else would keep the
 * choice across an Inertia visit between the auth screens.
 */
export const chooseGuestLocale = (language: Language): void => {
    chosen.value = language.code;

    void loadLanguageAsync(language.code);
    align(language.code, language.dir);
};

/**
 * Re-align after an Inertia visit. The locale is read once at boot but changes
 * mid-session — logging in swaps the guest default for the account's language
 * over a visit that never re-runs the app setup.
 */
export const syncLocale = (props: LocaleProps): void => {
    const authenticated = Boolean(props.auth?.user);

    if (authenticated) {
        chosen.value = null;
    }

    const next = authenticated ? props.locale : (chosen.value ?? props.locale);

    if (!next || next === getActiveLanguage()) {
        return;
    }

    void loadLanguageAsync(next);
    align(next);
};

export const i18nConfig = (lang: string) => ({
    lang,
    resolve: async (locale: string) => {
        const langs = import.meta.glob('../../lang/*.json');

        return await langs[`../../lang/php_${locale}.json`]();
    },
});
