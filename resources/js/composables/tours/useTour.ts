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

import { useBelowBreakpoint } from '@/composables/useBreakpoint';
import { useUserStorage } from '@/composables/useBrowserStorage';
import { filled } from '@/lib/helpers';
import { shownSteps, stepAfter, stepBefore, TOURS } from '@/lib/tours';
import { store as storeTour } from '@/routes/app/tours';
import type { SharedData } from '@/types';
import type { TourName, TourOutcome, TourStepName } from '@/types/tour';

/** The steps of each tour whose control is on the page. */
const onPage = reactive(new Map<TourName, string[]>());

/** The tour on screen and its step: one tour at a time, the first one on the page. */
const active = shallowRef<{ tour: TourName; step: string } | null>(null);

/** Where the open step's control is on screen, in pixels. */
export interface TourSpotlight {
    top: number;
    left: number;
    width: number;
    height: number;
}

/** What the open step shows and does, drawn by the one overlay of every tour (`TourOverlay`). */
export interface OpenTourStep {
    tour: TourName;
    step: string;
    position: number;
    total: number;
    tag?: string;
    tagColor: string;
    align: 'start' | 'center' | 'end';
    control: HTMLElement;
    frame: TourSpotlight;
    next: () => void;
    back: () => void;
    skip: () => void;
}

/**
 * The open step, published by its `TourStep`: one backdrop and one card for
 * the whole tour, so moving to the next step moves them to the next control
 * instead of closing one and opening another.
 */
const openStep = shallowRef<OpenTourStep | null>(null);

/** The start of every step on the page, in mount order: tried again whenever no tour is open. */
const starters = new Set<() => void>();

/** Closes the open tour and opens the next one waiting on the page, if any. */
const closeActive = (): void => {
    active.value = null;
    openStep.value = null;

    nextTick(() => {
        starters.forEach((start) => start());
    });
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
 * Leaving the page midway closes it without a record, so it opens again;
 * closing it in another tab closes it here. Once it closes, the next tour
 * waiting on the page opens. Tours are a desktop thing: on a phone none opens.
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

        const [first] = shown.value;

        if (!filled(first)) {
            return;
        }

        active.value = { tour, step: first };
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

    /** Takes one mount of this step off the page, keeping any other mount of it. */
    const leavePage = (): void => {
        const mounted = [...(onPage.get(tour) ?? [])];
        const index = mounted.indexOf(step);

        if (index === -1) {
            return;
        }

        mounted.splice(index, 1);
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
            closeActive();
        }
    });

    return { isActive, position, total, next, back, skip };
};
