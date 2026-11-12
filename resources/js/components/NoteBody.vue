<script setup lang="ts">
import { computed } from 'vue';

import { parseNoteBody } from '@/lib/noteMentions';

/** A note's text, with each mention as `@Name` in a chip. */
const props = defineProps<{
    body: string;
}>();

const segments = computed(() => parseNoteBody(props.body));
</script>

<template>
    <p
        class="mt-0.5 text-sm break-words whitespace-pre-wrap"
        data-testid="note-body"
    >
        <template v-for="(segment, index) in segments" :key="index">
            <span
                v-if="segment.kind === 'mention'"
                class="rounded-sm bg-primary-subtle px-0.5 font-medium text-foreground"
                data-testid="note-mention"
                >@{{ segment.name }}</span
            >
            <template v-else>{{ segment.text }}</template>
        </template>
    </p>
</template>
