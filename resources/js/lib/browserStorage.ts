import type {
    BrowserStorage,
    UserStorage,
    WorkspaceStorage,
} from '@/types/browser-storage';

export const browserStorageKey = (userId: string): string => `trypost:${userId}`;

const empty = (): BrowserStorage => ({ version: 1, user: {}, workspaces: {} });

/** The user's stored value; a missing, unreadable or other-version one (or a missing block) reads as empty. */
export const readBrowserStorage = (userId: string): BrowserStorage => {
    try {
        const stored = JSON.parse(
            window.localStorage.getItem(browserStorageKey(userId)) ?? 'null',
        ) as BrowserStorage | null;

        if (stored?.version === 1) {
            return {
                version: 1,
                user: stored.user ?? {},
                workspaces: stored.workspaces ?? {},
            };
        }
    } catch {
        return empty();
    }

    return empty();
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

export const readUserItem = <Key extends keyof UserStorage>(
    userId: string,
    key: Key,
): UserStorage[Key] | undefined => readBrowserStorage(userId).user[key];

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

export const readWorkspaceItem = <Key extends keyof WorkspaceStorage>(
    userId: string,
    workspaceId: string,
    key: Key,
): WorkspaceStorage[Key] | undefined =>
    readBrowserStorage(userId).workspaces[workspaceId]?.[key];

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
