<script setup lang="ts">
import { computed } from 'vue';

import { editablePollOptions, formatPollLength } from '@/lib/xPoll';
import type { XPoll, XPollLimits } from '@/types/x';

const props = defineProps<{
    poll: Partial<XPoll>;
    /** The account's poll limits, from its `publishConfig`. */
    limits: XPollLimits;
}>();

const options = computed((): string[] =>
    editablePollOptions(props.poll.options, props.limits.minOptions),
);

const length = computed((): string =>
    formatPollLength(props.poll.duration_minutes ?? props.limits.defaultDuration),
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
