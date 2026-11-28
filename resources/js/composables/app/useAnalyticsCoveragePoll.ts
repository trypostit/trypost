import { router, usePoll } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';

import type { CoverageRow } from '@/types/analytics';

const IDLE_POLL_WINDOW_MS = 2 * 60 * 1000;

type CoverageState = 'off' | 'import' | 'idle';

export const useAnalyticsCoveragePoll = (
    coverage: () => CoverageRow[] | undefined,
    refresh?: {
        firstDay: () => string | null | undefined;
        only: string[];
        reset?: string[];
    },
): void => {
    const state = computed<CoverageState>(() => {
        const rows = coverage();

        if (!rows) {
            return 'off';
        }

        return rows.some(
            (row) =>
                row.collector === 'publication_backfill' &&
                (row.status === 'pending' || row.status === 'running'),
        )
            ? 'import'
            : 'idle';
    });
    const importPoll = usePoll(
        5000,
        { only: ['report'] },
        { autoStart: false },
    );
    const idlePoll = usePoll(15000, { only: ['report'] }, { autoStart: false });

    let idleTimer: ReturnType<typeof setTimeout> | undefined;

    const clearIdleTimer = (): void => {
        clearTimeout(idleTimer);
        idleTimer = undefined;
    };

    const sync = (current: CoverageState): void => {
        clearIdleTimer();

        if (current === 'import') {
            idlePoll.stop();
            importPoll.start();
        } else if (current === 'idle') {
            importPoll.stop();
            idlePoll.start();
            idleTimer = setTimeout(idlePoll.stop, IDLE_POLL_WINDOW_MS);
        } else {
            importPoll.stop();
            idlePoll.stop();
        }
    };

    onMounted(() => sync(state.value));
    watch(state, sync);
    onBeforeUnmount(clearIdleTimer);

    if (!refresh) {
        return;
    }

    watch(
        [state, refresh.firstDay] as const,
        ([current, firstDay], [previous, previousFirstDay]) => {
            const imported = previous === 'import' && current === 'idle';
            const dataArrived = !previousFirstDay && Boolean(firstDay);

            if (imported || dataArrived) {
                router.reload({
                    only: ['report', ...refresh.only],
                    reset: refresh.reset,
                });
            }
        },
    );
};
