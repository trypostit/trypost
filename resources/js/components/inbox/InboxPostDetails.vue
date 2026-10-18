<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import {
    destroy as destroyPost,
    edit as editPost,
} from '@/actions/App/Http/Controllers/App/PostController';
import { show as showPostGroup } from '@/actions/App/Http/Controllers/App/PostGroupController';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import PostDetailsDialog from '@/components/publish/PostDetailsDialog.vue';
import {
    copyPostCardLink,
    duplicatePostCard,
    schedulePostCard,
    sharePostCardLink,
} from '@/composables/usePostCardActions';
import { userTimezone } from '@/preferences';
import type { PostCard, PostCardMenuAction } from '@/types/publish';

const props = defineProps<{
    postId: string;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    loading: [isLoading: boolean];
}>();

const http = useHttp<Record<string, never>, PostCard[]>({});
const post = ref<PostCard | null>(null);
const dialogOpen = ref(false);
const deleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

const loadPost = async (): Promise<void> => {
    emit('loading', true);

    try {
        const posts = await http.get(showPostGroup.url(props.postId));
        post.value = (posts ?? []).find((card) => card.id === props.postId) ?? null;
    } catch {
        post.value = null;
    } finally {
        emit('loading', false);
    }

    if (!post.value) {
        open.value = false;
        toast.error(trans('inbox.conversation.post_failed'));

        return;
    }

    dialogOpen.value = true;
};

watch(open, (value) => {
    if (value) {
        loadPost();
    } else {
        dialogOpen.value = false;
    }
});

watch(dialogOpen, (value) => {
    if (!value) {
        open.value = false;
    }
});

const editCard = (card: PostCard): void => {
    router.visit(editPost.url(card.id));
};

const runAction = (action: PostCardMenuAction, card: PostCard): void => {
    switch (action) {
        case 'publish_now':
            schedulePostCard(card.id, 'publish_now');
            break;
        case 'move_drafts':
            schedulePostCard(card.id, 'draft');
            break;
        case 'duplicate':
            duplicatePostCard(card);
            break;
        case 'share_link':
            sharePostCardLink(card);
            break;
        case 'copy_link':
            copyPostCardLink(card);
            break;
        case 'delete':
            deleteModal.value?.open({ url: destroyPost.url(card.id) });
            break;
    }
};
</script>

<template>
    <div class="contents">
        <PostDetailsDialog
            v-if="post"
            v-model:open="dialogOpen"
            :post="post"
            :test-key="post.id"
            :timezone="userTimezone"
            @select="runAction"
            @edit="editCard"
        />
        <ConfirmDeleteModal
            ref="deleteModal"
            :title="$t('posts.edit.delete_modal.title')"
            :description="$t('posts.edit.delete_modal.description')"
            :action="$t('posts.edit.delete_modal.action')"
            :cancel="$t('posts.edit.delete_modal.cancel')"
        />
    </div>
</template>
