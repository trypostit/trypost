import { useHttp } from '@inertiajs/vue3';
import { useConnectionStatus } from '@laravel/echo-vue';
import { trans } from 'laravel-vue-i18n';
import { getCurrentScope, onScopeDispose, watch } from 'vue';
import { toast } from 'vue-sonner';

import { useWorkspaceEcho } from '@/composables/echo/useWorkspaceEcho';
import type { MediaUploader } from '@/composables/useMediaUpload';
import { filled } from '@/lib/helpers';
import { extractErrorMessage } from '@/lib/httpError';
import { show, store } from '@/routes/app/media/imports';
import type { MediaItem } from '@/types/media';

/** The fields a request that starts an import sends. */
export type MediaImportRequest = Record<
    string,
    string | number | null | Record<string, string>
>;

export type MediaImportPayload = {
    source: string;
} & MediaImportRequest;

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

/** Why a failed import failed; an import without a reason could not be reached. */
const failureReason = (status: MediaImportStatus): string => {
    if (filled(status.reason)) {
        return status.reason;
    }

    return 'unreachable';
};

/** What the workspace channel says when one of its imports settled. */
interface MediaImportSettled {
    import_id: string;
}

/**
 * Why an import could not start: the first validation error the server
 * gave, else its message, else a generic failure.
 */
const startFailureMessage = (
    errors: Record<string, string>,
    exception: unknown,
): string => {
    const [validationError] = Object.values(errors);

    if (filled(validationError)) {
        return validationError;
    }

    const message = extractErrorMessage(exception);

    if (filled(message)) {
        return message;
    }

    return trans('posts.composer.media_sources.errors.import_failed');
};

/**
 * Starts a queued import (a picker source by default, or another endpoint
 * that queues one). The ids of the imports it started, in tile order; empty
 * when it could not start, which toasts why.
 */
export const startMediaImport = async (
    payload: MediaImportRequest,
    url: string = store.url(),
): Promise<string[]> => {
    const http = useHttp<MediaImportRequest, { import_ids: string[] }>(
        payload,
    );

    try {
        const response = await http.post(url);

        return response.import_ids;
    } catch (exception) {
        toast.error(startFailureMessage(http.errors, exception));

        return [];
    }
};

/**
 * Waits for started imports until each file is a temporary upload or the
 * import failed. The job tells the workspace channel when an import
 * settled (`MediaImportSettled`), and only then is its status read; it is
 * also read once right after the tile appears, in case the job finished
 * before the composer heard of the import, and again whenever the socket
 * connects anew, since an event sent while it was down never arrives.
 * Failures toast; successes are shown by the tile they fill.
 */
export const useMediaImport = () => {
    let disposed = false;
    const waiting = new Map<string, () => void>();

    const wake = (importId: string): void => {
        const resume = waiting.get(importId);

        if (resume === undefined) {
            return;
        }

        waiting.delete(importId);
        resume();
    };

    useWorkspaceEcho<MediaImportSettled>('.media.import.settled', (payload) =>
        wake(payload.import_id),
    );

    watch(useConnectionStatus(), (status) => {
        if (status !== 'connected') {
            return;
        }

        [...waiting.keys()].forEach(wake);
    });

    if (getCurrentScope()) {
        onScopeDispose(() => {
            disposed = true;
            [...waiting.keys()].forEach(wake);
        });
    }

    const settledOnce = (importId: string): Promise<void> =>
        new Promise((resolve) => waiting.set(importId, resolve));

    const waitFor = async (
        importId: string,
        onFailed: (reason: string) => void,
        isWanted: () => boolean,
    ): Promise<MediaImportResult | null> => {
        const fail = (reason: string): null => {
            onFailed(reason);

            if (!disposed) {
                toast.error(
                    trans('posts.composer.media_sources.errors.import_failed'),
                );
            }

            return null;
        };
        const http = useHttp<Record<string, never>, MediaImportStatus>({});
        const stillWanted = (): boolean => {
            if (disposed) {
                return false;
            }

            return isWanted();
        };

        while (stillWanted()) {
            const settled = settledOnce(importId);
            let status: MediaImportStatus;

            try {
                status = await http.get(show.url(importId));
            } catch {
                waiting.delete(importId);

                return fail('unreachable');
            }

            if (!stillWanted()) {
                waiting.delete(importId);

                return null;
            }
            if (status.status === 'done') {
                waiting.delete(importId);

                if (filled(status.media)) {
                    return { item: status.media, replaces: status.replaces };
                }

                return fail('unreachable');
            }
            if (status.status === 'failed') {
                waiting.delete(importId);

                return fail(failureReason(status));
            }

            await settled;
        }

        return null;
    };

    /**
     * Shows a started import as a pending tile of `uploader` and fills or
     * fails that tile when the import settles; a tile removed meanwhile
     * stops waiting for it. Resolves once the tile settled.
     */
    const track = (
        uploader: Pick<
            MediaUploader,
            'addPending' | 'resolvePending' | 'imports'
        >,
        { importId, label, replaces = null }: MediaImportStarted,
    ): Promise<void> => {
        uploader.addPending(importId, label, replaces);

        return waitFor(
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

    return { track };
};
