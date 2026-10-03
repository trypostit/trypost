import { getCurrentScope, onScopeDispose, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';

import type { DestinationOverride } from '@/composables/usePostComposition';
import dayjs from '@/dayjs';
import { classify, MediaType } from '@/lib/mediaType';
import type { MediaItem, MediaSource, SourceMetaValue } from '@/types/media';

export const AUTOSAVE_DEBOUNCE_MS = 800;

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
}

export type AutosaveOverride = Omit<DestinationOverride, 'media'> & {
    media?: AutosaveMediaRef[];
};

export interface AutosaveSnapshot {
    version: 1;
    savedAt: string;
    content: string;
    overrides: Record<string, AutosaveOverride>;
    accountIds: string[];
    media: AutosaveMediaRef[];
    labelIds: string[];
    scheduleMode: string | null;
    scheduledAt: string | null;
}

const AUTOSAVE_PREFIX = 'trypost:composer:autosave:';

export const composerAutosaveKey = (
    userId: string | number | null | undefined,
    workspaceId: string | number | null | undefined,
): string => `${AUTOSAVE_PREFIX}${userId ?? ''}:${workspaceId ?? ''}`;

export const toAutosaveMedia = (item: MediaItem): AutosaveMediaRef => ({
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

export const fromAutosaveMedia = (media: AutosaveMediaRef): MediaItem => ({
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

export const clearComposerAutosave = (
    userId: string | number | null | undefined,
    workspaceId: string | number | null | undefined,
): void => {
    try {
        localStorage.removeItem(composerAutosaveKey(userId, workspaceId));
    } catch {
        return;
    }
};

export const clearAllComposerAutosaves = (): void => {
    try {
        Object.keys(localStorage)
            .filter((key) => key.startsWith(AUTOSAVE_PREFIX))
            .forEach((key) => localStorage.removeItem(key));
    } catch {
        return;
    }
};

const isSnapshot = (value: unknown): value is AutosaveSnapshot =>
    typeof value === 'object' &&
    value !== null &&
    (value as AutosaveSnapshot).version === 1 &&
    typeof (value as AutosaveSnapshot).content === 'string' &&
    Array.isArray((value as AutosaveSnapshot).accountIds) &&
    Array.isArray((value as AutosaveSnapshot).media) &&
    Array.isArray((value as AutosaveSnapshot).labelIds) &&
    typeof (value as AutosaveSnapshot).overrides === 'object' &&
    (value as AutosaveSnapshot).overrides !== null;

const read = (key: string): AutosaveSnapshot | null => {
    try {
        const parsed: unknown = JSON.parse(localStorage.getItem(key) ?? 'null');

        return isSnapshot(parsed) ? parsed : null;
    } catch {
        return null;
    }
};

export const useComposerAutosave = (
    key: ComputedRef<string>,
): {
    saved: Ref<AutosaveSnapshot | null>;
    save: (snapshot: AutosaveSnapshot) => void;
    flush: () => void;
    clear: () => void;
} => {
    const saved = ref<AutosaveSnapshot | null>(read(key.value));
    let pending: AutosaveSnapshot | null = null;
    let timer: ReturnType<typeof setTimeout> | null = null;

    const write = (snapshot: AutosaveSnapshot): void => {
        try {
            localStorage.setItem(key.value, JSON.stringify(snapshot));
        } catch {
            return;
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
        try {
            localStorage.removeItem(key.value);
        } catch {
            return;
        }
    };

    if (getCurrentScope()) {
        onScopeDispose(flush);
    }

    return { saved, save, flush, clear };
};
