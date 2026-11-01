import {
    BROWSER_STORAGE_VERSION,
    type BrowserStorage,
    type UserStorage,
    type WorkspaceStorage,
} from '@/types/browser-storage';

export const browserStorageKey = (userId: string): string => `trypost:${userId}`;

const empty = (): BrowserStorage => ({
    version: BROWSER_STORAGE_VERSION,
    user: {},
    workspaces: {},
});

const isObject = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null && !Array.isArray(value);

/** A stored block of items, or an empty one when it is not an object; each item is checked by its reader. */
const block = <Block>(value: unknown): Block => {
    if (isObject(value)) {
        return value as Block;
    }

    return {} as Block;
};

/** The user's stored value; a missing, unreadable or other-version one (or a missing block) reads as empty. */
export const readBrowserStorage = (userId: string): BrowserStorage => {
    try {
        const stored: unknown = JSON.parse(
            window.localStorage.getItem(browserStorageKey(userId)) ?? 'null',
        );

        if (!isObject(stored) || stored.version !== BROWSER_STORAGE_VERSION) {
            return empty();
        }

        const workspaces = block<Record<string, unknown>>(stored.workspaces);

        return {
            version: BROWSER_STORAGE_VERSION,
            user: block<BrowserStorage['user']>(stored.user),
            workspaces: Object.fromEntries(
                Object.entries(workspaces).map(([workspaceId, items]) => [
                    workspaceId,
                    block<Partial<WorkspaceStorage>>(items),
                ]),
            ),
        };
    } catch {
        return empty();
    }
};

/** Reads the value again before writing, so two tabs never write back each other's stale copy. */
export const updateBrowserStorage = (
    userId: string,
    update: (storage: BrowserStorage) => BrowserStorage,
): void => {
    try {
        window.localStorage.setItem(
            browserStorageKey(userId),
            JSON.stringify(update(readBrowserStorage(userId))),
        );
    } catch {
        return;
    }
};

/** What is stored for an item, unchecked: the browser may hold anything, so the caller checks its shape. */
export const readUserItem = (userId: string, key: keyof UserStorage): unknown =>
    readBrowserStorage(userId).user[key];

/** Saves an item for every workspace; `undefined` removes it. */
export const writeUserItem = <Key extends keyof UserStorage>(
    userId: string,
    key: Key,
    value: UserStorage[Key] | undefined,
): void => {
    updateBrowserStorage(userId, (storage) => ({
        ...storage,
        user: { ...storage.user, [key]: value },
    }));
};

/** What is stored for an item of one workspace, unchecked: the caller checks its shape. */
export const readWorkspaceItem = (
    userId: string,
    workspaceId: string,
    key: keyof WorkspaceStorage,
): unknown => readBrowserStorage(userId).workspaces[workspaceId]?.[key];

/** Saves an item for one workspace; `undefined` removes it. */
export const writeWorkspaceItem = <Key extends keyof WorkspaceStorage>(
    userId: string,
    workspaceId: string,
    key: Key,
    value: WorkspaceStorage[Key] | undefined,
): void => {
    updateBrowserStorage(userId, (storage) => ({
        ...storage,
        workspaces: {
            ...storage.workspaces,
            [workspaceId]: { ...storage.workspaces[workspaceId], [key]: value },
        },
    }));
};
