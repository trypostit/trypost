<script setup lang="ts">
import { computed } from 'vue';

import date from '@/date';
import { filled, transform } from '@/lib/helpers';
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

const hasVotes = computed((): boolean => totalVotes.value > 0);

/** Whether the network hides the votes until the poll ends (Mastodon). */
const totalsHidden = computed((): boolean => props.poll.totals_hidden === true);

/**
 * What each share is of: the people who voted on a multiple choice poll
 * (Mastodon counts each voter once, whatever they picked), else the votes.
 */
const shareBase = computed((): number => {
    if (filled(props.poll.voters)) {
        return props.poll.voters;
    }

    return totalVotes.value;
});

/**
 * The total under the choices, counted like the shares: the people who voted
 * on a multiple choice poll ("3 people"), else the votes.
 */
const summary = computed((): { key: string; count: number } => {
    if (filled(props.poll.voters)) {
        return { key: 'posts.poll_results.voters', count: props.poll.voters };
    }

    return { key: 'posts.poll_results.votes', count: totalVotes.value };
});

/** A choice's percentage of the votes; none before the first vote. */
const percentOf = (count: number): number => {
    if (!hasVotes.value || shareBase.value === 0) {
        return 0;
    }

    return (count / shareBase.value) * 100;
};

/** The choice with the most votes leads, ties included; none before the first vote. */
const isLeading = (count: number): boolean =>
    hasVotes.value && count === topVotes.value;

const rows = computed(() =>
    props.poll.options.map(({ label, votes: count }) => {
        const percent = percentOf(count);

        return {
            label,
            votes: count,
            width: `${percent}%`,
            percent: formatPercent(percent, PERCENT_DIGITS),
            leading: isLeading(count),
        };
    }),
);

/** When voting ends: already ended, or in how long. */
const votingEnd = (endsAt: string): PollStatus => {
    if (date.hasPassed(endsAt)) {
        return { key: 'posts.poll_results.ended' };
    }

    return {
        key: 'posts.poll_results.ends',
        replacements: { time: date.diffForHumans(endsAt) },
    };
};

/** The voting state shown after the total: final, ended or when voting ends. */
const status = computed((): PollStatus | null => {
    if (props.poll.voting_status === 'closed') {
        return { key: 'posts.poll_results.final' };
    }

    return transform(props.poll.end_datetime, votingEnd);
});
</script>

<template>
    <div class="flex flex-col gap-1.5" :data-testid="testId">
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
                v-if="!totalsHidden"
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
            <template v-if="totalsHidden">{{
                $t('posts.poll_results.hidden')
            }}</template>
            <template v-else>{{
                $tChoice(summary.key, summary.count, {
                    count: formatFullNumber(summary.count),
                })
            }}</template>
            <template v-if="status">
                · {{ $t(status.key, status.replacements) }}</template
            >
        </p>
    </div>
</template>
