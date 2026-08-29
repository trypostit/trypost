<script setup lang="ts">
import { computed } from 'vue';

import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { useXLinkDefuser } from '@/composables/useXLinkDefuser';
import type { MediaItem } from '@/types/media';

import BlueskyPreview from './BlueskyPreview.vue';
import DiscordPreview from './DiscordPreview.vue';
import FacebookPreview from './FacebookPreview.vue';
import InstagramPreview from './InstagramPreview.vue';
import LinkedInPreview from './LinkedInPreview.vue';
import MastodonPreview from './MastodonPreview.vue';
import PinterestPreview from './PinterestPreview.vue';
import TelegramPreview from './TelegramPreview.vue';
import ThreadsPreview from './ThreadsPreview.vue';
import TikTokPreview from './TikTokPreview.vue';
import XPreview from './XPreview.vue';
import YouTubePreview from './YouTubePreview.vue';

interface SocialAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    display_label: string;
    handle_label: string;
    avatar_url: string | null;
}

interface Props {
    platform: string;
    socialAccount: SocialAccount | null | undefined;
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

const resolvedSocialAccount = computed((): SocialAccount => props.socialAccount ?? {
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
        default:
            return LinkedInPreview;
    }
});
</script>

<template>
    <component
        :is="previewComponent"
        :social-account="resolvedSocialAccount"
        :content="previewContent"
        :media="media"
        :content-type="contentType"
        :meta="meta"
        :posted-at="postedAt"
    />
</template>
