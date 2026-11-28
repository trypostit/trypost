<script setup lang="ts">
import PreviewText from '@/components/app/posts/previews/PreviewText.vue';

defineProps<{
    author: string;
    text: string;
}>();
</script>

<template>
    <p
        class="mt-2 line-clamp-2 break-words whitespace-pre-line"
        data-testid="instagram-first-comment-preview"
    >
        <span class="mr-1 font-semibold">{{ author }}</span>
        <PreviewText :text="text" :links="false" />
    </p>
</template>
