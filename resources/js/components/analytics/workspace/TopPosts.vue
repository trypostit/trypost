<script setup lang="ts">
import { IconArrowUpRight } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import PublicationTypeBadge from '@/components/analytics/PublicationTypeBadge.vue';
import ChannelAvatar from '@/components/ChannelAvatar.vue';
import PostDetailsDialog from '@/components/publish/PostDetailsDialog.vue';
import PublicationDetailsDialog from '@/components/publish/PublicationDetailsDialog.vue';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { usePostDetails } from '@/composables/usePostDetails';
import date from '@/date';
import { isImage, isVideo } from '@/lib/mediaType';
import { formatNumberCompact } from '@/lib/utils';
import { videoFrameUrl } from '@/lib/videoFrame';
import type { TopPost, WorkspaceAnalyticsReport } from '@/types/analytics';

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
    >
        <template #actions>
            <AnalyticsModeToggle
                v-model="metric"
                label="analytics.dashboard.top_posts_sort"
                :options="buttons"
            />
        </template>

        <div
            v-if="posts.length === 0"
            data-testid="analytics-top-posts-empty"
            class="rounded-lg border border-dashed border-border-strong bg-card px-5 py-10 text-center text-sm text-muted-foreground"
        >
            {{
                $t(
                    filtered
                        ? 'analytics.dashboard.filtered_no_posts'
                        : 'analytics.dashboard.no_ranked_posts',
                )
            }}
        </div>
        <div
            v-else
            class="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5"
        >
            <article
                v-for="(post, index) in posts"
                :key="post.id"
                class="relative flex min-w-0 flex-col overflow-hidden rounded-xl border border-border bg-secondary transition-control has-[a:hover]:border-border-strong dark:bg-muted"
                data-testid="analytics-top-post"
            >
                <div class="flex h-8 items-center justify-between gap-2 px-3">
                    <span class="text-xs text-foreground">#{{ index + 1 }}</span>
                    <span class="text-xs tabular-nums">
                        <strong class="font-emphasis text-foreground">{{
                            formatNumberCompact(post[metric] ?? 0)
                        }}</strong>
                        {{ ' ' }}
                        <span class="font-medium text-muted-foreground">{{
                            $t(`analytics.dashboard.${metric}`)
                        }}</span>
                    </span>
                </div>
                <div
                    class="flex flex-1 flex-col gap-2 rounded-t-lg border-t border-border bg-card p-3"
                >
                    <div
                        class="flex min-h-6 min-w-0 items-center justify-between gap-2"
                    >
                        <span
                            class="inline-flex min-w-0 items-center gap-1 text-xs text-foreground"
                            :title="
                                post.username
                                    ? `@${post.username}`
                                    : (post.name ?? undefined)
                            "
                        >
                            <ChannelAvatar
                                :platform="post.platform"
                                :src="post.avatar_url"
                                :name="
                                    post.username
                                        ? `@${post.username}`
                                        : (post.name ??
                                          getPlatformLabel(post.platform))
                                "
                                :size="24"
                                ring="card"
                                :status="post.status"
                                :account-id="post.social_account_key"
                                data-testid="analytics-top-post-channel"
                            />
                            <span class="truncate">{{
                                date.formatDateShort(post.published_at)
                            }}</span>
                        </span>
                        <PublicationTypeBadge
                            :platform="post.platform"
                            :content-type="post.content_type"
                            :test-id="`analytics-top-post-type-${post.id}`"
                        />
                    </div>
                    <div class="flex min-h-[54px] gap-3">
                        <p
                            class="line-clamp-3 min-w-0 flex-1 text-xs text-foreground"
                        >
                            {{
                                post.excerpt ||
                                $t('analytics.dashboard.no_excerpt')
                            }}
                        </p>
                        <video
                            v-if="post.media && isVideo(post.media)"
                            :src="videoFrameUrl(post.media)"
                            class="size-11 shrink-0 rounded-md object-cover"
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
                            class="size-11 shrink-0 rounded-md object-cover"
                        />
                    </div>
                    <div
                        class="mt-auto flex min-h-8 items-center justify-between gap-2 pt-2 text-xs text-muted-foreground"
                    >
                        <span class="line-clamp-1">{{
                            post.origin === 'trypost'
                                ? $t(
                                      'analytics.dashboard.published_via_trypost',
                                  )
                                : $t('analytics.dashboard.published_on_network')
                        }}</span>
                        <button
                            type="button"
                            data-testid="analytics-top-post-link"
                            class="inline-flex h-6 shrink-0 items-center gap-0.5 rounded-md px-1.5 font-medium text-foreground transition-control after:absolute after:inset-0 hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring"
                            @click="openDetails(post)"
                        >
                            {{ $t('analytics.detail.details') }}
                            <IconArrowUpRight
                                class="size-3.5"
                                aria-hidden="true"
                            />
                        </button>
                    </div>
                </div>
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
