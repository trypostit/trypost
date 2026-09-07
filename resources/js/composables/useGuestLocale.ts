import { usePage } from '@inertiajs/vue3';
import { loadLanguageAsync } from 'laravel-vue-i18n';
import { computed } from 'vue';
import { ref } from 'vue';

import type { Language } from '@/types';

/**
 * The locale a logged-out visitor picked in the auth language switcher.
 *
 * It lives at module scope so the choice survives Inertia navigations between
 * the auth screens, and every auth form submits it as a hidden `locale` field —
 * LocaleResolver reads that to render the backend validation messages in the
 * language the visitor is looking at, and on the register screen it is also the
 * locale the account is created with.
 */
const chosen = ref<string | null>(null);

export const useGuestLocale = () => {
    const page = usePage();

    const languages = computed<Language[]>(
        () => (page.props.languages ?? []) as Language[],
    );

    const locale = computed<string>({
        get: () => chosen.value ?? (page.props.locale as string),
        set: (value) => {
            chosen.value = value;

            void loadLanguageAsync(value);

            document.documentElement.lang = value;
            document.documentElement.dir =
                languages.value.find((language) => language.code === value)
                    ?.dir ?? 'ltr';
        },
    });

    return { locale, languages };
};
