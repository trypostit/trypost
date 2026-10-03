import { trans } from 'laravel-vue-i18n';
import { toast } from 'vue-sonner';

export interface PopupResult {
    type?: string;
    source?: string;
    nonce?: string | null;
    success?: boolean;
    import_id?: string | null;
    replaces?: string | null;
    message?: string | null;
}

/** Same-origin channel the popup page reports on; also read by MediaSourcePopup.vue. */
export const MEDIA_SOURCE_POPUP_CHANNEL = 'trypost.media-source-popup';

const FAST_POLL_MS = 5000;
const FAST_POLL_WINDOW_MS = 2 * 60 * 1000;
const SLOW_POLL_MS = 30 * 1000;
const RATE_LIMIT_BACKOFF_MS = 60 * 1000;
const MAX_RATE_LIMIT_BACKOFF_MS = 5 * 60 * 1000;
const WAIT_LIMIT_MS = 60 * 60 * 1000;
const TOO_MANY_REQUESTS = 429;

const responseStatus = (error: unknown): number | undefined =>
    (error as { response?: { status?: number } } | null)?.response?.status;

export const createPopupNonce = (): string => {
    const bytes = crypto.getRandomValues(new Uint8Array(24));

    return btoa(String.fromCharCode(...bytes))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
};

/** Opens a centred popup; null (after a toast) when the browser blocked it. */
export const openSourcePopup = (
    url: string,
    name: string,
    width: number,
    height: number,
): Window | null => {
    const left = window.screenX + Math.max(0, (window.outerWidth - width) / 2);
    const top = window.screenY + Math.max(0, (window.outerHeight - height) / 2);
    const popup = window.open(
        url,
        name,
        `width=${width},height=${height},left=${left},top=${top},scrollbars=yes,resizable=yes`,
    );

    if (!popup) {
        toast.error(trans('posts.composer.media_sources.errors.popup_blocked'));
    }

    return popup;
};

export interface PopupWait<T> {
    popup: Window;
    source: string;
    nonce: string;
    failureMessage: string;
    /** The result a success message carries, or null to claim it from the server. */
    fromMessage: (data: PopupResult) => T | null;
    /** The result the server holds for this nonce; null or a throw while it has none. */
    fromServer: () => Promise<T | null>;
    signal?: AbortSignal;
}

/**
 * Resolves with what the popup produced, or null on failure, timeout or
 * `signal` abort.
 *
 * A provider's pages may set `Cross-Origin-Opener-Policy`, which severs the
 * popup from this window: `popup.closed` then reads true while the user is
 * still there, and the popup has no `opener` to post to. So the popup's
 * result is matched by a nonce and accepted from `postMessage`, a
 * same-origin BroadcastChannel or a `storage` event, and once the popup
 * looks closed the server is asked for it as a last resort: every 5 s for
 * the first two minutes, then every 30 s, backing off further while the
 * server answers 429, and never past an hour.
 */
export const waitForPopupResult = <T>(options: PopupWait<T>): Promise<T | null> =>
    new Promise((resolve) => {
        const { popup, source, nonce, signal } = options;
        let settled = false;
        let polling = false;
        let claimable = false;
        let pollTimer = 0;
        let limitTimer = 0;
        let rateLimitBackoff = 0;
        const startedAt = Date.now();
        const channel =
            'BroadcastChannel' in window
                ? new BroadcastChannel(MEDIA_SOURCE_POPUP_CHANNEL)
                : null;

        const finish = (result: T | null): void => {
            if (settled) {
                return;
            }
            settled = true;
            window.removeEventListener('message', onMessage);
            window.removeEventListener('storage', onStorage);
            window.clearTimeout(pollTimer);
            window.clearTimeout(limitTimer);
            signal?.removeEventListener('abort', onAbort);
            channel?.close();
            resolve(result);
        };

        const askServer = async (): Promise<void> => {
            if (settled || polling || (!claimable && !popup.closed)) {
                return;
            }
            polling = true;
            try {
                const result = await options.fromServer();
                rateLimitBackoff = 0;
                if (result !== null) {
                    finish(result);
                }
            } catch (error) {
                rateLimitBackoff =
                    responseStatus(error) === TOO_MANY_REQUESTS
                        ? Math.min(
                              Math.max(rateLimitBackoff * 2, RATE_LIMIT_BACKOFF_MS),
                              MAX_RATE_LIMIT_BACKOFF_MS,
                          )
                        : 0;
            } finally {
                polling = false;
            }
        };

        const deliver = (data: PopupResult | null | undefined): void => {
            if (
                settled ||
                data?.type !== 'media-source-popup' ||
                data.source !== source ||
                data.nonce !== nonce
            ) {
                return;
            }

            if (!data.success) {
                toast.error(data.message || trans(options.failureMessage));
                finish(null);

                return;
            }

            const result = options.fromMessage(data);
            if (result !== null) {
                finish(result);

                return;
            }

            claimable = true;
            void askServer();
        };

        const onMessage = (event: MessageEvent<PopupResult>): void => {
            if (event.origin === window.location.origin) {
                deliver(event.data);
            }
        };

        const onStorage = (event: StorageEvent): void => {
            if (event.key !== MEDIA_SOURCE_POPUP_CHANNEL || !event.newValue) {
                return;
            }
            try {
                deliver(JSON.parse(event.newValue) as PopupResult);
            } catch {
                return;
            }
        };

        const onAbort = (): void => finish(null);

        const nextPollDelay = (): number =>
            Math.max(
                Date.now() - startedAt < FAST_POLL_WINDOW_MS
                    ? FAST_POLL_MS
                    : SLOW_POLL_MS,
                rateLimitBackoff,
            );

        const schedulePoll = (): void => {
            pollTimer = window.setTimeout(async () => {
                await askServer();
                if (!settled) {
                    schedulePoll();
                }
            }, nextPollDelay());
        };

        window.addEventListener('message', onMessage);
        window.addEventListener('storage', onStorage);
        if (channel) {
            channel.onmessage = (event: MessageEvent<PopupResult>) => deliver(event.data);
        }
        signal?.addEventListener('abort', onAbort);
        schedulePoll();
        limitTimer = window.setTimeout(() => finish(null), WAIT_LIMIT_MS);
    });
