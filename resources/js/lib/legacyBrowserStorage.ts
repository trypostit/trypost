import { updateBrowserStorage } from '@/lib/browserStorage';
import type { Auth } from '@/types';
import type {
    BrowserStorage,
    UserStorage,
    WorkspaceStorage,
} from '@/types/browser-storage';

/**
 * Moves what the app kept in its earlier localStorage keys (#461) into the
 * signed-in user's `trypost:<user id>`, then removes every earlier key, any
 * user's, so nothing is lost and no old draft stays behind. A value already
 * in the new storage wins. Does nothing once the earlier keys are gone:
 * remove this file a release or two after it ships.
 */

const isLegacyKey = (key: string): boolean =>
    [
        'publish.tz',
        'publish.showSlots',
        'insights.performanceColumns',
        'insights.columns.',
        'calendar.skipCustomTimeConfirm',
        'trypost.emoji.recents',
        'trypost:instagram:collaborators-notice-seen',
        'trypost:composer:last-used-accounts',
        'trypost.command-palette.recent.',
        'trypost:composer:autosave:',
    ].some((legacy) => key.startsWith(legacy));

const parsed = (raw: string): unknown => {
    try {
        return JSON.parse(raw);
    } catch {
        return undefined;
    }
};

/** What the earlier keys held for this user and workspace, in the new item names. */
const movedItems = (
    legacy: Map<string, string>,
    userId: string,
    workspaceId: string | undefined,
): Pick<BrowserStorage, 'user' | 'workspaces'> => {
    const user: Record<string, unknown> = {};
    const workspaces: Record<string, Record<string, unknown>> = {};
    const intoWorkspace = (id: string | undefined, key: string, value: unknown): void => {
        if (id && value !== undefined) {
            workspaces[id] = { ...workspaces[id], [key]: value };
        }
    };

    legacy.forEach((raw, key) => {
        if (key === 'publish.tz') {
            user['publish.tz'] = raw;
        } else if (key === 'publish.showSlots') {
            user['publish.showSlots'] = raw !== 'false';
        } else if (key === 'insights.performanceColumns' || key.startsWith('insights.columns.')) {
            user[key] = parsed(raw);
        } else if (key === `calendar.skipCustomTimeConfirm.${userId}`) {
            user['calendar.skipCustomTimeConfirm'] = raw === 'true';
        } else if (key === 'trypost.emoji.recents') {
            user['emoji.recents'] = parsed(raw);
        } else if (key === 'trypost:instagram:collaborators-notice-seen') {
            user['composer.instagramCollaboratorsNoticeSeen'] = raw === '1';
        } else if (key === 'trypost:composer:last-used-accounts') {
            intoWorkspace(workspaceId, 'composer.lastUsedAccounts', parsed(raw));
        } else if (key === `trypost.command-palette.recent.${userId}`) {
            intoWorkspace(workspaceId, 'commandPalette.recent', parsed(raw));
        } else if (key.startsWith(`trypost:composer:autosave:${userId}:`)) {
            intoWorkspace(key.split(':').pop(), 'composer.autosave', parsed(raw));
        }
    });

    return {
        user: user as Partial<UserStorage>,
        workspaces: workspaces as Record<string, Partial<WorkspaceStorage>>,
    };
};

export const moveLegacyBrowserStorage = (auth: Auth | undefined): void => {
    const userId = auth?.user?.id;

    if (!userId) {
        return;
    }

    try {
        const legacy = new Map(
            Object.keys(window.localStorage)
                .filter(isLegacyKey)
                .map((key) => [key, window.localStorage.getItem(key) ?? '']),
        );

        if (legacy.size === 0) {
            return;
        }

        const moved = movedItems(legacy, userId, auth.currentWorkspace?.id);

        updateBrowserStorage(userId, (storage) => ({
            ...storage,
            user: { ...moved.user, ...storage.user },
            workspaces: Object.fromEntries(
                [...new Set([...Object.keys(moved.workspaces), ...Object.keys(storage.workspaces)])].map(
                    (id) => [id, { ...moved.workspaces[id], ...storage.workspaces[id] }],
                ),
            ),
        }));

        legacy.forEach((_, key) => window.localStorage.removeItem(key));
    } catch {
        return;
    }
};
