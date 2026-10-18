<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import { Button } from '@/components/ui/button';
import { useAvatarFallback } from '@/composables/inbox/useAvatarFallback';
import { useNetworkConnect } from '@/composables/useNetworkConnect';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import date from '@/date';
import { inboxChannelName, inboxWriteOptions } from '@/lib/inbox';
import { store as syncChannel } from '@/routes/app/inbox/channel-sync';
import type { InboxChannel, InboxSyncError, InboxTab } from '@/types/inbox';
import { Platform } from '@/types/platform';
import type { AvailablePlatform } from '@/types/social-account';

const props = defineProps<{
    channel: InboxChannel;
    error: InboxSyncError;
    tab: InboxTab;
}>();

const emit = defineEmits<{
    dismiss: [channel: InboxChannel, error: InboxSyncError];
}>();

const INSTAGRAM_PLATFORMS: string[] = [Platform.Instagram, Platform.InstagramFacebook];

const page = usePage();
const { canManageAccounts, canPublishDirectly } = useWorkspaceAbilities();
const { avatarSrc } = useAvatarFallback();
const { startConnect } = useNetworkConnect(
    () => (page.props.connectablePlatforms as AvailablePlatform[]) ?? [],
);

const name = computed(() => inboxChannelName(props.channel));
const network = computed(() => getPlatformLabel(props.channel.platform));
const isMessagesUnavailable = computed(() => props.error.sync_error === 'messages_unavailable');

/** Reviews read as their own word on a channel that has no comments. */
const noun = computed(() => {
    if (props.error.kind === 'review') {
        return 'reviews';
    }

    return props.tab;
});

const titleKey = computed(() =>
    isMessagesUnavailable.value ? 'inbox.sync_error.messages_unavailable_title' : `inbox.sync_error.title_${noun.value}`,
);

const descriptionKey = computed(() => {
    if (isMessagesUnavailable.value) {
        if (!canManageAccounts.value) {
            return 'inbox.sync_error.messages_unavailable_member';
        }

        return INSTAGRAM_PLATFORMS.includes(props.channel.platform)
            ? 'inbox.sync_error.messages_unavailable_instagram'
            : 'inbox.sync_error.messages_unavailable_description';
    }

    if (props.error.sync_error === 'permission') {
        return canManageAccounts.value ? 'inbox.sync_error.permission_description' : 'inbox.sync_error.permission_member';
    }

    if (!canManageAccounts.value) {
        return 'inbox.sync_error.failing_member';
    }

    return props.error.last_synced_at ? 'inbox.sync_error.failing_description' : 'inbox.sync_error.failing_description_never';
});

const lastSynced = computed(() => date.formatMonthDayTime(props.error.last_synced_at));

const reconnectChannel = (): void => {
    startConnect(props.channel.platform, props.channel.id);
};

const isRetrying = ref(false);

const retrySync = (): void => {
    router.post(
        syncChannel.url(props.channel.id),
        { kind: props.error.kind },
        inboxWriteOptions(null, {
            onStart: () => {
                isRetrying.value = true;
            },
            onFinish: () => {
                isRetrying.value = false;
            },
        }),
    );
};

const dismissBanner = (): void => {
    emit('dismiss', props.channel, props.error);
};
</script>

<template>
    <div
        class="flex min-w-0 flex-wrap items-center gap-3 rounded-lg border border-border bg-warning/10 px-4 py-3"
        :data-testid="`inbox-sync-error-${channel.id}`"
        :data-category="error.sync_error"
        :data-kind="error.kind"
        role="status"
    >
        <ChannelAvatar
            :platform="channel.platform"
            :name="channel.display_name || channel.username"
            :src="avatarSrc(channel.avatar_url)"
            :size="28"
            ring="card"
        />
        <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-foreground" :data-testid="`inbox-sync-error-title-${channel.id}`">
                {{ $t(titleKey, { name }) }}
            </p>
            <p class="text-sm text-muted-foreground" :data-testid="`inbox-sync-error-description-${channel.id}`">
                {{ $t(descriptionKey, { name, network, time: lastSynced }) }}
            </p>
        </div>
        <div class="flex shrink-0 items-center justify-end gap-2 max-sm:w-full">
            <Button
                variant="ghost"
                :data-testid="`inbox-sync-error-dismiss-${channel.id}`"
                @click="dismissBanner"
            >
                {{ $t('inbox.sync_error.not_now') }}
            </Button>
            <Button
                v-if="canManageAccounts && !isMessagesUnavailable"
                variant="outline"
                :data-testid="`inbox-sync-error-reconnect-${channel.id}`"
                @click="reconnectChannel"
            >
                {{ $t('inbox.reconnect.button') }}
            </Button>
            <Button
                v-if="canPublishDirectly"
                :loading="isRetrying"
                :data-testid="`inbox-sync-error-retry-${channel.id}`"
                @click="retrySync"
            >
                {{ $t('inbox.sync_error.try_again') }}
            </Button>
        </div>
    </div>
</template>
