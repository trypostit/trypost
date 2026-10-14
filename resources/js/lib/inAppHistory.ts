import { router } from '@inertiajs/vue3';

let pushedUrl: string | null = null;

/**
 * Remembers the URL of the last page the app pushed onto the browser history
 * with a full visit (a link or `router.visit`: no replace, no partial reload,
 * no prefetch). Back and forward make no visit, and a reload starts empty.
 */
export const trackInAppPushes = (): void => {
    router.on('finish', (event) => {
        const visit = event.detail.visit;

        if (
            visit.method === 'get' &&
            visit.completed &&
            !visit.replace &&
            !visit.prefetch &&
            visit.only.length === 0 &&
            visit.except.length === 0
        ) {
            pushedUrl = window.location.href;
        }
    });
};

/**
 * Whether the app pushed the current history entry, so the entry behind it is
 * another page of the app the user can be sent back to. A partial reload may
 * add query parameters to the entry afterwards (`queue_days`), so the entry
 * still counts while its path and every parameter it was pushed with match.
 */
export const cameFromAppPage = (): boolean => {
    if (pushedUrl === null) {
        return false;
    }

    const pushed = new URL(pushedUrl);
    const current = new URL(window.location.href);

    return (
        pushed.origin === current.origin &&
        pushed.pathname === current.pathname &&
        [...pushed.searchParams].every(
            ([key, value]) => current.searchParams.get(key) === value,
        )
    );
};

let settling: Promise<void> | null = null;

/**
 * Goes back one history entry and remembers it until the browser lands, so
 * `afterHistorySettles` can wait for it.
 */
export const stepBackInHistory = (): void => {
    settling = new Promise((resolve) => {
        window.addEventListener(
            'popstate',
            () => {
                settling = null;
                resolve();
            },
            { once: true },
        );
    });
    window.history.back();
};

/**
 * Runs `then` once a step taken through `stepBackInHistory` has landed, or
 * right away when none is under way.
 */
export const afterHistorySettles = (then: () => void): void => {
    if (settling) {
        void settling.then(then);

        return;
    }

    then();
};
