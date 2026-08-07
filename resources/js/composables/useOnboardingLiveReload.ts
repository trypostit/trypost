import { router, usePoll } from '@inertiajs/vue3';
import { watch, type WatchSource } from 'vue';

import { useWorkspaceEcho } from '@/composables/echo/useWorkspaceEcho';

/**
 * Echo for fast onboarding updates; sparse poll as Reverb fallback.
 */
export const useOnboardingLiveReload = (options: {
    only: string[];
    pollMs?: number;
    enabled: WatchSource<boolean>;
    onEcho?: () => void;
}): void => {
    const pollMs = options.pollMs ?? 30_000;

    useWorkspaceEcho('.onboarding.status.updated', () => {
        if (! toEnabled(options.enabled)) {
            return;
        }

        options.onEcho?.();
        router.reload({ only: options.only });
    });

    const { start, stop } = usePoll(
        pollMs,
        { only: options.only },
        { autoStart: false },
    );

    watch(
        options.enabled,
        (enabled) => {
            if (enabled) {
                start();

                return;
            }

            stop();
        },
        { immediate: true },
    );
};

const toEnabled = (source: WatchSource<boolean>): boolean => {
    if (typeof source === 'function') {
        return source();
    }

    return source.value;
};
