import { usePage } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import { computed, shallowRef, type WritableComputedRef } from 'vue';

import {
    browserStorageKey,
    readUserItem,
    readWorkspaceItem,
    writeUserItem,
    writeWorkspaceItem,
} from '@/lib/browserStorage';
import type { SharedData } from '@/types';
import type { UserStorage, WorkspaceStorage } from '@/types/browser-storage';

/**
 * An item of the user's browser storage as a ref: read when the component
 * mounts, saved on set, and kept in step with a change made in another tab.
 */
const useStoredItem = <Value>(
    userId: string,
    read: () => Value,
    write: (value: Value) => void,
): WritableComputedRef<Value> => {
    const stored = shallowRef<Value>(read());

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
            write(value);
        },
    });
};

/** An item the signed-in user keeps in this browser, in every workspace. */
export const useUserStorage = <Key extends keyof UserStorage>(
    key: Key,
    fallback: UserStorage[Key],
): WritableComputedRef<UserStorage[Key]> => {
    const userId = usePage<SharedData>().props.auth.user.id;

    return useStoredItem(
        userId,
        () => readUserItem(userId, key) ?? fallback,
        (value) => writeUserItem(userId, key, value),
    );
};

/** An item the signed-in user keeps in this browser for the current workspace. */
export const useWorkspaceStorage = <Key extends keyof WorkspaceStorage>(
    key: Key,
    fallback: WorkspaceStorage[Key],
): WritableComputedRef<WorkspaceStorage[Key]> => {
    const { user, currentWorkspace } = usePage<SharedData>().props.auth;
    const workspaceId = currentWorkspace?.id ?? '';

    return useStoredItem(
        user.id,
        () => readWorkspaceItem(user.id, workspaceId, key) ?? fallback,
        (value) => writeWorkspaceItem(user.id, workspaceId, key, value),
    );
};
