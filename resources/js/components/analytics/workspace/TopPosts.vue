<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

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
</script>

<template>
    <AnalyticsSection
        :title="$t('analytics.dashboard.top_posts')"
        :subtitle="`${range.start} – ${range.end}`"
    >
        <template #actions>
            <div
                class="inline-flex rounded-lg border border-border bg-background p-1"
                role="group"
                :aria-label="$t('analytics.dashboard.top_posts_sort')"
            >
                <button
                    type="button"
                    data-testid="top-reactions"
                    class="rounded-md px-3 py-1 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    :class="
                        metric === 'reactions'
                            ? 'bg-primary/10 text-primary'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :aria-pressed="metric === 'reactions'"
                    @click="metric = 'reactions'"
                >
                    {{ $t('analytics.dashboard.reactions') }}
                </button>
                <button
                    type="button"
                    data-testid="top-comments"
                    class="rounded-md px-3 py-1 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    :class="
                        metric === 'comments'
                            ? 'bg-primary/10 text-primary'
                            : 'text-muted-foreground hover:text-foreground'
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
                class="flex min-h-44 flex-col rounded-lg border border-border bg-background"
            >
                <div
                    class="flex items-center justify-between border-b border-border bg-muted/35 px-3 py-2 text-xs font-semibold text-foreground"
                >
                    <span>#{{ index + 1 }}</span>
                    <span class="tabular-nums"
                        >{{ formatNumberCompact(post[metric] ?? 0) }}
                        {{ $t(`analytics.dashboard.${metric}`) }}</span
                    >
                </div>
                <div class="flex flex-1 flex-col gap-2 p-3">
                    <AccountIdentity
                        :account="{
                            social_account_key: post.social_account_key,
                            platform: post.platform,
                            name: post.name,
                            username: post.username,
                            avatar_url: null,
                        }"
                    />
                    <p class="line-clamp-3 text-sm leading-5 text-foreground">
                        {{
                            post.excerpt || $t('analytics.dashboard.no_excerpt')
                        }}
                    </p>
                    <div
                        class="mt-auto flex items-end justify-between gap-2 pt-2 text-xs text-muted-foreground"
                    >
                        <span>{{
                            post.origin === 'trypost'
                                ? $t(
                                      'analytics.dashboard.published_via_trypost',
                                  )
                                : $t('analytics.dashboard.published_on_network')
                        }}</span>
                        <Link
                            v-if="post.availability === 'available'"
                            :href="publicationShow.url(post.id)"
                            class="shrink-0 font-medium text-primary underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                            >{{ $t('analytics.detail.details') }}</Link
                        >
                    </div>
                </div>
            </article>
        </div>
    </AnalyticsSection>
</template>
