import type { BrowserStorage } from '@/types/browser-storage';

/**
 * The user's one localStorage key (`types/browser-storage.ts` has its shape).
 * A missing, unreadable or other-version value reads as empty, and a browser
 * without storage keeps working on the defaults.
 */
export const browserStorageKey = (userId: string): string => `trypost:${userId}`;

const empty = (): BrowserStorage => ({ version: 1, user: {}, workspaces: {} });

const isRecord = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null && !Array.isArray(value);

const isBrowserStorage = (value: unknown): value is BrowserStorage =>
    isRecord(value) &&
    value.version === 1 &&
    isRecord(value.user) &&
    isRecord(value.workspaces);

export const readBrowserStorage = (userId: string): BrowserStorage => {
    try {
        const stored: unknown = JSON.parse(
            window.localStorage.getItem(browserStorageKey(userId)) ?? 'null',
        );

        if (isBrowserStorage(stored)) {
            return stored;
        }

        return empty();
    } catch {
        return empty();
    }
};

/** Reads the key again before writing, so two tabs never write back each other's stale copy. */
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
