import {
    computed,
    inject,
    provide,
    type ComputedRef,
    type InjectionKey,
    type Ref,
} from 'vue';

import { userTimezone } from '@/preferences';

const viewTimezoneKey: InjectionKey<Ref<string>> = Symbol('viewTimezone');

/**
 * Pages with a display time zone picker (publish list, calendar) provide it so
 * times shown next to their cards use the same zone.
 */
export const provideViewTimezone = (timezone: Ref<string>): void => {
    provide(viewTimezoneKey, timezone);
};

/** The page's display zone where it has a picker, otherwise the user's zone. */
export const useViewTimezone = (): ComputedRef<string> => {
    const provided = inject(viewTimezoneKey, null);

    return computed(() => provided?.value ?? userTimezone.value);
};
