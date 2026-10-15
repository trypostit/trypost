<script setup lang="ts">
import type { ChangelogTag, ChangelogTagValue } from '@/types/changelog';

defineProps<{
    tag: ChangelogTag;
}>();

const TAG_CLASSES: Record<ChangelogTagValue, string> = {
    new: 'bg-pink-50 text-pink-700 dark:bg-pink-400/15 dark:text-pink-300',
    improved: 'bg-sky-50 text-sky-700 dark:bg-sky-400/15 dark:text-sky-300',
    fixed: 'bg-amber-50 text-amber-700 dark:bg-amber-400/15 dark:text-amber-300',
    beta: 'bg-teal-50 text-teal-700 dark:bg-teal-400/15 dark:text-teal-300',
};
</script>

<template>
    <span
        class="inline-flex h-5 shrink-0 items-center rounded-md px-1.5 text-xs font-medium"
        :class="TAG_CLASSES[tag.value]"
        :data-testid="`changelog-tag-${tag.value}`"
    >
        {{ tag.label }}
    </span>
</template>
