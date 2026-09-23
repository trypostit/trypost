<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconArrowUpRight } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import dayjs from '@/dayjs';
import { formatNumberCompact } from '@/lib/utils';
import { show as publicationShow } from '@/routes/app/analytics/publications';

import AccountIdentity from './AccountIdentity.vue';
import AnalyticsSection from './AnalyticsSection.vue';
import type { TopPost, WorkspaceAnalyticsReport } from './types';

const props = defineProps<{
    topPosts: WorkspaceAnalyticsReport['top_posts'];
    range: WorkspaceAnalyticsReport['range'];
}>();
const metric = ref<'reactions' | 'comments'>('reactions');
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
        :subtitle="`${dayjs(range.start).format('D MMM YYYY')} – ${dayjs(range.end).format('D MMM YYYY')}`"
    >
        <template #actions>
            <div
                class="inline-flex rounded-lg border-2 border-foreground bg-card p-1 shadow-xs"
                role="group"
                :aria-label="$t('analytics.dashboard.top_posts_sort')"
            >
                <button
                    type="button"
                    data-testid="top-reactions"
                    class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground"
                    :class="
                        metric === 'reactions'
                            ? 'bg-violet-100 text-foreground'
                            : 'text-foreground/65 hover:bg-muted hover:text-foreground'
                    "
                    :aria-pressed="metric === 'reactions'"
                    @click="metric = 'reactions'"
                >
                    {{ $t('analytics.dashboard.reactions') }}
                </button>
                <button
                    type="button"
                    data-testid="top-comments"
                    class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground"
                    :class="
                        metric === 'comments'
                            ? 'bg-violet-100 text-foreground'
                            : 'text-foreground/65 hover:bg-muted hover:text-foreground'
                    "
                    :aria-pressed="metric === 'comments'"
                    @click="metric = 'comments'"
                >
                    {{ $t('analytics.dashboard.comments') }}
                </button>
            </div>
        </template>

        <div
            v-if="posts.length === 0"
            class="rounded-lg border border-dashed border-border px-5 py-10 text-center text-sm text-muted-foreground"
        >
            {{ $t('analytics.dashboard.no_ranked_posts') }}
        </div>
        <div v-else class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
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
                            dayjs(post.published_at).format('D MMM')
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
                            :href="publicationShow.url(post.id)"
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
