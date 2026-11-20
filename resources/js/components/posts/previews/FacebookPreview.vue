<script setup lang="ts">
import {
    IconDots,
    IconMessageCircle,
    IconShare3,
    IconThumbUp,
} from '@tabler/icons-vue';

import PreviewAvatar from '@/components/posts/previews/PreviewAvatar.vue';
import PreviewStoryHeader from '@/components/posts/previews/PreviewStoryHeader.vue';
import PreviewText from '@/components/posts/previews/PreviewText.vue';
import PreviewTextPost from '@/components/posts/previews/PreviewTextPost.vue';
import PreviewVerticalCard from '@/components/posts/previews/PreviewVerticalCard.vue';
import type { PreviewProps } from '@/components/posts/previews/types';
import date from '@/date';
import { facebookLinkPreviewUrl } from '@/lib/facebookLinkPreview';
import { ContentType } from '@/types/content-type';


defineProps<PreviewProps>();
</script>

<template>
    <PreviewVerticalCard
        v-if="contentType === ContentType.FacebookReel"
        data-testid="facebook-reel-preview"
        :media="media"
        :actions="[
            { icon: IconThumbUp },
            { icon: IconMessageCircle },
            { icon: IconShare3 },
            { icon: IconDots },
        ]"
    >
        <template #footer>
            <div class="flex items-center gap-2 font-semibold">
                <PreviewAvatar :account="socialAccount" class="size-8 text-xs" />
                <span class="truncate">{{ socialAccount.display_label }}</span>
            </div>
            <p v-if="content" class="truncate">
                <PreviewText :text="content" overlay />
            </p>
        </template>
    </PreviewVerticalCard>
    <PreviewVerticalCard
        v-else-if="contentType === ContentType.FacebookStory"
        data-testid="facebook-story-preview"
        :media="media"
    >
        <template #top>
            <PreviewStoryHeader
                :account="socialAccount"
                :name="socialAccount.display_label"
            />
        </template>
    </PreviewVerticalCard>
    <PreviewTextPost
        v-else
        data-testid="facebook-preview"
        variant="stacked"
        :account="socialAccount"
        :content="content"
        :media="media"
        :caption="date.formatPreviewPostedAt(postedAt, $t('common.just_now'))"
        caption-globe
        avatar-square
        :truncate="110"
        more-key="posts.composer.preview.see_more"
        media-layout="collage"
        mute-badge
        :link-card="meta?.link_preview !== false"
        :link-card-options="{ bleed: true, band: true, boldTitle: true }"
        :link-url="facebookLinkPreviewUrl"
        actions-style="inline-labels"
        :actions="[
            { icon: IconThumbUp, labelKey: 'posts.composer.preview.like' },
            {
                icon: IconMessageCircle,
                labelKey: 'posts.composer.preview.comment',
            },
            { icon: IconShare3, labelKey: 'posts.composer.preview.share' },
        ]"
        action-class="text-foreground"
    >
        <template #aside>
            <IconDots class="size-5 shrink-0 self-start text-muted-foreground" />
        </template>
    </PreviewTextPost>
</template>
