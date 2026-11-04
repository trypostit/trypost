<script setup lang="ts">
import {
    IconMessage,
    IconRepeat,
    IconSend,
    IconThumbUp,
} from '@tabler/icons-vue';

import PreviewFirstComment from '@/components/posts/previews/PreviewFirstComment.vue';
import PreviewTextPost from '@/components/posts/previews/PreviewTextPost.vue';
import type { PreviewProps } from '@/components/posts/previews/types';
import date from '@/date';
import { filled } from '@/lib/helpers';


defineProps<PreviewProps>();
</script>

<template>
    <PreviewTextPost
        data-testid="linkedin-preview"
        variant="stacked"
        media-layout="peek"
        :account="socialAccount"
        :content="content"
        :media="media"
        :caption="date.formatPreviewPostedAt(postedAt, $t('common.just_now'))"
        caption-globe
        text-class="text-sm"
        :truncate="140"
        more-below
        :link-card="meta?.link_preview !== false"
        :link-card-options="{
            bleed: true,
            band: true,
            titleFirst: true,
            boldTitle: true,
        }"
        actions-style="column-labels"
        :actions="[
            { icon: IconThumbUp, labelKey: 'posts.composer.preview.like' },
            { icon: IconMessage, labelKey: 'posts.composer.preview.comment' },
            { icon: IconRepeat, labelKey: 'posts.composer.preview.repost' },
            { icon: IconSend, labelKey: 'posts.composer.preview.send' },
        ]"
    >
        <template v-if="filled(meta?.first_comment?.text)" #after-actions>
            <PreviewFirstComment
                :account="socialAccount"
                :text="meta?.first_comment?.text"
            />
        </template>
    </PreviewTextPost>
</template>
