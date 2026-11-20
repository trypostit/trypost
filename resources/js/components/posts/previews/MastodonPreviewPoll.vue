<script setup lang="ts">
import { computed } from 'vue';

import {
    editablePollOptions,
    formatPollLength,
    pollDurationOf,
} from '@/lib/poll';
import type { Poll, PollLimits } from '@/types/poll';

const props = defineProps<{
    poll: Partial<Poll>;
    /** The account's poll limits, from its `publishConfig`. */
    limits: PollLimits;
}>();

const options = computed((): string[] =>
    editablePollOptions(props.poll.options, props.limits.minOptions),
);

const length = computed((): string =>
    formatPollLength(pollDurationOf(props.poll, props.limits)),
);

/** A multiple choice poll shows square boxes, a single choice round ones. */
const markerClass = computed((): string => {
    if (props.poll.multiple === true) {
        return 'rounded-sm';
    }

    return 'rounded-full';
});
</script>

<template>
    <div class="space-y-2.5" data-testid="mastodon-preview-poll">
        <div
            v-for="(option, index) in options"
            :key="index"
            class="flex min-h-6 items-center gap-2.5"
        >
            <span
                class="size-[18px] shrink-0 border border-border-strong"
                :class="markerClass"
            />
            <span class="truncate">{{ option }}</span>
        </div>
        <p class="text-muted-foreground">
            {{ $t('posts.composer.preview.poll_summary', { time: length }) }}
        </p>
    </div>
</template>
