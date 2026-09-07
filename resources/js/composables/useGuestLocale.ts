import { usePage } from '@inertiajs/vue3';
import { loadLanguageAsync } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import type { Language } from '@/types';

const chosen = ref<string | null>(null);

export const useGuestLocale = () => {
    const page = usePage();

    const locale = computed<string>({
        get: () => chosen.value ?? (page.props.locale as string),
        set: (value) => {
            chosen.value = value;

            void loadLanguageAsync(value);
        },
    });

    const languages = computed<Language[]>(
        () => page.props.languages as Language[],
    );

    return { locale, languages };
};
