import { useHttp } from '@inertiajs/vue3';

import dayjs from '@/dayjs';
import { destroy, show } from '@/routes/app/media/google-photos/sessions';

import { signInWithGoogle } from './googleSignIn';

export interface GooglePhotosPick {
    sessionId: string;
}

interface Polling {
    interval_ms: number;
    timeout_ms: number;
}

interface ClaimedSession {
    session_id: string;
    polling: Polling;
}

interface SessionStatus {
    media_items_set: boolean;
    polling: Polling;
}

const POPUP_SIZE = { width: 1000, height: 760 };

const wait = (milliseconds: number): Promise<void> =>
    new Promise((resolve) => window.setTimeout(resolve, milliseconds));

const discardSession = (sessionId: string): void => {
    useHttp({})
        .delete(destroy.url(sessionId))
        .catch(() => undefined);
};

/**
 * Polls the session until the user picked, closed the window or Google's
 * timeout passed. `popup.closed` only means "closed" when the window was
 * still reachable once the session arrived: a popup cut off from this
 * window reads closed while the user is still picking.
 */
const waitForPick = async (
    popup: Window,
    session: ClaimedSession,
    closeIsReliable: boolean,
): Promise<GooglePhotosPick | null> => {
    const http = useHttp<Record<string, never>, SessionStatus>({});
    const deadline = dayjs().add(session.polling.timeout_ms, 'millisecond');
    let interval = session.polling.interval_ms;

    while (dayjs().isBefore(deadline)) {
        await wait(interval);

        const closed = closeIsReliable && popup.closed;
        let status: SessionStatus;

        try {
            status = await http.get(show.url(session.session_id));
        } catch (error) {
            popup.close();
            discardSession(session.session_id);

            throw error;
        }

        if (status.media_items_set) {
            popup.close();

            return { sessionId: session.session_id };
        }
        if (closed) {
            discardSession(session.session_id);

            return null;
        }

        interval = status.polling.interval_ms;
    }

    popup.close();
    discardSession(session.session_id);

    return null;
};

/**
 * Photos and videos from the user's Google Photos library, confirmed with
 * Google's "Done". A popup opens inside the click on Google's sign-in; the
 * server then creates the picker session with the token it keeps, and the
 * popup moves on to the picker. Resolves the session the server imports
 * every picked item from, or null when the popup was
 * blocked, sign-in failed (a toast says why) or the user closed the picker
 * without choosing.
 */
export const pickFromGooglePhotos = async (
    signal?: AbortSignal,
): Promise<GooglePhotosPick | null> => {
    const signIn = signInWithGoogle<ClaimedSession>(
        'google_photos',
        POPUP_SIZE,
        signal,
    );
    if (!signIn) return null;

    const session = await signIn.result;
    if (!session?.session_id) return null;

    return waitForPick(signIn.popup, session, !signIn.popup.closed);
};
