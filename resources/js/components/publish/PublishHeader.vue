<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconCalendarEvent, IconSettings } from '@tabler/icons-vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import HeaderTitle from '@/components/HeaderTitle.vue';
import GoalProgress from '@/components/publish/GoalProgress.vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { settings } from '@/routes/app/channels';
import { channels as channelsSettings } from '@/routes/app/workspace';
import { channelName } from '@/types/channel';
import type { PublishChannel } from '@/types/publish';

defineProps<{
    channel: PublishChannel | null;
}>();

const { canManageAccounts } = useWorkspaceAbilities();
</script>

<template>
    <TooltipProvider :delay-duration="0">
        <div v-if="channel" class="flex min-w-0 items-center gap-4">
            <ChannelAvatar
                :status="channel.status"
                :account-id="channel.id"
                :platform="channel.platform"
                :src="channel.avatar_url"
                :verified="channel.verified_badge"
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
                    <Tooltip v-if="canManageAccounts">
                        <TooltipTrigger as-child>
                            <Button
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
                        </TooltipTrigger>
                        <TooltipContent
                            data-testid="publish-channel-settings-tooltip"
                        >
                            {{ $t('channels.settings') }}
                        </TooltipContent>
                    </Tooltip>
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
        <HeaderTitle v-else :icon="IconCalendarEvent">
            <div class="flex min-w-0 items-center gap-2">
                <h1
                    class="truncate font-heading text-xl leading-tight font-medium text-foreground"
                    data-testid="header-title"
                >
                    {{ $t('posts.publish.all_channels') }}
                </h1>
                <Tooltip v-if="canManageAccounts">
                    <TooltipTrigger as-child>
                        <Button
                            as-child
                            variant="ghost"
                            size="icon-xs"
                            class="shrink-0 text-muted-foreground"
                        >
                            <Link
                                :href="channelsSettings.url()"
                                :aria-label="$t('channels.settings')"
                                data-testid="publish-channels-settings"
                            >
                                <IconSettings class="size-4" />
                            </Link>
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent
                        data-testid="publish-channels-settings-tooltip"
                    >
                        {{ $t('channels.settings') }}
                    </TooltipContent>
                </Tooltip>
            </div>
        </HeaderTitle>
    </TooltipProvider>
</template>
