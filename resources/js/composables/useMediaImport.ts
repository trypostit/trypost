import { useHttp } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { getCurrentScope, onScopeDispose } from 'vue';
import { toast } from 'vue-sonner';

import type { MediaUploader } from '@/composables/useMediaUpload';
import { show, store } from '@/routes/app/media/imports';
import type { MediaItem } from '@/types/media';

export type MediaImportPayload = {
    source: string;
} & Record<string, string | number | null | Record<string, string>>;

export interface MediaImportStarted {
    importId: string;
    label: string;
    replaces?: string | null;
}

export interface MediaImportResult {
    item: MediaItem;
    replaces: string | null;
}

interface MediaImportStatus {
    status: 'pending' | 'done' | 'failed';
    media: MediaItem | null;
    reason: string | null;
    replaces: string | null;
}

export const IMPORT_POLL_INTERVAL_MS = 1500;

const FAILURE_REASONS = [
    'unreachable',
    'host_not_allowed',
    'type_not_allowed',
    'too_large',
    'canva_export_failed',
    'session_expired',
    'video_not_ready',
] as const;

/** Lang key for an import failure reason; unknown reasons read as a generic failure. */
export const importErrorKey = (reason: string): string =>
    (FAILURE_REASONS as readonly string[]).includes(reason)
        ? `posts.composer.media_sources.errors.${reason}`
        : 'posts.composer.media_sources.errors.import_failed';

const MAX_POLLS = 800;

const wait = (milliseconds: number): Promise<void> =>
    new Promise((resolve) => window.setTimeout(resolve, milliseconds));

/**
 * Starts a queued import and polls its status until the file is a
 * temporary upload or the import failed. Failures toast; successes are
 * shown by the tile they fill.
 */
export const useMediaImport = () => {
    let disposed = false;

    if (getCurrentScope()) {
        onScopeDispose(() => {
            disposed = true;
        });
    }

    const failed = (): null => {
        if (!disposed) {
            toast.error(trans('posts.composer.media_sources.errors.import_failed'));
        }

        return null;
    };

    /** The imports a pick started, in tile order; empty when it could not start. */
    const run = async (payload: MediaImportPayload): Promise<string[]> => {
        const http = useHttp<MediaImportPayload, { import_ids: string[] }>(
            payload,
        );

        try {
            const response = await http.post(store.url());

            return response.import_ids;
        } catch {
            failed();

            return [];
        }
    };

    const waitFor = async (
        importId: string,
        onFailed?: (reason: string) => void,
        isWanted: () => boolean = () => true,
    ): Promise<MediaImportResult | null> => {
        const fail = (reason: string): null => {
            onFailed?.(reason);

            return failed();
        };
        const http = useHttp<Record<string, never>, MediaImportStatus>({});

        const polling = (): boolean => !disposed && isWanted();

        for (let poll = 0; poll < MAX_POLLS && polling(); poll++) {
            let status: MediaImportStatus;

            try {
                status = await http.get(show.url(importId));
            } catch {
                return fail('unreachable');
            }

            if (!polling()) {
                return null;
            }
            if (status.status === 'done') {
                return status.media
                    ? { item: status.media, replaces: status.replaces }
                    : fail('unreachable');
            }
            if (status.status === 'failed') {
                return fail(status.reason ?? 'unreachable');
            }

            await wait(IMPORT_POLL_INTERVAL_MS);
        }

        return polling() ? fail('unreachable') : null;
    };

    /**
     * Shows a started import as a pending tile of `uploader` and fills or
     * fails that tile when the import settles; a tile removed meanwhile
     * stops the polling.
     */
    const track = (
        uploader: Pick<
            MediaUploader,
            'addPending' | 'resolvePending' | 'imports'
        >,
        { importId, label, replaces = null }: MediaImportStarted,
    ): void => {
        uploader.addPending(importId, label, replaces);
        void waitFor(
            importId,
            (reason) => uploader.resolvePending(importId, null, reason),
            () =>
                uploader.imports.value.some(
                    (entry) =>
                        entry.key === importId && entry.state === 'importing',
                ),
        ).then((result) => {
            if (result) uploader.resolvePending(importId, result.item);
        });
    };

    return { run, waitFor, track };
};
