<script setup lang="ts">
import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { Platform } from '@/types/platform';

defineProps<{
    label: string;
    platform: string;
}>();
</script>

<template>
    <span class="inline-flex min-w-0 items-center gap-1" data-testid="inbox-card-via">
        <span class="truncate">{{ label }}</span>
        <PlatformBrandIcon :platform="platform" class="shrink-0" />
        <span v-if="platform !== Platform.X" class="truncate">{{ getPlatformLabel(platform) }}</span>
    </span>
</template>
