<script setup lang="ts">
import { IconMessageCircle } from '@tabler/icons-vue';
import { computed } from 'vue';

import InboxScoreHow from '@/components/inbox/InboxScoreHow.vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import type { InboxChannel, InboxCommentScore } from '@/types/inbox';

const props = defineProps<{
    score: InboxCommentScore;
    channels: InboxChannel[];
    channelIds: string[];
}>();

const GAUGE_PATH = 'M21.6 80.9 A42 42 0 1 1 78.4 80.9';

const STRONG_METRIC = 61;

const hasScore = computed(() => props.score.score !== null);

const scoreShare = computed(() => (props.score.score ?? 0) / 100);

const pickedChannel = computed(() =>
    props.channelIds.length === 1
        ? props.channels.find((channel) => channel.id === props.channelIds[0]) ?? null
        : null,
);

const titleKey = computed(() => {
    if (pickedChannel.value) {
        return 'inbox.score.title_channel';
    }

    return props.channelIds.length > 1 ? 'inbox.score.title_selected' : 'inbox.score.title_all';
});

const channelName = computed(() =>
    pickedChannel.value ? pickedChannel.value.display_name || pickedChannel.value.username : '',
);

const metrics = computed(() =>
    (['response_rate', 'response_speed', 'consistency'] as const).map((key) => ({
        key,
        value: props.score[key] ?? 0,
    })),
);
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <button
                type="button"
                class="relative flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :aria-label="hasScore ? $t('inbox.score.badge', { score: String(score.score) }) : $t('inbox.score.badge_empty')"
                data-testid="inbox-score-badge"
            >
                <svg
                    viewBox="0 0 36 36"
                    class="absolute inset-0 size-full -rotate-90"
                    aria-hidden="true"
                >
                    <circle
                        cx="18"
                        cy="18"
                        r="16"
                        fill="none"
                        stroke-width="2.5"
                        :class="hasScore ? 'stroke-primary-subtle' : 'stroke-border-strong'"
                    />
                    <circle
                        v-if="hasScore"
                        cx="18"
                        cy="18"
                        r="16"
                        fill="none"
                        stroke-width="2.5"
                        stroke-linecap="round"
                        pathLength="1"
                        :stroke-dasharray="`${scoreShare} 1`"
                        class="stroke-primary-strong"
                    />
                </svg>
                <span
                    class="relative -mt-1 text-xs leading-none font-medium tabular-nums"
                    :class="hasScore ? 'text-primary-text' : 'text-muted-foreground'"
                    data-testid="inbox-score-badge-value"
                >
                    {{ hasScore ? score.score : '–' }}
                </span>
                <IconMessageCircle
                    class="absolute bottom-1 left-1/2 size-2.5 -translate-x-1/2"
                    :class="hasScore ? 'text-primary-text' : 'text-muted-foreground'"
                    stroke-width="2.5"
                    aria-hidden="true"
                />
            </button>
        </PopoverTrigger>

        <PopoverContent
            align="start"
            :side-offset="8"
            :collision-padding="16"
            class="w-[min(30rem,calc(100vw-2rem))] p-4"
            data-testid="inbox-score-popover"
        >
            <div class="flex flex-col gap-0.5 border-b border-border pb-3">
                <h3
                    class="text-base leading-snug font-medium text-foreground"
                    data-testid="inbox-score-title"
                >
                    {{ $t(titleKey, { channel: channelName }) }}
                </h3>
                <p class="text-xs text-muted-foreground">
                    {{ $t('inbox.score.description') }}
                </p>
            </div>

            <div
                v-if="hasScore"
                class="flex flex-col items-center gap-4 pt-4 sm:flex-row sm:items-start"
            >
                <div class="flex shrink-0 flex-col items-center gap-2 sm:w-48">
                    <span
                        class="rounded-full bg-primary-subtle px-2.5 py-1 text-xs font-medium whitespace-nowrap text-primary-text"
                        data-testid="inbox-score-level"
                    >
                        {{ $t(`inbox.score.levels.${score.level}`) }}
                    </span>
                    <div class="relative size-40" data-testid="inbox-score-gauge">
                        <svg viewBox="0 0 100 100" class="size-full" aria-hidden="true">
                            <path
                                :d="GAUGE_PATH"
                                fill="none"
                                stroke-width="8.5"
                                stroke-linecap="round"
                                class="stroke-primary-subtle"
                            />
                            <path
                                v-if="scoreShare > 0"
                                :d="GAUGE_PATH"
                                fill="none"
                                stroke-width="8.5"
                                stroke-linecap="round"
                                pathLength="1"
                                :stroke-dasharray="`${scoreShare} 1`"
                                class="stroke-primary-strong"
                            />
                            <circle cx="50" cy="50" r="28" class="fill-primary-subtle" />
                        </svg>
                        <span
                            class="absolute inset-x-0 top-1/2 -translate-y-1/2 text-center text-3xl font-medium tabular-nums text-primary-text"
                            data-testid="inbox-score-value"
                        >
                            {{ score.score }}
                        </span>
                        <span class="absolute inset-x-0 bottom-1 text-center text-xs font-medium text-muted-foreground">
                            /100
                        </span>
                    </div>
                </div>

                <div class="flex w-full min-w-0 flex-1 flex-col gap-3 sm:pt-2">
                    <div
                        v-for="metric in metrics"
                        :key="metric.key"
                        class="flex flex-col gap-1.5"
                        :data-testid="`inbox-score-metric-${metric.key}`"
                    >
                        <div class="flex items-center justify-between gap-2 text-sm font-medium text-muted-foreground">
                            <span class="truncate">{{ $t(`inbox.score.metrics.${metric.key}`) }}</span>
                            <span class="shrink-0 tabular-nums">{{ $t('inbox.score.percent', { value: String(metric.value) }) }}</span>
                        </div>
                        <div
                            class="h-1.5 overflow-hidden rounded-full bg-muted"
                            role="progressbar"
                            :aria-valuenow="metric.value"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            :aria-label="$t(`inbox.score.metrics.${metric.key}`)"
                        >
                            <div
                                class="h-full rounded-full"
                                :class="metric.value >= STRONG_METRIC ? 'bg-success' : 'bg-warning'"
                                :style="{ width: `${metric.value}%` }"
                            />
                        </div>
                    </div>

                    <InboxScoreHow />
                </div>
            </div>

            <div
                v-else
                class="flex items-start gap-3 pt-4"
                data-testid="inbox-score-empty"
            >
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
                >
                    <IconMessageCircle class="size-4" />
                </span>
                <div class="flex flex-col gap-3">
                    <p class="text-sm text-muted-foreground">
                        {{ $t('inbox.score.empty') }}
                    </p>
                    <InboxScoreHow />
                </div>
            </div>
        </PopoverContent>
    </Popover>
</template>
