<script setup lang="ts">
import { IconWorld } from '@tabler/icons-vue';
import { computed } from 'vue';

import MultiSelectFilter from '@/components/MultiSelectFilter.vue';
import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import { getPlatformLabel } from '@/composables/usePlatformLogo';

/**
 * Filters a list by social network (Instagram, LinkedIn…), with the same
 * trigger and options as the channels filter, each network shown by its logo.
 */
const props = withDefaults(
    defineProps<{
        /** The networks to offer, each once, in the order they should show. */
        networks: string[];
        testId?: string;
        /** Opens as a bottom sheet on phones instead of a popover. */
        sheetBelowSm?: boolean;
    }>(),
    { testId: 'network', sheetBelowSm: false },
);

const selectedNetworks = defineModel<string[]>({ required: true });

const options = computed(() =>
    props.networks.map((network) => ({
        id: network,
        label: getPlatformLabel(network),
    })),
);
</script>

<template>
    <MultiSelectFilter
        v-model="selectedNetworks"
        :options="options"
        :label="$t('channels.list.networks')"
        :search-placeholder="$t('channels.list.networks_search')"
        :empty-message="$t('channels.list.no_networks')"
        :select-all-label="$t('posts.composer.select_all')"
        :deselect-all-label="$t('posts.composer.deselect_all')"
        :test-id="testId"
        content-class="w-80"
        icon-only-on-mobile
        :sheet-below-sm="sheetBelowSm"
    >
        <template #icon>
            <IconWorld class="size-4" />
        </template>
        <template #option="{ option }">
            <span class="flex min-w-0 items-center gap-3">
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-border bg-card"
                    aria-hidden="true"
                >
                    <PlatformBrandIcon
                        :platform="option.id"
                        colored
                        class="size-4.5"
                    />
                </span>
                <span class="min-w-0 truncate">{{ option.label }}</span>
            </span>
        </template>
    </MultiSelectFilter>
</template>
