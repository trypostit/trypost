<script setup lang="ts">
import PreviewTextPost from '@/components/posts/previews/PreviewTextPost.vue';

import type { PreviewProps } from './types';

defineProps<PreviewProps>();
</script>

<template>
    <PreviewTextPost
        data-testid="telegram-preview"
        :account="socialAccount"
        :content="content"
        :media="media"
        :handle="socialAccount.handle_label"
        variant="stacked"
    />
</template>
