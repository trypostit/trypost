<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';

import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { useXLinkDefuser } from '@/composables/useXLinkDefuser';
import { type ThreadReply, threadRepliesOf } from '@/lib/threadReplies';
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
import VkPreview from './VkPreview.vue';
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
    /** The thread post being edited: -1 is the first post, otherwise the reply index. */
    activePost?: number;
}

const props = defineProps<Props>();

const { contentFor } = useXLinkDefuser();

/**
 * X publishes links defused (`acme(.)com`), so the preview has to show that or it
 * promises text the network never receives. Every preview goes through here, which
 * keeps the rewrite in one place on the client just as it is on the server.
 */
const previewContent = computed((): string => contentFor(props.content, props.platform));

const shownReplies = computed((): { reply: ThreadReply; index: number }[] =>
    THREAD_PLATFORMS.includes(props.platform)
        ? threadRepliesOf(props.meta)
              .map((reply, index) => ({ reply, index }))
              .filter(
                  ({ reply }) =>
                      reply.text.trim() !== '' || reply.media.length > 0,
              )
        : [],
);
const threadReplies = computed((): ThreadReply[] =>
    shownReplies.value.map(({ reply }) => reply),
);

const thread = ref<HTMLElement | null>(null);

/** Keeps the post being edited in view: an empty reply has no preview, so the closest one above it stands in. */
watch(
    () => props.activePost,
    async (active) => {
        if (active === undefined || !threadReplies.value.length) {
            return;
        }

        await nextTick();
        const position = shownReplies.value.filter(
            ({ index }) => index <= active,
        ).length;
        thread.value
            ?.querySelectorAll(':scope > [data-thread-post]')
            [position]?.scrollIntoView({
            block: 'nearest',
            behavior: 'smooth',
        });
    },
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
        case 'vk':
            return VkPreview;
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
            ref="thread"
            :data-testid="threadReplies.length ? 'preview-thread' : undefined"
        >
            <div data-thread-post>
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
            </div>
            <div
                v-for="(reply, index) in threadReplies"
                :key="reply.key"
                data-thread-post
                :data-testid="`preview-thread-reply-${index}`"
            >
                <component
                    :is="previewComponent"
                    :social-account="resolvedSocialAccount"
                    :content="contentFor(reply.text, platform)"
                    :media="reply.media"
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
