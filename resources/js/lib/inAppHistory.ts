import type { ActiveVisit } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';

import { blank, filled } from '@/lib/helpers';

let pushedUrl: string | null = null;

/** A completed GET that pushed a whole page: no replace, no prefetch, no partial reload. */
const pushedAPage = (visit: ActiveVisit): boolean => {
    if (visit.method !== 'get' || !visit.completed) {
        return false;
    }

    if (visit.replace || visit.prefetch) {
        return false;
    }

    return blank(visit.only) && blank(visit.except);
};

/**
 * Remembers the URL of the last page the app pushed onto the browser history
 * with a full visit (a link or `router.visit`). Back and forward make no visit,
 * and a reload starts empty.
 */
export const trackInAppPushes = (): void => {
    router.on('finish', (event) => {
        if (pushedAPage(event.detail.visit)) {
            pushedUrl = window.location.href;
        }
    });
};

/**
 * Marks the current history entry as pushed by the app, for a visit that is
 * not a plain GET but still pushed a page on top of the one it was sent from:
 * duplicating a post sends a POST that lands on the copy's composer, so closing
 * that composer goes back to the List, Calendar or Board it was duplicated from.
 */
export const rememberInAppPush = (): void => {
    pushedUrl = window.location.href;
};

/**
 * Whether the app pushed the current history entry, so the entry behind it is
 * another page of the app the user can be sent back to. A partial reload may
 * add query parameters to the entry afterwards (`queue_days`), so the entry
 * still counts while its path and every parameter it was pushed with match.
 */
export const cameFromAppPage = (): boolean => {
    if (!filled(pushedUrl)) {
        return false;
    }

    const pushed = new URL(pushedUrl);
    const current = new URL(window.location.href);

    if (pushed.origin !== current.origin || pushed.pathname !== current.pathname) {
        return false;
    }

    return [...pushed.searchParams].every(
        ([key, value]) => current.searchParams.get(key) === value,
    );
};

/**
 * Runs `then` once Inertia has written the current page into its history
 * entry. On a full page load it writes it only after the page has mounted,
 * and a `preserveUrl` visit answered meanwhile makes it skip that write, which
 * leaves an entry the browser's Back can never bring back. Returns a cancel,
 * for a page that unmounts before the write.
 */
export const afterPageInHistory = (then: () => void): (() => void) => {
    if (filled(window.history.state?.page)) {
        then();

        return () => {};
    }

    const stop = router.on('navigate', () => {
        stop();
        then();
    });

    return stop;
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
    if (filled(settling)) {
        void settling.then(then);

        return;
    }

    then();
};
