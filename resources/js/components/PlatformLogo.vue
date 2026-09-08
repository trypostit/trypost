<script setup lang="ts">
import { computed } from 'vue';

import {
    getPlatformLabel,
    getPlatformTheme,
} from '@/composables/usePlatformLogo';

const props = withDefaults(
    defineProps<{
        platform: string;
        size?: 'xs' | 'sm' | 'md' | 'lg';
        tilt?: boolean;
        plain?: boolean;
        /** Native title. Pass `null` when a richer Tooltip wraps this logo. */
        title?: string | null;
    }>(),
    { size: 'md', tilt: true, plain: false, title: undefined },
);

const nativeTitle = computed(() =>
    props.title === undefined
        ? getPlatformLabel(props.platform)
        : (props.title ?? undefined),
);

const theme = computed(() => getPlatformTheme(props.platform));

const boxClass = computed(
    () =>
        ({
            xs: 'size-6 rounded-md',
            sm: 'size-10 rounded-xl',
            md: 'size-12 rounded-xl',
            lg: 'size-16 rounded-2xl',
        })[props.size],
);

const imageClass = computed(
    () =>
        ({
            xs: 'size-3.5 rounded-sm',
            sm: 'size-5 rounded-sm',
            md: 'size-7 rounded-md',
            lg: 'size-9 rounded-lg',
        })[props.size],
);

const plainImageClass = computed(
    () =>
        ({
            xs: 'size-5',
            sm: 'size-6',
            md: 'size-8',
            lg: 'size-10',
        })[props.size],
);
</script>

<template>
    <span class="inline-flex shrink-0">
        <img
            v-if="plain"
            :src="theme.image"
            :alt="getPlatformLabel(platform)"
            :title="nativeTitle"
            :class="['rounded-full object-cover', plainImageClass]"
            loading="lazy"
        />
        <span
            v-else
            :class="[
                theme.bg,
                tilt ? theme.rotate : '',
                boxClass,
                'inline-flex items-center justify-center border-2 border-foreground shadow-sm transition-transform group-hover:!rotate-0',
            ]"
            :title="nativeTitle"
        >
            <img
                :src="theme.image"
                :alt="getPlatformLabel(platform)"
                :class="imageClass"
                loading="lazy"
            />
        </span>
    </span>
</template>
