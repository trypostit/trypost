import { usePage } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import { computed, ref, type Ref, type WritableComputedRef } from 'vue';

import {
    browserStoreKey,
    readUserPref,
    writeUserPref,
    type UserPrefs,
} from '@/lib/browserStore';
import type { SharedData } from '@/types';

/**
 * A preference the signed-in user keeps in this browser (`lib/browserStore.ts`).
 * Setting it saves it, and a change made in another tab shows here at once.
 */
export const useUserPref = <Key extends keyof UserPrefs>(
    key: Key,
    fallback: UserPrefs[Key],
): WritableComputedRef<UserPrefs[Key]> => {
    const page = usePage<SharedData>();
    const userId = (): string => page.props.auth.user.id;
    const stored = ref(readUserPref(userId(), key, fallback)) as Ref<UserPrefs[Key]>;

    const refreshFromOtherTab = (event: StorageEvent): void => {
        if (event.key === browserStoreKey(userId())) {
            stored.value = readUserPref(userId(), key, fallback);
        }
    };

    useEventListener(window, 'storage', refreshFromOtherTab);

    return computed({
        get: () => stored.value,
        set: (value) => {
            stored.value = value;
            writeUserPref(userId(), key, value);
        },
    });
};
