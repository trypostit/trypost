<script setup lang="ts">
import { InfiniteScroll } from '@inertiajs/vue3';
import {
    IconAlertTriangle,
    IconCheck,
    IconChevronRight,
    IconClock,
    IconExternalLink,
    IconMinus,
    IconPencil,
} from '@tabler/icons-vue';
import type { Component } from 'vue';

import { getPlatformLabel, getPlatformLogo } from '@/composables/usePlatformLogo';
import date from '@/date';
import { edit } from '@/routes/app/posts';
import { PublishStatus, type PublishStatusValue } from '@/types/post';
import type { RepurposeItem, RepurposeItemPost } from '@/types/repurpose';
import { RepurposeItemStatus, type RepurposeItemStatusValue } from '@/types/repurpose-status';

defineProps<{
    items: RepurposeItem[];
}>();

const marks: Record<RepurposeItemStatusValue, { icon: Component; class: string }> = {
    [RepurposeItemStatus.Published]: { icon: IconCheck, class: 'bg-success-subtle text-success-text' },
    [RepurposeItemStatus.Drafted]: { icon: IconPencil, class: 'bg-info-subtle text-info-text' },
    [RepurposeItemStatus.Pending]: { icon: IconClock, class: 'bg-secondary text-muted-foreground' },
    [RepurposeItemStatus.Processing]: { icon: IconClock, class: 'bg-secondary text-muted-foreground' },
    [RepurposeItemStatus.Skipped]: { icon: IconMinus, class: 'bg-secondary text-muted-foreground' },
    [RepurposeItemStatus.Failed]: { icon: IconAlertTriangle, class: 'bg-critical-subtle text-destructive-text' },
};

const detail = (item: RepurposeItem): string | null => item.error ?? null;

const postState = (post: RepurposeItemPost): PublishStatusValue | null =>
    post.platform
        ? post.publish_status === PublishStatus.Rejected
            ? PublishStatus.Failed
            : post.publish_status
        : null;
</script>

<template>
    <InfiniteScroll data="items" items-element="#repurpose-items-body" preserve-url>
        <ul id="repurpose-items-body" class="divide-y divide-border-strong">
            <li
                v-for="item in items"
                :key="item.id"
                class="flex flex-wrap items-start gap-3 py-4 first:pt-0 last:pb-0"
                :data-testid="`repurpose-item-${item.id}`"
            >
                <span
                    class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg"
                    :class="marks[item.status].class"
                >
                    <component :is="marks[item.status].icon" class="size-4" />
                </span>

                <div class="min-w-0 flex-1 space-y-1">
                    <p class="text-sm leading-tight font-emphasis text-foreground">
                        {{
                            item.reason
                                ? $t(`repurposes.items.reasons.${item.reason}`)
                                : $t(`repurposes.items.statuses.${item.status}`)
                        }}
                    </p>

                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                        <span :title="date.formatDateTime(item.created_at)">
                            {{ date.diffForHumans(item.created_at) }}
                        </span>

                        <template v-if="item.source_created_at">
                            <span aria-hidden="true">·</span>

                            <a
                                v-if="item.source_permalink"
                                :href="item.source_permalink"
                                target="_blank"
                                rel="noopener noreferrer"
                                :title="date.formatDateTime(item.source_created_at)"
                                class="inline-flex items-center gap-1 underline"
                            >
                                {{ $t('repurposes.items.original_from', { date: date.formatDate(item.source_created_at) }) }}
                                <IconExternalLink class="size-3" />
                            </a>

                            <span v-else :title="date.formatDateTime(item.source_created_at)">
                                {{ $t('repurposes.items.original_from', { date: date.formatDate(item.source_created_at) }) }}
                            </span>
                        </template>
                    </p>

                    <p v-if="detail(item)" class="max-w-prose text-xs break-words text-destructive-text">
                        {{ detail(item) }}
                    </p>
                </div>

                <div v-if="(item.posts ?? []).length > 0" class="flex flex-wrap items-center gap-1.5">
                    <a
                        v-for="post in item.posts"
                        :key="post.id"
                        :href="edit.url(post.id)"
                        class="group/post inline-flex h-6 items-center gap-1.5 rounded-md bg-secondary pr-1.5 pl-2 text-xs font-medium text-foreground transition-control hover:bg-border-strong"
                    >
                        <img
                            v-if="post.platform"
                            :src="getPlatformLogo(post.platform)"
                            :alt="getPlatformLabel(post.platform)"
                            class="size-4 rounded-sm"
                            :class="{ 'opacity-40': post.publish_status === PublishStatus.Failed }"
                        />

                        {{ post.platform ? getPlatformLabel(post.platform) : '' }}

                        <span
                            v-if="postState(post)"
                            :class="[
                                'rounded px-1 py-px text-[10px] font-semibold uppercase tracking-wide',
                                postState(post) === PublishStatus.Failed
                                    ? 'bg-critical-subtle text-destructive-text'
                                    : 'bg-foreground/10 text-foreground/60',
                            ]"
                        >
                            {{
                                postState(post) === PublishStatus.PendingReview
                                    ? $t('posts.publish.in_google_review')
                                    : $t(`posts.status.${postState(post)}`)
                            }}
                        </span>

                        <IconChevronRight class="size-3.5 text-muted-foreground transition-colors group-hover/post:text-foreground" />
                    </a>
                </div>
            </li>
        </ul>
    </InfiniteScroll>
</template>
