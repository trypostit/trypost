<script setup lang="ts">
import { computed } from 'vue';

import date from '@/date';
import { formatFullNumber, formatPercent } from '@/lib/utils';
import type { PublicationPoll } from '@/types/analytics';

const props = defineProps<{
    poll: PublicationPoll;
    testId: string;
    /** Names the thread post a reply poll sits on. */
    caption?: string;
}>();

/** X shows each choice's share with one decimal ("33.3%"). */
const PERCENT_DIGITS = 1;

type PollStatus = { key: string; replacements?: Record<string, string> };

const votes = computed((): number[] =>
    props.poll.options.map((option) => option.votes),
);

const totalVotes = computed((): number =>
    votes.value.reduce((sum, count) => sum + count, 0),
);

const topVotes = computed((): number => Math.max(...votes.value));

/** A choice's part of the votes, from 0 to 1; nothing before the first vote. */
const shareOf = (count: number): number =>
    totalVotes.value === 0 ? 0 : count / totalVotes.value;

/** The choice with the most votes leads, ties included; none before the first vote. */
const isLeading = (count: number): boolean =>
    totalVotes.value > 0 && count === topVotes.value;

const rows = computed(() =>
    props.poll.options.map(({ label, votes: count }) => ({
        label,
        votes: count,
        width: `${shareOf(count) * 100}%`,
        percent: formatPercent(shareOf(count) * 100, PERCENT_DIGITS),
        leading: isLeading(count),
    })),
);

/** The voting state shown after the total: final, ended or when voting ends. */
const status = computed((): PollStatus | null => {
    const endsAt = props.poll.end_datetime;

    if (props.poll.voting_status === 'closed') {
        return { key: 'posts.poll_results.final' };
    }

    if (endsAt === null) {
        return null;
    }

    if (date.hasPassed(endsAt)) {
        return { key: 'posts.poll_results.ended' };
    }

    return {
        key: 'posts.poll_results.ends',
        replacements: { time: date.diffForHumans(endsAt) },
    };
});
</script>

<template>
    <div class="flex flex-col gap-1.5 px-4 pb-3" :data-testid="testId">
        <p
            v-if="caption"
            class="text-xs font-medium text-muted-foreground"
            :data-testid="`${testId}-caption`"
        >
            {{ caption }}
        </p>
        <div
            v-for="(row, index) in rows"
            :key="index"
            class="group relative flex items-center justify-between gap-3 overflow-hidden rounded-md bg-muted px-3 py-1.5 text-sm text-muted-foreground data-[leading=true]:font-semibold data-[leading=true]:text-foreground"
            :data-testid="`${testId}-option-${index}`"
            :data-leading="String(row.leading)"
        >
            <span
                class="absolute inset-y-0 start-0 rounded-md bg-secondary group-data-[leading=true]:bg-primary-selected"
                :style="{ width: row.width }"
                aria-hidden="true"
            />
            <span class="relative min-w-0 truncate">{{ row.label }}</span>
            <span
                class="relative shrink-0 tabular-nums"
                :data-testid="`${testId}-option-${index}-votes`"
            >
                {{
                    $tChoice('posts.poll_results.votes', row.votes, {
                        count: formatFullNumber(row.votes),
                    })
                }}
                ·
                {{ row.percent }}
            </span>
        </div>

        <p
            class="text-xs text-muted-foreground"
            :data-testid="`${testId}-summary`"
        >
            {{
                $tChoice('posts.poll_results.votes', totalVotes, {
                    count: formatFullNumber(totalVotes),
                })
            }}
            <template v-if="status"
                >· {{ $t(status.key, status.replacements) }}</template
            >
        </p>
    </div>
</template>
