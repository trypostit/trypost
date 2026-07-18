<script setup lang="ts">
import { computed, ref } from 'vue';

import CommentsTab from '@/components/posts/editor/CommentsTab.vue';
import PreviewTab from '@/components/posts/editor/PreviewTab.vue';
import ScheduleTab from '@/components/posts/editor/ScheduleTab.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { PinterestBoard } from '@/types';
import type { MediaItem } from '@/types/media';

interface SocialAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    avatar_url: string | null;
}

interface PostPlatform {
    id: string;
    social_account_id: string | null;
    enabled: boolean;
    platform: string;
    platform_name: string | null;
    platform_username: string | null;
    platform_avatar: string | null;
    content_type: string | null;
    status: string;
    platform_url: string | null;
    error_message: string | null;
    published_at: string | null;
    social_account: SocialAccount | null;
    meta?: Record<string, any>;
}

interface Post {
    id: string;
    post_platforms: PostPlatform[];
}

interface TikTokCreatorInfo {
    creator_nickname: string | null;
    creator_username: string | null;
    creator_avatar_url: string | null;
    privacy_level_options: string[];
    comment_disabled: boolean;
    duet_disabled: boolean;
    stitch_disabled: boolean;
    max_video_post_duration_sec: number | null;
}

const props = defineProps<{
    post: Post;
    workspaceId: string;
    content: string;
    media: MediaItem[];
    selectedPlatformIds: string[];
    platformMeta: Record<string, Record<string, any>>;
    platformContentTypes: Record<string, string>;
    platformIssues: Record<string, string>;
    platformConfigs: Record<string, any>;
    labels: { id: string; name: string; color: string }[];
    selectedLabelIds: string[];
    tiktokCreatorInfos?: Record<string, TikTokCreatorInfo> | null;
    pinterestBoards?: Record<string, PinterestBoard[]> | null;
    isReadOnly: boolean;
    authUserId: string;
    initialHighlightCommentId: string | null;
}>();

const activeTab = defineModel<string>('activeTab', { required: true });

const emit = defineEmits<{
    (e: 'toggle-platform', platformId: string): void;
    (e: 'toggle-label', labelId: string): void;
    (e: 'update:platformMeta', platformId: string, meta: Record<string, any>): void;
    (e: 'update:platformContentType', platformId: string, contentType: string): void;
}>();

const commentsTabRef = ref<InstanceType<typeof CommentsTab> | null>(null);

const previewablePlatforms = computed(() =>
    props.post.post_platforms.filter((pp) => props.selectedPlatformIds.includes(pp.id)),
);

defineExpose({
    addCommentFromBroadcast: (comment: any) => commentsTabRef.value?.addCommentFromBroadcast(comment),
    registerMentionedUsers: (users: any) => commentsTabRef.value?.registerMentionedUsers(users),
});
</script>

<template>
    <Tabs v-model="activeTab" class="h-full flex flex-col">
        <TabsList class="mx-4 mt-4 hidden w-fit shrink-0 self-start lg:inline-flex">
            <TabsTrigger value="preview">{{ $t('posts.edit.tabs.preview') }}</TabsTrigger>
            <TabsTrigger value="schedule">{{ $t('posts.edit.tabs.channels') }}</TabsTrigger>
            <TabsTrigger value="comments">{{ $t('posts.edit.tabs.comments') }}</TabsTrigger>
        </TabsList>

        <TabsContent value="preview" class="flex-1 overflow-y-auto">
            <PreviewTab
                :platforms="previewablePlatforms"
                :content="content"
                :media="media"
                :platform-content-types="platformContentTypes"
                :platform-meta="platformMeta"
            />
        </TabsContent>

        <TabsContent value="schedule" force-mount data-testid="channels-panel" :class="['flex-1 overflow-y-auto p-4', { hidden: activeTab !== 'schedule' }]">
            <ScheduleTab
                :post-platforms="post.post_platforms"
                :selected-platform-ids="selectedPlatformIds"
                :labels="labels"
                :selected-label-ids="selectedLabelIds"
                :is-read-only="isReadOnly"
                :platform-configs="platformConfigs"
                :platform-meta="platformMeta"
                :platform-content-types="platformContentTypes"
                :platform-issues="platformIssues"
                :tiktok-creator-infos="tiktokCreatorInfos"
                :pinterest-boards="pinterestBoards"
                :media="media"
                @toggle-platform="(id) => emit('toggle-platform', id)"
                @toggle-label="(id) => emit('toggle-label', id)"
                @update:platform-meta="(id, meta) => emit('update:platformMeta', id, meta)"
                @update:platform-content-type="(id, contentType) => emit('update:platformContentType', id, contentType)"
            />
        </TabsContent>

        <TabsContent value="comments" class="flex-1 overflow-hidden">
            <CommentsTab
                ref="commentsTabRef"
                :post-id="post.id"
                :current-user-id="authUserId"
                :highlight-comment-id="initialHighlightCommentId"
            />
        </TabsContent>

    </Tabs>
</template>
