<script setup lang="ts">
import PostDetailsDialog from '@/components/app/publish/PostDetailsDialog.vue';
import PostRecurrenceDialog from '@/components/app/publish/PostRecurrenceDialog.vue';
import { usePostCardMenuActions } from '@/composables/app/publish/usePostCardMenuActions';
import type { PostCard } from '@/types/app/publish';

const props = defineProps<{
    post: PostCard;
    timezone: string;
}>();

const {
    detailsOpen,
    recurrenceOpen,
    recurrenceTarget,
    recurrenceTimezone,
    edit,
    runPostAction,
} = usePostCardMenuActions(() => props.post, {
    timezone: () => props.timezone,
    detailsOpen: true,
});
</script>

<template>
    <div class="contents">
        <PostDetailsDialog
            v-model:open="detailsOpen"
            :post="post"
            :test-key="post.id"
            :timezone="timezone"
            @select="runPostAction"
            @edit="edit"
        />
        <PostRecurrenceDialog
            v-model:open="recurrenceOpen"
            :post="recurrenceTarget"
            :test-key="post.id"
            :timezone="recurrenceTimezone"
        />
    </div>
</template>
