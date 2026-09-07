import { usePage } from '@inertiajs/vue3';
import { loadLanguageAsync } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import type { Language } from '@/types';

/**
 * At module scope so the choice survives Inertia navigation between the auth
 * screens. Every auth form submits it as a hidden `locale` field: LocaleResolver
 * reads that for the validation messages, and register also stores it.
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
