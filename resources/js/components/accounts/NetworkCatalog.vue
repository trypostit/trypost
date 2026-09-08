<script setup lang="ts">
import { computed } from 'vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import type {
    AvailablePlatform,
    ConnectedAccount,
} from '@/types/social-account';

const props = withDefaults(
    defineProps<{
        platforms: AvailablePlatform[];
        connectedAccounts?: ConnectedAccount[];
        gridClass?: string;
    }>(),
    {
        connectedAccounts: () => [],
        gridClass: 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4',
    },
);

const emit = defineEmits<{
    select: [platform: string];
}>();

interface CatalogTile {
    platform: AvailablePlatform;
    title: string;
    count: number;
}

const tiles = computed<CatalogTile[]>(() =>
    props.platforms.map((platform) => ({
        platform,
        title: platform.label.split('(')[0].trim(),
        count: props.connectedAccounts.filter(
            (account) => account.network === platform.network,
        ).length,
    })),
);
</script>

<template>
    <div :class="['grid gap-3', gridClass]">
        <button
            v-for="tile in tiles"
            :key="tile.platform.value"
            type="button"
            class="group relative flex flex-col items-center gap-2.5 rounded-xl border-2 border-foreground bg-card p-4 text-center shadow-xs transition-[box-shadow,transform] hover:-translate-y-0.5 hover:shadow-md focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
            :data-testid="`connect-${tile.platform.value}`"
            @click="emit('select', tile.platform.value)"
        >
            <span
                v-if="tile.count > 0"
                class="absolute -top-2 -right-2 inline-flex h-6 min-w-6 items-center justify-center rounded-full border-2 border-foreground bg-emerald-200 px-1.5 text-[11px] font-black text-foreground shadow-2xs"
                :data-testid="`connect-count-${tile.platform.value}`"
            >
                {{ tile.count }}
            </span>

            <PlatformLogo :platform="tile.platform.value" size="md" />

            <span class="w-full min-w-0">
                <span
                    class="block truncate text-sm font-semibold text-foreground"
                >
                    {{ tile.title }}
                </span>
                <span
                    class="mt-0.5 line-clamp-2 block text-xs leading-tight text-foreground/60"
                >
                    {{ $t(`accounts.descriptions.${tile.platform.value}`) }}
                </span>
            </span>
        </button>
    </div>
</template>
