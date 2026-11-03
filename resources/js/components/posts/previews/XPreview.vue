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
import { pollLimitsOf } from '@/lib/xPoll';
import { ContentType } from '@/types/content-type';
import type { XPoll } from '@/types/x';

const props = defineProps<PreviewProps>();

const poll = computed((): Partial<XPoll> | null =>
    props.contentType === ContentType.XPoll ? (props.meta?.poll ?? null) : null,
);

const pollLimits = computed(() => pollLimitsOf(props.publishConfig));
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
        :link-card="!poll"
        :actions="[
            { icon: IconMessageCircle },
            { icon: IconRepeat },
            { icon: IconHeart },
            { icon: IconChartBar },
        ]"
        :trailing-actions="[{ icon: IconBookmark }, { icon: IconUpload }]"
    >
        <XPreviewPoll v-if="poll && pollLimits" :poll="poll" :limits="pollLimits" />
    </PreviewTextPost>
</template>
