import { router } from '@inertiajs/vue3';
import { onScopeDispose, ref, watch } from 'vue';

import { stepBackInHistory } from '@/lib/inAppHistory';

/**
 * Asks before unsaved work is lost: closing what holds it (through `guard`),
 * an Inertia visit to another page, the browser's back button, or reloading
 * and closing the tab (the browser's own prompt, which no page can word).
 * Writes (POST, PUT, ...) and partial reloads of the current page are never
 * held.
 *
 * The back button cannot be cancelled, so while there is unsaved work the
 * current history entry is doubled: back lands on the copy (an entry without
 * Inertia state, which Inertia leaves on the same page), the entry is doubled
 * again at once so a second back or a forward is caught too, and it asks from
 * there. Discarding after back runs `close` rather than going back in
 * history, since a page opened from a link may have nothing to go back to.
 * A jump past the copy (the back button's history menu) lands on an entry
 * with state: it cannot be held, so the doubling ends and Inertia navigates.
 * Unmounting while held (a save whose page no longer shows the composer)
 * steps back onto the copy, so it never leaves a Back that goes nowhere; the
 * page waits for that step (`afterHistorySettles`) before leaving through
 * history itself.
 *
 * Releasing the hold steps back onto the copy and gives it the page's state
 * again. Inertia only fills an entry without state when its page differs from
 * the last one it wrote, which is no longer true once a back was caught, and
 * a copy left without state is a Back that does nothing.
 */
export const useUnsavedChanges = (
    isDirty: () => boolean,
    close: () => void,
) => {
    const confirming = ref(false);
    let pending: (() => void) | null = null;
    let discarded = false;

    const holds = (): boolean => !discarded && isDirty();

    const guard = (action: () => void): void => {
        if (!holds()) {
            action();
            return;
        }
        pending = action;
        confirming.value = true;
    };

    let holdingBack = false;
    let releasing = false;
    let afterRelease: (() => void) | null = null;
    let pageState: unknown = null;
    let pageUrl = window.location.href;

    const holdBack = (): void => {
        if (holdingBack || releasing) return;
        holdingBack = true;
        pageState = window.history.state ?? pageState;
        window.history.replaceState(null, '', window.location.href);
        window.history.pushState(pageState, '', window.location.href);
    };

    /**
     * Drops the doubled history entry, then runs `then` once the browser is
     * back on the copy, so leaving never strands it.
     */
    const releaseBack = (then?: () => void): void => {
        if (!holdingBack) {
            then?.();
            return;
        }
        holdingBack = false;
        releasing = true;
        afterRelease = then ?? null;
        pageState = window.history.state ?? pageState;
        pageUrl = window.location.href;
        window.history.back();
    };

    const discard = (): void => {
        const action = pending;
        pending = null;
        confirming.value = false;
        if (!action) return;
        discarded = true;
        releaseBack(action);
    };

    const keepEditing = (): void => {
        pending = null;
        confirming.value = false;
    };

    const onPopState = (event: PopStateEvent): void => {
        if (releasing) {
            releasing = false;
            if (window.history.state === null && pageState !== null) {
                window.history.replaceState(pageState, '', pageUrl);
            }
            const then = afterRelease;
            afterRelease = null;

            if (then) {
                then();
            } else if (isDirty()) {
                holdBack();
            }

            return;
        }

        if (!holdingBack || !holds()) return;
        holdingBack = false;
        if (event.state !== null) return;
        holdBack();
        guard(close);
    };

    watch(isDirty, (dirty) => (dirty ? holdBack() : releaseBack()));

    const stopVisits = router.on('before', (event) => {
        const visit = event.detail.visit;

        if (
            visit.method !== 'get' ||
            visit.only.length > 0 ||
            visit.except.length > 0 ||
            visit.prefetch ||
            visit.url.href === window.location.href ||
            !holds()
        ) {
            return;
        }

        event.preventDefault();
        guard(() =>
            router.visit(visit.url.href, {
                preserveScroll: visit.preserveScroll,
                preserveState: visit.preserveState,
                replace: visit.replace,
            }),
        );
    });

    const onBeforeUnload = (event: BeforeUnloadEvent): void => {
        if (!holds()) return;
        event.preventDefault();
        event.returnValue = '';
    };

    window.addEventListener('beforeunload', onBeforeUnload);
    window.addEventListener('popstate', onPopState);

    onScopeDispose(() => {
        stopVisits();
        window.removeEventListener('beforeunload', onBeforeUnload);
        window.removeEventListener('popstate', onPopState);
        if (holdingBack) {
            holdingBack = false;
            stepBackInHistory();
        }
    });

    return { confirming, guard, discard, keepEditing };
};
