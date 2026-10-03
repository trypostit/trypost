import { useHttp } from '@inertiajs/vue3';

import { start } from '@/routes/app/integrations/google';
import { claim } from '@/routes/app/integrations/google/returns';

import {
    createPopupNonce,
    openSourcePopup,
    waitForPopupResult,
} from './popup';

export type GoogleMediaSource = 'google_drive' | 'google_photos';

export interface GoogleSignIn<T> {
    popup: Window;
    result: Promise<T | null>;
}

/**
 * Opens Google's sign-in for one source in a popup (synchronously, inside
 * the click, so pop-up blockers let it through). The server runs the OAuth
 * flow and keeps the result under the popup's nonce; once the popup says it
 * is done, the result is claimed from the server exactly once. Null when
 * the browser blocked the popup (a toast says so).
 */
export const signInWithGoogle = <T>(
    source: GoogleMediaSource,
    size: { width: number; height: number },
    signal?: AbortSignal,
): GoogleSignIn<T> | null => {
    const nonce = createPopupNonce();
    const popup = openSourcePopup(
        start.url({ query: { source, nonce } }),
        `${source}-${nonce}`,
        size.width,
        size.height,
    );

    if (!popup) {
        return null;
    }

    const http = useHttp<Record<string, never>, T>({});

    return {
        popup,
        result: waitForPopupResult<T>({
            popup,
            source,
            nonce,
            signal,
            failureMessage:
                'posts.composer.media_sources.errors.google_connect_failed',
            fromMessage: () => null,
            fromServer: () => http.post(claim.url(nonce)),
        }),
    };
};
