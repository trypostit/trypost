<script setup lang="ts">
import { computed } from 'vue';

import AiGeneratedRow from '@/components/posts/editor/AiGeneratedRow.vue';
import DiscordSettings from '@/components/posts/editor/DiscordSettings.vue';
import FacebookSettings from '@/components/posts/editor/FacebookSettings.vue';
import GoogleBusinessSettings from '@/components/posts/editor/GoogleBusinessSettings.vue';
import InstagramSettings from '@/components/posts/editor/InstagramSettings.vue';
import LinkedInSettings from '@/components/posts/editor/LinkedInSettings.vue';
import MastodonSettings from '@/components/posts/editor/MastodonSettings.vue';
import PinterestSettings from '@/components/posts/editor/PinterestSettings.vue';
import ThreadsSettings from '@/components/posts/editor/ThreadsSettings.vue';
import TikTokSettings from '@/components/posts/editor/TikTokSettings.vue';
import YouTubeSettings from '@/components/posts/editor/YouTubeSettings.vue';
import type {
    ComposerAccount,
    DestinationDraft,
} from '@/composables/usePostComposition';
import { isVideo } from '@/lib/mediaType';
import type { PinterestBoardsPayload } from '@/types';
import type { ChannelTikTokCreatorInfo } from '@/types/channel';
import { ContentType } from '@/types/content-type';
import type { MediaItem } from '@/types/media';
import { Platform } from '@/types/platform';

const props = withDefaults(
    defineProps<{
        account: ComposerAccount;
        destination: DestinationDraft & { content: string; media: MediaItem[] };
        platformIndex: number;
        platformConfig?: Record<string, any> | null;
        pinterestBoards?: PinterestBoardsPayload | null;
        tiktokCreatorInfo?: ChannelTikTokCreatorInfo | null;
        disabled?: boolean;
    }>(),
    {
        platformConfig: null,
        pinterestBoards: null,
        tiktokCreatorInfo: null,
        disabled: false,
    },
);

const emit = defineEmits<{ 'update:meta': [value: Record<string, any>] }>();

const videoDurationSec = computed(
    () =>
        Math.ceil(
            props.destination.media.find((item) => isVideo(item))?.meta
                ?.duration ?? 0,
        ) || null,
);

const update = (meta: Record<string, any>): void => emit('update:meta', meta);

const AI_GENERATED_ROWS: Partial<
    Record<string, { metaKey: string; testId: string }>
> = {
    [ContentType.YouTubeShort]: {
        metaKey: 'is_ai_generated',
        testId: 'youtube-ai-generated',
    },
    [ContentType.InstagramFeed]: {
        metaKey: 'is_ai_generated',
        testId: 'instagram-ai-generated',
    },
    [ContentType.InstagramReel]: {
        metaKey: 'is_ai_generated',
        testId: 'instagram-ai-generated',
    },
    [ContentType.InstagramStory]: {
        metaKey: 'is_ai_generated',
        testId: 'instagram-ai-generated',
    },
    [ContentType.TikTokVideo]: { metaKey: 'is_aigc', testId: 'tiktok-ai-generated' },
};

const aiRow = computed(
    () => AI_GENERATED_ROWS[props.destination.content_type] ?? null,
);
const aiGenerated = computed({
    get: (): boolean =>
        aiRow.value
            ? props.destination.meta?.[aiRow.value.metaKey] === true
            : false,
    set: (value: boolean) => {
        if (aiRow.value) {
            update({ ...props.destination.meta, [aiRow.value.metaKey]: value });
        }
    },
});
</script>

<template>
    <div class="contents" data-testid="composer-network-settings">
        <FacebookSettings
            v-if="account.platform === Platform.Facebook"
            :content-type="destination.content_type"
            :meta="destination.meta"
            :disabled="disabled"
            @update:meta="update"
        />
        <InstagramSettings
            v-else-if="
                account.platform === Platform.Instagram ||
                account.platform === Platform.InstagramFacebook
            "
            :content-type="destination.content_type"
            :meta="destination.meta"
            :disabled="disabled"
            @update:meta="update"
        />
        <ThreadsSettings
            v-else-if="
                account.platform === Platform.Threads &&
                destination.content_type !== ContentType.ThreadsGhostPost
            "
            :meta="destination.meta"
            :disabled="disabled"
            @update:meta="update"
        />
        <TikTokSettings
            v-else-if="account.platform === Platform.TikTok"
            :social-account="account"
            :publish-config="platformConfig?.publishConfig ?? null"
            :creator-info="tiktokCreatorInfo"
            :video-duration-sec="videoDurationSec"
            :content-type="destination.content_type"
            :meta="destination.meta"
            :disabled="disabled"
            @update:meta="update"
        />
        <YouTubeSettings
            v-else-if="account.platform === Platform.YouTube"
            :platform-index="platformIndex"
            :publish-config="platformConfig?.publishConfig ?? null"
            :content="destination.content"
            :meta="destination.meta"
            :disabled="disabled"
            @update:meta="update"
        />
        <PinterestSettings
            v-else-if="account.platform === Platform.Pinterest"
            :social-account="account"
            :boards="pinterestBoards?.boards ?? []"
            :boards-truncated="pinterestBoards?.truncated ?? false"
            :meta="destination.meta"
            :disabled="disabled"
            @update:meta="update"
        />
        <LinkedInSettings
            v-else-if="
                account.platform === Platform.LinkedIn ||
                account.platform === Platform.LinkedInPage
            "
            :account-id="account.id"
            :media="destination.media"
            :meta="destination.meta"
            :disabled="disabled"
            @update:meta="update"
        />
        <GoogleBusinessSettings
            v-else-if="account.platform === Platform.GoogleBusiness"
            :platform-index="platformIndex"
            :meta="destination.meta"
            :disabled="disabled"
            @update:meta="update"
        />
        <DiscordSettings
            v-else-if="account.platform === Platform.Discord"
            :social-account="account"
            :meta="destination.meta"
            :disabled="disabled"
            @update:meta="update"
        />
        <MastodonSettings
            v-else-if="account.platform === Platform.Mastodon"
            :meta="destination.meta"
            :disabled="disabled"
            @update:meta="update"
        />
        <AiGeneratedRow
            v-if="aiRow"
            v-model="aiGenerated"
            :test-id="aiRow.testId"
            :disabled="disabled"
        />
    </div>
</template>
