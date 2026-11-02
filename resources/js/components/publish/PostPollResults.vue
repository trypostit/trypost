<script setup lang="ts">
import { computed } from 'vue';

import date from '@/date';
import { activeLocale } from '@/language';
import { formatFullNumber } from '@/lib/utils';
import type { PublicationPoll } from '@/types/analytics';

const props = defineProps<{
    poll: PublicationPoll;
    testId: string;
}>();

const totalVotes = computed(() =>
    props.poll.options.reduce((sum, option) => sum + option.votes, 0),
);

const topVotes = computed(() =>
    Math.max(0, ...props.poll.options.map((option) => option.votes)),
);

const percentFormatter = computed(
    () =>
        new Intl.NumberFormat(activeLocale.value, {
            style: 'percent',
            maximumFractionDigits: 1,
        }),
);

const rows = computed(() =>
    props.poll.options.map((option) => {
        const share = totalVotes.value > 0 ? option.votes / totalVotes.value : 0;

        return {
            label: option.label,
            votes: option.votes,
            share,
            percent: percentFormatter.value.format(share),
            leading: totalVotes.value > 0 && option.votes === topVotes.value,
        };
    }),
);

const isClosed = computed(() => props.poll.voting_status === 'closed');

const hasEnded = computed(
    () =>
        !isClosed.value &&
        props.poll.end_datetime !== null &&
        date.hasPassed(props.poll.end_datetime),
);

const endsAt = computed(() =>
    !isClosed.value && !hasEnded.value && props.poll.end_datetime
        ? date.diffForHumans(props.poll.end_datetime)
        : null,
);
</script>

<template>
    <div class="flex flex-col gap-1.5 px-4 pb-3" :data-testid="testId">
        <div
            v-for="(row, index) in rows"
            :key="index"
            class="relative flex items-center justify-between gap-3 overflow-hidden rounded-md bg-muted px-3 py-1.5 text-sm"
            :class="row.leading ? 'font-semibold text-foreground' : 'text-muted-foreground'"
            :data-testid="`${testId}-option-${index}`"
            :data-leading="row.leading ? 'true' : 'false'"
        >
            <span
                class="absolute inset-y-0 start-0 rounded-md"
                :class="row.leading ? 'bg-primary' : 'bg-secondary'"
                :style="{ width: `${row.share * 100}%` }"
                aria-hidden="true"
            />
            <span class="relative min-w-0 truncate">{{ row.label }}</span>
            <span class="relative shrink-0 tabular-nums" :data-testid="`${testId}-option-${index}-votes`">
                {{ $tChoice('posts.poll_results.votes', row.votes, { count: formatFullNumber(row.votes) }) }}
                ·
                {{ row.percent }}
            </span>
        </div>

        <p class="text-xs text-muted-foreground" :data-testid="`${testId}-summary`">
            {{ $tChoice('posts.poll_results.votes', totalVotes, { count: formatFullNumber(totalVotes) }) }}
            ·
            <template v-if="isClosed">
                {{ $t('posts.poll_results.final') }}
            </template>
            <template v-else-if="hasEnded">
                {{ $t('posts.poll_results.ended') }}
            </template>
            <template v-else-if="endsAt">
                {{ $t('posts.poll_results.ends', { time: endsAt }) }}
            </template>
        </p>
    </div>
</template>
