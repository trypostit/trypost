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
</script>

<template>
    <div class="space-y-2" data-testid="x-preview-poll">
        <div
            v-for="(option, index) in options"
            :key="index"
            class="min-h-8 truncate rounded-full border border-info px-3 py-1.5 text-center font-bold text-info dark:text-info-text"
        >
            {{ option }}
        </div>
        <p class="text-muted-foreground">
            {{ $t('posts.composer.preview.poll_summary', { time: length }) }}
        </p>
    </div>
</template>
