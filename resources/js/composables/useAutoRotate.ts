import { useIntervalFn, useMediaQuery } from '@vueuse/core';
import { ref, watch, type Ref } from 'vue';

/**
 * Cycles an index through `count` items every `intervalMs`, pausing while the
 * pointer is over the carousel and never moving for reduced-motion users.
 */
export const useAutoRotate = (
    count: number,
    intervalMs: number,
): {
    active: Ref<number>;
    select: (index: number) => void;
    pause: () => void;
    resume: () => void;
} => {
    const active = ref(0);
    const prefersReducedMotion = useMediaQuery(
        '(prefers-reduced-motion: reduce)',
    );

    const showNext = (): void => {
        active.value = (active.value + 1) % count;
    };

    const interval = useIntervalFn(showNext, intervalMs, { immediate: false });

    const select = (index: number): void => {
        active.value = index;
    };

    const pause = (): void => {
        interval.pause();
    };

    const resume = (): void => {
        if (!prefersReducedMotion.value) {
            interval.resume();
        }
    };

    watch(
        prefersReducedMotion,
        (reduced) => {
            if (reduced) {
                interval.pause();
            } else {
                interval.resume();
            }
        },
        { immediate: true },
    );

    return { active, select, pause, resume };
};
