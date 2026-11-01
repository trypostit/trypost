import { usePage } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import { computed, shallowRef, type WritableComputedRef } from 'vue';

import {
    browserStorageKey,
    readBrowserStorage,
    updateBrowserStorage,
} from '@/lib/browserStorage';
import type { SharedData } from '@/types';
import type { UserStorage } from '@/types/browser-storage';

/**
 * An item the signed-in user keeps in this browser, in every workspace.
 * Setting it saves it, and a change made in another tab shows here at once.
 */
export const useUserStorage = <Key extends keyof UserStorage>(
    key: Key,
    fallback: UserStorage[Key],
): WritableComputedRef<UserStorage[Key]> => {
    const userId = usePage<SharedData>().props.auth.user.id;
    const read = (): UserStorage[Key] => readBrowserStorage(userId).user[key] ?? fallback;
    const stored = shallowRef<UserStorage[Key]>(read());

    const followOtherTabs = (event: StorageEvent): void => {
        if (event.key === browserStorageKey(userId)) {
            stored.value = read();
        }
    };

    useEventListener(window, 'storage', followOtherTabs);

    return computed({
        get: () => stored.value,
        set: (value) => {
            stored.value = value;
            updateBrowserStorage(userId, (storage) => ({
                ...storage,
                user: { ...storage.user, [key]: value },
            }));
        },
    });
};
