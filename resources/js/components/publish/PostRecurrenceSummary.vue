<script setup lang="ts">
import { computed } from 'vue';

import { summarizeRecurrence } from '@/lib/recurrence';
import type { RecurrenceRule } from '@/lib/recurrence';

const props = defineProps<{
    scheduledAt: string;
    timezone: string;
    rule: RecurrenceRule;
    remaining?: number;
    originAt?: string | null;
}>();

const summary = computed(() =>
    summarizeRecurrence(
        props.scheduledAt,
        props.timezone,
        props.rule,
        props.originAt ?? null,
    ),
);
</script>

<template>
    <span>{{
        remaining === undefined
            ? $t('posts.recurrence.summary', {
                  rule: $tChoice(summary.ruleKey, summary.interval, summary.params),
                  until: summary.until,
              })
            : $tChoice('posts.recurrence.banner', remaining, {
                  rule: $tChoice(summary.ruleKey, summary.interval, summary.params),
                  until: summary.until,
                  count: String(remaining),
              })
    }}</span>
</template>
