import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

/**
 * Active UI locale for Reka Calendar / RangeCalendar.
 *
 * Mirrors the shared Inertia `locale` prop (same codes as config/languages.php
 * and dayjs), so weekday headers and calendar a11y labels follow the user's
 * language instead of the Reka English default.
 */
export const useCalendarLocale = (): ComputedRef<string> => {
    const page = usePage();

    return computed(() => (page.props.locale as string | undefined) || 'en');
};
