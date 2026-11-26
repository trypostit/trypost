import { usePage } from '@inertiajs/vue3';
import { computed, getCurrentScope, onScopeDispose, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';

import { mediaUploadLimits } from '@/composables/useMediaUpload';
import dayjs from '@/dayjs';
import {
    readWorkspaceItem,
    updateBrowserStorage,
    writeWorkspaceItem,
} from '@/lib/browserStorage';
import { filled } from '@/lib/helpers';
import { moveLegacyBrowserStorage } from '@/lib/legacyBrowserStorage';
import { asAddedFile } from '@/lib/mediaEdits';
import { classify, MediaType } from '@/lib/mediaType';
import type { SharedData } from '@/types';
import type { SidebarChannel } from '@/types/channel';
import type { MediaItem, MediaSource, SourceMetaValue } from '@/types/media';
import type {
    ComposerAccount,
    DestinationOverride,
} from '@/types/post-composition';

export const AUTOSAVE_DEBOUNCE_MS = 800;

/**
 * Bumped each time this tab writes or drops a draft, and when another tab
 * changes the browser storage, so `useHasUnfinishedPost` reads it again: the
 * drafts are written through the storage lib, which tells no ref of this tab.
 */
const draftChanges = ref(0);

const draftChanged = (): void => {
    draftChanges.value += 1;
};

/** Read inside a computed, it makes the computed read the draft again on every change. */
const trackDraftChanges = (): void => {
    void draftChanges.value;
};

let followsOtherTabs = false;

/** Listens once per tab for a draft written or dropped in another tab. */
const followOtherTabs = (): void => {
    if (followsOtherTabs) {
        return;
    }

    followsOtherTabs = true;
    window.addEventListener('storage', draftChanged);
};

export interface AutosaveMediaRef {
    id: string;
    upload_token?: string | null;
    created_at?: string;
    url: string;
    path?: string;
    type: MediaType;
    mime_type?: string | null;
    original_filename?: string | null;
    source?: MediaSource;
    source_meta?: Record<string, SourceMetaValue>;
    meta?: Record<string, unknown> | null;
    /** The item as it was added, before the media editor changed it for a network. */
    original?: AutosaveMediaRef;
}

export type AutosaveOverride = Omit<DestinationOverride, 'media' | 'media_edits'> & {
    media?: AutosaveMediaRef[];
    media_edits?: AutosaveMediaRef[];
};

/** The shape of a saved draft; a draft of another version is not offered back. */
export const AUTOSAVE_VERSION = 1;

export interface AutosaveSnapshot {
    version: typeof AUTOSAVE_VERSION;
    savedAt: string;
    content: string;
    overrides: Record<string, AutosaveOverride>;
    accountIds: string[];
    media: AutosaveMediaRef[];
    labelIds: string[];
    scheduleMode: string | null;
    /** The custom time as a UTC instant; older snapshots hold a composer-zone wall clock. */
    scheduledAt: string | null;
}


export const toAutosaveMedia = (item: MediaItem): AutosaveMediaRef => {
    const stored = storedMediaRef(item);

    if (filled(item.original)) {
        stored.original = toAutosaveMedia(item.original);
    }

    return stored;
};

const storedMediaRef = (item: MediaItem): AutosaveMediaRef => ({
    id: item.id,
    upload_token: item.upload_token ?? null,
    ...(item.created_at ? { created_at: item.created_at } : {}),
    url: item.url,
    ...(item.path ? { path: item.path } : {}),
    type: classify(item) ?? MediaType.Image,
    mime_type: item.mime_type ?? null,
    original_filename: item.original_filename ?? null,
    meta: item.meta ?? null,
    ...(item.source ? { source: item.source } : {}),
    ...(item.source_meta ? { source_meta: item.source_meta } : {}),
});

export const fromAutosaveMedia = (media: AutosaveMediaRef): MediaItem => {
    const restored = restoredMediaItem(media);

    if (filled(media.original)) {
        restored.original = fromAutosaveMedia(media.original);
    }

    return restored;
};

const restoredMediaItem = (media: AutosaveMediaRef): MediaItem => ({
    id: media.id,
    url: media.url,
    ...(media.path ? { path: media.path } : {}),
    type: media.type,
    ...(media.mime_type ? { mime_type: media.mime_type } : {}),
    ...(media.original_filename
        ? { original_filename: media.original_filename }
        : {}),
    upload_token: media.upload_token ?? null,
    ...(media.created_at ? { created_at: media.created_at } : {}),
    ...(media.meta ? { meta: media.meta as MediaItem['meta'] } : {}),
    ...(media.source ? { source: media.source } : {}),
    ...(media.source_meta ? { source_meta: media.source_meta } : {}),
});

export const isExpiredUpload = (
    media: AutosaveMediaRef,
    retentionHours: number,
): boolean =>
    Boolean(media.upload_token) &&
    Boolean(media.created_at) &&
    retentionHours > 0 &&
    dayjs(media.created_at).add(retentionHours, 'hour').isBefore(dayjs());

/** A network's override as the draft keeps it: its media lists as stored references. */
export const toAutosaveOverride = ({
    media,
    media_edits: mediaEdits,
    ...override
}: DestinationOverride): AutosaveOverride => {
    const stored: AutosaveOverride = { ...override };

    if (Array.isArray(media)) {
        stored.media = media.map(toAutosaveMedia);
    }

    if (Array.isArray(mediaEdits)) {
        stored.media_edits = mediaEdits.map(toAutosaveMedia);
    }

    return stored;
};

/**
 * The media a saved draft brings back, the shared items and each network's
 * override, with no upload that expired: a shared item whose upload expired
 * gives its place to the live file a network edited it into (as added), else
 * to its own live original; an edit whose original expired points at the file
 * that took the original's place, else starts from its own file. The shared
 * items are restored first, since the overrides read what replaced them.
 */
export const restoreSnapshotMedia = (
    snapshot: AutosaveSnapshot,
    retentionHours: number,
): { media: MediaItem[]; overrides: Record<string, DestinationOverride> } => {
    const isLive = (item: AutosaveMediaRef): boolean =>
        !isExpiredUpload(item, retentionHours);
    const replacements = new Map<string, MediaItem>();
    const liveEdits = Object.values(snapshot.overrides)
        .flatMap((override) => override.media_edits ?? [])
        .filter(isLive);

    const withLiveOriginal = (item: AutosaveMediaRef): MediaItem => {
        const restored = fromAutosaveMedia(item);

        if (!filled(item.original)) {
            return restored;
        }

        if (isLive(item.original)) {
            return restored;
        }

        const replacement = replacements.get(item.original.id);

        if (filled(replacement)) {
            return { ...restored, original: replacement };
        }

        return { ...restored, original: asAddedFile(restored) };
    };

    const restoredShared = (item: AutosaveMediaRef): MediaItem[] => {
        if (isLive(item)) {
            return [withLiveOriginal(item)];
        }

        const edit = liveEdits.find((candidate) => candidate.original?.id === item.id);

        if (filled(edit)) {
            const replacement = asAddedFile(fromAutosaveMedia(edit));
            replacements.set(item.id, replacement);

            return [replacement];
        }

        if (filled(item.original) && isLive(item.original)) {
            return [asAddedFile(fromAutosaveMedia(item.original))];
        }

        return [];
    };

    const restoreMedia = (items: AutosaveMediaRef[]): MediaItem[] =>
        items.filter(isLive).map(withLiveOriginal);

    const restoredOverride = ({
        media,
        media_edits: mediaEdits,
        ...override
    }: AutosaveOverride): DestinationOverride => {
        const restored: DestinationOverride = { ...override };

        if (Array.isArray(media)) {
            restored.media = restoreMedia(media);
        }

        if (Array.isArray(mediaEdits)) {
            restored.media_edits = restoreMedia(mediaEdits);
        }

        return restored;
    };

    const media = snapshot.media.flatMap(restoredShared);

    return {
        media,
        overrides: Object.fromEntries(
            Object.entries(snapshot.overrides).map(([id, override]) => [id, restoredOverride(override)]),
        ),
    };
};

/** Drops the draft of one workspace, once its post is saved. */
export const clearComposerAutosave = (
    userId: string | undefined,
    workspaceId: string | undefined,
): void => {
    if (!userId || !workspaceId) {
        return;
    }

    writeWorkspaceItem(userId, workspaceId, 'composer.autosave', undefined);
    draftChanged();
};

/** Drops the user's drafts in every workspace, on logout; their other items stay. */
export const clearAllComposerAutosaves = (userId: string): void => {
    updateBrowserStorage(userId, (storage) => ({
        ...storage,
        workspaces: Object.fromEntries(
            Object.entries(storage.workspaces).map(([workspaceId, items]) => [
                workspaceId,
                { ...items, 'composer.autosave': undefined },
            ]),
        ),
    }));
    draftChanged();
};

const isSnapshot = (value: unknown): value is AutosaveSnapshot => {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    const snapshot = value as Partial<AutosaveSnapshot>;

    return (
        snapshot.version === AUTOSAVE_VERSION &&
        typeof snapshot.content === 'string' &&
        Array.isArray(snapshot.accountIds) &&
        Array.isArray(snapshot.media) &&
        Array.isArray(snapshot.labelIds) &&
        typeof snapshot.overrides === 'object' &&
        snapshot.overrides !== null
    );
};

const read = (
    userId: string,
    workspaceId: string | undefined,
): AutosaveSnapshot | null => {
    if (!workspaceId) {
        return null;
    }

    const stored = readWorkspaceItem(userId, workspaceId, 'composer.autosave');

    if (isSnapshot(stored)) {
        return stored;
    }

    return null;
};

/** The unfinished new post of the current workspace, saved in this browser as it is written. */
export const useComposerAutosave = (): {
    saved: Ref<AutosaveSnapshot | null>;
    save: (snapshot: AutosaveSnapshot) => void;
    flush: () => void;
    clear: () => void;
} => {
    const { auth } = usePage<SharedData>().props;
    moveLegacyBrowserStorage(auth);

    const userId = auth.user.id;
    const workspaceId = auth.currentWorkspace?.id;
    const saved = ref<AutosaveSnapshot | null>(read(userId, workspaceId));
    let pending: AutosaveSnapshot | null = null;
    let timer: ReturnType<typeof setTimeout> | null = null;

    const write = (snapshot: AutosaveSnapshot): void => {
        if (workspaceId) {
            writeWorkspaceItem(userId, workspaceId, 'composer.autosave', snapshot);
            draftChanged();
        }
    };

    const cancel = (): void => {
        if (timer) clearTimeout(timer);
        timer = null;
        pending = null;
    };

    const flush = (): void => {
        const snapshot = pending;
        cancel();
        if (snapshot) write(snapshot);
    };

    const save = (snapshot: AutosaveSnapshot): void => {
        pending = snapshot;
        if (timer) clearTimeout(timer);
        timer = setTimeout(flush, AUTOSAVE_DEBOUNCE_MS);
    };

    const clear = (): void => {
        cancel();
        saved.value = null;
        clearComposerAutosave(userId, workspaceId);
    };

    if (getCurrentScope()) {
        onScopeDispose(flush);
    }

    return { saved, save, flush, clear };
};

/**
 * What the "resume your unfinished post" dialog shows of an autosaved post:
 * its channels that still exist, its media still within the upload
 * retention, and its first line of text.
 */
export const useAutosavePreview = (
    saved: Ref<AutosaveSnapshot | null>,
    accounts: () => ComposerAccount[],
): {
    accounts: ComputedRef<ComposerAccount[]>;
    mediaCount: ComputedRef<number>;
    preview: ComputedRef<string>;
} => ({
    accounts: computed(() =>
        (saved.value?.accountIds ?? []).flatMap((id) => {
            const account = accounts().find((candidate) => candidate.id === id);

            return account ? [account] : [];
        }),
    ),
    mediaCount: computed(
        () =>
            (saved.value?.media ?? []).filter(
                (item) =>
                    !isExpiredUpload(
                        item,
                        mediaUploadLimits().upload_retention_hours,
                    ),
            ).length,
    ),
    preview: computed(
        () =>
            (saved.value?.content ?? '')
                .split('\n')
                .map((line) => line.trim())
                .find(Boolean) ?? '',
    ),
});

/**
 * Whether a saved draft holds what the resume dialog offers back
 * (`useAutosavePreview`): text, a channel that still exists, or media still
 * within the upload retention.
 */
const isUnfinished = (
    snapshot: AutosaveSnapshot | null,
    channelIds: string[],
): boolean => {
    if (!filled(snapshot)) {
        return false;
    }

    if (filled(snapshot.content.trim())) {
        return true;
    }

    if (snapshot.accountIds.some((id) => channelIds.includes(id))) {
        return true;
    }

    const retentionHours = mediaUploadLimits().upload_retention_hours;

    return snapshot.media.some((item) => !isExpiredUpload(item, retentionHours));
};

/**
 * Whether the current workspace has a new post left unfinished in this
 * browser, the one the composer offers to resume: the sidebar's New button
 * marks it. It follows the drafts written and dropped in this tab and others.
 */
export const useHasUnfinishedPost = (): ComputedRef<boolean> => {
    const page = usePage<SharedData>();
    followOtherTabs();

    return computed((): boolean => {
        const { auth } = page.props;
        const channels = (page.props.channels ?? []) as SidebarChannel[];
        trackDraftChanges();

        return isUnfinished(
            read(auth.user.id, auth.currentWorkspace?.id),
            channels.map((channel) => channel.id),
        );
    });
};
