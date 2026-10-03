<script setup lang="ts">
import { IconInfoCircle } from '@tabler/icons-vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import type { AvailablePlatform } from '@/types/social-account';

withDefaults(
    defineProps<{
        platforms: AvailablePlatform[];
        withDetails?: boolean;
    }>(),
    { withDetails: false },
);

const emit = defineEmits<{
    select: [platform: string];
    details: [platform: string];
}>();
</script>

<template>
    <div
        class="grid grid-cols-2 gap-3 px-4 pt-1 pb-6 sm:grid-cols-3 sm:gap-4 sm:px-20"
        data-testid="channel-platform-grid"
    >
        <div
            v-for="platform in platforms"
            :key="platform.value"
            class="group relative flex"
        >
            <button
                type="button"
                class="flex w-full cursor-pointer flex-col items-center gap-4 rounded-lg border border-border bg-card px-3 pt-6 pb-5 text-center transition-shadow duration-150 ease-out hover:shadow-[0_3px_14px_-12px_var(--subtle-foreground)] focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring sm:px-4 sm:pt-[39px] sm:pb-6"
                :data-testid="`connect-channel-${platform.value}`"
                @click="emit('select', platform.value)"
            >
                <PlatformLogo :platform="platform.value" size="sm" />
                <span class="flex min-w-0 flex-col gap-1">
                    <span
                        class="text-base leading-5 font-emphasis text-foreground"
                        >{{ platform.label }}</span
                    >
                    <span
                        class="px-1 text-base leading-5 text-muted-foreground"
                        >{{ $t(`channels.platforms.${platform.value}`) }}</span
                    >
                </span>
            </button>
            <button
                v-if="withDetails"
                type="button"
                class="absolute top-[13px] left-[13px] inline-flex size-8 cursor-pointer items-center justify-center rounded-lg text-muted-foreground opacity-0 transition-[opacity,background-color] duration-150 ease-out group-hover:opacity-100 group-has-focus-visible:opacity-100 hover:bg-accent hover:text-foreground focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring pointer-coarse:opacity-100"
                :aria-label="
                    $t('channels.details.more', { network: platform.label })
                "
                :title="$t('channels.details.more', { network: platform.label })"
                :data-testid="`connect-info-${platform.value}`"
                @click="emit('details', platform.value)"
            >
                <IconInfoCircle class="size-4" aria-hidden="true" />
            </button>
        </div>
    </div>
</template>
