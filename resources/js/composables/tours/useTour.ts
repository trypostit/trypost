import { useHttp, usePage } from '@inertiajs/vue3';
import {
    computed,
    type ComputedRef,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    shallowRef,
    watch,
} from 'vue';

import { isDialogBlockingTours } from '@/composables/tours/useTourWaitsForDialogs';
import { useBelowBreakpoint } from '@/composables/useBreakpoint';
import { useUserStorage } from '@/composables/useBrowserStorage';
import { isPostComposerOpen } from '@/composables/useGlobalPostComposer';
import { filled } from '@/lib/helpers';
import {
    firstStep,
    shownSteps,
    stepAfter,
    stepBefore,
    TOURS,
} from '@/lib/tours';
import { store as storeTour } from '@/routes/app/tours';
import type { SharedData } from '@/types';
import type {
    OpenTourStep,
    TourName,
    TourOutcome,
    TourStepName,
} from '@/types/tour';

/** The steps of each tour whose control is on the page. */
const onPage = reactive(new Map<TourName, string[]>());

/** The tour on screen and its step: one tour at a time, the first one on the page. */
const active = shallowRef<{ tour: TourName; step: string } | null>(null);

/**
 * The open step, published by its `TourStep`: one backdrop and one card for
 * the whole tour, so moving to the next step moves them to the next control
 * instead of closing one and opening another.
 */
const openStep = shallowRef<OpenTourStep | null>(null);

/**
 * The step each tour was on when it was put away without ending (a visit
 * that remounts the page, a dialog opened over it, a window narrowed to a
 * phone's width), keyed by user and tour (`<user id>:<tour>`): the tour picks
 * up there when that step is back on the page, for that user only.
 */
const resumeAt = new Map<string, string>();

/** The start of every step on the page, in mount order: tried again whenever no tour is open. */
const starters = new Set<() => void>();

/** Tries every step on the page, so the first tour that may open opens. */
const startWaitingTours = (): void => {
    starters.forEach((start) => start());
};

/** Closes the open tour and opens the next one waiting on the page, if any. */
const closeActive = (): void => {
    active.value = null;
    openStep.value = null;

    nextTick(startWaitingTours);
};

/** Whether this tour's step is the one open. */
const isOpenStep = (tour: TourName, step: string): boolean => {
    const current = active.value;

    if (!filled(current)) {
        return false;
    }

    if (current.tour !== tour) {
        return false;
    }

    return current.step === step;
};

/** Whether a tour is open on the page, so what would sit over it (the MCP card) waits. */
export const useTourOnScreen = (): ComputedRef<boolean> =>
    computed(() => filled(active.value));

/** The open step shows itself on its control. */
export const showTourStep = (step: OpenTourStep): void => {
    openStep.value = step;
};

/** The step the overlay shows, or none while no tour is open. */
export const useOpenTourStep = (): ComputedRef<OpenTourStep | null> =>
    computed(() => {
        if (!filled(active.value)) {
            return null;
        }

        return openStep.value;
    });

interface TourProgressState {
    wentThrough: ComputedRef<boolean>;
    record: (outcome: TourOutcome, step: number) => void;
}

/**
 * Whether the user went through a tour in this browser, and the way to record
 * how it ended (`tours` in the browser storage). A tour is recorded once.
 */
export const useTourProgress = (tour: TourName): TourProgressState => {
    const progress = useUserStorage('tours', {});

    const wentThrough = computed(() => filled(progress.value?.[tour]));

    const record = (outcome: TourOutcome, step: number): void => {
        if (wentThrough.value) {
            return;
        }

        progress.value = {
            ...progress.value,
            [tour]: { status: outcome, step },
        };
    };

    return { wentThrough, record };
};

interface TourStepState {
    isActive: ComputedRef<boolean>;
    position: ComputedRef<number>;
    total: ComputedRef<number>;
    next: () => void;
    back: () => void;
    skip: () => void;
}

/**
 * One step of a tour, on the control it points at. The tour opens on its
 * first step on the page, once per user in this browser: reaching the end or
 * closing it before is recorded (`useTourProgress`) and tracked.
 * Leaving the page midway closes it without a record, so it opens again, at
 * the step it was on while the app stays open; closing it in another tab
 * closes it here. Once it closes, the next tour waiting on the page opens. A
 * tour waits while the page is busy (the composer, a dialog, the referral
 * survey). Tours are a desktop thing: on a phone none opens, and narrowing
 * the window to a phone's width hides the open one until it widens again.
 * A step that is not `enabled` (a page where its copy does not fit) never
 * counts as on the page.
 */
export const useTourStep = <Tour extends TourName>(
    tour: Tour,
    step: TourStepName<Tour>,
    enabled = true,
): TourStepState => {
    const page = usePage<SharedData>();
    const isPhone = useBelowBreakpoint('sm');
    const { wentThrough, record } = useTourProgress(tour);
    const tracking = useHttp<{
        tour: TourName;
        outcome: TourOutcome;
        step: number;
        steps: number;
    }>({ tour, outcome: 'completed', step: 1, steps: 1 });

    const shown = computed(() =>
        shownSteps(TOURS[tour], onPage.get(tour) ?? []),
    );
    const position = computed(() => shown.value.indexOf(step) + 1);
    const total = computed(() => shown.value.length);

    const isActive = computed(() => isOpenStep(tour, step));

    /**
     * Something on the page goes first: the composer, any open dialog, or the
     * "How did you find us?" survey.
     */
    const isPageBusy = computed((): boolean => {
        if (isPostComposerOpen.value) {
            return true;
        }

        if (filled(page.props.referralSources)) {
            return true;
        }

        return isDialogBlockingTours.value;
    });

    const resumeKey = (): string => `${page.props.auth.user.id}:${tour}`;

    /** The step the tour opens on: where this user left it, while that step is on the page, else its first. */
    const openingStep = (): string | null => {
        const resumed = resumeAt.get(resumeKey());

        if (!filled(resumed)) {
            return firstStep(shown.value);
        }

        if (!shown.value.includes(resumed)) {
            return firstStep(shown.value);
        }

        return resumed;
    };

    /** Puts this tour away, when it is the open one, to pick it up at the same step later. */
    const putAway = (): void => {
        const current = active.value;

        if (!filled(current)) {
            return;
        }

        if (current.tour !== tour) {
            return;
        }

        resumeAt.set(resumeKey(), current.step);
        closeActive();
    };

    const start = (): void => {
        if (page.props.toursEnabled !== true) {
            return;
        }

        if (isPhone.value) {
            return;
        }

        if (filled(active.value)) {
            return;
        }

        if (wentThrough.value) {
            return;
        }

        const opening = openingStep();

        if (!filled(opening)) {
            return;
        }

        if (isPageBusy.value) {
            return;
        }

        resumeAt.delete(resumeKey());
        active.value = { tour, step: opening };
    };

    const finish = (outcome: TourOutcome, reached: number): void => {
        closeActive();

        if (wentThrough.value) {
            return;
        }

        record(outcome, reached);

        tracking.outcome = outcome;
        tracking.step = reached;
        tracking.steps = total.value;
        tracking.post(storeTour.url()).catch(() => {});
    };

    const next = (): void => {
        const after = stepAfter(shown.value, step);

        if (!filled(after)) {
            finish('completed', total.value);

            return;
        }

        active.value = { tour, step: after };
    };

    const back = (): void => {
        const before = stepBefore(shown.value, step);

        if (!filled(before)) {
            return;
        }

        active.value = { tour, step: before };
    };

    const skip = (): void => {
        finish('skipped', position.value);
    };

    /** Closing the tour in another tab closes it here, and lets the next one open. */
    watch(wentThrough, (went) => {
        if (!went) {
            return;
        }

        if (active.value?.tour !== tour) {
            return;
        }

        closeActive();
    });

    /**
     * A dialog opened over the open tour (the command palette, the composer)
     * puts it away; once the page is free again (the dialog closed, the survey
     * answered), the tour opens, at the step it was on.
     */
    watch(isPageBusy, (busy) => {
        if (busy) {
            putAway();

            return;
        }

        start();
    });

    /**
     * A window narrowed to a phone's width hides the open tour without a
     * record, and widening it again opens the tour at the step it was on.
     */
    watch(isPhone, (phone) => {
        if (!phone) {
            start();

            return;
        }

        putAway();
    });

    /** Takes one mount of this step off the page, keeping any other mount of it. */
    const leavePage = (): void => {
        const mounted = [...(onPage.get(tour) ?? [])];

        if (!mounted.includes(step)) {
            return;
        }

        mounted.splice(mounted.indexOf(step), 1);
        onPage.set(tour, mounted);
    };

    onMounted(() => {
        if (!enabled) {
            return;
        }

        onPage.set(tour, [...(onPage.get(tour) ?? []), step]);
        starters.add(start);
        nextTick(start);
    });

    onBeforeUnmount(() => {
        if (!enabled) {
            return;
        }

        leavePage();
        starters.delete(start);

        if (isOpenStep(tour, step)) {
            putAway();
        }
    });

    return { isActive, position, total, next, back, skip };
};
