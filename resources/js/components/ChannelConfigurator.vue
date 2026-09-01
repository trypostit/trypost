<script setup lang="ts">
import { IconAlertCircle, IconCircleCheck } from '@tabler/icons-vue';
import { computed } from 'vue';

import DiscordSettings from '@/components/posts/editor/DiscordSettings.vue';
import FacebookSettings from '@/components/posts/editor/FacebookSettings.vue';
import GoogleBusinessSettings from '@/components/posts/editor/GoogleBusinessSettings.vue';
import InstagramSettings from '@/components/posts/editor/InstagramSettings.vue';
import LinkedInSettings from '@/components/posts/editor/LinkedInSettings.vue';
import PinterestSettings from '@/components/posts/editor/PinterestSettings.vue';
import TikTokSettings from '@/components/posts/editor/TikTokSettings.vue';
import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { getPlatformLabel, getPlatformLogo } from '@/composables/usePlatformLogo';
import type { Channel } from '@/types/channel';
import type { MediaItem } from '@/types/media';
import { Platform } from '@/types/platform';
import { PostPlatformStatus } from '@/types/post';

const props = withDefaults(defineProps<{
    channels: Channel[];
    selectedIds: string[];
    media?: MediaItem[];
    videoDurationSec?: number | null;
    disabled?: boolean;
    previewOnly?: boolean;
}>(), {
    media: () => [],
    videoDurationSec: null,
    disabled: false,
    previewOnly: false,
});

const emit = defineEmits<{
    toggle: [id: string];
    'update:contentType': [id: string, value: string];
    'update:meta': [id: string, value: Record<string, any>];
}>();

const isSelected = (id: string): boolean => props.selectedIds.includes(id);

// Order matches the `platforms` array the editor submits (both filter the same
// post_platforms list by the same selection), so a settings panel's position
// here is the `platforms.{index}.*` index its backend errors are keyed by.
const selectedChannels = computed(() => props.channels.filter((channel) => isSelected(channel.id)));
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap gap-3">
            <TooltipProvider v-for="channel in channels" :key="channel.id" :delay-duration="200">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            class="flex w-20 cursor-pointer flex-col items-center gap-1.5 transition-opacity"
                            :class="[
                                channel.issue && !isSelected(channel.id) ? 'cursor-not-allowed opacity-40' : '',
                                channel.issue && isSelected(channel.id) ? 'opacity-100' : '',
                                !channel.issue ? 'opacity-100 hover:opacity-90' : '',
                            ]"
                            :disabled="Boolean(channel.issue) && !isSelected(channel.id)"
                            @click="emit('toggle', channel.id)"
                        >
                            <div class="relative">
                                <Avatar
                                    :src="channel.avatarUrl"
                                    :name="channel.displayName"
                                    class="size-10 shrink-0 rounded-full border-2"
                                    :class="[
                                        channel.issue && isSelected(channel.id) ? 'border-rose-500 shadow-2xs' : '',
                                        !channel.issue && isSelected(channel.id) ? 'border-foreground shadow-2xs' : '',
                                        !isSelected(channel.id) ? 'border-foreground/20' : '',
                                    ]"
                                />
                                <span class="absolute -bottom-1 -right-1 inline-flex size-5 items-center justify-center overflow-hidden rounded-full border-2 border-foreground bg-card shadow-2xs">
                                    <img :src="getPlatformLogo(channel.platform)" :alt="channel.platform" class="size-full object-cover" />
                                </span>
                                <Badge
                                    v-if="channel.issue && isSelected(channel.id)"
                                    variant="destructive"
                                    class="absolute -top-1 -right-1 h-4 w-4 p-0"
                                >
                                    <IconAlertCircle class="h-2.5 w-2.5" />
                                </Badge>
                                <Badge v-else-if="channel.status === PostPlatformStatus.Published" variant="success" class="absolute -top-1 -right-1 h-4 w-4 p-0">
                                    <IconCircleCheck class="h-2.5 w-2.5" />
                                </Badge>
                                <Badge v-else-if="channel.status === PostPlatformStatus.Failed" variant="destructive" class="absolute -top-1 -right-1 h-4 w-4 p-0 text-[9px]">!</Badge>
                            </div>
                            <span
                                class="line-clamp-2 text-center text-xs leading-tight"
                                :class="isSelected(channel.id) ? 'font-bold text-foreground' : 'font-medium text-foreground/70'"
                            >
                                {{ channel.displayName }}
                            </span>
                        </button>
                    </TooltipTrigger>
                    <TooltipContent>
                        <div class="space-y-0.5 text-xs">
                            <p class="font-semibold">
                                {{ channel.displayName }}<span v-if="channel.username" class="font-normal opacity-80">&nbsp;·&nbsp;@{{ channel.username }}</span>
                            </p>
                            <p class="opacity-70">{{ getPlatformLabel(channel.platform) }}</p>
                            <p v-if="channel.issue" class="mt-1 max-w-xs text-destructive-foreground/90">
                                {{ channel.issue }}
                            </p>
                        </div>
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </div>

        <slot />

        <template v-for="(channel, index) in selectedChannels" :key="channel.id">
            <InstagramSettings
                v-if="channel.platform === Platform.Instagram || channel.platform === Platform.InstagramFacebook"
                :social-account="channel.socialAccount"
                :content-type="channel.contentType"
                :media="media"
                :meta="channel.meta"
                :disabled="disabled"
                :preview-only="previewOnly"
                @update:content-type="emit('update:contentType', channel.id, $event)"
                @update:meta="emit('update:meta', channel.id, $event)"
            />
            <FacebookSettings
                v-else-if="channel.platform === Platform.Facebook"
                :social-account="channel.socialAccount"
                :content-type="channel.contentType"
                :media="media"
                :meta="channel.meta"
                :disabled="disabled"
                :preview-only="previewOnly"
                @update:content-type="emit('update:contentType', channel.id, $event)"
                @update:meta="emit('update:meta', channel.id, $event)"
            />
            <TikTokSettings
                v-else-if="channel.platform === Platform.TikTok"
                :social-account="channel.socialAccount"
                :publish-config="channel.publishConfig ?? null"
                :creator-info="channel.creatorInfo ?? null"
                :video-duration-sec="videoDurationSec"
                :content-type="channel.contentType"
                :content-type-error="channel.contentTypeError"
                :meta="channel.meta"
                :disabled="disabled"
                :preview-only="previewOnly"
                @update:content-type="emit('update:contentType', channel.id, $event)"
                @update:meta="emit('update:meta', channel.id, $event)"
            />
            <PinterestSettings
                v-else-if="channel.platform === Platform.Pinterest"
                :social-account="channel.socialAccount"
                :content-type="channel.contentType"
                :media="media"
                :boards="channel.boards ?? []"
                :boards-truncated="channel.boardsTruncated ?? false"
                :meta="channel.meta"
                :disabled="disabled"
                :preview-only="previewOnly"
                @update:content-type="emit('update:contentType', channel.id, $event)"
                @update:meta="emit('update:meta', channel.id, $event)"
            />
            <LinkedInSettings
                v-else-if="channel.platform === Platform.LinkedIn || channel.platform === Platform.LinkedInPage"
                :social-account="channel.socialAccount"
                :platform="channel.platform"
                :media="media"
                :meta="channel.meta"
                :disabled="disabled"
                :preview-only="previewOnly"
                @update:meta="emit('update:meta', channel.id, $event)"
            />
            <DiscordSettings
                v-else-if="channel.platform === Platform.Discord"
                :social-account="channel.socialAccount"
                :meta="channel.meta"
                :disabled="disabled"
                :preview-only="previewOnly"
                @update:meta="emit('update:meta', channel.id, $event)"
            />
            <GoogleBusinessSettings
                v-else-if="channel.platform === Platform.GoogleBusiness"
                :social-account="channel.socialAccount"
                :platform-index="index"
                :meta="channel.meta"
                :disabled="disabled"
                :preview-only="previewOnly"
                @update:meta="emit('update:meta', channel.id, $event)"
            />
        </template>
    </div>
</template>
