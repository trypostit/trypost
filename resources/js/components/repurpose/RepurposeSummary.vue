<script setup lang="ts">
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

type SummarySentence = { key: string; params: Record<string, string> };

const sentence = computed<SummarySentence>(
    (): SummarySentence => {
        if (!props.sourceAccount) {
            return { key: 'repurposes.summary.no_source', params: {} };
        }

        if (destinationLabels.value.length === 0) {
            return {
                key: 'repurposes.summary.no_destinations',
                params: { format: props.formatLabel, source: source.value },
            };
        }

        return {
            key: 'repurposes.summary.sentence',
            params: {
                format: props.formatLabel,
                source: source.value,
                destinations: destinationLabels.value.join(', '),
            },
        };
    },
);
</script>

<template>
    <p
        class="max-w-2xl text-sm text-muted-foreground"
        data-testid="repurpose-summary"
    >
        {{ $t(sentence.key, sentence.params) }}
    </p>
</template>
