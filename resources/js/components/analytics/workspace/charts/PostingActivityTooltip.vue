<script setup lang="ts">
import { transChoice } from 'laravel-vue-i18n';
import { computed } from 'vue';

import date from '@/date';
import { activeLocale } from '@/language';
import type { PostingActivityDay } from '@/types/analytics';

defineOptions({ inheritAttrs: false });

/** Rendered to a string by `componentToString`, which hands the day over as `payload`. */
const props = defineProps<{
    payload: PostingActivityDay;
}>();

const posts = computed((): string =>
    transChoice(
        'analytics.posting_activity.posts_on_day',
        props.payload.count,
        {
            count: props.payload.count.toLocaleString(activeLocale.value),
        },
    ),
);

const day = computed((): string =>
    date.formatWeekdayMonthDay(props.payload.date, 'UTC'),
);
</script>

<template>
    <div
        class="grid gap-0.5 rounded-xl bg-popover px-3 py-2 text-xs shadow-md ring-1 ring-foreground/5 dark:ring-border"
        data-testid="posting-activity-tooltip"
    >
        <p class="font-medium text-foreground tabular-nums">{{ posts }}</p>
        <p class="text-muted-foreground">{{ day }}</p>
    </div>
</template>
