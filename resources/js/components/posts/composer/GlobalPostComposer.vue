<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import PostComposerDialog from '@/components/posts/composer/PostComposerDialog.vue';
import { clearComposerAutosave } from '@/composables/useComposerAutosave';
import { useComposerData } from '@/composables/useComposerData';
import {
    closePostComposer,
    openPostComposer,
    postComposerRequest,
} from '@/composables/useGlobalPostComposer';
import type { PostComposition } from '@/composables/usePostComposition';
import { store as storePost } from '@/routes/app/posts';
import type { SharedData } from '@/types';

const page = usePage<SharedData>();
const composerData = useComposerData();
const {
    composer,
    socialAccounts,
    platformConfigs,
    signatures,
    labels,
    pinterestBoards,
    tiktokCreatorInfos,
} = composerData;
const submitting = ref(false);

const flashRequest = computed(
    () =>
        (
            page.props.flash as
                | {
                      openPostComposer?: {
                          date?: string | null;
                          assistant?: boolean;
                      };
                  }
                | undefined
        )?.openPostComposer,
);

watch(
    flashRequest,
    (request) => {
        if (request) openPostComposer(request);
    },
    { immediate: true },
);

watch(
    postComposerRequest,
    (request) => {
        if (request) {
            void composerData.load();
        } else {
            composerData.reset();
        }
    },
    { immediate: true },
);

const onOpenChange = (open: boolean): void => {
    if (!open) closePostComposer();
};

const submitComposition = (
    composition: PostComposition,
    createAnother: boolean,
    onSaved?: () => void,
): void => {
    submitting.value = true;
    const payload: Record<string, any> = { ...composition };
    router.post(storePost.url(), payload, {
        preserveScroll: true,
        onSuccess: () => {
            clearComposerAutosave(
                page.props.auth?.user?.id,
                page.props.auth?.currentWorkspace?.id,
            );
            closePostComposer();
            if (createAnother) openPostComposer();
            onSaved?.();
        },
        onFinish: () => {
            submitting.value = false;
        },
    });
};
</script>

<template>
    <PostComposerDialog
        v-if="postComposerRequest && composer"
        :key="postComposerRequest.id"
        :open="true"
        :social-accounts="socialAccounts"
        :labels="labels"
        :signatures="signatures"
        :initial-date="postComposerRequest.date"
        :initial-account-ids="postComposerRequest.socialAccountIds"
        :initial-queue-slot="postComposerRequest.queueSlot"
        :initial-draft="postComposerRequest.draft"
        :open-assistant="postComposerRequest.assistant"
        :submitting="submitting"
        :platform-configs="platformConfigs"
        :pinterest-boards="pinterestBoards"
        :tiktok-creator-infos="tiktokCreatorInfos"
        @update:open="onOpenChange"
        @submit="submitComposition"
    />
</template>
