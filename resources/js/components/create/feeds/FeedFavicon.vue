<script setup lang="ts">
import { IconRss } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import { favicon } from '@/routes/app';

const props = withDefaults(
    defineProps<{
        url: string | null;
        size?: 12 | 16 | 32 | 48;
    }>(),
    { size: 16 },
);

const sizeClass = computed(() => ({
    12: 'size-3',
    16: 'size-4',
    32: 'size-8',
    48: 'size-12',
})[props.size]);

const src = computed(() => {
    if (!props.url) {
        return null;
    }

    try {
        return favicon.url(new URL(props.url).hostname);
    } catch {
        return null;
    }
});

const failed = ref(false);

const markFailed = (): void => {
    failed.value = true;
};

watch(
    () => props.url,
    () => {
        failed.value = false;
    },
);
</script>

<template>
    <img
        v-if="src && !failed"
        :src="src"
        alt=""
        loading="lazy"
        referrerpolicy="no-referrer"
        class="shrink-0 rounded-full bg-muted object-cover"
        :class="sizeClass"
        @error="markFailed"
    />
    <span
        v-else
        class="flex shrink-0 items-center justify-center rounded-full bg-muted"
        :class="sizeClass"
    >
        <IconRss class="size-2/3 text-muted-foreground" />
    </span>
</template>
