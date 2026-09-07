<script setup lang="ts">
import { computed } from 'vue';

import { getPlatformLabel, getPlatformTheme } from '@/composables/usePlatformLogo';

const props = withDefaults(
    defineProps<{
        platform: string;
        size?: 'sm' | 'md' | 'lg';
        tilt?: boolean;
    }>(),
    { size: 'md', tilt: true },
);

const theme = computed(() => getPlatformTheme(props.platform));

const boxClass = computed(
    () =>
        ({
            sm: 'size-10 rounded-xl',
            md: 'size-12 rounded-xl',
            lg: 'size-16 rounded-2xl',
        })[props.size],
);

const imageClass = computed(
    () =>
        ({
            sm: 'size-5 rounded-sm',
            md: 'size-7 rounded-md',
            lg: 'size-9 rounded-lg',
        })[props.size],
);
</script>

<template>
    <span
        :class="[
            theme.bg,
            tilt ? theme.rotate : '',
            boxClass,
            'inline-flex shrink-0 items-center justify-center border-2 border-foreground shadow-sm transition-transform group-hover:!rotate-0',
        ]"
        :title="getPlatformLabel(platform)"
    >
        <img :src="theme.image" :alt="getPlatformLabel(platform)" :class="imageClass" loading="lazy" />
    </span>
</template>
