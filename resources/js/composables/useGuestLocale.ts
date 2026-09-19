import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import { chooseGuestLocale, guestLocale } from '@/language';
import type { Language } from '@/types';

export const useGuestLocale = () => {
    const page = usePage();

    const languages = computed<Language[]>(
        () => page.props.languages as Language[],
    );

    const locale = computed<string>({
        get: () => guestLocale() ?? (page.props.locale as string),
        set: (value) => {
            const language = languages.value.find(
                (candidate) => candidate.code === value,
            );

            if (language) {
                chooseGuestLocale(language);
            }
        },
    });

    return { locale, chosen: computed(guestLocale), languages };
};
