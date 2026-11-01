/**
 * What the app keeps in this browser for a user, in one localStorage key per
 * user (`trypost:<user id>`):
 *
 *     { "version": 1, "user": { "mcp.promptDismissed": true }, "workspaces": { "<id>": { … } } }
 *
 * `user` holds what applies in every workspace, `workspaces` what only makes
 * sense in one. Every write reads the key again first, so two tabs never write
 * back each other's stale copy. A missing, unreadable or older key reads as
 * empty, and a browser without storage keeps working on the defaults.
 */

/** What the app keeps for a user in every workspace, by name. */
export interface UserPrefs {
    'mcp.promptDismissed': boolean;
}

interface StoredPrefs {
    version: typeof VERSION;
    user: Partial<UserPrefs>;
    workspaces: Record<string, Record<string, unknown>>;
}

const VERSION = 1;

export const browserStoreKey = (userId: string): string => `trypost:${userId}`;

const empty = (): StoredPrefs => ({ version: VERSION, user: {}, workspaces: {} });

const isRecord = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null && !Array.isArray(value);

const isStoredPrefs = (value: unknown): value is StoredPrefs =>
    isRecord(value) &&
    value.version === VERSION &&
    isRecord(value.user) &&
    isRecord(value.workspaces);

const read = (userId: string): StoredPrefs => {
    try {
        const stored: unknown = JSON.parse(
            window.localStorage.getItem(browserStoreKey(userId)) ?? 'null',
        );

        if (isStoredPrefs(stored)) {
            return stored;
        }

        return empty();
    } catch {
        return empty();
    }
};

const write = (userId: string, update: (prefs: StoredPrefs) => StoredPrefs): void => {
    try {
        window.localStorage.setItem(
            browserStoreKey(userId),
            JSON.stringify(update(read(userId))),
        );
    } catch {
        return;
    }
};

export const readUserPref = <Key extends keyof UserPrefs>(
    userId: string,
    key: Key,
    fallback: UserPrefs[Key],
): UserPrefs[Key] => read(userId).user[key] ?? fallback;

export const writeUserPref = <Key extends keyof UserPrefs>(
    userId: string,
    key: Key,
    value: UserPrefs[Key],
): void => {
    write(userId, (prefs) => ({ ...prefs, user: { ...prefs.user, [key]: value } }));
};
