<script setup lang="ts">
import { router, useHttp, usePage } from '@inertiajs/vue3';
import { IconLoader2 } from '@tabler/icons-vue';
import { computed, provide, ref, watch } from 'vue';

import PostComposerDialog from '@/components/posts/composer/PostComposerDialog.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    closePostComposer,
    openPostComposer,
    postComposerRequest,
} from '@/composables/useGlobalPostComposer';
import type {
    ComposerAccount,
    PostComposition,
} from '@/composables/usePostComposition';
import { xLinkTldsKey } from '@/composables/useXLinkDefuser';
import {
    composerData as composerDataRoute,
    store as storePost,
} from '@/routes/app/posts';

type ComposerData = {
    socialAccounts: ComposerAccount[];
    platformConfigs: Record<string, any>;
    pinterestBoards: Record<string, any>;
    tiktokCreatorInfos: Record<string, any>;
    signatures: { id: string; name: string; content: string }[];
    labels: { id: string; name: string; color: string }[];
    xLinkTlds: string[];
};

const page = usePage();
const http = useHttp<Record<string, never>, ComposerData>({});
const data = ref<ComposerData | null>(null);
const loading = ref(false);
const loadFailed = ref(false);
const submitting = ref(false);
let latestLoad = 0;

provide(
    xLinkTldsKey,
    computed(() => data.value?.xLinkTlds ?? []),
);

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

const loadComposerData = async (): Promise<void> => {
    const loadId = ++latestLoad;
    loading.value = true;
    loadFailed.value = false;
    data.value = null;
    try {
        const result = (await http.get(
            composerDataRoute.url(),
        )) as ComposerData;
        if (loadId === latestLoad) data.value = result;
    } catch {
        if (loadId === latestLoad) loadFailed.value = true;
    } finally {
        if (loadId === latestLoad) loading.value = false;
    }
};

watch(
    postComposerRequest,
    (request) => {
        if (request) {
            void loadComposerData();
        } else {
            ++latestLoad;
            data.value = null;
            loadFailed.value = false;
        }
    },
    { immediate: true },
);

const submitComposition = (
    composition: PostComposition,
    createAnother: boolean,
): void => {
    submitting.value = true;
    const payload: Record<string, any> = { ...composition };
    router.post(storePost.url(), payload, {
        preserveScroll: true,
        onSuccess: () => {
            closePostComposer();
            if (createAnother) openPostComposer();
        },
        onFinish: () => {
            submitting.value = false;
        },
    });
};
</script>

<template>
    <PostComposerDialog
        v-if="postComposerRequest && data"
        :key="postComposerRequest.id"
        :open="true"
        :social-accounts="data.socialAccounts"
        :current-user-id="(page.props.auth as any)?.user?.id"
        :labels="data.labels"
        :signatures="data.signatures"
        :initial-date="postComposerRequest.date"
        :open-assistant="postComposerRequest.assistant"
        :submitting="submitting"
        :platform-configs="data.platformConfigs"
        :pinterest-boards="data.pinterestBoards"
        :tiktok-creator-infos="data.tiktokCreatorInfos"
        @update:open="if (!$event) closePostComposer();"
        @submit="submitComposition"
    />
    <Dialog
        v-else
        :open="Boolean(postComposerRequest)"
        @update:open="if (!$event) closePostComposer();"
    >
        <DialogContent
            class="sm:max-w-md"
            data-testid="global-composer-loading"
        >
            <DialogHeader
                ><DialogTitle>{{
                    $t('posts.create.title')
                }}</DialogTitle></DialogHeader
            >
            <div v-if="loading" class="flex justify-center p-8">
                <IconLoader2 class="size-6 animate-spin" />
            </div>
            <div v-else-if="loadFailed" class="space-y-4 text-sm">
                <p role="alert">{{ $t('posts.composer.load_failed') }}</p>
                <Button type="button" @click="loadComposerData">{{
                    $t('posts.composer.retry')
                }}</Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
