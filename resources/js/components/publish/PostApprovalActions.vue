<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconX } from '@tabler/icons-vue';

import { reject as rejectPostRoute } from '@/actions/App/Http/Controllers/App/PostApprovalController';
import ApprovePostButton from '@/components/publish/ApprovePostButton.vue';
import { Button } from '@/components/ui/button';
import {
    schedulePostCard,
    toastFirstError,
    usePostCardReload,
} from '@/composables/usePostCardActions';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import type { PostCard } from '@/types/publish';

/**
 * What a post pending approval offers on its card: approve and reject for an
 * approver, take the request back for the member who asked.
 */
const props = defineProps<{
    post: PostCard;
    testKey: string;
}>();

const { canApprove } = useWorkspaceAbilities();

const approvalReload = usePostCardReload({
    only: ['posts', 'counts', 'queue', 'hasData'],
    reset: ['posts'],
});

const rejectPost = (): void => {
    router.put(
        rejectPostRoute.url(props.post.id),
        {},
        { ...approvalReload(), preserveScroll: true, onError: toastFirstError },
    );
};

const revertRequest = (): void => {
    schedulePostCard(props.post.id, 'draft', approvalReload());
};
</script>

<template>
    <template v-if="canApprove">
        <ApprovePostButton :post="post" :test-key="testKey" />
        <Button
            variant="outline"
            class="text-destructive-text"
            :data-testid="`post-reject-${testKey}`"
            @click="rejectPost"
        >
            <IconX class="size-4" />
            {{ $t('posts.approvals.reject') }}
        </Button>
    </template>
    <Button
        v-else
        variant="outline"
        :data-testid="`post-revert-${testKey}`"
        @click="revertRequest"
    >
        <IconX class="size-4" />
        {{ $t('posts.approvals.revert') }}
    </Button>
</template>
