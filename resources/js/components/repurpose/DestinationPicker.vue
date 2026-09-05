<script setup lang="ts">
import { IconCheck } from '@tabler/icons-vue';
import { computed } from 'vue';

import PlatformLogo from '@/components/repurpose/PlatformLogo.vue';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import type { ChannelAccount } from '@/types/channel';
import type { RepurposeDestination } from '@/types/repurpose';

/**
 * Lists accounts, never networks: a workspace may hold two Instagram accounts
 * and both are valid destinations.
 */
const props = defineProps<{
    accounts: ChannelAccount[];
    contentTypes: Record<string, string>;
}>();

const destinations = defineModel<RepurposeDestination[]>({ default: () => [] });

const selectedIds = computed(() => destinations.value.map((destination) => destination.social_account_id));

const supported = computed(() => props.accounts.filter((account) => props.contentTypes[account.platform]));

const toggle = (account: ChannelAccount) => {
    if (selectedIds.value.includes(account.id)) {
        destinations.value = destinations.value.filter(
            (destination) => destination.social_account_id !== account.id,
        );

        return;
    }

    destinations.value = [
        ...destinations.value,
        {
            social_account_id: account.id,
            content_type: props.contentTypes[account.platform],
            meta: {},
        },
    ];
};
</script>

<template>
    <div class="space-y-3" data-testid="destination-picker">
        <p v-if="supported.length === 0" class="rounded-lg border-2 border-dashed border-foreground/20 p-4 text-sm text-muted-foreground">
            {{ $t('repurposes.destinations.none_available') }}
        </p>

        <div v-else class="grid gap-3 sm:grid-cols-2">
            <button
                v-for="account in supported"
                :key="account.id"
                type="button"
                class="group relative flex items-center gap-3 rounded-xl border-2 border-foreground p-3 text-left shadow-xs transition-shadow hover:shadow-md"
                :class="
                    selectedIds.includes(account.id)
                        ? 'bg-emerald-50'
                        : 'bg-card'
                "
                :data-testid="`destination-${account.id}`"
                @click="toggle(account)"
            >
                <PlatformLogo :platform="account.platform" />

                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-bold">{{ account.display_name }}</span>
                    <span class="block truncate text-xs text-muted-foreground">
                        {{ getPlatformLabel(account.platform) }}
                    </span>
                </span>

                <span
                    v-if="selectedIds.includes(account.id)"
                    class="absolute -top-2 -right-2 inline-flex size-6 items-center justify-center rounded-full border-2 border-foreground bg-emerald-200 text-emerald-700 shadow-2xs"
                    aria-hidden="true"
                >
                    <IconCheck class="size-3.5" stroke-width="3" />
                </span>
            </button>
        </div>

        <p class="text-xs text-muted-foreground">{{ $t('repurposes.destinations.hint') }}</p>
    </div>
</template>
