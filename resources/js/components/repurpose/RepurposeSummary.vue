<script setup lang="ts">
import { IconSparkles } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import { getPlatformLabel } from '@/composables/usePlatformLogo';
import type { ChannelAccount } from '@/types/channel';
import type { RepurposeDestination, RepurposeSourceFormat } from '@/types/repurpose';

/**
 * One plain sentence saying exactly what will happen, so the configuration
 * never has to be read back from the controls.
 */
const props = defineProps<{
    sourceAccount: ChannelAccount | null | undefined;
    sourceFormat: RepurposeSourceFormat;
    destinations: RepurposeDestination[];
    destinationAccounts: ChannelAccount[];
    formatLabel: string;
}>();

const destinationLabels = computed(() =>
    props.destinations
        .map((destination) => {
            const account = props.destinationAccounts.find((item) => item.id === destination.social_account_id);

            return account ? getPlatformLabel(account.platform) : null;
        })
        .filter((label): label is string => label !== null),
);

const sentence = computed(() =>
    trans('repurposes.summary.sentence', {
        source: getPlatformLabel(props.sourceAccount?.platform ?? ''),
        format: props.formatLabel,
        destinations: destinationLabels.value.join(', '),
    }),
);
</script>

<template>
    <div
        v-if="destinationLabels.length > 0"
        class="flex items-start gap-3 rounded-xl border-2 border-foreground bg-emerald-50 p-4"
        data-testid="repurpose-summary"
    >
        <IconSparkles class="mt-0.5 size-5 shrink-0 text-emerald-700" />
        <p class="text-sm font-semibold text-foreground">{{ sentence }}</p>
    </div>
</template>
