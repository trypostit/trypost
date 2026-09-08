<script setup lang="ts">
import { IconPlus } from '@tabler/icons-vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed } from 'vue';

import SocialAccountCard from '@/components/accounts/SocialAccountCard.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import type {
    AvailablePlatform,
    ConnectedAccount,
} from '@/types/social-account';

const props = withDefaults(
    defineProps<{
        platforms: AvailablePlatform[];
        connectedAccounts?: ConnectedAccount[];
    }>(),
    {
        connectedAccounts: () => [],
    },
);

const emit = defineEmits<{
    connect: [platform: string];
    reconnect: [account: ConnectedAccount];
    disconnect: [account: ConnectedAccount];
    toggle: [account: ConnectedAccount];
}>();

interface NetworkGroup {
    platform: AvailablePlatform;
    title: string;
    accounts: ConnectedAccount[];
}

/**
 * One section per network, in catalog order, even when nothing is connected
 * yet so every option stays visible. Variants (LinkedIn profile/page,
 * Instagram direct/via Facebook) collapse into the network they belong to.
 */
const groups = computed<NetworkGroup[]>(() =>
    props.platforms.map((platform) => ({
        platform,
        title: platform.label.split('(')[0].trim(),
        accounts: props.connectedAccounts.filter(
            (account) => account.network === platform.network,
        ),
    })),
);

const variantLabel = (
    group: NetworkGroup,
    account: ConnectedAccount,
): string | undefined =>
    account.platform === group.platform.value
        ? undefined
        : trans(`accounts.variants.${account.platform}`);
</script>

<template>
    <div class="space-y-8">
        <section
            v-for="group in groups"
            :key="group.platform.network"
            class="space-y-3"
            :data-testid="`network-group-${group.platform.network}`"
        >
            <header class="flex items-center gap-2.5">
                <PlatformLogo
                    :platform="group.platform.value"
                    size="sm"
                    :tilt="false"
                />
                <h2
                    class="truncate text-lg leading-tight font-semibold text-foreground"
                >
                    {{ group.title }}
                </h2>
                <span class="text-sm text-foreground/50">
                    {{
                        group.accounts.length > 0
                            ? transChoice(
                                  'accounts.accounts_count',
                                  group.accounts.length,
                                  { count: String(group.accounts.length) },
                              )
                            : $t('accounts.not_connected')
                    }}
                </span>
            </header>

            <div
                class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4"
            >
                <SocialAccountCard
                    v-for="account in group.accounts"
                    :key="account.id"
                    :account="account"
                    :variant-label="variantLabel(group, account)"
                    @reconnect="emit('reconnect', $event)"
                    @disconnect="emit('disconnect', $event)"
                    @toggle="emit('toggle', $event)"
                />

                <button
                    type="button"
                    class="flex items-center gap-3 rounded-xl border-2 border-dashed border-foreground/40 px-3 py-2.5 text-left text-foreground/60 transition-colors hover:border-foreground hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    :data-testid="
                        group.accounts.length > 0
                            ? `connect-another-${group.platform.value}`
                            : `connect-${group.platform.value}`
                    "
                    @click="emit('connect', group.platform.value)"
                >
                    <span
                        class="inline-flex size-10 shrink-0 items-center justify-center rounded-full border-2 border-dashed border-current"
                    >
                        <IconPlus class="size-4" stroke-width="2.5" />
                    </span>
                    <span class="truncate text-sm font-semibold">
                        {{
                            group.accounts.length > 0
                                ? $t('accounts.connect_another')
                                : $t('accounts.connect')
                        }}
                    </span>
                </button>
            </div>
        </section>
    </div>
</template>
