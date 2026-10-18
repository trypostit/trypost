<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconAt } from '@tabler/icons-vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import { useNetworkConnect } from '@/composables/useNetworkConnect';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import type { InboxMentionNetwork } from '@/types/inbox';
import type { AvailablePlatform } from '@/types/social-account';

defineProps<{
    networks: InboxMentionNetwork[];
}>();

const page = usePage();
const { canManageAccounts } = useWorkspaceAbilities();
const { startConnect } = useNetworkConnect(
    () => (page.props.connectablePlatforms as AvailablePlatform[]) ?? [],
);

const connectNetwork = (network: InboxMentionNetwork): void => {
    if (network.state === 'available' && canManageAccounts.value) {
        startConnect(network.platform);
    }
};
</script>

<template>
    <div
        class="mx-auto flex w-full max-w-[685px] flex-col items-center pt-4 pb-[22px] text-center"
        data-testid="inbox-mentions-availability"
    >
        <div class="flex flex-col items-center gap-4 p-[17px]">
            <div
                class="flex size-[72px] items-center justify-center rounded-full bg-secondary text-muted-foreground md:size-14"
                aria-hidden="true"
            >
                <IconAt class="size-8 md:size-6" stroke-width="1.75" />
            </div>
            <h2
                class="max-w-[406px] font-heading text-lg leading-[22.5px] font-[550] text-foreground md:text-sm md:leading-[21px]"
            >
                {{ $t('inbox.mentions.availability_title') }}
            </h2>
        </div>

        <div
            class="flex w-full flex-col items-center gap-4 rounded-xl border border-border bg-card p-[17px]"
        >
            <p class="text-sm leading-[21px] text-muted-foreground">
                {{ $t('inbox.mentions.availability_description') }}
            </p>
            <ul class="flex w-full flex-wrap justify-center gap-y-4">
                <li
                    v-for="network in networks"
                    :key="network.platform"
                    class="flex w-[130px] flex-col items-center gap-2 px-2"
                    :data-testid="`inbox-mentions-network-${network.platform}`"
                    :data-state="network.state"
                >
                    <button
                        type="button"
                        class="flex size-12 items-center justify-center rounded-full border border-dashed transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                        :class="
                            network.state === 'available' && canManageAccounts
                                ? 'border-border-strong text-foreground hover:bg-accent'
                                : 'cursor-default border-border'
                        "
                        :disabled="network.state !== 'available' || !canManageAccounts"
                        :aria-label="
                            network.state === 'available'
                                ? $t('inbox.mentions.connect', { network: getPlatformLabel(network.platform) })
                                : `${getPlatformLabel(network.platform)} · ${$t('inbox.mentions.coming_soon')}`
                        "
                        :data-testid="`inbox-mentions-connect-${network.platform}`"
                        @click="connectNetwork(network)"
                    >
                        <PlatformLogo :platform="network.platform" :size="24" plain />
                    </button>
                    <span
                        class="text-base leading-6 font-medium"
                        :class="network.state === 'available' ? 'text-foreground' : 'text-muted-foreground'"
                        >{{ getPlatformLabel(network.platform) }}</span
                    >
                    <span
                        v-if="network.state === 'coming_soon'"
                        class="rounded-full bg-secondary px-1 text-sm leading-[18px] font-medium text-foreground"
                        >{{ $t('inbox.mentions.coming_soon') }}</span
                    >
                </li>
            </ul>
        </div>
    </div>
</template>
