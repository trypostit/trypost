import type { BrowserStorage } from '@/types/browser-storage';

export const browserStorageKey = (userId: string): string => `trypost:${userId}`;

const empty = (): BrowserStorage => ({ version: 1, user: {}, workspaces: {} });

/** The user's stored value; a missing, unreadable or other-version one reads as empty. */
export const readBrowserStorage = (userId: string): BrowserStorage => {
    try {
        const stored = JSON.parse(
            window.localStorage.getItem(browserStorageKey(userId)) ?? 'null',
        ) as BrowserStorage | null;

        if (stored?.version === 1) {
            return stored;
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
