<script setup lang="ts">
import type { ChangelogTag } from '@/types/changelog';

defineProps<{
    tag: ChangelogTag;
}>();
</script>

<template>
    <span
        class="inline-flex h-5 shrink-0 items-center rounded-md px-1.5 align-[2px] text-xs font-medium"
        :class="
            tag === 'beta'
                ? 'bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300'
                : 'bg-pink-50 text-pink-700 dark:bg-pink-950 dark:text-pink-300'
        "
        :data-testid="`changelog-tag-${tag}`"
    >
        {{ tag === 'beta' ? $t('common.beta') : $t('changelog.tags.new') }}
    </span>
</template>
