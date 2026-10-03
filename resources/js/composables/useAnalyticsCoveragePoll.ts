import { router, usePoll } from '@inertiajs/vue3';
import { computed, onMounted, watch } from 'vue';

import type { CoverageRow } from '@/types/analytics';

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

    const sync = (current: CoverageState): void => {
        if (current === 'import') {
            idlePoll.stop();
            importPoll.start();
        } else if (current === 'idle') {
            importPoll.stop();
            idlePoll.start();
        } else {
            importPoll.stop();
            idlePoll.stop();
        }
    };

    onMounted(() => sync(state.value));
    watch(state, sync);

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
