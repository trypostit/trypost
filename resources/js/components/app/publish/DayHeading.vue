<script setup lang="ts">
import { computed } from 'vue';

import dayjs from '@/dayjs';
import { activeLocale } from '@/language';

const props = defineProps<{
    dateKey: string;
    timezone: string;
}>();

const RELATIVE_KEYS: Record<number, string> = {
    [-1]: 'posts.publish.yesterday',
    0: 'posts.publish.today',
    1: 'posts.publish.tomorrow',
};

const day = computed(() =>
    dayjs(props.dateKey).locale(activeLocale.value.toLowerCase()),
);

const relativeKey = computed<string | null>(() => {
    const today = dayjs().tz(props.timezone).format('YYYY-MM-DD');

    return RELATIVE_KEYS[day.value.diff(dayjs(today), 'day')] ?? null;
});

const detail = computed(() =>
    new Intl.DateTimeFormat(activeLocale.value, {
        month: 'long',
        day: 'numeric',
        year:
            day.value.year() === dayjs().tz(props.timezone).year()
                ? undefined
                : 'numeric',
    }).format(day.value.toDate()),
);
</script>

<template>
    <h2 class="text-base leading-5 font-emphasis text-foreground">
        <span class="capitalize">{{
            relativeKey ? $t(relativeKey) : day.format('dddd')
        }}</span
        >, <span class="text-muted-foreground">{{ detail }}</span>
    </h2>
</template>
