<script setup lang="ts">
import {
    IconAlertCircle,
    IconBan,
    IconCircleCheck,
    IconExternalLink,
    IconHourglass,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import ChannelMediaWarnings from '@/components/posts/editor/ChannelMediaWarnings.vue';
import ContentTypeRadioGroup from '@/components/posts/editor/ContentTypeRadioGroup.vue';
import DiscordSettings from '@/components/posts/editor/DiscordSettings.vue';
import FacebookSettings from '@/components/posts/editor/FacebookSettings.vue';
import LinkedInSettings from '@/components/posts/editor/LinkedInSettings.vue';
import PinterestSettings from '@/components/posts/editor/PinterestSettings.vue';
import TikTokSettings from '@/components/posts/editor/TikTokSettings.vue';
import YouTubeSettings from '@/components/posts/editor/YouTubeSettings.vue';
import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { isDocumentMedia } from '@/composables/useMedia';
import {
    getContentTypeOptions,
    getPlatformLabel,
} from '@/composables/usePlatformLogo';
import type { Channel } from '@/types/channel';
import type { MediaItem } from '@/types/media';
import { Platform } from '@/types/platform';
import { PostPlatformStatus } from '@/types/post';

const props = withDefaults(
    defineProps<{
        channels: Channel[];
        selectedIds: string[];
        media?: MediaItem[];
        videoDurationSec?: number | null;
        disabled?: boolean;
        previewOnly?: boolean;
    }>(),
    {
        media: () => [],
        videoDurationSec: null,
        disabled: false,
        previewOnly: false,
    },
);

const emit = defineEmits<{
    toggle: [id: string];
    'update:contentType': [id: string, value: string];
    'update:meta': [id: string, value: Record<string, any>];
}>();

const isSelected = (id: string): boolean => props.selectedIds.includes(id);

// Order matches the `platforms` array the editor submits (both filter the same
// post_platforms list by the same selection), so a settings panel's position
// here is the `platforms.{index}.*` index its backend errors are keyed by.
const selectedChannels = computed(() =>
    props.channels.filter((channel) => isSelected(channel.id)),
);

const SETTINGS_PLATFORMS: string[] = [
    Platform.Facebook,
    Platform.TikTok,
    Platform.Pinterest,
    Platform.YouTube,
    Platform.Discord,
];

const hasDocument = computed(() =>
    props.media.some((item) => isDocumentMedia(item)),
);

const hasSettingsCard = (channel: Channel): boolean =>
    getContentTypeOptions(channel.platform).length > 1 ||
    channel.platform === Platform.Instagram ||
    channel.platform === Platform.InstagramFacebook ||
    SETTINGS_PLATFORMS.includes(channel.platform) ||
    ((channel.platform === Platform.LinkedIn ||
        channel.platform === Platform.LinkedInPage) &&
        hasDocument.value);

const updateMeta = (channel: Channel, value: Record<string, any>) =>
    emit('update:meta', channel.id, value);
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap gap-3">
            <TooltipProvider
                v-for="channel in channels"
                :key="channel.id"
                :delay-duration="200"
            >
                <Tooltip>
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            class="flex w-20 cursor-pointer flex-col items-center gap-1.5 transition-opacity hover:opacity-90"
                            :aria-pressed="isSelected(channel.id)"
                            :data-testid="`channel-${channel.id}`"
                            @click="emit('toggle', channel.id)"
                        >
                            <ChannelAvatar
                                :platform="channel.platform"
                                :src="channel.avatarUrl"
                                :name="channel.displayName"
                                :size="40"
                                ring="card"
                                :reserve-space="false"
                                :avatar-class="[
                                    'rounded-full border',
                                    isSelected(channel.id)
                                        ? [
                                              'shadow-xs ring-2',
                                              channel.issue
                                                  ? 'border-rose-500 ring-rose-200'
                                                  : 'border-primary-strong ring-primary-subtle',
                                          ]
                                        : 'border-border',
                                ]"
                            >
                                <Badge
                                    v-if="
                                        channel.status ===
                                        PostPlatformStatus.Published
                                    "
                                    variant="success"
                                    class="absolute -top-1 -right-1 h-4 w-4 p-0"
                                >
                                    <IconCircleCheck class="h-2.5 w-2.5" />
                                </Badge>
                                <Badge
                                    v-else-if="
                                        channel.status ===
                                        PostPlatformStatus.PendingReview
                                    "
                                    variant="warning"
                                    class="absolute -top-1 -right-1 h-4 w-4 p-0"
                                >
                                    <IconHourglass class="h-2.5 w-2.5" />
                                </Badge>
                                <Badge
                                    v-else-if="
                                        channel.status ===
                                        PostPlatformStatus.Rejected
                                    "
                                    variant="destructive"
                                    class="absolute -top-1 -right-1 h-4 w-4 p-0"
                                >
                                    <IconBan class="h-2.5 w-2.5" />
                                </Badge>
                                <Badge
                                    v-else-if="
                                        channel.status ===
                                        PostPlatformStatus.Failed
                                    "
                                    variant="destructive"
                                    class="absolute -top-1 -right-1 h-4 w-4 p-0 text-[9px]"
                                    >!</Badge
                                >
                                <Badge
                                    v-else-if="channel.issue"
                                    variant="destructive"
                                    class="absolute -top-1 -right-1 h-4 w-4 p-0"
                                    :data-testid="`channel-issue-${channel.id}`"
                                >
                                    <IconAlertCircle class="h-2.5 w-2.5" />
                                </Badge>
                            </ChannelAvatar>
                            <span
                                class="line-clamp-2 text-center text-xs leading-tight"
                                :class="
                                    isSelected(channel.id)
                                        ? 'font-bold text-foreground'
                                        : 'font-medium text-foreground/70'
                                "
                            >
                                {{ channel.displayName }}
                            </span>
                        </button>
                    </TooltipTrigger>
                    <TooltipContent>
                        <div class="space-y-0.5 text-xs">
                            <p class="font-semibold">
                                {{ channel.displayName
                                }}<span
                                    v-if="channel.username"
                                    class="font-normal opacity-80"
                                    >&nbsp;·&nbsp;@{{ channel.username }}</span
                                >
                            </p>
                            <p class="opacity-70">
                                {{ getPlatformLabel(channel.platform) }}
                            </p>
                            <p
                                v-if="channel.issue"
                                class="mt-1 max-w-xs text-destructive-foreground/90"
                            >
                                {{ channel.issue }}
                            </p>
                            <a
                                v-if="channel.issue && channel.issueDocsUrl"
                                :href="channel.issueDocsUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="mt-1 inline-flex items-center gap-1 font-semibold underline underline-offset-2"
                                :data-testid="`channel-issue-docs-${channel.id}`"
                            >
                                {{
                                    $t(
                                        'posts.edit.compliance.media_limits_docs',
                                    )
                                }}
                                <IconExternalLink class="size-3" />
                            </a>
                        </div>
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </div>

        <slot />

        <template
            v-for="(channel, index) in selectedChannels"
            :key="channel.id"
        >
            <div
                v-if="hasSettingsCard(channel)"
                class="flex gap-3 rounded-xl border border-border bg-card p-3"
                :data-testid="`channel-settings-${channel.id}`"
            >
                <PlatformLogo :platform="channel.platform" :size="24" />
                <div class="flex min-w-0 flex-1 flex-col gap-3">
                    <p class="truncate text-sm font-medium text-foreground">
                        {{ channel.displayName }}
                    </p>
                    <ContentTypeRadioGroup
                        v-if="getContentTypeOptions(channel.platform).length > 1"
                        :options="getContentTypeOptions(channel.platform)"
                        :model-value="channel.contentType"
                        :test-id-prefix="`channel-type-${channel.id}`"
                        :disabled="disabled"
                        :error="channel.contentTypeError"
                        @update:model-value="
                            emit('update:contentType', channel.id, $event)
                        "
                    />
                    <ChannelMediaWarnings
                        :platform="channel.platform"
                        :content-type="channel.contentType"
                        :media="media"
                        :disabled="disabled"
                    />
                    <FacebookSettings
                        v-if="channel.platform === Platform.Facebook"
                        :content-type="channel.contentType"
                        :meta="channel.meta"
                        :disabled="disabled"
                        @update:meta="updateMeta(channel, $event)"
                    />
                    <TikTokSettings
                        v-else-if="channel.platform === Platform.TikTok"
                        :social-account="channel.socialAccount"
                        :publish-config="channel.publishConfig ?? null"
                        :creator-info="channel.creatorInfo ?? null"
                        :video-duration-sec="videoDurationSec"
                        :content-type="channel.contentType"
                        :meta="channel.meta"
                        :disabled="disabled"
                        @update:meta="updateMeta(channel, $event)"
                    />
                    <PinterestSettings
                        v-else-if="
                            channel.platform === Platform.Pinterest &&
                            channel.socialAccount
                        "
                        :social-account="channel.socialAccount"
                        :boards="channel.boards ?? []"
                        :boards-truncated="channel.boardsTruncated ?? false"
                        :meta="channel.meta"
                        :disabled="disabled"
                        @update:meta="updateMeta(channel, $event)"
                    />
                    <LinkedInSettings
                        v-else-if="
                            channel.platform === Platform.LinkedIn ||
                            channel.platform === Platform.LinkedInPage
                        "
                        :account-id="channel.id"
                        :media="media"
                        :meta="channel.meta"
                        :disabled="disabled"
                        @update:meta="updateMeta(channel, $event)"
                    />
                    <YouTubeSettings
                        v-else-if="channel.platform === Platform.YouTube"
                        :platform-index="index"
                        :publish-config="channel.publishConfig ?? null"
                        :meta="channel.meta"
                        :disabled="disabled"
                        @update:meta="updateMeta(channel, $event)"
                    />
                    <DiscordSettings
                        v-else-if="channel.platform === Platform.Discord"
                        :social-account="channel.socialAccount"
                        :meta="channel.meta"
                        :disabled="disabled"
                        @update:meta="updateMeta(channel, $event)"
                    />
                </div>
            </div>
        </template>
    </div>
</template>
