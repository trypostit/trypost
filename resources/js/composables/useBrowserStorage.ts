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
 * Items of every type share the map, so each ref is typed again by the item it
 * was created for (its id names it).
 */
const shared = new Map<string, ShallowRef<unknown>>();

/** Whether a stored value has the shape of its default (a list, a flag, a text), so a hand-edited one never breaks its reader. */
const hasShapeOf = <Value>(value: unknown, fallback: Value): value is Value => {
    if (Array.isArray(fallback)) {
        return Array.isArray(value);
    }

    return typeof value === typeof fallback;
};

/**
 * An item of the user's browser storage as a ref: saved on set, and kept in
 * step with a change made in another tab.
 */
const storedItem = <Value>(
    id: string,
    userId: string,
    read: () => unknown,
    write: (value: Value) => void,
    fallback: Value,
): WritableComputedRef<Value> => {
    const current = (): Value => {
        const value = read();

        if (hasShapeOf(value, fallback)) {
            return value;
        }

        return fallback;
    };

    const share = (): ShallowRef<unknown> => {
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

        return created;
    };

    const stored = shared.get(id) ?? share();

    return computed({
        get: () => stored.value as Value,
        set: (value) => {
            stored.value = value;
            write(value);
        },
    });
};

/**
 * The item of the page's current user and workspace: a component that stays
 * mounted while they change (the layout's command palette, when switching
 * workspace keeps the page) reads and writes the new one.
 */
const useCurrentItem = <Value>(
    item: (auth: SharedData['auth']) => WritableComputedRef<Value>,
): WritableComputedRef<Value> => {
    const page = usePage<SharedData>();
    const current = computed(() => {
        moveLegacyBrowserStorage(page.props.auth);

        return item(page.props.auth);
    });

    return computed({
        get: () => current.value.value,
        set: (value) => {
            current.value.value = value;
        },
    });
};

/** An item the signed-in user keeps in this browser, in every workspace. */
export const useUserStorage = <Key extends keyof UserStorage>(
    key: Key,
    fallback: UserStorage[Key],
): WritableComputedRef<UserStorage[Key]> =>
    useCurrentItem(({ user }) =>
        storedItem(
            `user|${user.id}|${key}`,
            user.id,
            () => readUserItem(user.id, key),
            (value) => writeUserItem(user.id, key, value),
            fallback,
        ),
    );

/** An item the signed-in user keeps in this browser for the current workspace; without one, nothing is kept. */
export const useWorkspaceStorage = <Key extends keyof WorkspaceStorage>(
    key: Key,
    fallback: WorkspaceStorage[Key],
): WritableComputedRef<WorkspaceStorage[Key]> =>
    useCurrentItem(({ user, currentWorkspace }) => {
        const workspaceId = currentWorkspace?.id;

        const read = (): unknown => {
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

        return storedItem(
            `workspace|${user.id}|${workspaceId}|${key}`,
            user.id,
            read,
            write,
            fallback,
        );
    });
