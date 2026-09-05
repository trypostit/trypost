<script setup lang="ts">
import { IconCheck } from '@tabler/icons-vue';
import { computed } from 'vue';

import PlatformLogo from '@/components/repurpose/PlatformLogo.vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import type { ChannelAccount } from '@/types/channel';
import type { DestinationFormat, RepurposeDestination } from '@/types/repurpose';

/**
 * Lists accounts, never networks: a workspace may hold two Instagram accounts
 * and both are valid destinations. Each selected account picks the format it
 * publishes as, so a Story from the source can land as a Reel here.
 */
const props = defineProps<{
    accounts: ChannelAccount[];
    formats: Record<string, DestinationFormat[]>;
}>();

const destinations = defineModel<RepurposeDestination[]>({ default: () => [] });

const selectedIds = computed(() => destinations.value.map((destination) => destination.social_account_id));

const supported = computed(() => props.accounts.filter((account) => (props.formats[account.id] ?? []).length > 0));

const contentTypeFor = (accountId: string) =>
    destinations.value.find((destination) => destination.social_account_id === accountId)?.content_type ?? '';

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
            content_type: props.formats[account.id]?.[0]?.value ?? '',
            meta: {},
        },
    ];
};

const setContentType = (accountId: string, contentType: string) => {
    destinations.value = destinations.value.map((destination) =>
        destination.social_account_id === accountId ? { ...destination, content_type: contentType } : destination,
    );
};
</script>

<template>
    <div class="space-y-3" data-testid="destination-picker">
        <p
            v-if="supported.length === 0"
            class="rounded-xl border-2 border-dashed border-foreground/20 p-4 text-sm text-muted-foreground"
        >
            {{ $t('repurposes.destinations.none_available') }}
        </p>

        <div v-else class="grid gap-3 sm:grid-cols-2">
            <div
                v-for="account in supported"
                :key="account.id"
                class="group relative rounded-xl border-2 border-foreground shadow-xs transition-shadow hover:shadow-md"
                :class="selectedIds.includes(account.id) ? 'bg-emerald-50' : 'bg-card'"
            >
                <button
                    type="button"
                    class="flex w-full items-center gap-3 p-3 text-left"
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
                </button>

                <span
                    v-if="selectedIds.includes(account.id)"
                    class="absolute -top-2 -right-2 inline-flex size-6 items-center justify-center rounded-full border-2 border-foreground bg-emerald-200 text-emerald-700 shadow-2xs"
                    aria-hidden="true"
                >
                    <IconCheck class="size-3.5" stroke-width="3" />
                </span>

                <div v-if="selectedIds.includes(account.id) && (formats[account.id] ?? []).length > 1" class="px-3 pb-3">
                    <p class="mb-1 text-[11px] font-black uppercase tracking-widest text-foreground/60">
                        {{ $t('repurposes.destinations.publish_as') }}
                    </p>

                    <Select
                        :model-value="contentTypeFor(account.id)"
                        @update:model-value="(value) => setContentType(account.id, String(value))"
                    >
                        <SelectTrigger :data-testid="`destination-format-${account.id}`">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="format in formats[account.id]"
                                :key="format.value"
                                :value="format.value"
                            >
                                {{ format.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>
        </div>

        <p class="text-xs text-muted-foreground">{{ $t('repurposes.destinations.hint') }}</p>
    </div>
</template>
