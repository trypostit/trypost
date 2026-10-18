<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import { Button } from '@/components/ui/button';
import { useAvatarFallback } from '@/composables/inbox/useAvatarFallback';
import { useNetworkConnect } from '@/composables/useNetworkConnect';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { inboxChannelName } from '@/lib/inbox';
import type { InboxChannel, InboxTab } from '@/types/inbox';
import { Platform } from '@/types/platform';
import type { AvailablePlatform } from '@/types/social-account';

const props = withDefaults(
    defineProps<{
        channel: InboxChannel;
        mentions?: boolean;
        messages?: boolean;
        tab?: InboxTab;
    }>(),
    { mentions: false, messages: false, tab: 'comments' },
);

const channelName = computed(() => inboxChannelName(props.channel));

/** A channel that lost access says what the open tab cannot show. */
const titleKey = computed(() => {
    if (props.messages) {
        return 'inbox.reconnect.messages_title';
    }

    if (props.mentions || props.tab === 'mentions') {
        return 'inbox.mentions.reconnect_title';
    }

    return props.tab === 'messages' ? 'inbox.reconnect.title_messages' : 'inbox.reconnect.title';
});

/** What reconnecting brings back on the open tab, said to the admin who can do it. */
const descriptionKey = computed(() => {
    if (props.mentions || props.tab === 'mentions') {
        return 'inbox.mentions.reconnect_description';
    }

    return props.tab === 'messages' ? 'inbox.reconnect.description_messages' : 'inbox.reconnect.description';
});

const page = usePage();
const { avatarSrc } = useAvatarFallback();
const { canManageAccounts } = useWorkspaceAbilities();
const { startConnect } = useNetworkConnect(
    () => (page.props.connectablePlatforms as AvailablePlatform[]) ?? [],
);

const reconnectChannel = (): void => {
    startConnect(props.channel.platform, props.channel.id);
};
</script>

<template>
    <div
        class="flex min-w-0 flex-wrap items-center gap-3 rounded-lg border border-border bg-warning/10 px-4 py-3"
        :data-testid="`inbox-reconnect-${channel.id}`"
    >
        <ChannelAvatar
            :platform="channel.platform"
            :name="channel.display_name || channel.username"
            :src="avatarSrc(channel.avatar_url)"
            :size="28"
            ring="card"
        />
        <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-foreground" :data-testid="`inbox-reconnect-title-${channel.id}`">
                {{ $t(titleKey, { name: channelName }) }}
            </p>
            <p
                v-if="messages && canManageAccounts"
                class="text-sm text-muted-foreground"
                :data-testid="`inbox-reconnect-messages-${channel.id}`"
            >
                {{
                    channel.platform === Platform.Bluesky
                        ? $t('inbox.reconnect.bluesky_messages_description')
                        : $t('inbox.reconnect.messages_description')
                }}
            </p>
            <p
                v-else-if="canManageAccounts"
                class="text-sm text-muted-foreground"
                :data-testid="`inbox-reconnect-description-${channel.id}`"
            >
                {{ $t(descriptionKey) }}
            </p>
            <p
                v-if="!canManageAccounts"
                class="text-sm text-muted-foreground"
            >
                {{ $t('inbox.reconnect.member_description') }}
            </p>
        </div>
        <Button
            v-if="canManageAccounts"
            variant="outline"
            :data-testid="`inbox-reconnect-button-${channel.id}`"
            @click="reconnectChannel"
        >
            {{ $t('inbox.reconnect.button') }}
        </Button>
    </div>
</template>
