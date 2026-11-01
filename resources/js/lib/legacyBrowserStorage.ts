import { updateBrowserStorage } from '@/lib/browserStorage';
import type { Auth } from '@/types';
import type { UserStorage, WorkspaceStorage } from '@/types/browser-storage';
import { Platform } from '@/types/platform';

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
    ...Object.values(Platform).map(
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

const legacyDraft = (
    userId: string,
    workspaceId: string,
): LegacyItem<WorkspaceStorage> => ({
    legacy: `trypost:composer:autosave:${userId}:${workspaceId}`,
    item: 'composer.autosave',
    value: parsed,
});

/** The earlier keys by the workspace they move into: each workspace's draft, and what was kept for every workspace into the current one. */
const legacyWorkspaceItems = (
    auth: Auth,
    workspaceId: string,
): Record<string, LegacyItem<WorkspaceStorage>[]> => ({
    ...Object.fromEntries(
        auth.workspaces.map((workspace) => [
            workspace.id,
            [legacyDraft(auth.user.id, workspace.id)],
        ]),
    ),
    [workspaceId]: [
        {
            legacy: 'trypost:composer:last-used-accounts',
            item: 'composer.lastUsedAccounts',
            value: parsed,
        },
        {
            legacy: `trypost.command-palette.recent.${auth.user.id}`,
            item: 'commandPalette.recent',
            value: parsed,
        },
        legacyDraft(auth.user.id, workspaceId),
    ],
});

const isStored = ({ legacy }: { legacy: string }): boolean =>
    window.localStorage.getItem(legacy) !== null;

/** The items the earlier keys become; each entry's value was typed against its item above. */
const movedItems = <Storage>(items: LegacyItem<Storage>[]): Partial<Storage> =>
    Object.fromEntries(
        items.map(({ legacy, item, value }) => [
            item,
            value(window.localStorage.getItem(legacy) ?? ''),
        ]),
    ) as Partial<Storage>;

export const moveLegacyBrowserStorage = (auth: Auth | undefined): void => {
    const workspaceId = auth?.currentWorkspace?.id;

    if (!auth?.user || !workspaceId) {
        return;
    }

    try {
        const userItems = legacyUserItems(auth.user.id).filter(isStored);
        const workspaceItems = Object.entries(
            legacyWorkspaceItems(auth, workspaceId),
        )
            .map(([id, items]) => [id, items.filter(isStored)] as const)
            .filter(([, items]) => items.length > 0);

        if (userItems.length === 0 && workspaceItems.length === 0) {
            return;
        }

        updateBrowserStorage(auth.user.id, (storage) => ({
            ...storage,
            user: { ...movedItems(userItems), ...storage.user },
            workspaces: {
                ...storage.workspaces,
                ...Object.fromEntries(
                    workspaceItems.map(([id, items]) => [
                        id,
                        { ...movedItems(items), ...storage.workspaces[id] },
                    ]),
                ),
            },
        }));

        [...userItems, ...workspaceItems.flatMap(([, items]) => items)].forEach(
            ({ legacy }) => window.localStorage.removeItem(legacy),
        );
    } catch {
        return;
    }
};
