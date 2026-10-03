<script setup lang="ts">
import { computed } from 'vue';

import {
    getPlatformLabel,
    getPlatformLogo,
} from '@/composables/usePlatformLogo';

type PlatformLogoRing = 'background' | 'card' | 'popover' | 'sidebar';

const props = withDefaults(
    defineProps<{
        platform: string;
        size?: 'xs' | 'sm' | 'md' | 'lg' | number;
        ring?: PlatformLogoRing | null;
        plain?: boolean;
        title?: string | null;
    }>(),
    { size: 'md', ring: null, plain: false, title: undefined },
);

const NAMED_SIZES: Record<'xs' | 'sm' | 'md' | 'lg', number> = {
    xs: 24,
    sm: 40,
    md: 48,
    lg: 64,
};

const RING_CLASSES: Record<PlatformLogoRing, string> = {
    background: 'border-background bg-background',
    card: 'border-card bg-card',
    popover: 'border-popover bg-popover',
    sidebar: 'border-sidebar bg-sidebar',
};

const pixels = computed(() =>
    typeof props.size === 'number' ? props.size : NAMED_SIZES[props.size],
);

const radiusClass = computed(() => {
    if (props.plain || props.ring) {
        return 'rounded-full';
    }

    if (pixels.value >= 24) {
        return 'rounded-lg';
    }

    return pixels.value >= 16 ? 'rounded-md' : 'rounded-sm';
});

const nativeTitle = computed(() =>
    props.title === undefined
        ? getPlatformLabel(props.platform)
        : (props.title ?? undefined),
);

const boxStyle = computed(() => ({
    width: `${pixels.value}px`,
    height: `${pixels.value}px`,
}));
</script>

<template>
    <span
        :class="[
            'inline-flex shrink-0 overflow-hidden',
            radiusClass,
            ring ? ['border', RING_CLASSES[ring]] : '',
        ]"
        :style="boxStyle"
        :title="nativeTitle"
    >
        <img
            :src="getPlatformLogo(platform)"
            :alt="getPlatformLabel(platform)"
            :class="['size-full object-cover', radiusClass]"
            loading="lazy"
        />
    </span>
</template>
