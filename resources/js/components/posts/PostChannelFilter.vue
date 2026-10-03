<script setup lang="ts">
import { IconLayoutGrid, IconPlus } from '@tabler/icons-vue';
import { computed } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import FilterEmptyState from '@/components/FilterEmptyState.vue';
import MultiSelectFilter from '@/components/MultiSelectFilter.vue';
import { Button } from '@/components/ui/button';
import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import type { SocialAccountStatusValue } from '@/types/social-account-status';

interface Channel {
    id: string;
    platform: string;
    display_label: string;
    username: string | null;
    avatar_url: string | null;
    status?: SocialAccountStatusValue | null;
}

const props = withDefaults(
    defineProps<{ channels: Channel[]; testId?: string }>(),
    { testId: 'posts-channel' },
);
const selectedIds = defineModel<string[]>({ required: true });

const options = computed(() =>
    props.channels.map((channel) => ({
        id: channel.id,
        label: channel.display_label,
        searchText: `${channel.display_label} ${channel.username ?? ''} ${getPlatformLabel(channel.platform)}`,
        ariaLabel: `${channel.display_label} (${getPlatformLabel(channel.platform)})`,
    })),
);

const channelsById = computed(
    () => new Map(props.channels.map((channel) => [channel.id, channel])),
);

const channelFor = (id: string): Channel => channelsById.value.get(id)!;

const { canManageAccounts } = useWorkspaceAbilities();
const { open: openConnectDialog } = useConnectChannelDialog();
</script>

<template>
    <MultiSelectFilter
        v-model="selectedIds"
        :options="options"
        :label="$t('posts.filter_by_channel')"
        :search-placeholder="$t('posts.channel_search_placeholder')"
        :empty-message="$t('posts.no_channels')"
        :select-all-label="$t('posts.composer.select_all')"
        :deselect-all-label="$t('posts.composer.deselect_all')"
        :test-id="testId"
        content-class="w-96"
    >
        <template #icon>
            <IconLayoutGrid class="size-4" />
        </template>
        <template #option="{ option }">
            <span class="flex min-w-0 items-center gap-3">
                <ChannelAvatar
                    :status="channelFor(option.id).status"
                    :account-id="channelFor(option.id).id"
                    :platform="channelFor(option.id).platform"
                    :src="channelFor(option.id).avatar_url"
                    :name="option.label"
                    ring="popover"
                />
                <span class="min-w-0 truncate">{{ option.label }}</span>
            </span>
        </template>
        <template v-if="channels.length === 0" #empty>
            <FilterEmptyState
                :icon="IconLayoutGrid"
                :title="$t('posts.no_channels')"
                :test-id="`${testId}-empty`"
            >
                <Button
                    v-if="canManageAccounts"
                    type="button"
                    size="sm"
                    :data-testid="`${testId}-connect`"
                    @click="openConnectDialog()"
                >
                    <IconPlus class="size-4" />
                    {{ $t('channels.connect') }}
                </Button>
            </FilterEmptyState>
        </template>
    </MultiSelectFilter>
</template>
