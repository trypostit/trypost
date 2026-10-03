import {
    computed,
    getCurrentScope,
    onScopeDispose,
    shallowRef,
    toValue,
} from 'vue';
import type { ComputedRef, MaybeRefOrGetter, Ref } from 'vue';

import { isHeic, uploadTypeOf } from '@/lib/mediaType';
import { storeChunked } from '@/routes/app/media';
import type { MediaUploadLimits } from '@/types';
import type { MediaItem } from '@/types/media';
import { ChunkedUploadError, uploadChunked } from '@/utils/chunkedUpload';

export type UploadErrorReason =
    | 'too_large'
    | 'unsupported_type'
    | 'heic_unavailable'
    | 'rejected'
    | 'network'
    | 'server';

export type UploadEntry =
    | {
          key: string;
          state: 'uploading';
          file: File;
          progress: number;
          controller: AbortController;
      }
    | {
          key: string;
          state: 'error';
          file: File;
          reason: UploadErrorReason;
          limitBytes?: number;
          message?: string;
      };

export type PendingImport =
    | { key: string; state: 'importing'; label: string; replaces: string | null }
    | { key: string; state: 'error'; label: string; reason: string };

export interface MediaUploader {
    entries: Ref<UploadEntry[]>;
    imports: Ref<PendingImport[]>;
    busy: ComputedRef<boolean>;
    failed: ComputedRef<boolean>;
    add: (files: File[]) => void;
    retry: (key: string) => void;
    cancel: (key: string) => void;
    remove: (key: string) => void;
    clear: () => void;
    addPending: (
        importId: string,
        label: string,
        replaces?: string | null,
    ) => void;
    resolvePending: (
        importId: string,
        item: MediaItem | null,
        reason?: string,
    ) => void;
}

export const MAX_CONCURRENT_UPLOADS = 3;

const RETRYABLE: readonly UploadErrorReason[] = ['network', 'server'];

export const isRetryable = (reason: UploadErrorReason): boolean =>
    RETRYABLE.includes(reason);

/** One limit for every queue on the page, so the shared and per-channel trays together never run more than three uploads. */
const slots = { active: 0, waiting: new Set<() => void>() };

const releaseSlot = (): void => {
    slots.active = Math.max(0, slots.active - 1);
    [...slots.waiting].forEach((pump) => pump());
};

let sequence = 0;

const nextKey = (): string => `upload-${++sequence}`;

const uploading = (key: string, file: File): UploadEntry => ({
    key,
    state: 'uploading',
    file,
    progress: 0,
    controller: new AbortController(),
});

/**
 * Owns one upload queue: every file is checked against the shared limits
 * before anything is sent, at most three uploads run at once across all
 * queues, and finished items are handed to `onReady` in the order the files
 * were added.
 */
export const useMediaUpload = (options: {
    limits: MaybeRefOrGetter<MediaUploadLimits>;
    onReady: (item: MediaItem, key: string, replaces?: string | null) => void;
}): MediaUploader => {
    const entries = shallowRef<UploadEntry[]>([]);
    const imports = shallowRef<PendingImport[]>([]);
    const active = new Set<string>();
    const finished = new Map<string, MediaItem>();

    const find = (key: string): UploadEntry | undefined =>
        entries.value.find((entry) => entry.key === key);

    const replace = (key: string, next: UploadEntry | null): void => {
        entries.value = entries.value.flatMap((entry) =>
            entry.key === key ? (next ? [next] : []) : [entry],
        );
    };

    const entryFor = (key: string, file: File): UploadEntry => {
        const limits = toValue(options.limits);

        if (isHeic(file) && !limits.heic) {
            return { key, state: 'error', file, reason: 'heic_unavailable' };
        }

        const type = uploadTypeOf(file, limits.extensions);
        if (!type) {
            return { key, state: 'error', file, reason: 'unsupported_type' };
        }

        const limitBytes = limits.max_bytes[type];
        if (file.size > limitBytes) {
            return {
                key,
                state: 'error',
                file,
                reason: 'too_large',
                limitBytes,
            };
        }

        return uploading(key, file);
    };

    const flush = (): void => {
        for (const entry of [...entries.value]) {
            if (entry.state === 'error') continue;

            const item = finished.get(entry.key);
            if (!item) return;

            finished.delete(entry.key);
            replace(entry.key, null);
            options.onReady(item, entry.key);
        }
    };

    const setProgress = (key: string, progress: number): void => {
        entries.value = entries.value.map((entry) =>
            entry.key === key && entry.state === 'uploading'
                ? { ...entry, progress }
                : entry,
        );
    };

    const failure = (
        entry: UploadEntry,
        error: unknown,
    ): Extract<UploadEntry, { state: 'error' }> => {
        const base = { key: entry.key, state: 'error' as const, file: entry.file };

        if (error instanceof TypeError) {
            return { ...base, reason: 'network' };
        }
        if (
            error instanceof ChunkedUploadError &&
            error.status === 422 &&
            error.serverMessage
        ) {
            return { ...base, reason: 'rejected', message: error.serverMessage };
        }

        return { ...base, reason: 'server' };
    };

    const start = async (
        entry: Extract<UploadEntry, { state: 'uploading' }>,
    ): Promise<void> => {
        active.add(entry.key);

        try {
            const uploaded = await uploadChunked({
                file: entry.file,
                url: storeChunked.url(),
                signal: entry.controller.signal,
                onProgress: (progress) => setProgress(entry.key, progress),
            });
            if (!uploaded.id || !uploaded.url) {
                throw new Error('Incomplete upload');
            }

            finished.set(entry.key, {
                id: uploaded.id,
                path: uploaded.path,
                url: uploaded.url,
                type: uploaded.type,
                mime_type: uploaded.mime_type ?? entry.file.type,
                original_filename:
                    uploaded.original_filename ?? entry.file.name,
                size: uploaded.size ?? entry.file.size,
                meta: uploaded.meta,
                upload_token: uploaded.upload_token ?? null,
                created_at: uploaded.created_at,
            });
        } catch (error) {
            if (!entry.controller.signal.aborted && find(entry.key)) {
                replace(entry.key, failure(entry, error));
            }
        } finally {
            active.delete(entry.key);
            flush();
            releaseSlot();
        }
    };

    const pump = (): void => {
        for (const entry of entries.value) {
            if (slots.active >= MAX_CONCURRENT_UPLOADS) return;
            if (
                entry.state === 'uploading' &&
                !active.has(entry.key) &&
                !finished.has(entry.key)
            ) {
                slots.active++;
                void start(entry);
            }
        }
    };

    const add = (files: File[]): void => {
        entries.value = [
            ...entries.value,
            ...files.map((file) => entryFor(nextKey(), file)),
        ];
        pump();
    };

    const retry = (key: string): void => {
        const entry = find(key);
        if (entry?.state !== 'error' || !isRetryable(entry.reason)) return;

        replace(key, uploading(key, entry.file));
        pump();
    };

    const finishedImports = new Map<string, MediaItem>();

    /** Hands finished imports to `onReady` in the order they were started. */
    const flushImports = (): void => {
        for (const entry of [...imports.value]) {
            if (entry.state === 'error') continue;

            const item = finishedImports.get(entry.key);
            if (!item) return;

            finishedImports.delete(entry.key);
            imports.value = imports.value.filter(
                (pending) => pending.key !== entry.key,
            );
            options.onReady(item, entry.key, entry.replaces);
        }
    };

    const addPending = (
        importId: string,
        label: string,
        replaces: string | null = null,
    ): void => {
        imports.value = [
            ...imports.value,
            { key: importId, state: 'importing', label, replaces },
        ];
    };

    const resolvePending = (
        importId: string,
        item: MediaItem | null,
        reason = 'import_failed',
    ): void => {
        const pending = imports.value.find((entry) => entry.key === importId);
        if (pending?.state !== 'importing') return;

        if (item) {
            finishedImports.set(importId, item);
            flushImports();

            return;
        }

        imports.value = imports.value.map((entry) =>
            entry.key === importId
                ? { key: importId, state: 'error', label: entry.label, reason }
                : entry,
        );
        flushImports();
    };

    const cancel = (key: string): void => {
        if (imports.value.some((entry) => entry.key === key)) {
            finishedImports.delete(key);
            imports.value = imports.value.filter((entry) => entry.key !== key);
            flushImports();

            return;
        }

        const entry = find(key);
        if (!entry) return;

        if (entry.state === 'uploading') {
            entry.controller.abort();
        }
        finished.delete(key);
        replace(key, null);
        flush();
        pump();
    };

    const clear = (): void => {
        for (const entry of entries.value) {
            if (entry.state === 'uploading') entry.controller.abort();
        }
        finished.clear();
        finishedImports.clear();
        entries.value = [];
        imports.value = [];
    };

    const busy = computed(
        () =>
            entries.value.some((entry) => entry.state === 'uploading') ||
            imports.value.some((entry) => entry.state === 'importing'),
    );
    const failed = computed(
        () =>
            entries.value.some((entry) => entry.state === 'error') ||
            imports.value.some((entry) => entry.state === 'error'),
    );

    slots.waiting.add(pump);
    if (getCurrentScope()) {
        onScopeDispose(() => {
            slots.waiting.delete(pump);
            clear();
        });
    }

    return {
        entries,
        imports,
        busy,
        failed,
        add,
        retry,
        cancel,
        remove: cancel,
        clear,
        addPending,
        resolvePending,
    };
};
