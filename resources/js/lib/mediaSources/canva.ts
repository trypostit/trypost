import { useHttp } from '@inertiajs/vue3';

import { create, edit } from '@/routes/app/integrations/canva/designs';
import { show } from '@/routes/app/integrations/canva/returns';
import type { CanvaPresetOption } from '@/types';
import type { MediaItem } from '@/types/media';

import {
    createPopupNonce,
    openSourcePopup,
    waitForPopupResult,
} from './popup';

export interface CanvaResult {
    importId: string;
    replaces: string | null;
}

const POPUP_WIDTH = 1200;
const POPUP_HEIGHT = 800;

export const defaultCanvaPreset = (
    presets: CanvaPresetOption[] | undefined,
): CanvaPresetOption | undefined =>
    presets?.find((preset) => preset.is_default) ?? presets?.[0];

/**
 * Opens Canva in a popup (synchronously, so the click's user gesture keeps
 * it from being blocked) and resolves with the import its return produced,
 * or null on failure, timeout or `signal` abort.
 */
const openCanvaPopup = (
    popupUrl: (nonce: string) => string,
    signal?: AbortSignal,
): Promise<CanvaResult | null> => {
    const nonce = createPopupNonce();
    const popup = openSourcePopup(
        popupUrl(nonce),
        `canva-${nonce}`,
        POPUP_WIDTH,
        POPUP_HEIGHT,
    );

    if (!popup) {
        return Promise.resolve(null);
    }

    const http = useHttp<Record<string, never>, { import_id: string | null; replaces: string | null }>({});

    return waitForPopupResult<CanvaResult>({
        popup,
        source: 'canva',
        nonce,
        signal,
        failureMessage: 'posts.composer.media_sources.errors.canva_export_failed',
        fromMessage: (data) =>
            data.import_id
                ? { importId: data.import_id, replaces: data.replaces ?? null }
                : null,
        fromServer: async () => {
            const result = await http.get(show.url(nonce));

            return result.import_id
                ? { importId: result.import_id, replaces: result.replaces }
                : null;
        },
    });
};

export const openCanva = (
    preset: string,
    signal?: AbortSignal,
): Promise<CanvaResult | null> =>
    openCanvaPopup((nonce) => create.url({ query: { preset, nonce } }), signal);

/** Reopens the design a Canva-made media item came from; the result replaces that item. */
export const editInCanva = (
    mediaId: string,
    signal?: AbortSignal,
): Promise<CanvaResult | null> =>
    openCanvaPopup(
        (nonce) => edit.url({ query: { media: mediaId, nonce } }),
        signal,
    );

/** The Canva design a media item was exported from, if any. */
export const canvaDesignId = (item: MediaItem): string | null => {
    const designId = item.meta?.source_meta?.design_id;

    return typeof designId === 'string' && designId !== '' ? designId : null;
};
