<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import { getPlatformLabel } from '@/composables/usePlatformLogo';
import type { ChannelAccount } from '@/types/channel';
import type { RepurposeDestination } from '@/types/repurpose';

const props = defineProps<{
    sourceAccount: ChannelAccount | null | undefined;
    formatLabel: string;
    destinations: RepurposeDestination[];
    destinationAccounts: ChannelAccount[];
}>();

const destinationLabels = computed(() =>
    props.destinations
        .map((destination) => {
            const account = props.destinationAccounts.find((item) => item.id === destination.social_account_id);

            return account ? getPlatformLabel(account.platform) : null;
        })
        .filter((label): label is string => label !== null),
);

const source = computed(() => {
    const network = getPlatformLabel(props.sourceAccount?.platform ?? '');
    const handle = props.sourceAccount?.username;

    return handle ? `${network} (@${handle})` : network;
});

const sentence = computed(() => {
    if (!props.sourceAccount) {
        return trans('repurposes.summary.no_source');
    }

    if (destinationLabels.value.length === 0) {
        return trans('repurposes.summary.no_destinations', {
            format: props.formatLabel,
            source: source.value,
        });
    }

    return trans('repurposes.summary.sentence', {
        format: props.formatLabel,
        source: source.value,
        destinations: destinationLabels.value.join(', '),
    });
});
</script>

<template>
    <p class="max-w-2xl text-sm leading-relaxed text-foreground/70" data-testid="repurpose-summary">
        {{ sentence }}
    </p>
</template>
