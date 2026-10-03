<script setup lang="ts">
import { computed } from 'vue';

import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { useXLinkDefuser } from '@/composables/useXLinkDefuser';
import type { MediaItem } from '@/types/media';
import { THREAD_PLATFORMS } from '@/types/network-options';

import BlueskyPreview from './BlueskyPreview.vue';
import DiscordPreview from './DiscordPreview.vue';
import FacebookPreview from './FacebookPreview.vue';
import GoogleBusinessPreview from './GoogleBusinessPreview.vue';
import InstagramPreview from './InstagramPreview.vue';
import LinkedInPreview from './LinkedInPreview.vue';
import MastodonPreview from './MastodonPreview.vue';
import PinterestPreview from './PinterestPreview.vue';
import TelegramPreview from './TelegramPreview.vue';
import ThreadsPreview from './ThreadsPreview.vue';
import TikTokPreview from './TikTokPreview.vue';
import type { PreviewAccount } from './types';
import XPreview from './XPreview.vue';
import YouTubePreview from './YouTubePreview.vue';

interface Props {
    platform: string;
    socialAccount: PreviewAccount | null | undefined;
    content: string;
    media: MediaItem[];
    contentType?: string;
    meta?: Record<string, any>;
    /** Local scheduled datetime (datetime-local); falls back to now in previews. */
    postedAt?: string | null;
}

const props = defineProps<Props>();

const { contentFor } = useXLinkDefuser();

/**
 * X publishes links defused (`acme(.)com`), so the preview has to show that or it
 * promises text the network never receives. Every preview goes through here, which
 * keeps the rewrite in one place on the client just as it is on the server.
 */
const previewContent = computed((): string => contentFor(props.content, props.platform));

const threadReplies = computed((): string[] =>
    THREAD_PLATFORMS.includes(props.platform) &&
    Array.isArray(props.meta?.thread_replies)
        ? props.meta.thread_replies.filter(
              (reply: string) => reply.trim() !== '',
          )
        : [],
);

const resolvedSocialAccount = computed((): PreviewAccount => props.socialAccount ?? {
    id: '',
    platform: props.platform,
    display_name: '',
    username: '',
    display_label: getPlatformLabel(props.platform),
    handle_label: getPlatformLabel(props.platform),
    avatar_url: null,
});

const previewComponent = computed(() => {
    switch (props.platform) {
        case 'linkedin':
        case 'linkedin-page':
            return LinkedInPreview;
        case 'x':
            return XPreview;
        case 'facebook':
            return FacebookPreview;
        case 'instagram':
        case 'instagram-facebook':
            return InstagramPreview;
        case 'threads':
            return ThreadsPreview;
        case 'tiktok':
            return TikTokPreview;
        case 'youtube':
            return YouTubePreview;
        case 'pinterest':
            return PinterestPreview;
        case 'bluesky':
            return BlueskyPreview;
        case 'mastodon':
            return MastodonPreview;
        case 'telegram':
            return TelegramPreview;
        case 'discord':
            return DiscordPreview;
        case 'google_business':
            return GoogleBusinessPreview;
        default:
            return LinkedInPreview;
    }
});
</script>

<template>
    <div
        class="overflow-hidden rounded-lg border bg-card text-card-foreground"
    >
        <div
            :data-testid="threadReplies.length ? 'preview-thread' : undefined"
        >
            <component
                :is="previewComponent"
                :social-account="resolvedSocialAccount"
                :content="previewContent"
                :media="media"
                :content-type="contentType"
                :meta="meta"
                :posted-at="postedAt"
                :thread-position="threadReplies.length ? 'first' : undefined"
            />
            <div
                v-for="(reply, index) in threadReplies"
                :key="index"
                :data-testid="`preview-thread-reply-${index}`"
            >
                <component
                    :is="previewComponent"
                    :social-account="resolvedSocialAccount"
                    :content="contentFor(reply, platform)"
                    :media="[]"
                    :content-type="contentType"
                    :meta="{ spoiler_text: meta?.spoiler_text }"
                    :posted-at="postedAt"
                    :thread-position="
                        index === threadReplies.length - 1 ? 'last' : 'middle'
                    "
                />
            </div>
        </div>
    </div>
</template>
