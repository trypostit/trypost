<script setup lang="ts">
import {
    IconBookmark,
    IconChartBar,
    IconHeart,
    IconMessageCircle,
    IconRepeat,
    IconUpload,
} from '@tabler/icons-vue';

import PreviewTextPost from '@/components/posts/previews/PreviewTextPost.vue';

import type { PreviewProps } from './types';

defineProps<PreviewProps>();
</script>

<template>
    <PreviewTextPost
        data-testid="x-preview"
        :account="socialAccount"
        :content="content"
        :media="media"
        :handle="`@${socialAccount.username || socialAccount.handle_label}`"
        content-testid="x-preview-content"
        media-layout="stack"
        link-card
        :actions="[
            { icon: IconMessageCircle },
            { icon: IconRepeat },
            { icon: IconHeart },
            { icon: IconChartBar },
        ]"
        :trailing-actions="[{ icon: IconBookmark }, { icon: IconUpload }]"
    />
</template>
