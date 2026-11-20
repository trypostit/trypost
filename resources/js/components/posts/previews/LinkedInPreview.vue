<script setup lang="ts">
import {
    IconMessage,
    IconRepeat,
    IconSend,
    IconThumbUp,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import LinkedInPreviewComment from '@/components/posts/previews/LinkedInPreviewComment.vue';
import PreviewTextPost from '@/components/posts/previews/PreviewTextPost.vue';
import type { PreviewProps } from '@/components/posts/previews/types';
import date from '@/date';
import { filled } from '@/lib/helpers';
import { threadRepliesOf } from '@/lib/threadReplies';

const props = defineProps<PreviewProps>();

const comments = computed(() =>
    threadRepliesOf(props.meta).filter((reply) => filled(reply.text)),
);
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
        <template v-if="filled(comments)" #after-actions>
            <LinkedInPreviewComment
                v-for="(comment, index) in comments"
                :key="comment.key"
                :account="socialAccount"
                :text="comment.text"
                :data-testid="`preview-comment-${index}`"
            />
        </template>
    </PreviewTextPost>
</template>
