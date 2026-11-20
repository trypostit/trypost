import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import { chooseGuestLocale, guestLocale } from '@/language';
import { detectBrowserLanguage } from '@/lib/detectPreferences';
import type { Language } from '@/types';

export const useGuestLocale = () => {
    const page = usePage();

    const languages = computed<Language[]>(
        () => page.props.languages as Language[],
    );

    const pageLocale = (): string => page.props.locale as string;

    const browserLocale = computed(() =>
        detectBrowserLanguage(languages.value.map((language) => language.code)),
    );

    const locale = computed<string>({
        get: () => guestLocale() ?? pageLocale(),
        set: (value) => {
            const language = languages.value.find(
                (candidate) => candidate.code === value,
            );

            if (language) {
                chooseGuestLocale(language);
            }
        },
    });

    const signupLocale = computed(
        () => guestLocale() ?? browserLocale.value ?? pageLocale(),
    );

    return { locale, signupLocale, chosen: computed(guestLocale), languages };
};
