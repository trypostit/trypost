<script setup lang="ts">
import {
    IconBookmark,
    IconChartBar,
    IconHeart,
    IconMessageCircle,
    IconRepeat,
    IconUpload,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import PreviewTextPost from '@/components/posts/previews/PreviewTextPost.vue';
import type { PreviewProps } from '@/components/posts/previews/types';
import XPreviewPoll from '@/components/posts/previews/XPreviewPoll.vue';
import { blank } from '@/lib/helpers';
import { type PreviewPoll, previewPollOf } from '@/lib/poll';

const props = defineProps<PreviewProps>();

const previewPoll = computed((): PreviewPoll | null =>
    previewPollOf(props.contentType, props.meta, props.publishConfig),
);

/** A poll shows no link card. */
const showsLinkCard = computed((): boolean => blank(previewPoll.value));
</script>

<template>
    <PreviewTextPost
        data-testid="x-preview"
        :account="socialAccount"
        :content="content"
        :media="media"
        :handle="`@${socialAccount.username || socialAccount.handle_label}`"
        content-testid="x-preview-content"
        media-layout="grid"
        :link-card="showsLinkCard"
        :actions="[
            { icon: IconMessageCircle },
            { icon: IconRepeat },
            { icon: IconHeart },
            { icon: IconChartBar },
        ]"
        :trailing-actions="[{ icon: IconBookmark }, { icon: IconUpload }]"
    >
        <template v-if="previewPoll" #default>
            <XPreviewPoll :poll="previewPoll.poll" :limits="previewPoll.limits" />
        </template>
    </PreviewTextPost>
</template>
