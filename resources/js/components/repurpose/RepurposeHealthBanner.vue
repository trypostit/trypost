<script setup lang="ts">
import { IconAlertTriangle, IconRefresh } from '@tabler/icons-vue';
import { computed } from 'vue';

import type { ChannelAccount } from '@/types/channel';
import type { Repurpose } from '@/types/repurpose';
import { RepurposeStatus } from '@/types/repurpose-status';

const props = defineProps<{
    repurpose: Repurpose;
    accounts: ChannelAccount[];
}>();

/**
 * Derived from current account health, never from paused_reason. The stored
 * reason decides the watermark and whether the system may resume on its own; it
 * is not a description of the situation the user is looking at now, which may
 * already be fixed.
 */
const state = computed<'source_missing' | 'source_unusable' | 'no_destinations' | 'ready' | null>(() => {
    if (props.repurpose.status !== RepurposeStatus.Paused || props.repurpose.paused_reason === null) {
        return null;
    }

    if (!props.repurpose.source_social_account_id) {
        return 'source_missing';
    }

    const source = props.accounts.find((account) => account.id === props.repurpose.source_social_account_id);

    if (!source || !source.is_active || source.status !== 'connected') {
        return 'source_unusable';
    }

    const usable = props.repurpose.destinations.filter((destination) => {
        const account = props.accounts.find((item) => item.id === destination.social_account_id);

        return account !== undefined && account.is_active;
    });

    return usable.length === 0 ? 'no_destinations' : 'ready';
});
</script>

<template>
    <div
        v-if="state"
        data-testid="repurpose-health-banner"
        :class="[
            'flex items-start gap-3 rounded-lg border px-4 py-3 text-sm',
            state === 'ready'
                ? 'border-emerald-500/30 bg-emerald-500/5 text-emerald-700 dark:text-emerald-400'
                : 'border-amber-500/30 bg-amber-500/5 text-amber-700 dark:text-amber-400',
        ]"
    >
        <component :is="state === 'ready' ? IconRefresh : IconAlertTriangle" class="mt-0.5 size-4 shrink-0" />

        <p class="leading-relaxed">
            {{ $t(`repurposes.health.${state}`) }}
        </p>
    </div>
</template>
