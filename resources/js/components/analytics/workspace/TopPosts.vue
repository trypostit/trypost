<script setup lang="ts">
import { computed, ref } from 'vue';

import AppLogo from '@/components/AppLogo.vue';
import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import PostDetailsDialog from '@/components/publish/PostDetailsDialog.vue';
import PublicationDetailsDialog from '@/components/publish/PublicationDetailsDialog.vue';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { usePostDetails } from '@/composables/usePostDetails';
import date from '@/date';
import { isImage, isVideo } from '@/lib/mediaType';
import { formatNumberCompact } from '@/lib/utils';
import { videoFrameUrl } from '@/lib/videoFrame';
import type { TopPost, WorkspaceAnalyticsReport } from '@/types/analytics';
import { Platform } from '@/types/platform';

import AccountIdentity from './AccountIdentity.vue';
import AnalyticsModeToggle from './AnalyticsModeToggle.vue';
import AnalyticsSection from './AnalyticsSection.vue';

const props = defineProps<{
    topPosts: WorkspaceAnalyticsReport['top_posts'];
    range: WorkspaceAnalyticsReport['range'];
    filtered?: boolean;
}>();
const metric = ref<'reactions' | 'comments'>('reactions');
const buttons = [
    {
        mode: 'reactions',
        label: 'analytics.dashboard.reactions',
        test: 'top-reactions',
    },
    {
        mode: 'comments',
        label: 'analytics.dashboard.comments',
        test: 'top-comments',
    },
] as const;
const posts = computed<TopPost[]>(() => props.topPosts[metric.value]);
const {
    detailsPost,
    detailsOpen,
    openPost,
    detailsPublication,
    publicationOpen,
    openPublication,
    editPost,
    runPostAction,
} = usePostDetails();

const openDetails = (post: TopPost): void => {
    if (post.post_id) {
        openPost(post.post_id);

        return;
    }

    openPublication(post.id);
};
const thumbnailFor = (post: TopPost): string | null => {
    if (post.media && isImage(post.media)) {
        return post.media.url;
    }

    const value = post.preview_metadata?.thumbnail_url;
    return typeof value === 'string' && /^https:\/\//i.test(value)
        ? value
        : null;
};
</script>

<template>
    <AnalyticsSection
        :title="$t('analytics.dashboard.top_posts')"
        :info="$t('analytics.insights.about.top_posts')"
        info-testid="analytics-top-posts-about"
        :range="range"
        flush
    >
        <template #actions>
            <AnalyticsModeToggle
                v-model="metric"
                label="analytics.dashboard.top_posts_sort"
                :options="buttons"
            />
        </template>

        <div v-if="posts.length === 0" class="px-4 pb-4 sm:px-5 sm:pb-5">
            <div
                data-testid="analytics-top-posts-empty"
                class="rounded-lg border border-dashed border-border-strong px-5 py-10 text-center text-sm text-muted-foreground"
            >
                {{
                    $t(
                        filtered
                            ? 'analytics.dashboard.filtered_no_posts'
                            : 'analytics.dashboard.no_ranked_posts',
                    )
                }}
            </div>
        </div>
        <div
            v-else
            class="grid border-t border-border sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5"
        >
            <article
                v-for="(post, index) in posts"
                :key="post.id"
                class="group relative flex min-w-0 flex-col gap-3 p-4 shadow-[1px_0_0_var(--border),0_1px_0_var(--border)] transition-colors duration-150 has-[button:hover]:bg-muted/60 has-[button:focus-visible]:bg-muted/60 sm:px-5"
                data-testid="analytics-top-post"
            >
                <div class="mb-1 flex items-stretch justify-between gap-2">
                    <div class="flex min-w-0 flex-col">
                        <span
                            class="font-heading text-2xl leading-8 font-medium text-foreground tabular-nums"
                            >{{ formatNumberCompact(post[metric] ?? 0) }}</span
                        >
                        <span class="text-xs leading-4 text-muted-foreground">{{
                            $t(`analytics.dashboard.${metric}`)
                        }}</span>
                    </div>
                    <div class="flex shrink-0 flex-col items-end justify-between gap-1">
                        <span
                            class="inline-flex h-6 min-w-7 items-center justify-center rounded-md bg-primary-selected px-1.5 text-xs font-semibold text-primary-text tabular-nums"
                            :data-testid="`analytics-top-post-rank-${post.id}`"
                            >#{{ index + 1 }}</span
                        >
                        <span
                            class="text-xs leading-4 whitespace-nowrap text-muted-foreground"
                            :data-testid="`analytics-top-post-date-${post.id}`"
                            >{{ date.formatDayMonthYear(post.published_at) }}</span
                        >
                    </div>
                </div>
                <AccountIdentity
                    :account="post"
                    with-avatar
                    with-name
                    data-testid="analytics-top-post-channel"
                />
                <div class="flex min-h-[54px] gap-3">
                    <p
                        class="line-clamp-3 min-w-0 flex-1 text-sm leading-[18px] text-foreground"
                    >
                        {{ post.excerpt || $t('analytics.dashboard.no_excerpt') }}
                    </p>
                    <video
                        v-if="post.media && isVideo(post.media)"
                        :src="videoFrameUrl(post.media)"
                        class="size-12 shrink-0 rounded-lg object-cover"
                        muted
                        playsinline
                        preload="metadata"
                        aria-hidden="true"
                        :data-testid="`analytics-top-post-video-${post.id}`"
                    />
                    <img
                        v-else-if="thumbnailFor(post)"
                        :src="thumbnailFor(post)!"
                        alt=""
                        loading="lazy"
                        class="size-12 shrink-0 rounded-lg object-cover"
                    />
                </div>
                <div
                    class="-mx-4 mt-auto -mb-4 flex min-h-11 items-center justify-center border-t border-border px-4 text-xs text-muted-foreground sm:-mx-5 sm:px-5"
                >
                    <span
                        class="inline-flex min-w-0 items-center gap-1"
                        :data-testid="`analytics-top-post-published-via-${post.id}`"
                    >
                        <span class="truncate">{{
                            $t('analytics.dashboard.published_via')
                        }}</span>
                        <template v-if="post.origin === 'trypost'">
                            <AppLogo variant="mark" class="text-[13px]" />
                            <span class="shrink-0">TryPost</span>
                        </template>
                        <template v-else>
                            <PlatformBrandIcon
                                :platform="post.platform"
                                class="size-3.5 shrink-0"
                            />
                            <span
                                v-if="post.platform !== Platform.X"
                                class="shrink-0"
                                >{{ getPlatformLabel(post.platform) }}</span
                            >
                        </template>
                    </span>
                </div>
                <button
                    type="button"
                    data-testid="analytics-top-post-link"
                    class="absolute inset-0 cursor-pointer focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                    :aria-label="$t('analytics.detail.details')"
                    @click="openDetails(post)"
                />
            </article>
        </div>
        <PublicationDetailsDialog
            v-if="detailsPublication"
            v-model:open="publicationOpen"
            :detail="detailsPublication"
            :channel-id="null"
        />
        <PostDetailsDialog
            v-if="detailsPost"
            v-model:open="detailsOpen"
            :post="detailsPost"
            :test-key="detailsPost.id"
            :timezone="date.getUserTimezone()"
            @select="runPostAction"
            @edit="editPost"
        />
    </AnalyticsSection>
</template>
