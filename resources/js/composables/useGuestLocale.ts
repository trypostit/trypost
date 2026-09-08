import { usePage } from '@inertiajs/vue3';
import { loadLanguageAsync } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import type { Language } from '@/types';

const chosen = ref<string | null>(null);

export const useGuestLocale = () => {
    const page = usePage();

    const languages = computed<Language[]>(
        () => page.props.languages as Language[],
    );

    const locale = computed<string>({
        get: () => chosen.value ?? (page.props.locale as string),
        set: (value) => {
            const language = languages.value.find(
                (candidate) => candidate.code === value,
            );

            if (!language) {
                return;
            }

            chosen.value = language.code;

            void loadLanguageAsync(language.code);

            document.documentElement.dir = language.dir;
        },
    });

    return { locale, chosen, languages };
};
