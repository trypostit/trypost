<script setup lang="ts">
import { computed } from 'vue';

import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
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
        <p v-if="supported.length === 0" class="text-sm text-muted-foreground">
            {{ $t('repurposes.destinations.none_available') }}
        </p>

        <div v-else class="grid gap-3 sm:grid-cols-2">
            <label
                v-for="account in supported"
                :key="account.id"
                class="flex cursor-pointer items-center gap-3 rounded-lg border-2 border-foreground/10 p-3 transition hover:border-foreground/30"
                :data-testid="`destination-${account.id}`"
            >
                <Checkbox
                    :model-value="selectedIds.includes(account.id)"
                    @update:model-value="toggle(account)"
                />

                <span class="min-w-0">
                    <span class="block truncate text-sm font-semibold">{{ account.display_label }}</span>
                    <span class="block truncate text-xs text-muted-foreground">{{ account.platform }}</span>
                </span>
            </label>
        </div>

        <Label class="text-xs text-muted-foreground">
            {{ $t('repurposes.destinations.hint') }}
        </Label>
    </div>
</template>
