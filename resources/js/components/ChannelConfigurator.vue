<script setup lang="ts">
import { IconAlertCircle, IconExternalLink } from '@tabler/icons-vue';
import { computed } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import ChannelMediaWarnings from '@/components/posts/editor/ChannelMediaWarnings.vue';
import ContentTypeRadioGroup from '@/components/posts/editor/ContentTypeRadioGroup.vue';
import DiscordSettings from '@/components/posts/editor/DiscordSettings.vue';
import LinkedInSettings from '@/components/posts/editor/LinkedInSettings.vue';
import PinterestSettings from '@/components/posts/editor/PinterestSettings.vue';
import TikTokSettings from '@/components/posts/editor/TikTokSettings.vue';
import YouTubeSettings from '@/components/posts/editor/YouTubeSettings.vue';
import { Switch } from '@/components/ui/switch';
import { isDocumentMedia } from '@/composables/useMedia';
import {
    getContentTypeOptions,
    getPlatformLabel,
} from '@/composables/usePlatformLogo';
import type { Channel } from '@/types/channel';
import type { MediaItem } from '@/types/media';
import { Platform } from '@/types/platform';

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

const selectedChannels = computed(() =>
    props.channels.filter((channel) => isSelected(channel.id)),
);

const SETTINGS_PLATFORMS: string[] = [
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

const toggleChannel = (channel: Channel): void => {
    emit('toggle', channel.id);
};

const settingsIndex = (channel: Channel): number =>
    selectedChannels.value.findIndex((item) => item.id === channel.id);
</script>

<template>
    <div class="flex flex-col gap-4">
        <ul
            class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card"
            data-testid="channel-configurator"
        >
            <li
                v-for="channel in channels"
                :key="channel.id"
                :data-testid="`channel-row-${channel.id}`"
            >
                <div class="flex items-center gap-3 px-4 py-3">
                    <ChannelAvatar
                        :platform="channel.platform"
                        :src="channel.avatarUrl"
                        :name="channel.displayName"
                        :size="32"
                        ring="card"
                    />
                    <div class="min-w-0 flex-1">
                        <p
                            class="truncate text-sm leading-5 font-emphasis text-foreground"
                        >
                            {{ channel.displayName }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ getPlatformLabel(channel.platform)
                            }}<template v-if="channel.username">
                                · @{{ channel.username }}</template
                            >
                        </p>
                        <p
                            v-if="isSelected(channel.id) && channel.issue"
                            class="mt-1 flex items-start gap-1.5 text-xs text-destructive-text"
                            :data-testid="`channel-issue-${channel.id}`"
                        >
                            <IconAlertCircle class="mt-px size-3.5 shrink-0" />
                            <span class="min-w-0">
                                {{ channel.issue }}
                                <a
                                    v-if="channel.issueDocsUrl"
                                    :href="channel.issueDocsUrl"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="ms-1 inline-flex items-center gap-1 font-medium underline underline-offset-2"
                                    :data-testid="`channel-issue-docs-${channel.id}`"
                                >
                                    {{
                                        $t(
                                            'posts.edit.compliance.media_limits_docs',
                                        )
                                    }}
                                    <IconExternalLink class="size-3" />
                                </a>
                            </span>
                        </p>
                    </div>
                    <Switch
                        :model-value="isSelected(channel.id)"
                        :disabled="disabled"
                        :aria-label="channel.displayName"
                        :data-testid="`channel-${channel.id}`"
                        @update:model-value="toggleChannel(channel)"
                    />
                </div>

                <div
                    v-if="isSelected(channel.id) && hasSettingsCard(channel)"
                    class="flex flex-col gap-3 border-t border-border bg-muted px-4 py-4 sm:ps-[60px] [&>[data-testid=channel-settings-rows]:first-child]:border-t-0 [&>[data-testid=channel-settings-rows]:first-child]:pt-0"
                    :data-testid="`channel-settings-${channel.id}`"
                >
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
                    <TikTokSettings
                        v-if="channel.platform === Platform.TikTok"
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
                        :platform-index="settingsIndex(channel)"
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
            </li>
        </ul>

        <slot />
    </div>
</template>
