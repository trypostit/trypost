<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconArrowUpRight, IconExternalLink } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import { formatNumberCompact } from '@/lib/utils';
import { index as postsIndex } from '@/routes/app/posts';
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
const detailsUrl = (post: TopPost): string | null =>
    post.post_id ? postsIndex.url({ query: { post: post.post_id } }) : null;
const networkUrl = (post: TopPost): string | null =>
    post.permalink && /^https:\/\//i.test(post.permalink)
        ? post.permalink
        : null;
const thumbnailFor = (post: TopPost): string | null => {
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
            class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-5"
        >
            <article
                v-for="(post, index) in posts"
                :key="post.id"
                class="relative flex min-w-0 flex-col overflow-hidden rounded-xl border border-border bg-secondary transition-control has-[a:hover]:border-border-strong"
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
                        <span
                            class="inline-flex h-4.5 shrink-0 items-center rounded-full bg-secondary px-1 text-xs font-medium text-foreground"
                            >{{
                                $t(
                                    `analytics.detail.content_types.${post.content_type}`,
                                )
                            }}</span
                        >
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
                        <img
                            v-if="thumbnailFor(post)"
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
                        <Link
                            v-if="detailsUrl(post)"
                            :href="detailsUrl(post)!"
                            data-testid="analytics-top-post-link"
                            class="inline-flex h-6 shrink-0 items-center gap-0.5 rounded-md px-1.5 font-medium text-foreground transition-control after:absolute after:inset-0 hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring"
                        >
                            {{ $t('analytics.detail.details') }}
                            <IconArrowUpRight
                                class="size-3.5"
                                aria-hidden="true"
                            />
                        </Link>
                        <a
                            v-else-if="networkUrl(post)"
                            :href="networkUrl(post)!"
                            target="_blank"
                            rel="noopener noreferrer"
                            data-testid="analytics-top-post-link"
                            class="inline-flex h-6 shrink-0 items-center gap-0.5 rounded-md px-1.5 font-medium text-foreground transition-control after:absolute after:inset-0 hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring"
                        >
                            {{ $t('analytics.dashboard.view_post') }}
                            <IconExternalLink
                                class="size-3.5"
                                aria-hidden="true"
                            />
                        </a>
                    </div>
                </div>
            </article>
        </div>
    </AnalyticsSection>
</template>
