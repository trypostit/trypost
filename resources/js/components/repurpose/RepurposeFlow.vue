<script setup lang="ts">
import { IconArrowRight } from '@tabler/icons-vue';

import PlatformLogo from '@/components/repurpose/PlatformLogo.vue';

/**
 * The one-glance summary of a repurpose: where the video comes from and where
 * it lands.
 */
withDefaults(
    defineProps<{
        source: string;
        destinations: string[];
        size?: 'sm' | 'md' | 'lg';
    }>(),
    { size: 'md' },
);
</script>

<template>
    <div class="flex items-center justify-center gap-3">
        <PlatformLogo :platform="source" :size="size" />

        <IconArrowRight class="size-4 shrink-0 text-foreground/40" />

        <div v-if="destinations.length > 0" class="flex items-center gap-2">
            <PlatformLogo
                v-for="(destination, index) in destinations"
                :key="`${destination}-${index}`"
                :platform="destination"
                :size="size"
                :tilt="false"
            />
        </div>

        <span v-else class="text-sm text-muted-foreground">
            {{ $t('repurposes.flow.no_destinations') }}
        </span>
    </div>
</template>
