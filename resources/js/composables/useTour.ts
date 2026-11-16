import { useHttp, usePage } from '@inertiajs/vue3';
import {
    computed,
    type ComputedRef,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    shallowRef,
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

/** Whether a tour is open on the page, so what would sit over it (the MCP card) waits. */
export const useTourOnScreen = (): ComputedRef<boolean> =>
    computed(() => filled(active.value));

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
 * closing it in another tab closes it here.
 * Tours are a desktop thing: on a phone none opens.
 */
export const useTourStep = <Tour extends TourName>(
    tour: Tour,
    step: TourStepName<Tour>,
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

    const isActive = computed((): boolean => {
        if (wentThrough.value) {
            return false;
        }

        const current = active.value;

        if (!filled(current)) {
            return false;
        }

        if (current.tour !== tour) {
            return false;
        }

        return current.step === step;
    });

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
        active.value = null;

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

    onMounted(() => {
        onPage.set(tour, [...(onPage.get(tour) ?? []), step]);
        nextTick(start);
    });

    onBeforeUnmount(() => {
        onPage.set(
            tour,
            (onPage.get(tour) ?? []).filter((mounted) => mounted !== step),
        );

        if (isActive.value) {
            active.value = null;
        }
    });

    return { isActive, position, total, next, back, skip };
};
