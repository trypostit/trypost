<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { IconArrowLeft, IconCalendar, IconExternalLink, IconFileTypePdf, IconLoader2 } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import ImagePreviewDialog from '@/components/ImagePreviewDialog.vue';
import LabelBadge from '@/components/labels/LabelBadge.vue';
import PostPlatformMetrics from '@/components/posts/PostPlatformMetrics.vue';
import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { usePostEcho } from '@/composables/echo/usePostEcho';
import { getPlatformLabel, getPlatformLogo } from '@/composables/usePlatformLogo';
import { getPlatformStatusConfig, getPostStatusConfig } from '@/composables/usePostStatus';
import dayjs from '@/dayjs';
import AppLayout from '@/layouts/AppLayout.vue';
import { classify, isDocument as isDocumentItem, isVideo as isVideoItem, MediaType } from '@/lib/mediaType';
import { index as postsIndex } from '@/routes/app/posts';
import type { MediaItem } from '@/types/media';
import { PostPlatformStatus, PostStatus } from '@/types/post';

interface SocialAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    avatar_url: string | null;
}

interface PostPlatform {
    id: string;
    platform: string;
    display_name: string;
    display_username: string | null;
    display_avatar: string | null;
    content_type: string | null;
    status: 'pending' | 'publishing' | 'published' | 'failed';
    platform_url: string | null;
    error_message: string | null;
    published_at: string | null;
    enabled: boolean;
    social_account: SocialAccount | null;
}

interface Post {
    id: string;
    content: string;
    media: MediaItem[];
    status: string;
    scheduled_at: string | null;
    published_at: string | null;
    platforms: PostPlatform[];
    labels?: { id: string; name: string; color: string }[];
}

interface Workspace {
    id: string;
    name: string;
}

const props = defineProps<{
    workspace: Workspace;
    post: Post;
}>();

const enabledPlatforms = computed(() => props.post.platforms.filter((pp) => pp.enabled));

const isPublishing = computed(() => props.post.status === PostStatus.Publishing);

const postStatus = computed(() => getPostStatusConfig(props.post.status));

const pageTitle = computed(() => {
    const snippet = props.post.content?.trim().split('\n')[0]?.slice(0, 60) ?? '';
    return snippet ? `${trans('posts.show.title')} · ${snippet}${props.post.content.length > 60 ? '…' : ''}` : trans('posts.show.title');
});

const getDisplayName = (pp: PostPlatform): string => pp.display_name ?? pp.platform;

const getDisplayUsername = (pp: PostPlatform): string | null => pp.display_username;

const getDisplayAvatar = (pp: PostPlatform): string | null => pp.display_avatar;

const formatDateTime = (date: string | null): string =>
    date ? dayjs.utc(date).local().format('D MMM YYYY, HH:mm') : '';

const lightbox = ref<InstanceType<typeof ImagePreviewDialog> | null>(null);

const openLightbox = (i: number) => {
    const collection = props.post.media.map((m) => ({
        url: m.url,
        type: classify(m) ?? MediaType.Image,
    }));
    lightbox.value?.openCollection(collection, i);
};

usePostEcho(props.post.id, '.post.platform.status.updated', () => {
    router.reload({ only: ['post'] });
});
</script>

<template>
    <Head :title="pageTitle" />

    <AppLayout full-width>
        <!-- Publishing state: clean centered loader, nothing else visible. -->
        <div v-if="isPublishing" class="flex flex-1 flex-col items-center justify-center gap-4 p-6">
            <div class="inline-flex size-14 -rotate-3 items-center justify-center rounded-2xl border-2 border-foreground bg-violet-200 shadow-2xs">
                <IconLoader2 class="size-7 animate-spin text-foreground" stroke-width="2" />
            </div>
            <p
                class="text-2xl font-semibold leading-tight text-foreground"
                style="font-family: var(--font-display)"
            >
                {{ $t('posts.edit.publishing_overlay_title') }}
            </p>
            <p class="max-w-md text-center text-sm text-foreground/70">
                {{ $t('posts.edit.publishing_overlay_subtitle') }}
            </p>
        </div>

        <div v-else class="flex flex-col flex-1 min-h-0">
            <header class="flex shrink-0 flex-col gap-3 border-b-2 border-foreground bg-card px-4 py-3 md:flex-row md:items-center md:justify-between md:px-6">
                <div class="pl-12 md:pl-0">
                    <Link :href="postsIndex.url()">
                        <Button variant="outline">
                            <IconArrowLeft class="size-4" />
                            {{ $t('posts.show.back') }}
                        </Button>
                    </Link>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 md:flex-nowrap md:justify-end">
                    <span class="flex items-start gap-1.5 text-sm font-medium text-foreground/70">
                        <IconCalendar class="mt-0.5 size-4 shrink-0 text-foreground/60" />
                        <span v-if="post.published_at">
                            {{ $t('posts.show.published_on', { date: formatDateTime(post.published_at) }) }}
                        </span>
                        <span v-else-if="post.scheduled_at">
                            {{ $t('posts.show.scheduled_for', { date: formatDateTime(post.scheduled_at) }) }}
                        </span>
                        <span v-else>{{ $t('posts.show.draft') }}</span>
                    </span>
                    <Badge :variant="postStatus.variant" class="shrink-0">
                        <component :is="postStatus.icon" class="size-3" />
                        {{ postStatus.label }}
                    </Badge>
                </div>
            </header>

            <div class="grid flex-1 grid-cols-1 overflow-y-auto lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:overflow-hidden">
                <!-- LEFT: post preview (mirrors PostEditorComposer layout) -->
                <div class="border-b-2 border-foreground/10 lg:overflow-y-auto lg:border-b-0 lg:border-r-2 lg:border-foreground">
                    <div class="mx-auto max-w-2xl px-6 py-10">
                        <!-- Media grid (top) — separate rounded tiles, 4-col -->
                        <div v-if="post.media.length > 0" class="mb-6">
                            <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                                <button
                                    v-for="(item, i) in post.media"
                                    :key="item.id"
                                    type="button"
                                    class="group relative aspect-square cursor-zoom-in overflow-hidden rounded-xl border-2 border-foreground bg-muted shadow-2xs transition-all focus:outline-none focus:ring-2 focus:ring-foreground focus:ring-offset-2"
                                    @click="openLightbox(i)"
                                >
                                    <video
                                        v-if="isVideoItem(item)"
                                        :src="item.url"
                                        class="h-full w-full object-cover"
                                        muted
                                    />
                                    <div
                                        v-else-if="isDocumentItem(item)"
                                        class="flex h-full w-full flex-col items-center justify-center gap-1.5 bg-rose-50 p-2 text-center"
                                    >
                                        <IconFileTypePdf class="size-8 text-rose-600" />
                                        <span class="line-clamp-2 break-all text-[10px] font-medium text-foreground/70">{{ item.original_filename || 'PDF' }}</span>
                                    </div>
                                    <img
                                        v-else
                                        :src="item.url"
                                        :alt="item.original_filename"
                                        class="h-full w-full object-cover"
                                        loading="lazy"
                                    />
                                </button>
                            </div>
                        </div>

                        <!-- Caption -->
                        <p
                            v-if="post.content"
                            class="whitespace-pre-wrap break-words font-sans text-base leading-[1.7] text-foreground"
                        >{{ post.content }}</p>

                        <!-- Labels -->
                        <div
                            v-if="post.labels && post.labels.length > 0"
                            class="mt-6 flex flex-wrap gap-2"
                        >
                            <LabelBadge
                                v-for="label in post.labels"
                                :key="label.id"
                                :label="label"
                            />
                        </div>
                    </div>
                </div>

                <!-- RIGHT: platforms breakdown -->
                <div class="flex flex-col gap-4 p-6 lg:overflow-y-auto">
                    <h2 class="text-[11px] font-black uppercase tracking-widest text-foreground/60">
                        {{ $t('posts.show.platforms') }}
                        <span class="ml-1 text-foreground/40">({{ enabledPlatforms.length }})</span>
                    </h2>

                    <Card v-if="enabledPlatforms.length === 0" class="py-0">
                        <CardContent class="p-8 text-center text-sm font-medium text-foreground/60">
                            {{ $t('posts.show.no_platforms') }}
                        </CardContent>
                    </Card>

                    <div v-else class="space-y-3">
                        <Card v-for="pp in enabledPlatforms" :key="pp.id" class="overflow-hidden py-0">
                            <CardContent class="p-0">
                                <div class="flex items-center gap-3 p-4">
                                    <div class="relative shrink-0">
                                        <Avatar
                                            :src="getDisplayAvatar(pp)"
                                            :name="getDisplayName(pp)"
                                            class="size-11 rounded-full border-2 border-foreground shadow-2xs"
                                        />
                                        <span class="absolute -bottom-1 -right-1 inline-flex size-5 items-center justify-center overflow-hidden rounded-full border-2 border-foreground bg-card shadow-2xs">
                                            <img
                                                :src="getPlatformLogo(pp.platform)"
                                                :alt="pp.platform"
                                                class="size-full object-cover"
                                            />
                                        </span>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-bold text-foreground">{{ getDisplayName(pp) }}</p>
                                        <p class="truncate text-xs font-medium text-foreground/60">
                                            <span v-if="getDisplayUsername(pp)">@{{ getDisplayUsername(pp) }} · </span>
                                            {{ getPlatformLabel(pp.platform) }}
                                        </p>
                                    </div>

                                    <div class="flex shrink-0 items-center gap-2">
                                        <Badge :variant="getPlatformStatusConfig(pp.status).variant">
                                            <component
                                                :is="getPlatformStatusConfig(pp.status).icon"
                                                class="size-3"
                                                :class="pp.status === PostPlatformStatus.Publishing ? 'animate-spin' : ''"
                                            />
                                            {{ getPlatformStatusConfig(pp.status).label }}
                                        </Badge>
                                        <TooltipProvider v-if="pp.status === PostPlatformStatus.Published && pp.platform_url" :delay-duration="200">
                                            <Tooltip>
                                                <TooltipTrigger as-child>
                                                    <a
                                                        :href="pp.platform_url"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="inline-flex size-8 cursor-pointer items-center justify-center rounded-full border-2 border-foreground bg-card text-foreground shadow-2xs transition-transform hover:rotate-3 hover:bg-violet-100"
                                                    >
                                                        <IconExternalLink class="size-4" stroke-width="2.5" />
                                                    </a>
                                                </TooltipTrigger>
                                                <TooltipContent>{{ $t('posts.show.view_on_platform') }}</TooltipContent>
                                            </Tooltip>
                                        </TooltipProvider>
                                    </div>
                                </div>

                                <!-- Failed: error message -->
                                <div
                                    v-if="pp.status === PostPlatformStatus.Failed && pp.error_message"
                                    class="border-t-2 border-foreground/10 bg-rose-50 px-4 py-3 text-xs font-semibold text-rose-700"
                                >
                                    {{ pp.error_message }}
                                </div>

                                <!-- Published: metrics -->
                                <PostPlatformMetrics
                                    v-if="pp.status === PostPlatformStatus.Published"
                                    :post-id="post.id"
                                    :post-platform-id="pp.id"
                                />
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </div>

        <ImagePreviewDialog ref="lightbox" />
    </AppLayout>
</template>
