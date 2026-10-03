<script setup lang="ts">
import {
    IconCalendarStats,
    IconCloudCheck,
    IconRefresh,
    IconUsers,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import date from '@/date';
import dayjs from '@/dayjs';
import type { CoverageRow, InsightsSyncCadence } from '@/types/analytics';

const props = defineProps<{
    cadence: InsightsSyncCadence;
    coverage: CoverageRow[];
}>();

const lastSyncedAt = computed<string | null>(() =>
    props.coverage.reduce<string | null>((latest, row) => {
        if (!row.last_success_at) {
            return latest;
        }

        return latest === null || dayjs(row.last_success_at).isAfter(latest)
            ? row.last_success_at
            : latest;
    }, null),
);
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <button
                type="button"
                class="inline-flex size-8 cursor-pointer items-center justify-center rounded-full bg-primary-subtle text-primary-strong transition-control hover:brightness-95 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring data-[state=open]:brightness-95"
                :aria-label="$t('analytics.insights.sync.button')"
                :title="$t('analytics.insights.sync.button')"
                data-testid="insights-sync-status"
            >
                <IconCloudCheck class="size-4.5" aria-hidden="true" />
            </button>
        </PopoverTrigger>
        <PopoverContent
            align="end"
            class="w-80 max-w-[calc(100vw-2rem)] p-0"
            data-testid="insights-sync-popover"
        >
            <div class="border-b border-border px-4 py-3">
                <p class="text-sm font-emphasis text-foreground">
                    {{ $t('analytics.insights.sync.title') }}
                </p>
                <p
                    class="mt-0.5 text-xs text-muted-foreground"
                    data-testid="insights-sync-last"
                    :title="
                        lastSyncedAt
                            ? date.formatDateTime(lastSyncedAt)
                            : undefined
                    "
                >
                    {{
                        lastSyncedAt
                            ? $t('analytics.insights.sync.last_sync', {
                                  time: date.diffForHumans(lastSyncedAt),
                              })
                            : $t('analytics.insights.sync.never')
                    }}
                </p>
            </div>
            <ul class="flex flex-col gap-3 px-4 py-3 text-sm text-foreground">
                <li class="flex gap-2.5" data-testid="insights-sync-new-posts">
                    <IconRefresh
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span>{{
                        $t('analytics.insights.sync.new_posts', {
                            interval: $tChoice(
                                'analytics.insights.sync.every_hours',
                                cadence.discovery_hours,
                                { count: String(cadence.discovery_hours) },
                            ),
                            x_interval: $tChoice(
                                'analytics.insights.sync.every_hours',
                                cadence.x_discovery_hours,
                                { count: String(cadence.x_discovery_hours) },
                            ),
                        })
                    }}</span>
                </li>
                <li class="flex gap-2.5" data-testid="insights-sync-metrics">
                    <IconCalendarStats
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span>{{
                        $t('analytics.insights.sync.metrics', {
                            days: String(cadence.metrics_days),
                            x_days: String(cadence.x_metrics_days),
                        })
                    }}</span>
                </li>
                <li class="flex gap-2.5">
                    <IconUsers
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span>{{ $t('analytics.insights.sync.followers') }}</span>
                </li>
            </ul>
        </PopoverContent>
    </Popover>
</template>
