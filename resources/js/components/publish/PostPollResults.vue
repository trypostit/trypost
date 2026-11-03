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

const totalVotes = computed(() =>
    props.poll.options.reduce((sum, option) => sum + option.votes, 0),
);

const topVotes = computed(() =>
    Math.max(0, ...props.poll.options.map((option) => option.votes)),
);

/** X shows each choice's share with one decimal ("33.3%"). */
const PERCENT_DIGITS = 1;

const rows = computed(() =>
    props.poll.options.map((option) => {
        const share = totalVotes.value > 0 ? option.votes / totalVotes.value : 0;

        return {
            label: option.label,
            votes: option.votes,
            share,
            percent: formatPercent(share * 100, PERCENT_DIGITS),
            leading: totalVotes.value > 0 && option.votes === topVotes.value,
        };
    }),
);

/** The voting state shown after the total: final, ended or when voting ends. */
const status = computed((): { key: string; time?: string } | null => {
    const endsAt = props.poll.end_datetime;

    if (props.poll.voting_status === 'closed') {
        return { key: 'posts.poll_results.final' };
    }

    if (endsAt === null) return null;

    return date.hasPassed(endsAt)
        ? { key: 'posts.poll_results.ended' }
        : { key: 'posts.poll_results.ends', time: date.diffForHumans(endsAt) };
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
            class="relative flex items-center justify-between gap-3 overflow-hidden rounded-md bg-muted px-3 py-1.5 text-sm"
            :class="
                row.leading
                    ? 'font-semibold text-foreground'
                    : 'text-muted-foreground'
            "
            :data-testid="`${testId}-option-${index}`"
            :data-leading="row.leading ? 'true' : 'false'"
        >
            <span
                class="absolute inset-y-0 start-0 rounded-md"
                :class="row.leading ? 'bg-primary-selected' : 'bg-secondary'"
                :style="{ width: `${row.share * 100}%` }"
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
                >· {{ $t(status.key, { time: status.time ?? '' }) }}</template
            >
        </p>
    </div>
</template>
