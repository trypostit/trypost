import { usePage } from '@inertiajs/vue3';
import {
    computed,
    shallowRef,
    type ShallowRef,
    type WritableComputedRef,
} from 'vue';

import {
    browserStorageKey,
    readUserItem,
    readWorkspaceItem,
    writeUserItem,
    writeWorkspaceItem,
} from '@/lib/browserStorage';
import { moveLegacyBrowserStorage } from '@/lib/legacyBrowserStorage';
import type { SharedData } from '@/types';
import type { UserStorage, WorkspaceStorage } from '@/types/browser-storage';

/**
 * One ref per stored item in this tab, read once and followed across tabs by
 * one listener, however many components use it (a list's every card does).
 */
const shared = new Map<string, ShallowRef<unknown>>();

/** Whether a stored value has the shape of its default (a list, a flag, a text), so a hand-edited one never breaks its reader. */
const hasShapeOf = (value: unknown, fallback: unknown): boolean => {
    if (Array.isArray(fallback)) {
        return Array.isArray(value);
    }

    return typeof value === typeof fallback;
};

/**
 * An item of the user's browser storage as a ref: saved on set, and kept in
 * step with a change made in another tab.
 */
const useStoredItem = <Value>(
    id: string,
    userId: string,
    read: () => unknown,
    write: (value: Value) => void,
    fallback: Value,
): WritableComputedRef<Value> => {
    const current = (): Value => {
        const value = read();

        if (hasShapeOf(value, fallback)) {
            return value as Value;
        }

        return fallback;
    };

    if (!shared.has(id)) {
        const created = shallowRef<unknown>(current());

        window.addEventListener('storage', (event) => {
            if (event.key !== browserStorageKey(userId)) {
                return;
            }

            const next = current();

            if (JSON.stringify(next) !== JSON.stringify(created.value)) {
                created.value = next;
            }
        });

        shared.set(id, created);
    }

    const stored = shared.get(id) as ShallowRef<unknown>;

    return computed({
        get: () => stored.value as Value,
        set: (value) => {
            stored.value = value;
            write(value);
        },
    });
};

/** The signed-in user, after the earlier keys moved into their storage. */
const storageAuth = (): SharedData['auth'] => {
    const { auth } = usePage<SharedData>().props;

    moveLegacyBrowserStorage(auth);

    return auth;
};

/** An item the signed-in user keeps in this browser, in every workspace. */
export const useUserStorage = <Key extends keyof UserStorage>(
    key: Key,
    fallback: UserStorage[Key],
): WritableComputedRef<UserStorage[Key]> => {
    const userId = storageAuth().user.id;

    return useStoredItem(
        `user|${userId}|${key}`,
        userId,
        () => readUserItem(userId, key),
        (value) => writeUserItem(userId, key, value),
        fallback,
    );
};

/** An item the signed-in user keeps in this browser for the current workspace; without one, nothing is kept. */
export const useWorkspaceStorage = <Key extends keyof WorkspaceStorage>(
    key: Key,
    fallback: WorkspaceStorage[Key],
): WritableComputedRef<WorkspaceStorage[Key]> => {
    const { user, currentWorkspace } = storageAuth();
    const workspaceId = currentWorkspace?.id;

    const read = (): WorkspaceStorage[Key] | undefined => {
        if (!workspaceId) {
            return undefined;
        }

        return readWorkspaceItem(user.id, workspaceId, key);
    };

    const write = (value: WorkspaceStorage[Key]): void => {
        if (workspaceId) {
            writeWorkspaceItem(user.id, workspaceId, key, value);
        }
    };

    return useStoredItem(
        `workspace|${user.id}|${workspaceId}|${key}`,
        user.id,
        read,
        write,
        fallback,
    );
};
