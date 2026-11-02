import { router } from '@inertiajs/vue3';
import { onMounted, ref, type Ref } from 'vue';

import { useUserStorage } from '@/composables/useBrowserStorage';
import date from '@/date';
import dayjs from '@/dayjs';

const CONTEXT_PARAMS = ['notes', 'note', 'edit'];

export const useDisplayTimezone = (
    defaultTz: string,
    available: string[],
    currentTab?: () => string,
    reloadProps: () => string[] = () => [
        'posts',
        'queue',
        'counts',
        'displayTimezone',
    ],
): {
    timezone: Ref<string>;
    setTimezone: (tz: string) => void;
    formatTime: (iso: string) => string;
    dayKey: (iso: string) => string;
} => {
    const timezone = ref(defaultTz);
    const storedTimezone = useUserStorage('publish.tz', '');

    const setTimezone = (tz: string): void => {
        if (!available.includes(tz)) {
            return;
        }

        timezone.value = tz;
        storedTimezone.value = tz;

        const query = new URLSearchParams(window.location.search);
        CONTEXT_PARAMS.forEach((key) => query.delete(key));

        if (currentTab) {
            query.set('tab', currentTab());
        }

        const search = query.toString();

        router.get(
            `${window.location.pathname}${search ? `?${search}` : ''}`,
            { tz },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: reloadProps(),
                reset: ['posts'],
            },
        );
    };

    onMounted(() => {
        const stored = storedTimezone.value;
        const hasExplicitTimezone = new URLSearchParams(
            window.location.search,
        ).has('tz');

        if (
            stored &&
            stored !== defaultTz &&
            !hasExplicitTimezone &&
            available.includes(stored)
        ) {
            setTimezone(stored);
        }
    });

    const formatTime = (iso: string): string =>
        date.formatTimeInTimezone(iso, timezone.value);

    const dayKey = (iso: string): string =>
        dayjs.utc(iso).tz(timezone.value).format('YYYY-MM-DD');

    return { timezone, setTimezone, formatTime, dayKey };
};
