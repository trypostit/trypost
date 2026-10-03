<script setup lang="ts">
import {
    IconBookmark,
    IconDots,
    IconHeart,
    IconMessageCircle,
    IconRepeat,
    IconUpload,
} from '@tabler/icons-vue';

import PreviewTextPost from '@/components/posts/previews/PreviewTextPost.vue';
import date from '@/date';

import type { PreviewProps } from './types';

defineProps<PreviewProps>();
</script>

<template>
    <PreviewTextPost
        data-testid="bluesky-preview"
        :account="socialAccount"
        :content="content"
        :media="media"
        :handle="`@${socialAccount.username || socialAccount.handle_label}`"
        :caption="date.formatPreviewPostedAt(postedAt, $t('common.just_now'))"
        link-tone="info"
        host-only
        :actions="[
            { icon: IconMessageCircle },
            { icon: IconRepeat },
            { icon: IconHeart },
        ]"
        :trailing-actions="[
            { icon: IconBookmark },
            { icon: IconUpload },
            { icon: IconDots },
        ]"
    />
</template>
