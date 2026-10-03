<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconCalendarEvent, IconSettings } from '@tabler/icons-vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import HeaderTitle from '@/components/HeaderTitle.vue';
import GoalProgress from '@/components/publish/GoalProgress.vue';
import { Button } from '@/components/ui/button';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { settings } from '@/routes/app/channels';
import { channelName } from '@/types/channel';
import type { PublishChannel } from '@/types/publish';

defineProps<{
    channel: PublishChannel | null;
}>();

const { canManageAccounts } = useWorkspaceAbilities();
</script>

<template>
    <div v-if="channel" class="flex min-w-0 items-center gap-4">
        <ChannelAvatar
            :status="channel.status"
            :account-id="channel.id"
            :platform="channel.platform"
            :src="channel.avatar_url"
            :name="channelName(channel)"
            :size="44"
        />
        <div class="min-w-0">
            <div class="flex min-w-0 items-center gap-2">
                <h1
                    class="truncate font-heading text-xl leading-6 font-medium text-foreground"
                    data-testid="header-title"
                >
                    {{ channelName(channel) }}
                </h1>
                <Button
                    v-if="canManageAccounts"
                    as-child
                    variant="ghost"
                    size="icon-xs"
                    class="shrink-0 text-muted-foreground"
                >
                    <Link
                        :href="settings.url(channel.id)"
                        :aria-label="$t('channels.settings')"
                        data-testid="publish-channel-settings"
                    >
                        <IconSettings class="size-4" />
                    </Link>
                </Button>
            </div>
            <GoalProgress
                v-if="channel.posting_goal !== null"
                :channel-id="channel.id"
                :sent="channel.sent_this_week"
                :scheduled="channel.scheduled_this_week"
                :goal="channel.posting_goal"
            />
        </div>
    </div>
    <HeaderTitle
        v-else
        :title="$t('posts.publish.all_channels')"
        :icon="IconCalendarEvent"
    />
</template>
