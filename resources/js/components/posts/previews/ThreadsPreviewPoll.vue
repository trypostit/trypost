<script setup lang="ts">
import { computed } from 'vue';

import { editablePollOptions } from '@/lib/poll';
import type { Poll, PollLimits } from '@/types/poll';

const props = defineProps<{
    poll: Partial<Poll>;
    /** The account's poll limits, from its `publishConfig`. */
    limits: PollLimits;
}>();

const options = computed((): string[] =>
    editablePollOptions(props.poll.options, props.limits.minOptions),
);
</script>

<template>
    <div class="space-y-2 pt-1" data-testid="threads-preview-poll">
        <div
            v-for="(option, index) in options"
            :key="index"
            class="min-h-9 truncate rounded-xl border border-border-strong px-3 py-2 font-medium"
        >
            {{ option }}
        </div>
    </div>
</template>
