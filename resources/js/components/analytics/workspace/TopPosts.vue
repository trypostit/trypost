<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconArrowUpRight } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import date from '@/date';
import { formatNumberCompact } from '@/lib/utils';
import { show as analyticsShow } from '@/routes/app/analytics';
import { show as publicationShow } from '@/routes/app/analytics/publications';

import type { TopPost, WorkspaceAnalyticsReport } from '@/types/analytics';
import AccountIdentity from './AccountIdentity.vue';
import AnalyticsModeToggle from './AnalyticsModeToggle.vue';
import AnalyticsSection from './AnalyticsSection.vue';

const props = defineProps<{
    topPosts: WorkspaceAnalyticsReport['top_posts'];
    range: WorkspaceAnalyticsReport['range'];
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
            class="rounded-lg border border-dashed border-border px-5 py-10 text-center text-sm text-muted-foreground"
        >
            {{ $t('analytics.dashboard.no_ranked_posts') }}
        </div>
        <div
            v-else
            class="grid gap-3 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-5"
        >
            <article
                v-for="(post, index) in posts"
                :key="post.id"
                class="flex min-h-56 min-w-0 flex-col rounded-xl border-2 border-foreground bg-background p-4 shadow-xs"
            >
                <div
                    class="flex items-center justify-between gap-2 border-b border-foreground/15 pb-3 text-xs font-medium text-muted-foreground"
                >
                    <span
                        class="flex size-7 items-center justify-center rounded-full border border-foreground bg-violet-100 font-semibold text-foreground"
                        >{{ index + 1 }}</span
                    >
                    <span class="tabular-nums">
                        <strong class="text-sm text-foreground">{{
                            formatNumberCompact(post[metric] ?? 0)
                        }}</strong>
                        {{ $t(`analytics.dashboard.${metric}`) }}
                    </span>
                </div>
                <div class="flex flex-1 flex-col gap-3 pt-3">
                    <div
                        class="flex min-w-0 items-center justify-between gap-2"
                    >
                        <AccountIdentity
                            :account="{
                                social_account_key: post.social_account_key,
                                platform: post.platform,
                                name: post.name,
                                username: post.username,
                                avatar_url: null,
                            }"
                        />
                        <span class="shrink-0 text-xs text-muted-foreground">{{
                            date.formatDateShort(post.published_at)
                        }}</span>
                    </div>
                    <div class="flex min-h-16 gap-3">
                        <p
                            class="line-clamp-3 min-w-0 flex-1 text-sm leading-5 text-foreground"
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
                            class="size-14 shrink-0 rounded-lg object-cover"
                        />
                    </div>
                    <div
                        class="mt-auto flex items-center justify-between gap-2 border-t border-foreground/10 pt-3 text-xs text-muted-foreground"
                    >
                        <span class="line-clamp-1">{{
                            post.origin === 'trypost'
                                ? $t(
                                      'analytics.dashboard.published_via_trypost',
                                  )
                                : $t('analytics.dashboard.published_on_network')
                        }}</span>
                        <Link
                            v-if="post.availability === 'available'"
                            :href="
                                post.post_id
                                    ? analyticsShow.url(post.post_id, {
                                          query: { publication: post.id },
                                      })
                                    : publicationShow.url(post.id)
                            "
                            class="inline-flex shrink-0 items-center gap-0.5 font-semibold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                        >
                            {{ $t('analytics.detail.details') }}
                            <IconArrowUpRight class="size-3.5" />
                        </Link>
                    </div>
                </div>
            </article>
        </div>
    </AnalyticsSection>
</template>
