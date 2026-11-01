import { updateBrowserStorage } from '@/lib/browserStorage';
import type { Auth } from '@/types';
import type { UserStorage, WorkspaceStorage } from '@/types/browser-storage';

/**
 * Moves what the app kept in its earlier localStorage keys (#461) into the
 * signed-in user's `trypost:<user id>`, then removes those keys, so nothing is
 * lost and no old draft stays behind. Every earlier key is named exactly, never
 * matched by prefix. A value already in the new storage wins. Waits for a page
 * with a current workspace, where the workspace items have a home. Does
 * nothing once the earlier keys are gone: remove this file a release or two
 * after it ships.
 */

/** An earlier key and the item it becomes; the reader checks the value's shape. */
type LegacyItem<Storage> = {
    [Item in keyof Storage]: {
        legacy: string;
        item: Item;
        value: (raw: string) => Storage[Item] | undefined;
    };
}[keyof Storage];

type LegacyWorkspaceItem = LegacyItem<WorkspaceStorage> & {
    workspaceId: string;
};

/** The networks the earlier `insights.columns.<network>` keys were written for. */
const LEGACY_PLATFORMS = [
    'linkedin',
    'linkedin-page',
    'x',
    'tiktok',
    'youtube',
    'facebook',
    'instagram',
    'instagram-facebook',
    'threads',
    'pinterest',
    'bluesky',
    'mastodon',
    'telegram',
    'discord',
    'google_business',
] as const;

/** An earlier JSON value, typed as the item it becomes: its reader checks the shape before use. */
const parsed = <Value>(raw: string): Value | undefined => {
    try {
        return JSON.parse(raw) as Value;
    } catch {
        return undefined;
    }
};

const legacyUserItems = (userId: string): LegacyItem<UserStorage>[] => [
    { legacy: 'publish.tz', item: 'publish.tz', value: (raw) => raw },
    {
        legacy: 'publish.showSlots',
        item: 'publish.showSlots',
        value: (raw) => raw !== 'false',
    },
    {
        legacy: 'insights.performanceColumns',
        item: 'insights.performanceColumns',
        value: parsed,
    },
    ...LEGACY_PLATFORMS.map(
        (platform): LegacyItem<UserStorage> => ({
            legacy: `insights.columns.${platform}`,
            item: `insights.columns.${platform}`,
            value: parsed,
        }),
    ),
    {
        legacy: `calendar.skipCustomTimeConfirm.${userId}`,
        item: 'calendar.skipCustomTimeConfirm',
        value: (raw) => raw === 'true',
    },
    { legacy: 'trypost.emoji.recents', item: 'emoji.recents', value: parsed },
    {
        legacy: 'trypost:instagram:collaborators-notice-seen',
        item: 'composer.instagramCollaboratorsNoticeSeen',
        value: (raw) => raw === '1',
    },
];

const legacyWorkspaceItems = (
    auth: Auth,
    workspaceId: string,
): LegacyWorkspaceItem[] => [
    {
        workspaceId,
        legacy: 'trypost:composer:last-used-accounts',
        item: 'composer.lastUsedAccounts',
        value: parsed,
    },
    {
        workspaceId,
        legacy: `trypost.command-palette.recent.${auth.user.id}`,
        item: 'commandPalette.recent',
        value: parsed,
    },
    ...auth.workspaces.map(
        (workspace): LegacyWorkspaceItem => ({
            workspaceId: workspace.id,
            legacy: `trypost:composer:autosave:${auth.user.id}:${workspace.id}`,
            item: 'composer.autosave',
            value: parsed,
        }),
    ),
];

const isStored = ({ legacy }: { legacy: string }): boolean =>
    window.localStorage.getItem(legacy) !== null;

/** The items the earlier keys become; each entry's value was typed against its item above. */
const movedItems = <Storage>(
    items: LegacyItem<Storage>[],
): Partial<Storage> =>
    Object.fromEntries(
        items.map(({ legacy, item, value }) => [
            item,
            value(window.localStorage.getItem(legacy) ?? ''),
        ]),
    ) as Partial<Storage>;

export const moveLegacyBrowserStorage = (auth: Auth | undefined): void => {
    const userId = auth?.user?.id;
    const workspaceId = auth?.currentWorkspace?.id;

    if (!auth || !userId || !workspaceId) {
        return;
    }

    try {
        const userItems = legacyUserItems(userId).filter(isStored);
        const workspaceItems = legacyWorkspaceItems(auth, workspaceId).filter(isStored);

        if (userItems.length === 0 && workspaceItems.length === 0) {
            return;
        }

        const user = movedItems(userItems);
        const workspaces = Object.fromEntries(
            [...new Set(workspaceItems.map(({ workspaceId: id }) => id))].map(
                (id) => [
                    id,
                    movedItems(
                        workspaceItems.filter(({ workspaceId: itemWorkspaceId }) => itemWorkspaceId === id),
                    ),
                ],
            ),
        );

        updateBrowserStorage(userId, (storage) => ({
            ...storage,
            user: { ...user, ...storage.user },
            workspaces: Object.fromEntries(
                [...new Set([...Object.keys(workspaces), ...Object.keys(storage.workspaces)])].map(
                    (id) => [id, { ...workspaces[id], ...storage.workspaces[id] }],
                ),
            ),
        }));

        [...userItems, ...workspaceItems].forEach(({ legacy }) =>
            window.localStorage.removeItem(legacy),
        );
    } catch {
        return;
    }
};
