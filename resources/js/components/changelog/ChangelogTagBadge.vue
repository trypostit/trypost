<script setup lang="ts">
import type { ChangelogTag } from '@/types/changelog';

defineProps<{
    tag: ChangelogTag;
}>();

const TAG_STYLES: Record<ChangelogTag, { label: string; classes: string }> = {
    new: {
        label: 'changelog.tags.new',
        classes:
            'bg-pink-50 text-pink-700 dark:bg-pink-950 dark:text-pink-300',
    },
    improved: {
        label: 'changelog.tags.improved',
        classes: 'bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
    },
    fixed: {
        label: 'changelog.tags.fixed',
        classes:
            'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    },
    beta: {
        label: 'common.beta',
        classes:
            'bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300',
    },
};
</script>

<template>
    <span
        class="inline-flex h-5 shrink-0 items-center rounded-md px-1.5 text-xs font-medium"
        :class="TAG_STYLES[tag].classes"
        :data-testid="`changelog-tag-${tag}`"
    >
        {{ $t(TAG_STYLES[tag].label) }}
    </span>
</template>
